<?php
require_once '../../../wp-load.php';
require_once 'utilities.php';
// insert this request into debug payments table
if (get_option('ihc_debug_payments_db')){
	ihc_insert_debug_payment_log('payir', $_POST);
}
function common($url, $params)
{
	$ch = curl_init();

	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

	$response = curl_exec($ch);
	$error    = curl_errno($ch);

	curl_close($ch);

	$output = $error ? false : json_decode($response);

	return $output;
}

global $wpdb;
$data = $wpdb->get_results("SELECT * FROM " . $wpdb->prefix . "indeed_members_payments WHERE `u_id`='" . $_GET['uid'] . "';");
$is_duplicate_tid = 0;

foreach ($data as $k=>$v){
    $data_payment = json_decode($v->payment_data);
    if (isset($data_payment->transId)){
        if ($data_payment->transId == $_POST['transId']) {
            $is_duplicate_tid = 1;
            $message = 'شماره تراکنش تکراری است.';
            wp_die($message);
            exit;
        } else {
            $is_duplicate_tid = 0;
        }
    }
}
if ( isset($_POST['transId'] ) && $is_duplicate_tid == 0){

    if (ihc_get_level_by_id($_GET['lid'])){
        $level_data = ihc_get_level_by_id($_GET['lid']);
        if ($level_data['payment_type']=='free' || $level_data['price']=='')  header( 'location:'. get_home_url());
    } else {
        header( 'location:'. get_home_url() );
        exit();
    }

    $r_url = get_option('ihc_payir_return_page');

    if(!$r_url || $r_url==-1){
        $r_url = get_option('page_on_front');
    }

    $r_url = get_permalink($r_url);
    if (!$r_url){
        $r_url = get_home_url();
    }

    $api_key = get_option('ihc_payir_key');
    $currency = get_option('ihc_currency');

    $transId = $_POST['transId'];
    $order_id = $_POST['order_id'];

    $amount = $level_data['price'];


    if ($currency != 'IRR')  {
        $amount = $amount  * 10 ;
    }

    $debug = FALSE;
    $path = str_replace('payir_ipn.php', '', __FILE__);
    $log_file = $path . 'payir.log';

    $params = array (

        'api'     => $api_key,
        'transId' => $transId
    );

    $result = common('https://pay.ir/payment/verify', $params);

    if ($result && isset($result->status) && $result->status == 1) {

        $_POST['ihc_payment_type'] = 'payir';
        $_POST['amount'] = $amount ;
        $_POST['currency'] = $currency ;
        $_POST['level'] = $_GET['lid'];
        $_POST['description'] = $level_data['description'];

        if($amount == $result->amount){

            ihc_update_user_level_expire($level_data, $_GET['lid'], $_GET['uid']);
            ihc_send_user_notifications($_GET['uid'], 'payment', $_GET['lid']);
            ihc_switch_role_for_user($_GET['uid']);
            $_POST['payment_status'] = 'Completed' ;
            ihc_insert_update_transaction($_GET['uid'], $transId, $_POST);
            header( 'location:'. $r_url );

        } else {
            $_POST['payment_status'] = 'Failed' ;
            ihc_insert_update_transaction($_GET['uid'], $transId, $_POST);
            $message = 'رقم تراكنش با رقم پرداخت شده مطابقت ندارد';
            wp_die($message);
            exit;
        }
    } else {
        $_POST['payment_status'] = 'Failed' ;
        $message = 'در ارتباط با وب سرویس Pay.ir و بررسی تراکنش خطایی رخ داده است';
        $message = isset($result->errorMessage) ? $result->errorMessage : $message;
        wp_die($message);
        exit;
    }
} else {
    //non Payir tries to access this file
    header('Status: 404 Not Found');
    exit();
}
