<?php
namespace Koopo_Appointments;

defined('ABSPATH') || exit;

/** Provider-private client history and service-specific intake/consent forms. */
final class Client_Records {
  const REQUEST_HOOK = 'koopo_appt_send_intake_request';

  public static function init(): void {
    add_action('rest_api_init', [__CLASS__, 'routes']);
    add_action('koopo_booking_confirmed_safe', [__CLASS__, 'capture_booking'], 40, 2);
    add_action(self::REQUEST_HOOK, [__CLASS__, 'send_form_request'], 10, 2);
  }

  public static function routes(): void {
    register_rest_route('koopo/v1', '/vendor/clients', ['methods'=>'GET','callback'=>[__CLASS__,'list_clients'],'permission_callback'=>static fn():bool=>is_user_logged_in()]);
    register_rest_route('koopo/v1', '/vendor/clients/(?P<id>\d+)', [
      ['methods'=>'GET','callback'=>[__CLASS__,'get_client'],'permission_callback'=>static fn():bool=>is_user_logged_in()],
      ['methods'=>'PUT,POST','callback'=>[__CLASS__,'update_client'],'permission_callback'=>static fn():bool=>is_user_logged_in()],
    ]);
    register_rest_route('koopo/v1', '/vendor/client-forms', [
      ['methods'=>'GET','callback'=>[__CLASS__,'list_forms'],'permission_callback'=>static fn():bool=>is_user_logged_in()],
      ['methods'=>'POST','callback'=>[__CLASS__,'save_form'],'permission_callback'=>static fn():bool=>is_user_logged_in()],
    ]);
    register_rest_route('koopo/v1', '/vendor/client-forms/(?P<id>\d+)', [
      ['methods'=>'PUT,POST','callback'=>[__CLASS__,'save_form'],'permission_callback'=>static fn():bool=>is_user_logged_in()],
      ['methods'=>'DELETE','callback'=>[__CLASS__,'delete_form'],'permission_callback'=>static fn():bool=>is_user_logged_in()],
    ]);
    register_rest_route('koopo/v1', '/customer/bookings/(?P<booking_id>\d+)/forms', ['methods'=>'GET','callback'=>[__CLASS__,'booking_forms'],'permission_callback'=>static fn():bool=>is_user_logged_in()]);
    register_rest_route('koopo/v1', '/customer/bookings/(?P<booking_id>\d+)/forms/(?P<form_id>\d+)', ['methods'=>'POST','callback'=>[__CLASS__,'submit_form'],'permission_callback'=>static fn():bool=>is_user_logged_in()]);
  }

