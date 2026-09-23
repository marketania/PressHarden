<?php
/** Explicit, per-directory PHP policy. No web-SAPI effectiveness is inferred from CLI. */
require_once __DIR__.'/ops-safety.php';
function ph_php_policies() {
    return [
        'display_errors'=>['bool','Off on production'], 'display_startup_errors'=>['bool','Off on production'],
        'log_errors'=>['bool','On; protect the log destination'], 'expose_php'=>['bool','Off where host-managed'],
        'allow_url_fopen'=>['bool','Application-specific; do not blanket-disable'],
        'allow_url_include'=>['bool','Off where host-managed'], 'file_uploads'=>['bool','Application-specific'],
        'upload_max_filesize'=>['size','Application-specific'], 'post_max_size'=>['size','At least required upload size'],
        'memory_limit'=>['size','Application-specific; hosting limits still apply'],
        'max_execution_time'=>['int','Application-specific'], 'max_input_time'=>['int','Application-specific'],
        'max_input_vars'=>['int','Application-specific'],
        'session.use_strict_mode'=>['bool','On for applications using native PHP sessions'],
        'session.cookie_httponly'=>['bool','On for applications using native PHP sessions'],
        'session.cookie_secure'=>['bool','On only after HTTPS is verified'],
        'session.cookie_samesite'=>['samesite','Lax unless application flow requires another value']
    ];
}
function ph_php_value($type,$value) {
    if (!is_string($value) || strlen($value)>40 || preg_match('/[\x00-\x1f\x7f]/',$value)) throw new RuntimeException('invalid PHP policy value');
    if ($type==='bool') {
        $v=strtolower($value); if(in_array($v,['true','on','yes','1'],true))return '1';
        if(in_array($v,['false','off','no','0'],true))return '0';
    } elseif ($type==='size' && preg_match('/^[1-9][0-9]{0,9}[KMG]?$/iD',$value)) return strtoupper($value);
    elseif($type==='int' && preg_match('/^[0-9]{1,7}$/D',$value))return (string)(int)$value;
    elseif($type==='samesite' && in_array($value,['Lax','Strict','None'],true))return $value;
    throw new RuntimeException('unsupported value for this directive');
}
function ph_php_config($site) {
    ph_ops_path($site); $file=$site.'/.user.ini'; $bytes=''; $stat=null;
    if (file_exists($file)||is_link($file)) {
        $stat=ph_ops_regular($file,1048576);
        if (function_exists('posix_geteuid') && $stat['uid']!==posix_geteuid())throw new RuntimeException('configuration file owned by another account');
        $bytes=file_get_contents($file);if($bytes===false)throw new RuntimeException('cannot read .user.ini');
        if(preg_match('/<\?(?:php|=)|\b(?:auto_prepend_file|auto_append_file)\s*=\s*[^;\s]/i',$bytes))throw new RuntimeException('executable or auto-loaded content requires security review; policy write refused');
    }
    $parsed=@parse_ini_string($bytes,false,INI_SCANNER_RAW);
    if($parsed===false)throw new RuntimeException('invalid .user.ini syntax');
    if(preg_match('/^\s*\[/m',$bytes))throw new RuntimeException('sectioned .user.ini needs manual review');
    return [$file,$bytes,$stat,$parsed];
}
function ph_php_apply($state,$site,$key,$value) {
    $policies=ph_php_policies();if(!isset($policies[$key]))throw new RuntimeException('directive is not in the supported policy allowlist');
    $value=ph_php_value($policies[$key][0],$value);
    $sapi=getenv('PRESSHARDEN_PHP_WEB_SAPI');
    if(!in_array($sapi,['cgi-fcgi','fpm-fcgi'],true))throw new RuntimeException('set PRESSHARDEN_PHP_WEB_SAPI=cgi-fcgi or fpm-fcgi only after verifying the actual web SAPI; CLI SAPI is not evidence');
    if((getenv('PRESSHARDEN_PHP_USER_INI_FILENAME')?:'.user.ini')!=='.user.ini')throw new RuntimeException('nondefault/disabled web user_ini.filename is hosting-managed; unsupported');
    $all=ini_get_all();
    if(!isset($all[$key]) || (($all[$key]['access'] & (INI_USER|INI_PERDIR))===0))throw new RuntimeException('directive is unavailable or system/hosting-managed; no local file written');
    $state=ph_ops_scope($state,[$site]);
    $locks=ph_ops_dir($state.'/php-policy-locks');$lockfile=$locks.'/'.hash('sha256',$site).'.lock';
    if(!file_exists($lockfile)&&!is_link($lockfile)){ $m=umask(0077);$h=@fopen($lockfile,'x');umask($m);if($h)fclose($h); }
    $ls=ph_ops_regular($lockfile);$lock=@fopen($lockfile,'r+');
    if(!$lock||fstat($lock)['ino']!==$ls['ino']||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('another PHP policy transaction is active or lock is unsafe');
    try {
        list($file,$bytes,$st,$parsed)=ph_php_config($site);
        $pattern='/^\h*'.preg_quote($key,'/').'\h*=.*$/m';
        if(preg_match_all($pattern,$bytes)>1)throw new RuntimeException('duplicate directive needs manual review');
        if(isset($parsed[$key])) {
            try{$old=ph_php_value($policies[$key][0],(string)$parsed[$key]);}catch(Throwable $e){$old=null;}
            if($old===$value){echo "UNCHANGED: $key=$value on disk; web effective value UNKNOWN.\n";return 1;}
        }
        $pattern='/^\h*'.preg_quote($key,'/').'\h*=.*$/m';
        if(preg_match_all($pattern,$bytes)>1)throw new RuntimeException('duplicate directive needs manual review');
        $line=$key.' = '.$value;
        $next=preg_match($pattern,$bytes)?preg_replace($pattern,$line,$bytes):rtrim($bytes)."\n".$line."\n";
        $check=@parse_ini_string($next,false,INI_SCANNER_RAW);
        if(!is_array($check)||!isset($check[$key])||ph_php_value($policies[$key][0],(string)$check[$key])!==$value)throw new RuntimeException('staged value validation failed');
        $backup=ph_ops_backup_dir($state,$site,'php-policy');
        if(file_put_contents($backup.'/original.user.ini',$bytes)!==strlen($bytes))throw new RuntimeException('backup failed');
        chmod($backup.'/original.user.ini',0600);
        file_put_contents($backup.'/meta.json',json_encode(['source'=>$file,'existed'=>$st!==null,'sha256'=>hash('sha256',$bytes),'key'=>$key,'value'=>$value],JSON_PRETTY_PRINT));
        $tmp=$site.'/.pressharden-php-policy-'.bin2hex(random_bytes(8));$h=@fopen($tmp,'x');
        if(!$h)throw new RuntimeException('exclusive stage creation failed');
        try {
            if(fwrite($h,$next)!==strlen($next))throw new RuntimeException('short staging write');fclose($h);$h=null;
            if(!chmod($tmp,$st?($st['mode']&0777):0600))throw new RuntimeException('cannot retain permissions');
            if($st&&lstat($tmp)['gid']!==$st['gid']&&!chgrp($tmp,$st['gid']))throw new RuntimeException('cannot retain ownership');
            clearstatcache();
            if($st){$now=ph_ops_regular($file);if($now['ino']!==$st['ino']||hash_file('sha256',$file)!==hash('sha256',$bytes))throw new RuntimeException('source changed during transaction');}
            elseif(file_exists($file)||is_link($file))throw new RuntimeException('source appeared during transaction');
            if(!rename($tmp,$file))throw new RuntimeException('atomic publication failed');
            clearstatcache();ph_ops_regular($file);
            if(hash_file('sha256',$file)!==hash('sha256',$next))throw new RuntimeException('live verification failed; backup retained at '.$backup);
        }finally{if(is_resource($h))fclose($h);if(file_exists($tmp))unlink($tmp);}
        echo "CONFIGURED: $key=$value; on-disk verification passed. Backup: $backup\n";
        echo "WEB EFFECT UNVERIFIED: FPM/admin overrides and .user.ini cache can affect the result. Verify through the hosting runtime.\n";
        return 1;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
if(isset($argv[0])&&realpath($argv[0])===__FILE__) {
    try {
        if(($argv[1]??'')==='status'&&$argc===3){
            list($file,$bytes,$stat,$parsed)=ph_php_config($argv[2]);$all=ini_get_all();
            echo "Directive\tCLI current\tLocal .user.ini\tWeb effective\tOwnership / recommendation\n";
            foreach(ph_php_policies() as $k=>$p){$cli=isset($all[$k])?(string)$all[$k]['local_value']:'UNSUPPORTED';$local=$parsed[$k]??'UNSET';
                $local=preg_replace('/[\x00-\x1f\x7f]/','?',(string)$local);if(strlen($local)>80)$local='UNEXPECTED VALUE';
                $owned=isset($all[$k])&&($all[$k]['access']&(INI_USER|INI_PERDIR))?'per-directory where supported':'hosting-managed/unsupported';
                echo "$k\t$cli\t$local\tUNKNOWN\t$owned; {$p[1]}\n";
            }
            echo "Status is inspection, not a claim of web-SAPI effectiveness or policy compliance.\n";
        }elseif(($argv[1]??'')==='set'&&$argc===6)exit(ph_php_apply($argv[2],$argv[3],$argv[4],$argv[5]));
        else throw new RuntimeException('invalid PHP policy arguments');
    }catch(Throwable $e){fwrite(STDERR,'PHP POLICY: '.$e->getMessage()."\n");exit(2);}
}
