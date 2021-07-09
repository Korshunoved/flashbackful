<?
require_once( $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php' );


$id=92;
$ema='testtt@tu.ru';

	 
$string='{
  "avalanche": {
    "version": 1,
    "type": "generic",
    "title": "Tests",
    "img": "http://cryptotik.beget.tech/wp-content/uploads/2021/07/a9ea190104041989dba0e5d032bfb014_999-1.png",
    "desc": "some data"
  }
}';


  echo  $apiUrl = 'http://18.116.131.159:3000/encode';
    $message = $string;
    $requestHeaders = array(
        'Content-type: application/json'
    );
	
	
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $message);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $requestHeaders);
    $response = curl_exec($ch);
	echo curl_error($ch);
	if ($response === FALSE) {
    // Тут-то мы о ней и скажем
   
    return;
}
	
print_r($response);
    $otvet=json_decode($response); 
echo '<pre>';;
print_r($otvet->data);	
echo '<pre>';


	


?>