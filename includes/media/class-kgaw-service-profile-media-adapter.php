<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Direct-upload control plane for service-profile images. */
final class Service_Profile_Media_Adapter implements \Koopo_Media_Gateway_Adapter {
  const REST_NAMESPACE = 'koopo-media-gateway/v1';
  const ROUTE_BASE = '/appointments/service-profiles/(?P<provider_id>\d+)/image/upload-sessions';
  const GALLERY_ROUTE_BASE = '/appointments/service-profiles/(?P<provider_id>\d+)/gallery/upload-sessions';
  const CLIENT_FILE_ROUTE_BASE = '/appointments/clients/(?P<client_id>\d+)/files/upload-sessions';
  const ROLE = 'service_profile_image';
  const GALLERY_ROLE = 'service_profile_gallery';
  const CLIENT_FILE_ROLE = 'appointment_client_file';

  private $coordinator;
  private $policy;
  private $projector;

  public function __construct($coordinator, $policy, $projector) {
    $this->coordinator = $coordinator;
    $this->policy = $policy;
    $this->projector = $projector;
  }

  public function key() { return 'appointments_media'; }

  public function boot() {
    $this->projector->boot();
    add_action('rest_api_init', [$this, 'register_routes']);
    add_action('admin_post_koopo_appt_client_file', [$this, 'download_client_file']);
  }

  public function register_routes(): void {
    foreach ([self::ROUTE_BASE, self::GALLERY_ROUTE_BASE] as $base) {
      register_rest_route(self::REST_NAMESPACE, $base, [
        'methods' => \WP_REST_Server::CREATABLE,
        'callback' => [$this, 'create_session'],
        'permission_callback' => [$this, 'authorize_request'],
      ]);
      register_rest_route(self::REST_NAMESPACE, $base . '/(?P<id>[a-f0-9-]{36})', [
        ['methods' => \WP_REST_Server::READABLE, 'callback' => [$this, 'get_session'], 'permission_callback' => [$this, 'authorize_request']],
        ['methods' => \WP_REST_Server::DELETABLE, 'callback' => [$this, 'cancel_session'], 'permission_callback' => [$this, 'authorize_request']],
      ]);
      foreach (['refresh', 'complete'] as $action) register_rest_route(self::REST_NAMESPACE, $base . '/(?P<id>[a-f0-9-]{36})/' . $action, [
        'methods' => \WP_REST_Server::CREATABLE,
        'callback' => [$this, $action . '_session'],
        'permission_callback' => [$this, 'authorize_request'],
      ]);
    }
    $base = self::CLIENT_FILE_ROUTE_BASE;
    register_rest_route(self::REST_NAMESPACE, $base, ['methods'=>\WP_REST_Server::CREATABLE,'callback'=>[$this,'create_session'],'permission_callback'=>[$this,'authorize_request']]);
    register_rest_route(self::REST_NAMESPACE, $base . '/(?P<id>[a-f0-9-]{36})', [
      ['methods'=>\WP_REST_Server::READABLE,'callback'=>[$this,'get_session'],'permission_callback'=>[$this,'authorize_request']],
      ['methods'=>\WP_REST_Server::DELETABLE,'callback'=>[$this,'cancel_session'],'permission_callback'=>[$this,'authorize_request']],
    ]);
    foreach(['refresh','complete'] as $action)register_rest_route(self::REST_NAMESPACE,$base.'/(?P<id>[a-f0-9-]{36})/'.$action,['methods'=>\WP_REST_Server::CREATABLE,'callback'=>[$this,$action.'_session'],'permission_callback'=>[$this,'authorize_request']]);
    register_rest_route(self::REST_NAMESPACE, '/appointments/clients/(?P<client_id>\d+)/files/(?P<file_id>\d+)', ['methods'=>\WP_REST_Server::DELETABLE,'callback'=>[$this,'delete_client_file'],'permission_callback'=>[$this,'authorize_request']]);
  }

