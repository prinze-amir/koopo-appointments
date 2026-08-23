<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Cancellation-fill waitlist with privacy-safe, expiring offers. */
final class Waitlist {
  const EXPIRE_HOOK = 'koopo_appt_waitlist_expire_offers';

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'routes']);
    add_action('koopo_booking_cancelled_safe', [__CLASS__, 'handle_opening'], 30, 3);
    add_action('koopo_booking_confirmed_safe', [__CLASS__, 'handle_customer_booked'], 30, 2);
    add_action(self::EXPIRE_HOOK, [__CLASS__, 'expire_offers']);
    if (!wp_next_scheduled(self::EXPIRE_HOOK)) wp_schedule_event(time() + 300, 'koopo_five_minutes', self::EXPIRE_HOOK);
  }

  public static function routes(): void {
    $id_arg=['type'=>'integer','minimum'=>1,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'];
    register_rest_route('koopo/v1', '/waitlist', [
      ['methods'=>'GET','callback'=>[__CLASS__,'customer_entries'],'permission_callback'=>static fn():bool=>is_user_logged_in()],
      ['methods'=>'POST','callback'=>[__CLASS__,'join'],'permission_callback'=>static fn():bool=>is_user_logged_in(),'args'=>self::join_args()],
    ]);
    register_rest_route('koopo/v1', '/waitlist/(?P<id>\d+)', [
      'methods'=>'DELETE','callback'=>[__CLASS__,'leave'],'permission_callback'=>static fn():bool=>is_user_logged_in(),'args'=>['id'=>$id_arg],
    ]);
    register_rest_route('koopo/v1', '/waitlist/offers/(?P<token>[A-Za-z0-9_-]{32,128})/accept', [
      'methods'=>'POST','callback'=>[__CLASS__,'accept_offer'],'permission_callback'=>static fn():bool=>is_user_logged_in(),'args'=>['token'=>['type'=>'string','minLength'=>32,'maxLength'=>128,'pattern'=>'^[A-Za-z0-9_-]+$','validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_text_field']],
    ]);
    register_rest_route('koopo/v1', '/vendor/waitlist', [
      'methods'=>'GET','callback'=>[__CLASS__,'vendor_entries'],'permission_callback'=>static fn():bool=>is_user_logged_in(),'args'=>['resource_id'=>array_merge($id_arg,['required'=>true])],
    ]);
    register_rest_route('koopo/v1', '/vendor/waitlist/(?P<id>\d+)/offer', [
      'methods'=>'POST','callback'=>[__CLASS__,'manual_offer'],'permission_callback'=>static fn():bool=>is_user_logged_in(),'args'=>[
        'id'=>$id_arg,
        'opening_booking_id'=>$id_arg,
        'start_datetime'=>['type'=>'string','required'=>true,'pattern'=>'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$','validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_text_field'],
        'end_datetime'=>['type'=>'string','required'=>true,'pattern'=>'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$','validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_text_field'],
        'timezone'=>['type'=>'string','maxLength'=>100,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_text_field'],
      ],
    ]);
    register_rest_route('koopo/v1', '/vendor/waitlist/(?P<id>\d+)', [
      'methods'=>'PATCH,PUT,POST','callback'=>[__CLASS__,'update_entry'],'permission_callback'=>static fn():bool=>is_user_logged_in(),'args'=>[
        'id'=>$id_arg,
        'priority'=>['type'=>'integer','minimum'=>1,'maximum'=>1000,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'],
        'provider_note'=>['type'=>'string','maxLength'=>5000,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_textarea_field'],
        'status'=>['type'=>'string','enum'=>['active','paused'],'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_key'],
      ],
    ]);
    register_rest_route('koopo/v1', '/vendor/waitlist/settings/(?P<resource_id>\d+)', [
      ['methods'=>'GET','callback'=>[__CLASS__,'get_settings'],'permission_callback'=>static fn():bool=>is_user_logged_in(),'args'=>['resource_id'=>$id_arg]],
      ['methods'=>'PUT,POST','callback'=>[__CLASS__,'save_settings'],'permission_callback'=>static fn():bool=>is_user_logged_in(),'args'=>[
        'resource_id'=>$id_arg,
        'mode'=>['type'=>'string','enum'=>['sequential','first_to_confirm','manual'],'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_key'],
        'offer_minutes'=>['type'=>'integer','minimum'=>5,'maximum'=>120,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'],
        'batch_size'=>['type'=>'integer','minimum'=>1,'maximum'=>20,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'],
      ]],
    ]);
  }

  private static function join_args(): array {
    return [
      'service_id'=>['type'=>'integer','required'=>true,'minimum'=>1,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'absint'],
      'fulfillment_mode'=>['type'=>'string','enum'=>['at_location','mobile','virtual'],'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_key'],
      'service_address'=>['type'=>'object','maxProperties'=>6,'properties'=>[
        'address_1'=>['type'=>'string','maxLength'=>255],
        'address_2'=>['type'=>'string','maxLength'=>255],
        'city'=>['type'=>'string','maxLength'=>120],
        'region'=>['type'=>'string','maxLength'=>120],
        'postal_code'=>['type'=>'string','maxLength'=>32],
        'country'=>['type'=>'string','maxLength'=>120],
      ],'validate_callback'=>'rest_validate_request_arg'],
      'preferred_days'=>['type'=>'array','maxItems'=>7,'items'=>['type'=>'string','enum'=>['sun','mon','tue','wed','thu','fri','sat']],'validate_callback'=>'rest_validate_request_arg'],
      'date_from'=>['type'=>'string','pattern'=>'^\d{4}-\d{2}-\d{2}$','validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_text_field'],
      'date_to'=>['type'=>'string','pattern'=>'^\d{4}-\d{2}-\d{2}$','validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_text_field'],
      'earliest_time'=>['type'=>'string','pattern'=>'^\d{2}:\d{2}(?::\d{2})?$','validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_text_field'],
      'latest_time'=>['type'=>'string','pattern'=>'^\d{2}:\d{2}(?::\d{2})?$','validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_text_field'],
      'channels'=>['type'=>'array','maxItems'=>2,'items'=>['type'=>'string','enum'=>['email','push']],'validate_callback'=>'rest_validate_request_arg'],
      'name'=>['type'=>'string','maxLength'=>191,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_text_field'],
      'email'=>['type'=>'string','maxLength'=>191,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_email'],
      'phone'=>['type'=>'string','maxLength'=>64,'validate_callback'=>'rest_validate_request_arg','sanitize_callback'=>'sanitize_text_field'],
    ];
  }

  public static function join(\WP_REST_Request $request) {
    $payload = (array) $request->get_json_params();
    $service_id = absint($payload['service_id'] ?? 0);
    $resource = $service_id ? Resources::for_service($service_id) : null;
    if (!$resource || (string) $resource->status !== 'active') return new \WP_Error('invalid_service','Select an active service.',['status'=>422]);
    $customer_id = get_current_user_id();
    $user = get_userdata($customer_id);
    $fulfillment_mode = sanitize_key((string) ($payload['fulfillment_mode'] ?? 'at_location'));
    $service_address = Service_Areas::sanitize_address((array) ($payload['service_address'] ?? []));
    if ((string) $resource->subject_type === 'listing') {
      $fulfillment_mode = 'at_location';
    } else {
      $provider_id = (int) $resource->subject_id;
      $modes = (array) get_post_meta($provider_id, Provider_Profiles::META_SERVICE_MODES, true);
      if (!in_array($fulfillment_mode, $modes, true)) return new \WP_Error('invalid_fulfillment_mode','Choose an available appointment type.',['status'=>422]);
      if ($fulfillment_mode === 'mobile') {
        $coverage = Service_Areas::check_destination($provider_id, $service_address);
        if (is_wp_error($coverage)) return $coverage;
        if (empty($coverage['eligible'])) return new \WP_Error('outside_service_area','That address is outside the provider’s mobile service area.',['status'=>422]);
      }
    }
    $days = array_values(array_intersect(['sun','mon','tue','wed','thu','fri','sat'], array_map('sanitize_key',(array)($payload['preferred_days']??[]))));
    $date_from = self::date((string)($payload['date_from']??''));
    $date_to = self::date((string)($payload['date_to']??''));
    if ($date_from && $date_to && $date_to < $date_from) return new \WP_Error('invalid_dates','The end date must be after the start date.',['status'=>422]);
    global $wpdb;
    $existing = (int) $wpdb->get_var($wpdb->prepare(
      'SELECT id FROM '.DB::waitlist_table().' WHERE resource_id=%d AND service_id=%d AND customer_id=%d AND status IN ("active","offered") LIMIT 1',
      (int) $resource->id,
      $service_id,
      $customer_id
    ));
    if ($existing) return new \WP_Error('already_waitlisted','You are already waiting for this service.',['status'=>409,'waitlist_id'=>$existing]);
    $channels = self::channels((array)($payload['channels']??['email','push']));
    if (!$channels) $channels = ['email'];
    $wpdb->insert(DB::waitlist_table(), [
      'resource_id'=>(int)$resource->id,
      'listing_id'=>(string)$resource->subject_type==='listing'?(int)$resource->subject_id:null,
      'provider_id'=>(string)$resource->subject_type==='provider'?(int)$resource->subject_id:null,
      'service_id'=>$service_id,
      'customer_id'=>$customer_id,
      'customer_name'=>sanitize_text_field((string)($payload['name']??$user->display_name??'')),
      'customer_email'=>sanitize_email((string)($payload['email']??$user->user_email??'')),
      'customer_phone'=>sanitize_text_field((string)($payload['phone']??get_user_meta($customer_id,'billing_phone',true))),
      'fulfillment_mode'=>$fulfillment_mode,
      'service_address_1'=>$fulfillment_mode==='mobile'?$service_address['address_1']:'',
      'service_address_2'=>$fulfillment_mode==='mobile'?$service_address['address_2']:'',
      'service_city'=>$fulfillment_mode==='mobile'?$service_address['city']:'',
      'service_region'=>$fulfillment_mode==='mobile'?$service_address['region']:'',
      'service_postal_code'=>$fulfillment_mode==='mobile'?$service_address['postal_code']:'',
      'service_country'=>$fulfillment_mode==='mobile'?$service_address['country']:'',
      'date_from'=>$date_from?:null,
      'date_to'=>$date_to?:null,
      'preferred_days'=>implode(',',$days),
      'earliest_time'=>self::time((string)($payload['earliest_time']??''))?:null,
      'latest_time'=>self::time((string)($payload['latest_time']??''))?:null,
      'channels'=>implode(',',$channels),
      'priority'=>100,
      'status'=>'active',
    ]);
    if (!$wpdb->insert_id) return new \WP_Error('waitlist_failed','The waitlist request could not be saved.',['status'=>500]);
    return new \WP_REST_Response(['id'=>(int)$wpdb->insert_id,'status'=>'active'],201);
  }

  public static function customer_entries(): \WP_REST_Response {
    global $wpdb;
    $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::waitlist_table().' WHERE customer_id=%d ORDER BY created_at DESC LIMIT 100',get_current_user_id()))?:[];
    return new \WP_REST_Response(array_map([__CLASS__,'format_entry'],$rows),200);
  }

  public static function leave(\WP_REST_Request $request) {
    global $wpdb;
    $id = absint($request['id']);
    $entry = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::waitlist_table().' WHERE id=%d', $id));
    if (!$entry || (int) $entry->customer_id !== get_current_user_id()) return new \WP_Error('waitlist_not_found','That waitlist request was not found.',['status'=>404]);
    if (!in_array((string) $entry->status, ['active','offered','paused'], true)) return new \WP_Error('waitlist_closed','That waitlist request is already closed.',['status'=>409]);
    $wpdb->update(DB::waitlist_table(), ['status'=>'cancelled'], ['id'=>$id]);
    $wpdb->query($wpdb->prepare('UPDATE '.DB::waitlist_offers_table().' SET status="expired" WHERE waitlist_id=%d AND status="offered"', $id));
    return new \WP_REST_Response(null, 204);
  }

  public static function vendor_entries(\WP_REST_Request $request) {
    $resource_id=absint($request->get_param('resource_id'));
    if(!Resources::can_manage($resource_id))return new \WP_Error('forbidden','You cannot manage this waitlist.',['status'=>403]);
    global $wpdb;
    $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::waitlist_table().' WHERE resource_id=%d ORDER BY FIELD(status,"offered","active","matched","paused","expired"),priority ASC,created_at ASC LIMIT 250',$resource_id))?:[];
    return new \WP_REST_Response(array_map([__CLASS__,'format_entry'],$rows),200);
  }

  public static function update_entry(\WP_REST_Request $request) {
    global $wpdb;$entry=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::waitlist_table().' WHERE id=%d',absint($request['id'])));if(!$entry||!Resources::can_manage((int)$entry->resource_id))return new \WP_Error('forbidden','You cannot update this waitlist request.',['status'=>403]);$p=(array)$request->get_json_params();$data=[];
    if(array_key_exists('priority',$p))$data['priority']=min(1000,max(1,absint($p['priority'])));
    if(array_key_exists('provider_note',$p))$data['provider_note']=sanitize_textarea_field((string)$p['provider_note']);
    if(array_key_exists('status',$p)){ $status=sanitize_key((string)$p['status']);if(in_array($status,['active','paused'],true))$data['status']=$status; }
    if($data)$wpdb->update(DB::waitlist_table(),$data,['id'=>(int)$entry->id]);$fresh=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::waitlist_table().' WHERE id=%d',(int)$entry->id));return new \WP_REST_Response(self::format_entry($fresh),200);
  }

  public static function get_settings(\WP_REST_Request $request) {
    $resource_id=absint($request['resource_id']);if(!Resources::can_manage($resource_id))return new \WP_Error('forbidden','You cannot manage this waitlist.',['status'=>403]);
    return new \WP_REST_Response(self::settings($resource_id),200);
  }

  public static function save_settings(\WP_REST_Request $request) {
    $resource_id=absint($request['resource_id']);if(!Resources::can_manage($resource_id))return new \WP_Error('forbidden','You cannot manage this waitlist.',['status'=>403]);
    $payload=(array)$request->get_json_params();$post_id=Resources::settings_post_id($resource_id);
    $mode=sanitize_key((string)($payload['mode']??'sequential'));if(!in_array($mode,['sequential','first_to_confirm','manual'],true))$mode='sequential';
    update_post_meta($post_id,'_koopo_waitlist_mode',$mode);update_post_meta($post_id,'_koopo_waitlist_offer_minutes',min(120,max(5,absint($payload['offer_minutes']??15))));update_post_meta($post_id,'_koopo_waitlist_batch_size',min(20,max(1,absint($payload['batch_size']??5))));
    return new \WP_REST_Response(self::settings($resource_id),200);
  }

  private static function settings(int $resource_id):array{
    $post_id=Resources::settings_post_id($resource_id);$mode=(string)get_post_meta($post_id,'_koopo_waitlist_mode',true);
    return ['mode'=>in_array($mode,['sequential','first_to_confirm','manual'],true)?$mode:'sequential','offer_minutes'=>min(120,max(5,(int)(get_post_meta($post_id,'_koopo_waitlist_offer_minutes',true)?:15))),'batch_size'=>min(20,max(1,(int)(get_post_meta($post_id,'_koopo_waitlist_batch_size',true)?:5)))];
  }

  public static function handle_opening(int $booking_id,string $status,$booking):void{
    if($status!=='cancelled'||!$booking||strtotime((string)$booking->start_datetime)<=time())return;
    $resource_id=Resources::booking_resource_id($booking);if(!$resource_id)return;
    $settings=self::settings($resource_id);if($settings['mode']==='manual')return;
    self::offer_matching($booking,$settings);
  }

  private static function offer_matching(object $opening,array $settings):int{
    $matches=self::matching_entries($opening,$settings['mode']==='first_to_confirm'?$settings['batch_size']:1);$count=0;
    foreach($matches as $entry){if(self::create_offer($entry,$opening,$settings['offer_minutes']))$count++;}
    return $count;
  }

  private static function matching_entries(object $opening,int $limit):array{
    global $wpdb;$resource_id=Resources::booking_resource_id($opening);$date=substr((string)$opening->start_datetime,0,10);$time=substr((string)$opening->start_datetime,11,8);$day=strtolower(substr(date('D',strtotime($date)),0,3));
    $sql=$wpdb->prepare('SELECT w.* FROM '.DB::waitlist_table().' w WHERE w.resource_id=%d AND w.service_id=%d AND w.status="active" AND (w.date_from IS NULL OR w.date_from<=%s) AND (w.date_to IS NULL OR w.date_to>=%s) AND (w.preferred_days="" OR FIND_IN_SET(%s,w.preferred_days)) AND (w.earliest_time IS NULL OR w.earliest_time<=%s) AND (w.latest_time IS NULL OR w.latest_time>=%s) AND NOT EXISTS (SELECT 1 FROM '.DB::waitlist_offers_table().' o WHERE o.waitlist_id=w.id AND o.opening_booking_id=%d) ORDER BY w.priority ASC,w.created_at ASC LIMIT %d',$resource_id,(int)$opening->service_id,$date,$date,$day,$time,$time,(int)$opening->id,$limit);
    return $wpdb->get_results($sql)?:[];
  }

  private static function create_offer(object $entry,object $opening,int $minutes):bool{
    global $wpdb;
    $claimed=$wpdb->query($wpdb->prepare('UPDATE '.DB::waitlist_table().' SET status="reserving" WHERE id=%d AND status="active"',(int)$entry->id));if($claimed!==1)return false;
    $token=rtrim(strtr(base64_encode(random_bytes(48)),'+/','-_'),'=');
    $inserted=$wpdb->insert(DB::waitlist_offers_table(),['waitlist_id'=>(int)$entry->id,'opening_booking_id'=>(int)$opening->id,'resource_id'=>(int)$entry->resource_id,'service_id'=>(int)$entry->service_id,'customer_id'=>(int)$entry->customer_id,'starts_at'=>(string)$opening->start_datetime,'ends_at'=>(string)$opening->end_datetime,'timezone'=>(string)($opening->timezone?:'UTC'),'token_hash'=>hash('sha256',$token),'token_encrypted'=>Calendar_Crypto::encrypt(['token'=>$token]),'status'=>'offered','expires_at'=>gmdate('Y-m-d H:i:s',time()+$minutes*60)]);
    if(!$inserted){$wpdb->update(DB::waitlist_table(),['status'=>'active'],['id'=>(int)$entry->id,'status'=>'reserving']);return false;}$offer_id=(int)$wpdb->insert_id;$wpdb->update(DB::waitlist_table(),['status'=>'offered','last_notified_at'=>current_time('mysql',true)],['id'=>(int)$entry->id]);self::notify($offer_id,$entry,$opening,$token,$minutes);return true;
  }

  public static function manual_offer(\WP_REST_Request $request){
    global $wpdb;$entry=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::waitlist_table().' WHERE id=%d',absint($request['id'])));if(!$entry||!Resources::can_manage((int)$entry->resource_id))return new \WP_Error('forbidden','You cannot offer this opening.',['status'=>403]);
    $payload=(array)$request->get_json_params();$start=sanitize_text_field((string)($payload['start_datetime']??''));$end=sanitize_text_field((string)($payload['end_datetime']??''));if(!$start||!$end||strtotime($end)<=strtotime($start))return new \WP_Error('invalid_time','Choose a valid opening.',['status'=>422]);
    $opening=(object)['id'=>absint($payload['opening_booking_id']??0),'resource_id'=>(int)$entry->resource_id,'service_id'=>(int)$entry->service_id,'start_datetime'=>$start,'end_datetime'=>$end,'timezone'=>sanitize_text_field((string)($payload['timezone']??'UTC'))];
    return self::create_offer($entry,$opening,self::settings((int)$entry->resource_id)['offer_minutes'])?new \WP_REST_Response(['offered'=>true],201):new \WP_Error('offer_failed','The offer could not be created.',['status'=>409]);
  }

  public static function accept_offer(\WP_REST_Request $request){
    global $wpdb;$token=(string)$request['token'];$offer=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::waitlist_offers_table().' WHERE token_hash=%s',hash('sha256',$token)));if(!$offer||(int)$offer->customer_id!==get_current_user_id())return new \WP_Error('offer_not_found','This opening offer is unavailable.',['status'=>404]);
    if((string)$offer->status!=='offered'||strtotime((string)$offer->expires_at.' UTC')<=time())return new \WP_Error('offer_expired','This opening offer has expired.',['status'=>409]);
    $claimed=$wpdb->query($wpdb->prepare('UPDATE '.DB::waitlist_offers_table().' SET status="accepting" WHERE id=%d AND status="offered" AND expires_at>UTC_TIMESTAMP()',(int)$offer->id));if($claimed!==1)return new \WP_Error('offer_claimed','This opening offer is already being accepted.',['status'=>409]);
    $entry=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::waitlist_table().' WHERE id=%d',(int)$offer->waitlist_id));if(!$entry)return new \WP_Error('waitlist_missing','The waitlist request is unavailable.',['status'=>404]);
    $resource=Resources::get((int)$offer->resource_id);$booking_request=new \WP_REST_Request('POST','/koopo/v1/bookings');$booking_request->set_body_params(['listing_id'=>$resource&&$resource->subject_type==='listing'?(int)$resource->subject_id:0,'provider_id'=>$resource&&$resource->subject_type==='provider'?(int)$resource->subject_id:0,'resource_id'=>(int)$offer->resource_id,'service_id'=>(int)$offer->service_id,'start_datetime'=>(string)$offer->starts_at,'end_datetime'=>(string)$offer->ends_at,'timezone'=>(string)$offer->timezone,'customer_name'=>(string)$entry->customer_name,'customer_email'=>(string)$entry->customer_email,'customer_phone'=>(string)$entry->customer_phone,'fulfillment_mode'=>(string)($entry->fulfillment_mode?:'at_location'),'service_address'=>['address_1'=>(string)$entry->service_address_1,'address_2'=>(string)$entry->service_address_2,'city'=>(string)$entry->service_city,'region'=>(string)$entry->service_region,'postal_code'=>(string)$entry->service_postal_code,'country'=>(string)$entry->service_country]]);
    $response=Bookings::create_booking($booking_request);$status=$response instanceof \WP_REST_Response?$response->get_status():500;$data=$response instanceof \WP_REST_Response?(array)$response->get_data():[];if($status>=300){$wpdb->update(DB::waitlist_offers_table(),['status'=>'expired'],['id'=>(int)$offer->id,'status'=>'accepting']);$wpdb->update(DB::waitlist_table(),['status'=>'active'],['id'=>(int)$entry->id]);return new \WP_Error('opening_taken',(string)($data['error']??'This opening was just taken.'),['status'=>409]);}
    $booking_id=absint($data['booking_id']??0);$wpdb->update(DB::waitlist_offers_table(),['status'=>'accepted','accepted_at'=>current_time('mysql',true),'booking_id'=>$booking_id],['id'=>(int)$offer->id,'status'=>'accepting']);
    if ((int) $offer->opening_booking_id > 0) {
      $wpdb->query($wpdb->prepare('UPDATE '.DB::waitlist_offers_table().' SET status="expired" WHERE opening_booking_id=%d AND id<>%d AND status="offered"',(int)$offer->opening_booking_id,(int)$offer->id));
    } else {
      $wpdb->query($wpdb->prepare('UPDATE '.DB::waitlist_offers_table().' SET status="expired" WHERE resource_id=%d AND starts_at=%s AND ends_at=%s AND id<>%d AND status="offered"',(int)$offer->resource_id,(string)$offer->starts_at,(string)$offer->ends_at,(int)$offer->id));
    }
    $wpdb->update(DB::waitlist_table(),['status'=>'matched'],['id'=>(int)$entry->id]);
    return new \WP_REST_Response(array_merge($data,['accepted'=>true]),200);
  }

  public static function expire_offers():void{
    global $wpdb;$expired=$wpdb->get_results('SELECT * FROM '.DB::waitlist_offers_table().' WHERE status IN ("offered","accepting") AND expires_at<=UTC_TIMESTAMP() ORDER BY opening_booking_id,id')?:[];$openings=[];
    foreach($expired as $offer){$wpdb->update(DB::waitlist_offers_table(),['status'=>'expired'],['id'=>(int)$offer->id]);$wpdb->update(DB::waitlist_table(),['status'=>'active'],['id'=>(int)$offer->waitlist_id]);if((int)$offer->opening_booking_id)$openings[(int)$offer->opening_booking_id]=(int)$offer->resource_id;}
    foreach($openings as $opening_id=>$resource_id){$remaining=(int)$wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM '.DB::waitlist_offers_table().' WHERE opening_booking_id=%d AND status="offered"',$opening_id));if($remaining)continue;$opening=Bookings::get_booking($opening_id);$settings=self::settings($resource_id);if($opening&&$settings['mode']!=='manual')self::offer_matching($opening,$settings);}
  }

  public static function handle_customer_booked(int $booking_id,$booking):void{
    if(!$booking)$booking=Bookings::get_booking($booking_id);if(!$booking)return;global $wpdb;$wpdb->query($wpdb->prepare('UPDATE '.DB::waitlist_table().' SET status="matched" WHERE resource_id=%d AND service_id=%d AND customer_id=%d AND status IN ("active","offered")',Resources::booking_resource_id($booking),(int)$booking->service_id,(int)$booking->customer_id));
  }

  private static function notify(int $offer_id,object $entry,object $opening,string $token,int $minutes):void{
    $subject_id=(int)($entry->listing_id?:$entry->provider_id);$url=add_query_arg('koopo_waitlist_offer',$token,get_permalink($subject_id));$when=Date_Formatter::format((string)$opening->start_datetime,(string)$opening->timezone,'full');$channels=array_filter(explode(',',(string)$entry->channels));$message=sprintf('An opening for %s is available on %s. This offer expires in %d minutes.',get_the_title((int)$entry->service_id),$when,$minutes);
    if(in_array('email',$channels,true)&&is_email($entry->customer_email))Notification_Delivery::send_email((int)$opening->id,'waitlist_offer_'.$offer_id,'customer',(string)$entry->customer_email,__('A Koopo appointment opening is available','koopo-appointments'),$message."\n\n".$url);
    if(in_array('push',$channels,true))self::send_inbox_offer($offer_id,$entry,$opening,$subject_id,$message,$url);
  }

  private static function send_inbox_offer(int $offer_id,object $entry,object $opening,int $subject_id,string $message,string $url):bool{
    $recipient_id=(int)$entry->customer_id;$resource=Resources::get((int)$entry->resource_id);$sender_id=$resource?(int)$resource->payee_user_id:0;
    if(!$recipient_id||!$sender_id||$sender_id===$recipient_id||!function_exists('messages_new_message'))return false;
    $delivery_id=Notification_Delivery::claim(['booking_id'=>(int)$opening->id,'recipient_user_id'=>$recipient_id,'event_name'=>'waitlist_offer_'.$offer_id,'channel'=>'inbox','recipient'=>(string)$recipient_id]);
    if(is_wp_error($delivery_id))return $delivery_id->get_error_code()==='delivery_already_handled';
    $sent=null;$allow_transactional_message=static fn()=>true;add_filter('bb_user_can_send_messages',$allow_transactional_message,PHP_INT_MAX,3);
    try{$sent=messages_new_message(['sender_id'=>$sender_id,'recipients'=>[$recipient_id],'subject'=>__('Appointment opening','koopo-appointments'),'content'=>$message."\n\n".$url,'error_type'=>'wp_error','mark_visible'=>true,'return'=>'object']);}
    finally{remove_filter('bb_user_can_send_messages',$allow_transactional_message,PHP_INT_MAX);}
    $message_id=!is_wp_error($sent)&&is_object($sent)?absint($sent->id??0):0;
    if(!$message_id){Notification_Delivery::fail((int)$delivery_id,'buddyboss_message_not_sent');return false;}
    if(function_exists('bp_messages_update_meta'))bp_messages_update_meta($message_id,'linked_entity',['type'=>'waitlist_offer','offerId'=>$offer_id,'bookingId'=>(int)$opening->id,'deepLink'=>'koopo://appointments/waitlist/'.$offer_id]);
    if(function_exists('bp_notifications_add_notification'))bp_notifications_add_notification(['user_id'=>$recipient_id,'item_id'=>$offer_id,'secondary_item_id'=>$subject_id,'component_name'=>'koopo_appointments','component_action'=>'waitlist_offer','date_notified'=>function_exists('bp_core_current_time')?bp_core_current_time():current_time('mysql',true),'is_new'=>1]);
    Notification_Delivery::complete((int)$delivery_id,(string)$message_id);do_action('koopo_appt_waitlist_message_sent',$offer_id,$message_id,$recipient_id);return true;
  }

  public static function offer_url(int $offer_id): string {
    global $wpdb;
    $offer = $wpdb->get_row($wpdb->prepare('SELECT o.token_encrypted,w.listing_id,w.provider_id FROM '.DB::waitlist_offers_table().' o INNER JOIN '.DB::waitlist_table().' w ON w.id=o.waitlist_id WHERE o.id=%d', $offer_id));
    if (!$offer) return home_url('/');
    $secret = Calendar_Crypto::decrypt((string) $offer->token_encrypted);
    $token = is_array($secret) ? sanitize_text_field((string) ($secret['token'] ?? '')) : '';
    $subject_id = (int) ($offer->listing_id ?: $offer->provider_id);
    return $token ? add_query_arg('koopo_waitlist_offer', $token, get_permalink($subject_id)) : get_permalink($subject_id);
  }

  private static function format_entry(object $row):array{return ['id'=>(int)$row->id,'resource_id'=>(int)$row->resource_id,'listing_id'=>(int)$row->listing_id,'provider_id'=>(int)$row->provider_id,'service_id'=>(int)$row->service_id,'service_title'=>get_the_title((int)$row->service_id),'customer_id'=>(int)$row->customer_id,'customer_name'=>(string)$row->customer_name,'customer_email'=>(string)$row->customer_email,'customer_phone'=>(string)$row->customer_phone,'fulfillment_mode'=>(string)($row->fulfillment_mode??'at_location'),'date_from'=>$row->date_from,'date_to'=>$row->date_to,'preferred_days'=>$row->preferred_days?explode(',',(string)$row->preferred_days):[],'earliest_time'=>$row->earliest_time,'latest_time'=>$row->latest_time,'channels'=>$row->channels?explode(',',(string)$row->channels):[],'priority'=>(int)$row->priority,'status'=>(string)$row->status,'provider_note'=>(string)$row->provider_note,'last_notified_at'=>$row->last_notified_at,'created_at'=>$row->created_at];}
  private static function date(string $value):string{return preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)?$value:'';}
  private static function time(string $value):string{if(!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/',$value,$m))return '';return ((int)$m[1]<24&&(int)$m[2]<60)?sprintf('%02d:%02d:00',(int)$m[1],(int)$m[2]):'';}
  private static function channels(array $channels):array{return array_values(array_intersect(['email','push'],array_map('sanitize_key',$channels)));}
}
