<?php 
	require '../../../../wp-load.php';
	
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
if (extension_loaded('curl')) {

	$api_key = get_option('ihc_payir_key');
	$currency = get_option('ihc_currency');
	$levels = get_option('ihc_levels');
	$r_url = get_option('ihc_payir_return_page');
	
	if(!$r_url || $r_url==-1){
		$r_url = get_option('page_on_front');
	}
	$r_url = get_permalink($r_url);
	if (!$r_url){
		$r_url = get_home_url();
	}
		
	$err = false;

	if (isset($levels[$_GET['lid']])){
		$level_arr = $levels[$_GET['lid']];
		if ($level_arr['payment_type']=='free' || $level_arr['price']=='') $err = true;
	} else {
		$err = true;
	}
	if (isset($_GET['uid']) && $_GET['uid']){
		$uid = $_GET['uid'];
	} else {
		$uid = get_current_user_id();
	}
	if (!$uid){
		$err = true;	
	}
		
	if ($err){
		header( 'location:'. $r_url );
		exit();
	}
	
	$callback = str_replace('public/', 'payir_ipn.php?lid=' . $_GET['lid'] . '&uid=' . $uid , plugin_dir_url(__FILE__));
	
	$reccurrence = FALSE;
	if (isset($level_arr['access_type']) && $level_arr['access_type']=='regular_period'){
		$reccurrence = TRUE;
	}

	$coupon_data = array();
	if (!empty($_GET['ihc_coupon'])){
		$coupon_data = ihc_check_coupon($_GET['ihc_coupon'], $_GET['lid']);
	}

    if ($coupon_data){
        $level_arr['price'] = ihc_coupon_return_price_after_decrease($level_arr['price'], $coupon_data);
    }

    if ($currency != 'IRR')  {
        $level_arr['price'] = $level_arr['price']  * 10 ;
    }

    $order_id = md5(uniqid(rand(), true));
	$params = array(
		'api'          => $api_key,
		'amount'       => $level_arr['price'],
		'redirect'     => urlencode($callback),
		'mobile'       => null,
		'description'  => $level_arr['description'],
	);
	$result = common('https://pay.ir/payment/send', $params);
	 
	if ($result && isset($result->status) && $result->status == 1) {
		$gateway_url = 'https://pay.ir/payment/gateway/' . $result->transId;
		header( 'location:' . $gateway_url );
		exit;
	} else {
		$message = 'در ارتباط با وب سرویس Pay.ir خطایی رخ داده است';
		$message = isset($result->errorMessage) ? $result->errorMessage : $message;
		echo $message;
		wp_die($message);
		exit;
	}
} else {
	$message = 'تابع cURL در سرور فعال نمی باشد';
	wp_die($message);
	exit;
}
	
	
	