  public function authorize_request(\WP_REST_Request $request) {
    if (!get_current_user_id()) return new \WP_Error('koopo_appt_media_auth_required', __('Authentication is required.', 'koopo-appointments'), ['status' => 401]);
    if (!$this->policy->is_module_enabled($this->key())) return new \WP_Error('koopo_appt_media_disabled', __('Direct service-profile image uploads are disabled.', 'koopo-appointments'), ['status' => 503]);
    if ($this->is_client_file_request($request)) {
      global $wpdb;
      $client = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::clients_table().' WHERE id=%d', absint($request['client_id'])));
      if (!$client || !Resources::can_manage((int)$client->resource_id)) return new \WP_Error('koopo_appt_media_forbidden', __('You cannot update that private client record.', 'koopo-appointments'), ['status'=>403]);
      return true;
    }
    $provider_id = absint($request['provider_id']);
    $provider = get_post($provider_id);
    if (!$provider || $provider->post_type !== Provider_Profiles::POST_TYPE || (!Access::is_admin_bypass() && (int) $provider->post_author !== get_current_user_id())) {
      return new \WP_Error('koopo_appt_media_forbidden', __('You cannot update that service profile image.', 'koopo-appointments'), ['status' => 403]);
    }
    return true;
  }

  public function create_session(\WP_REST_Request $request) {
    $body = $request->get_json_params();
    $body = is_array($body) ? $body : [];
    $mime = sanitize_mime_type((string) ($body['mimeType'] ?? ''));
    $size = absint($body['sizeBytes'] ?? 0);
    if ($this->is_client_file_request($request)) return $this->create_client_file_session($request, $body, $mime, $size);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/avif'];
    $max = (int) apply_filters('koopo_appt_service_profile_image_max_bytes', 6 * MB_IN_BYTES);
    if ('image' !== sanitize_key((string) ($body['kind'] ?? '')) || !in_array($mime, $allowed, true) || $size <= 0 || $size > $max) {
      return new \WP_Error('koopo_appt_media_invalid', __('Choose a JPEG, PNG, WebP, or AVIF image up to 6 MB.', 'koopo-appointments'), ['status' => 422]);
    }
    $provider_id = absint($request['provider_id']);
    $role = $this->request_role($request);
    if (self::GALLERY_ROLE === $role && count(Provider_Profiles::gallery_ids($provider_id)) >= Provider_Profiles::GALLERY_LIMIT) {
      return new \WP_Error('koopo_appt_gallery_full', sprintf(__('A service profile can have up to %d gallery photos.', 'koopo-appointments'), Provider_Profiles::GALLERY_LIMIT), ['status' => 409]);
    }
    $body['kind'] = 'image';
    $body['context'] = 'profile';
    $body['metadata'] = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
    $body['metadata']['context'] = 'profile';
    $body['metadata']['role'] = $role;
    $body['metadata']['targetId'] = $provider_id;
    $body['metadata']['origin'] = 'koopo_appointments';
    $key = sanitize_text_field((string) ($request->get_header('idempotency-key') ?: ($body['clientRequestId'] ?? '')));
    $result = $this->coordinator->create_session(get_current_user_id(), $body, $key);
    if (!is_wp_error($result)) {
      $session_id = sanitize_text_field((string) ($result['body']['session']['id'] ?? ''));
      if ($session_id) set_transient($this->session_binding_key($session_id), ['provider_id'=>$provider_id,'owner_id'=>get_current_user_id(),'role'=>$role], 2 * HOUR_IN_SECONDS);
    }
    return $this->gateway_response($result);
  }

  public function get_session(\WP_REST_Request $request) { return $this->gateway_response($this->coordinator->get_session(get_current_user_id(), $request['id'])); }
  public function refresh_session(\WP_REST_Request $request) { return $this->gateway_response($this->coordinator->refresh_session(get_current_user_id(), $request['id'])); }
  public function cancel_session(\WP_REST_Request $request) { $result=$this->coordinator->cancel_session(get_current_user_id(), $request['id']); if(!is_wp_error($result))delete_transient($this->session_binding_key((string)$request['id'])); return $this->gateway_response($result); }

  public function complete_session(\WP_REST_Request $request) {
    $result = $this->coordinator->complete_session(get_current_user_id(), $request['id']);
    if (is_wp_error($result)) return $result;
    $payload = is_array($result['body'] ?? null) ? $result['body'] : [];
    $asset = is_array($payload['asset'] ?? null) ? $payload['asset'] : [];
    $meta = is_array($asset['metadata'] ?? null) ? $asset['metadata'] : [];
    if ($this->is_client_file_request($request)) return $this->complete_client_file_session($request, $result, $payload, $asset, $meta);
    $provider_id = absint($request['provider_id']);
    $user_id = get_current_user_id();
    $role = $this->request_role($request);
    $binding = get_transient($this->session_binding_key((string) $request['id']));
    if (!is_array($binding) || $provider_id !== absint($binding['provider_id'] ?? 0) || $user_id !== absint($binding['owner_id'] ?? 0) || $role !== sanitize_key((string) ($binding['role'] ?? '')) || 'ready' !== sanitize_key((string) ($asset['status'] ?? '')) || 'image' !== sanitize_key((string) ($asset['kind'] ?? '')) || 'profile' !== sanitize_key((string) ($meta['context'] ?? '')) || 'koopo_appointments' !== sanitize_key((string) ($meta['origin'] ?? '')) || $provider_id !== absint($meta['targetId'] ?? 0) || (string) $user_id !== (string) ($asset['ownerId'] ?? '')) {
      return new \WP_Error('koopo_appt_media_mismatch', __('This upload does not match the selected service profile.', 'koopo-appointments'), ['status' => 409]);
    }
    $projection = $this->projector->project($asset, $user_id, [
      'post_parent' => $provider_id,
      'title' => self::GALLERY_ROLE === $role ? sprintf(__('%s portfolio photo', 'koopo-appointments'), get_the_title($provider_id)) : sprintf(__('%s service profile image', 'koopo-appointments'), get_the_title($provider_id)),
      'role' => $role,
      'origin' => 'koopo_appointments',
      'alt_text' => get_the_title($provider_id),
    ]);
    if (is_wp_error($projection)) return $projection;
    $attachment_id = absint($projection['id'] ?? 0);
    if (!$attachment_id) {
      wp_delete_attachment($attachment_id, true);
      return new \WP_Error('koopo_appt_media_projection_failed', __('The service profile image could not be attached.', 'koopo-appointments'), ['status' => 500]);
    }
    update_post_meta($attachment_id, 'koopo_bbmu_context', 'appointments_service_profile');
    if (self::GALLERY_ROLE === $role) {
      if (!Provider_Profiles::add_gallery_image($provider_id, $attachment_id)) {
        wp_delete_attachment($attachment_id, true);
        return new \WP_Error('koopo_appt_gallery_full', sprintf(__('A service profile can have up to %d gallery photos.', 'koopo-appointments'), Provider_Profiles::GALLERY_LIMIT), ['status' => 409]);
      }
    } else {
      $previous_id = (int) get_post_thumbnail_id($provider_id);
      $thumbnail_saved = set_post_thumbnail($provider_id, $attachment_id);
      if (!$thumbnail_saved && (int) get_post_thumbnail_id($provider_id) !== $attachment_id) {
        wp_delete_attachment($attachment_id, true);
        return new \WP_Error('koopo_appt_media_projection_failed', __('The service profile image could not be attached.', 'koopo-appointments'), ['status' => 500]);
      }
      update_post_meta($provider_id, '_koopo_provider_image_id', $attachment_id);
      if ($previous_id && $previous_id !== $attachment_id) wp_delete_attachment($previous_id, true);
    }
    $payload['serviceProfileMedia'] = [
      'providerId' => $provider_id,
      'attachmentId' => $attachment_id,
      'url' => esc_url_raw((string) ($projection['url'] ?? '')),
      'role' => $role,
    ];
    if (self::GALLERY_ROLE === $role) $payload['serviceProfileMedia']['gallery'] = Provider_Profiles::gallery($provider_id);
    delete_transient($this->session_binding_key((string) $request['id']));
    return new \WP_REST_Response($payload, (int) ($result['status'] ?? 200));
  }

  private function gateway_response($result) {
    if (is_wp_error($result)) return $result;
    return new \WP_REST_Response($result['body'], (int) $result['status']);
  }

  private function request_role(\WP_REST_Request $request): string {
    return false !== strpos($request->get_route(), '/gallery/upload-sessions') ? self::GALLERY_ROLE : self::ROLE;
  }

  private function is_client_file_request(\WP_REST_Request $request): bool { return false !== strpos($request->get_route(), '/appointments/clients/'); }

  private function create_client_file_session(\WP_REST_Request $request, array $body, string $mime, int $size) {
    $allowed=['image/jpeg','image/png','image/webp','application/pdf'];$max=(int)apply_filters('koopo_appt_client_file_max_bytes',10*MB_IN_BYTES);
    $kind=sanitize_key((string)($body['kind']??''));if(!in_array($mime,$allowed,true)||$size<=0||$size>$max||!in_array($kind,['image','document'],true))return new \WP_Error('koopo_appt_client_file_invalid',__('Choose a JPEG, PNG, WebP, or PDF up to 10 MB.','koopo-appointments'),['status'=>422]);
    $client_id=absint($request['client_id']);$filename=sanitize_file_name((string)($body['filename']??'client-file'));$body['context']='profile';$body['metadata']=is_array($body['metadata']??null)?$body['metadata']:[];$body['metadata']=array_merge($body['metadata'],['context'=>'profile','role'=>self::CLIENT_FILE_ROLE,'targetId'=>$client_id,'origin'=>'koopo_appointments','filename'=>$filename]);
    $key=sanitize_text_field((string)($request->get_header('idempotency-key')?:($body['clientRequestId']??'')));$result=$this->coordinator->create_session(get_current_user_id(),$body,$key);
    if(!is_wp_error($result)){ $session_id=sanitize_text_field((string)($result['body']['session']['id']??''));if($session_id)set_transient($this->session_binding_key($session_id),['client_id'=>$client_id,'owner_id'=>get_current_user_id(),'role'=>self::CLIENT_FILE_ROLE,'filename'=>$filename,'mime'=>$mime,'size'=>$size],2*HOUR_IN_SECONDS); }
    return $this->gateway_response($result);
  }

  private function complete_client_file_session(\WP_REST_Request $request,array $result,array $payload,array $asset,array $meta) {
    $client_id=absint($request['client_id']);$user_id=get_current_user_id();$binding=get_transient($this->session_binding_key((string)$request['id']));$asset_id=sanitize_text_field((string)($asset['id']??''));
    if(!is_array($binding)||$client_id!==absint($binding['client_id']??0)||$user_id!==absint($binding['owner_id']??0)||self::CLIENT_FILE_ROLE!==sanitize_key((string)($binding['role']??''))||'profile'!==sanitize_key((string)($meta['context']??''))||'koopo_appointments'!==sanitize_key((string)($meta['origin']??''))||$client_id!==absint($meta['targetId']??0)||(string)$user_id!==(string)($asset['ownerId']??'')||'ready'!==sanitize_key((string)($asset['status']??''))||!preg_match('/^[a-f0-9-]{36}$/',$asset_id))return new \WP_Error('koopo_appt_client_file_mismatch',__('This upload does not match the private client record.','koopo-appointments'),['status'=>409]);
    global $wpdb;$client=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::clients_table().' WHERE id=%d',$client_id));if(!$client||!Resources::can_manage((int)$client->resource_id))return new \WP_Error('koopo_appt_media_forbidden',__('You cannot update that private client record.','koopo-appointments'),['status'=>403]);
    $wpdb->insert(DB::client_files_table(),['resource_id'=>(int)$client->resource_id,'client_id'=>$client_id,'booking_id'=>null,'owner_user_id'=>$user_id,'asset_id'=>$asset_id,'filename'=>sanitize_file_name((string)($binding['filename']??'client-file')),'mime_type'=>sanitize_mime_type((string)($binding['mime']??'')),'size_bytes'=>absint($binding['size']??0)]);$file_id=(int)$wpdb->insert_id;if(!$file_id)return new \WP_Error('koopo_appt_client_file_save_failed',__('The private file could not be recorded.','koopo-appointments'),['status'=>500]);
    $reference=$this->coordinator->add_reference($user_id,$asset_id,self::CLIENT_FILE_ROLE,$file_id);if(is_wp_error($reference)){$wpdb->delete(DB::client_files_table(),['id'=>$file_id]);return $reference;}
    delete_transient($this->session_binding_key((string)$request['id']));return new \WP_REST_Response(['clientFile'=>['id'=>$file_id,'client_id'=>$client_id,'filename'=>sanitize_file_name((string)$binding['filename']),'mime_type'=>sanitize_mime_type((string)$binding['mime']),'size_bytes'=>absint($binding['size'])]],(int)($result['status']??200));
  }

  public function delete_client_file(\WP_REST_Request $request){global $wpdb;$file=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::client_files_table().' WHERE id=%d AND client_id=%d',absint($request['file_id']),absint($request['client_id'])));if(!$file||!Resources::can_manage((int)$file->resource_id))return new \WP_Error('koopo_appt_client_file_not_found',__('That private file was not found.','koopo-appointments'),['status'=>404]);$released=$this->coordinator->release_reference((int)$file->owner_user_id,(string)$file->asset_id,self::CLIENT_FILE_ROLE,(int)$file->id);if(is_wp_error($released))return $released;$wpdb->delete(DB::client_files_table(),['id'=>(int)$file->id]);return new \WP_REST_Response(null,204);}

  public function download_client_file():void{if(!is_user_logged_in())wp_die(__('Authentication is required.','koopo-appointments'),403);$file_id=absint($_GET['file_id']??0);check_admin_referer('koopo_appt_client_file_'.$file_id);global $wpdb;$file=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::client_files_table().' WHERE id=%d',$file_id));if(!$file||!Resources::can_manage((int)$file->resource_id))wp_die(__('You cannot access that private client file.','koopo-appointments'),403);if(!class_exists('Koopo_Media_Gateway_Plugin'))wp_die(__('Media Gateway is unavailable.','koopo-appointments'),503);$tmp=wp_tempnam((string)$file->filename);$download=\Koopo_Media_Gateway_Plugin::instance()->client()->download_private_asset((string)$file->asset_id,(int)$file->owner_user_id,$tmp,(int)apply_filters('koopo_appt_client_file_max_bytes',10*MB_IN_BYTES));if(is_wp_error($download)){@unlink($tmp);wp_die(esc_html($download->get_error_message()),502);}nocache_headers();header('Content-Type: '.sanitize_mime_type((string)$file->mime_type));header('Content-Disposition: attachment; filename="'.rawurlencode((string)$file->filename).'"');header('Content-Length: '.filesize($tmp));readfile($tmp);@unlink($tmp);exit;}

  private function session_binding_key(string $session_id): string { return 'koopo_appt_media_session_' . md5($session_id); }
}