  public static function capture_booking(int $booking_id, $booking): void {
    $booking = $booking ?: Bookings::get_booking($booking_id);
    if (!$booking) return;
    $resource_id = Resources::booking_resource_id($booking);
    $resource = Resources::get($resource_id);
    if (!$resource) return;
    $owner = (int) $resource->owner_user_id;
    $name = (string) Bookings::extra_from_record($booking, 'customer_name', '');
    $email = (string) Bookings::extra_from_record($booking, 'customer_email', '');
    $phone = (string) Bookings::extra_from_record($booking, 'customer_phone', '');
    global $wpdb;
    $client = null;
    if ((int) $booking->customer_id) $client = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::clients_table().' WHERE resource_id=%d AND wp_user_id=%d LIMIT 1', $resource_id, (int) $booking->customer_id));
    if (!$client && $email) $client = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::clients_table().' WHERE resource_id=%d AND email=%s LIMIT 1', $resource_id, $email));
    $data = ['owner_user_id'=>$owner,'wp_user_id'=>(int)$booking->customer_id?:null,'name'=>sanitize_text_field($name),'email'=>sanitize_email($email),'phone'=>sanitize_text_field($phone)];
    if ($client) { $wpdb->update(DB::clients_table(), $data, ['id'=>(int)$client->id]); $client_id=(int)$client->id; }
    else { $data['resource_id']=$resource_id; $wpdb->insert(DB::clients_table(),$data); $client_id=(int)$wpdb->insert_id; }
    if (!$client_id) return;
    $forms = self::required_forms($resource_id, (int)$booking->service_id);
    foreach ($forms as $form) {
      $wpdb->query($wpdb->prepare('INSERT IGNORE INTO '.DB::form_submissions_table().' (form_id,booking_id,client_id,customer_id,status,requested_at,answers_json) VALUES (%d,%d,%d,%d,"pending",UTC_TIMESTAMP(),"{}")',(int)$form->id,$booking_id,$client_id,(int)$booking->customer_id));
      $send_at = strtotime((string)$booking->start_datetime.' UTC') - ((int)$form->send_hours_before * HOUR_IN_SECONDS);
      if ($send_at <= time()) $send_at = time() + 60;
      if (!wp_next_scheduled(self::REQUEST_HOOK, [$booking_id,(int)$form->id])) wp_schedule_single_event($send_at, self::REQUEST_HOOK, [$booking_id,(int)$form->id]);
    }
  }

  public static function list_clients(\WP_REST_Request $request) {
    $resource_id=absint($request->get_param('resource_id')); if(!Resources::can_manage($resource_id))return self::forbidden();
    global $wpdb;
    $rows=$wpdb->get_results($wpdb->prepare('SELECT c.*,COUNT(b.id) appointment_count,MAX(b.start_datetime) last_appointment FROM '.DB::clients_table().' c LEFT JOIN '.DB::table().' b ON b.resource_id=c.resource_id AND (b.customer_id=c.wp_user_id OR (c.wp_user_id IS NULL AND b.customer_email=c.email)) WHERE c.resource_id=%d GROUP BY c.id ORDER BY COALESCE(MAX(b.start_datetime),c.created_at) DESC LIMIT 500',$resource_id))?:[];
    return new \WP_REST_Response(array_map([__CLASS__,'format_client'],$rows),200);
  }

  private static function owned_client(int $id) {
    global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::clients_table().' WHERE id=%d',$id));
    return $row&&Resources::can_manage((int)$row->resource_id)?$row:null;
  }

  public static function get_client(\WP_REST_Request $request) {
    $client=self::owned_client(absint($request['id'])); if(!$client)return self::forbidden();
    global $wpdb;
    $timeline=$wpdb->get_results($wpdb->prepare('SELECT b.id,b.service_id,b.start_datetime,b.end_datetime,b.timezone,b.status,b.price,b.customer_notes,p.post_title service_title FROM '.DB::table().' b LEFT JOIN '.$wpdb->posts.' p ON p.ID=b.service_id WHERE b.resource_id=%d AND (b.customer_id=%d OR (b.customer_id=0 AND b.customer_email=%s)) ORDER BY b.start_datetime DESC LIMIT 200',(int)$client->resource_id,(int)$client->wp_user_id,(string)$client->email),ARRAY_A)?:[];
    $submissions=$wpdb->get_results($wpdb->prepare('SELECT s.*,f.title FROM '.DB::form_submissions_table().' s INNER JOIN '.DB::client_forms_table().' f ON f.id=s.form_id WHERE s.client_id=%d ORDER BY s.created_at DESC',(int)$client->id),ARRAY_A)?:[];
    $files=$wpdb->get_results($wpdb->prepare('SELECT id,booking_id,filename,mime_type,size_bytes,created_at FROM '.DB::client_files_table().' WHERE client_id=%d ORDER BY created_at DESC',(int)$client->id),ARRAY_A)?:[];
    foreach($files as &$file)$file['download_url']=wp_nonce_url(admin_url('admin-post.php?action=koopo_appt_client_file&file_id='.(int)$file['id']),'koopo_appt_client_file_'.(int)$file['id']);unset($file);
    $data=self::format_client($client);$data['timeline']=$timeline;$data['submissions']=$submissions;$data['files']=$files;
    return new \WP_REST_Response($data,200);
  }

  public static function update_client(\WP_REST_Request $request) {
    $client=self::owned_client(absint($request['id']));if(!$client)return self::forbidden();$p=(array)$request->get_json_params();$data=[];
    foreach(['name','phone'] as $key)if(array_key_exists($key,$p))$data[$key]=sanitize_text_field((string)$p[$key]);
    if(array_key_exists('email',$p))$data['email']=sanitize_email((string)$p['email']);
    if(array_key_exists('birthday',$p))$data['birthday']=preg_match('/^\d{4}-\d{2}-\d{2}$/',(string)$p['birthday'])?$p['birthday']:null;
    foreach(['preferences','formulas','private_notes'] as $key)if(array_key_exists($key,$p))$data[$key]=sanitize_textarea_field((string)$p[$key]);
    global $wpdb;if($data)$wpdb->update(DB::clients_table(),$data,['id'=>(int)$client->id]);return self::get_client($request);
  }

  public static function list_forms(\WP_REST_Request $request) {
    $resource_id=absint($request->get_param('resource_id'));if(!Resources::can_manage($resource_id))return self::forbidden();global $wpdb;
    $rows=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::client_forms_table().' WHERE resource_id=%d ORDER BY enabled DESC,title',$resource_id))?:[];return new \WP_REST_Response(array_map([__CLASS__,'format_form'],$rows),200);
  }

  public static function save_form(\WP_REST_Request $request) {
    $p=(array)$request->get_json_params();$id=absint($request['id']??0);global $wpdb;$existing=$id?$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::client_forms_table().' WHERE id=%d',$id)):null;$resource_id=$existing?(int)$existing->resource_id:absint($p['resource_id']??0);if(!Resources::can_manage($resource_id))return self::forbidden();
    $fields=[];foreach((array)($p['fields']??[])as $field){$type=sanitize_key((string)($field['type']??'text'));if(!in_array($type,['text','textarea','checkbox','select','date'],true))continue;$label=sanitize_text_field((string)($field['label']??''));if(!$label)continue;$fields[]=['id'=>sanitize_key((string)($field['id']??'field_'.count($fields))),'label'=>$label,'type'=>$type,'required'=>!empty($field['required']),'options'=>array_values(array_filter(array_map('sanitize_text_field',(array)($field['options']??[]))))];}
    if(!$fields)return new \WP_Error('invalid_form','Add at least one intake question.',['status'=>422]);$data=['resource_id'=>$resource_id,'service_id'=>absint($p['service_id']??0)?:null,'title'=>sanitize_text_field((string)($p['title']??'')),'description'=>sanitize_textarea_field((string)($p['description']??'')),'fields_json'=>wp_json_encode($fields),'requires_signature'=>!empty($p['requires_signature'])?1:0,'send_hours_before'=>min(720,max(0,absint($p['send_hours_before']??24))),'enabled'=>isset($p['enabled'])?!empty($p['enabled']):1];if(!$data['title'])return new \WP_Error('invalid_form','Enter a form title.',['status'=>422]);
    if($existing)$wpdb->update(DB::client_forms_table(),$data,['id'=>$id]);else{$wpdb->insert(DB::client_forms_table(),$data);$id=(int)$wpdb->insert_id;}return new \WP_REST_Response(self::format_form($wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::client_forms_table().' WHERE id=%d',$id))),$existing?200:201);
  }

  public static function delete_form(\WP_REST_Request $request){global $wpdb;$form=$wpdb->get_row($wpdb->prepare('SELECT * FROM '.DB::client_forms_table().' WHERE id=%d',absint($request['id'])));if(!$form||!Resources::can_manage((int)$form->resource_id))return self::forbidden();$wpdb->update(DB::client_forms_table(),['enabled'=>0],['id'=>(int)$form->id]);return new \WP_REST_Response(null,204);}

  public static function booking_forms(\WP_REST_Request $request){$booking=Bookings::get_booking(absint($request['booking_id']));if(!$booking||(int)$booking->customer_id!==get_current_user_id())return self::forbidden();global $wpdb;$rows=$wpdb->get_results($wpdb->prepare('SELECT f.*,s.status submission_status,s.completed_at FROM '.DB::client_forms_table().' f INNER JOIN '.DB::form_submissions_table().' s ON s.form_id=f.id WHERE s.booking_id=%d AND s.customer_id=%d ORDER BY f.title',(int)$booking->id,get_current_user_id()))?:[];return new \WP_REST_Response(array_map([__CLASS__,'format_form'],$rows),200);}

  public static function submit_form(\WP_REST_Request $request){$booking=Bookings::get_booking(absint($request['booking_id']));if(!$booking||(int)$booking->customer_id!==get_current_user_id())return self::forbidden();global $wpdb;$form=$wpdb->get_row($wpdb->prepare('SELECT f.*,s.id submission_id FROM '.DB::client_forms_table().' f INNER JOIN '.DB::form_submissions_table().' s ON s.form_id=f.id WHERE f.id=%d AND s.booking_id=%d AND s.customer_id=%d',absint($request['form_id']),(int)$booking->id,get_current_user_id()));if(!$form)return new \WP_Error('form_not_found','That form is not required for this appointment.',['status'=>404]);$p=(array)$request->get_json_params();$answers=(array)($p['answers']??[]);foreach((array)json_decode((string)$form->fields_json,true)as $field)if(!empty($field['required'])&&trim((string)($answers[$field['id']]??''))==='')return new \WP_Error('required_answer',sprintf('Answer “%s”.',$field['label']),['status'=>422]);$signature=sanitize_text_field((string)($p['signature_name']??''));if((int)$form->requires_signature&&!$signature)return new \WP_Error('signature_required','Type your full legal name to sign.',['status'=>422]);$clean=[];foreach($answers as $key=>$value)$clean[sanitize_key((string)$key)]=is_array($value)?array_map('sanitize_text_field',$value):sanitize_textarea_field((string)$value);$now=current_time('mysql',true);$answers_json=wp_json_encode($clean);$signature_hash=$signature?hash_hmac('sha256',implode('|',[$signature,$booking->id,$form->id,$answers_json,$now]),wp_salt('auth')):'';$wpdb->update(DB::form_submissions_table(),['answers_json'=>$answers_json,'signature_name'=>$signature,'signature_hash'=>$signature_hash,'signed_at'=>$signature?$now:null,'status'=>'completed','completed_at'=>$now],['id'=>(int)$form->submission_id]);return new \WP_REST_Response(['completed'=>true,'completed_at'=>$now],200);}

  public static function send_form_request(int $booking_id,int $form_id):void{$booking=Bookings::get_booking($booking_id);if(!$booking||!in_array((string)$booking->status,['confirmed','pending_payment'],true))return;global $wpdb;$form=$wpdb->get_row($wpdb->prepare('SELECT f.*,s.status submission_status FROM '.DB::client_forms_table().' f INNER JOIN '.DB::form_submissions_table().' s ON s.form_id=f.id WHERE f.id=%d AND s.booking_id=%d',$form_id,$booking_id));if(!$form||$form->submission_status==='completed')return;$email=(string)Bookings::extra_from_record($booking,'customer_email','');if(!$email&&$booking->customer_id){$user=get_userdata((int)$booking->customer_id);$email=$user?(string)$user->user_email:'';}if(!$email)return;$url=class_exists(MyAccount::class)?MyAccount::appointments_url():home_url('/');wp_mail($email,sprintf(__('Please complete %s before your appointment','koopo-appointments'),$form->title),sprintf("Your provider requires this private form before your appointment.\n\n%s",$url));}

  private static function required_forms(int $resource_id,int $service_id):array{global $wpdb;return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.DB::client_forms_table().' WHERE resource_id=%d AND enabled=1 AND (service_id IS NULL OR service_id=0 OR service_id=%d)',$resource_id,$service_id))?:[];}
  private static function format_client(object $r):array{return ['id'=>(int)$r->id,'resource_id'=>(int)$r->resource_id,'wp_user_id'=>(int)$r->wp_user_id,'name'=>(string)$r->name,'email'=>(string)$r->email,'phone'=>(string)$r->phone,'birthday'=>$r->birthday,'preferences'=>(string)$r->preferences,'formulas'=>(string)$r->formulas,'private_notes'=>(string)$r->private_notes,'appointment_count'=>(int)($r->appointment_count??0),'last_appointment'=>$r->last_appointment??null,'created_at'=>$r->created_at];}
  private static function format_form(object $r):array{return ['id'=>(int)$r->id,'resource_id'=>(int)$r->resource_id,'service_id'=>(int)$r->service_id,'title'=>(string)$r->title,'description'=>(string)$r->description,'fields'=>(array)json_decode((string)$r->fields_json,true),'requires_signature'=>(bool)$r->requires_signature,'send_hours_before'=>(int)$r->send_hours_before,'enabled'=>(bool)$r->enabled,'submission_status'=>(string)($r->submission_status??''),'completed_at'=>$r->completed_at??null];}
  private static function forbidden():\WP_Error{return new \WP_Error('forbidden','You cannot access that private client record.',['status'=>403]);}
}
