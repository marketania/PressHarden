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
function ph_php_text($value) { return preg_replace('/[\p{Cc}\p{Cf}]/u','?', $value) ?? '(unprintable value)'; }
function ph_php_same(array $a,array $b) {
    foreach(['dev','ino','size','mtime','ctime','mode','uid','gid','nlink'] as $key)
        if(!isset($a[$key],$b[$key])||$a[$key]!==$b[$key])return false;
    return true;
}
function ph_php_private_write($file,$data,$replace=false) {
    if($replace&&(file_exists($file)||is_link($file)))ph_ops_regular($file,2097152);
    $path=$replace?$file.'.'.bin2hex(random_bytes(8)).'.tmp':$file;
    $mask=umask(0077);$h=@fopen($path,'xb');umask($mask);
    if(!$h)throw new RuntimeException('cannot allocate private recovery file');
    try {
        if(fwrite($h,$data)!==strlen($data)||!fflush($h))throw new RuntimeException('incomplete recovery file');
        if(function_exists('fsync')&&!fsync($h))throw new RuntimeException('cannot synchronize recovery file');
        fclose($h);$h=null;
        if(hash_file('sha256',$path)!==hash('sha256',$data))throw new RuntimeException('recovery file verification failed');
        if($replace&&!rename($path,$file))throw new RuntimeException('cannot publish recovery metadata');
    } finally {if(is_resource($h))fclose($h);if($replace&&is_file($path))unlink($path);}
}
function ph_php_meta($file,array $data) {
    $json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    if($json===false)throw new RuntimeException('cannot encode recovery metadata');
    ph_php_private_write($file,$json,true);
}
function ph_php_config($site) {
    clearstatcache();ph_ops_path($site);$file=$site.'/.user.ini';$bytes='';$stat=null;
    if(file_exists($file)||is_link($file)) {
        $stat=ph_ops_regular($file,1048576);
        if(function_exists('posix_geteuid')&&$stat['uid']!==posix_geteuid())throw new RuntimeException('configuration file owned by another account');
        $h=@fopen($file,'rb');if(!$h)throw new RuntimeException('cannot read .user.ini');
        try {
            $opened=fstat($h);
            if(!$opened||!ph_php_same($stat,$opened))throw new RuntimeException('configuration changed before reading');
            $bytes=stream_get_contents($h,1048577);$end=fstat($h);clearstatcache(true,$file);
            $after=ph_ops_regular($file,1048576);
            if(!is_string($bytes)||strlen($bytes)!==$stat['size']||!$end||!ph_php_same($stat,$end)||!ph_php_same($stat,$after))
                throw new RuntimeException('configuration changed or exceeded read limit');
        } finally {fclose($h);}
        if(preg_match('/<\?(?:php|=)|\b(?:auto_prepend_file|auto_append_file)\s*=\s*[^;\s]/i',$bytes))throw new RuntimeException('executable or auto-loaded content requires security review; policy write refused');
    }
    // RAW is essential: never expand untrusted constants/environment values.
    $parsed=@parse_ini_string($bytes,false,INI_SCANNER_RAW);
    if($parsed===false)throw new RuntimeException('invalid .user.ini syntax');
    if(preg_match('/^\s*\[/m',$bytes))throw new RuntimeException('sectioned .user.ini needs manual review');
    return [$file,$bytes,$stat,$parsed];
}
function ph_php_prepare($bytes,array $parsed,$key,$type,$value) {
    $pattern='/^\h*'.preg_quote($key,'/').'\h*=.*$/m';$count=preg_match_all($pattern,$bytes,$matches);
    if(preg_match('/^\h*'.preg_quote($key,'/').'\h*\[/m',$bytes))throw new RuntimeException('array directive needs manual review');
    if($count>1)throw new RuntimeException('duplicate directive needs manual review');
    $old=null;$quoted=false;
    if(array_key_exists($key,$parsed)) {
        if(!is_string($parsed[$key])||$count!==1)throw new RuntimeException('complex directive needs manual review');
        if(strpos($parsed[$key],'${')!==false)throw new RuntimeException('environment-driven directive needs manual review');
        try{$old=ph_php_value($type,$parsed[$key]);}catch(Throwable $e){throw new RuntimeException('nonliteral or unsupported current directive needs manual review');}
        $quoted=(bool)preg_match('/=\h*"'.preg_quote($value,'/').'"\h*(?:;[^\r\n]*)?\r?$/',$matches[0][0]);
    }
    // INI reserves bare None as a false/empty value. Quote the string enum.
    if($old===$value&&($type!=='samesite'||$quoted))return [$bytes,false];
    $literal=$type==='samesite'?'"'.$value.'"':$value;$line=$key.' = '.$literal;
    $next=$count?preg_replace($pattern,$line,$bytes):rtrim($bytes)."\n".$line."\n";
    $check=@parse_ini_string($next,false,INI_SCANNER_RAW);
    // NORMAL is used only on OUR generated allowlisted literal, never site input.
    $semantic=parse_ini_string($line,false,INI_SCANNER_NORMAL);
    if(!is_array($check)||!is_string($check[$key]??null)||ph_php_value($type,$check[$key])!==$value||
        !is_array($semantic)||($semantic[$key]??null)!==$value)throw new RuntimeException('staged value validation failed');
    return [$next,true];
}
function ph_php_apply($state,$site,$key,$value) {
    $policies=ph_php_policies();if(!isset($policies[$key]))throw new RuntimeException('directive is not in the supported policy allowlist');
    $value=ph_php_value($policies[$key][0],$value);
    $sapi=getenv('PRESSHARDEN_PHP_WEB_SAPI');
    if(!in_array($sapi,['cgi-fcgi','fpm-fcgi'],true))throw new RuntimeException('set PRESSHARDEN_PHP_WEB_SAPI=cgi-fcgi or fpm-fcgi only after verifying the actual web SAPI; CLI SAPI is not evidence');
    $iniName=getenv('PRESSHARDEN_PHP_USER_INI_FILENAME');
    if(($iniName===false?'.user.ini':$iniName)!=='.user.ini')throw new RuntimeException('nondefault/disabled web user_ini.filename is hosting-managed; unsupported');
    $all=ini_get_all();
    if(!isset($all[$key]) || (($all[$key]['access'] & (INI_USER|INI_PERDIR))===0))throw new RuntimeException('directive is unavailable or system/hosting-managed; no local file written');
    $state=ph_ops_scope($state,[$site]);
    $locks=ph_ops_dir($state.'/php-policy-locks');$lockfile=$locks.'/'.hash('sha256',$site).'.lock';
    if(!file_exists($lockfile)&&!is_link($lockfile)){ $m=umask(0077);$h=@fopen($lockfile,'x');umask($m);if($h)fclose($h); }
    clearstatcache();$ls=ph_ops_regular($lockfile);
    if(($ls['mode']&0077)!==0||(function_exists('posix_geteuid')&&$ls['uid']!==posix_geteuid()))throw new RuntimeException('unsafe PHP policy lock ownership/mode');
    $lock=@fopen($lockfile,'r+');$opened=$lock?fstat($lock):false;
    if(!$opened||$opened['ino']!==$ls['ino']||$opened['dev']!==$ls['dev']||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('another PHP policy transaction is active or lock is unsafe');
    try {
        list($file,$bytes,$st,$parsed)=ph_php_config($site);
        list($next,$changed)=ph_php_prepare($bytes,$parsed,$key,$policies[$key][0],$value);
        if(!$changed){echo "UNCHANGED: $key=$value on disk; web effective value UNKNOWN.\n";return 1;}
        echo 'PLAN: '.ph_php_text($file)."\n";
        echo 'File literal: '.ph_php_text($parsed[$key]??'UNSET')."; proposed $key=$value\n";
        echo 'Context: '.$policies[$key][1].". Web effect still requires hosting verification.\n";
        if(getenv('PRESSHARDEN_INTERACTIVE')!=='0') {
            $tty=@fopen('/dev/tty','r+');
            if(!$tty){fwrite(STDERR,"Confirmation terminal required after preview; no configuration changed.\n");return 1;}
            fwrite($tty,"Back up and apply this one site's PHP policy change? [y/N]: ");
            $answer=fgets($tty,32);fclose($tty);
            if(!in_array(strtolower(trim((string)$answer)),['y','yes'],true)){echo "DECLINED: no configuration changed.\n";return 1;}
        }
        list($again,$current,$currentStat)=ph_php_config($site);
        if($current!==$bytes||(($st===null)!==($currentStat===null))||($st!==null&&!ph_php_same($st,$currentStat)))
            throw new RuntimeException('source changed after preview; regenerate the plan');
        $backup=ph_ops_backup_dir($state,$site,'php-policy');
        ph_php_private_write($backup.'/original.user.ini',$bytes);
        $meta=['source'=>$file,'existed'=>$st!==null,'sha256'=>hash('sha256',$bytes),'key'=>$key,'value'=>$value,
               'original_stat'=>$st,'expected_sha256'=>hash('sha256',$next),'status'=>'PREPARED'];
        ph_php_meta($backup.'/meta.json',$meta);
        echo 'RECOVERY: '.ph_php_text($backup)."\n";
        $published=false;$tmp=$site.'/.pressharden-php-policy-'.bin2hex(random_bytes(8));$mask=umask(0077);$h=@fopen($tmp,'x');umask($mask);
        if(!$h)throw new RuntimeException('exclusive stage creation failed');
        try {
            if(fwrite($h,$next)!==strlen($next))throw new RuntimeException('short staging write');fclose($h);$h=null;
            if(!chmod($tmp,$st?($st['mode']&0777):0600))throw new RuntimeException('cannot retain permissions');
            if($st&&lstat($tmp)['gid']!==$st['gid']&&!chgrp($tmp,$st['gid']))throw new RuntimeException('cannot retain ownership');
            clearstatcache();
            if($st){$now=ph_ops_regular($file);if(!ph_php_same($st,$now)||hash_file('sha256',$file)!==hash('sha256',$bytes))throw new RuntimeException('source changed during transaction');}
            elseif(file_exists($file)||is_link($file))throw new RuntimeException('source appeared during transaction');
            if(!rename($tmp,$file))throw new RuntimeException('atomic publication failed');
            $published=true;clearstatcache();$live=ph_ops_regular($file);
            if(($live['mode']&0777)!==($st?($st['mode']&0777):0600)||($st&&($live['uid']!==$st['uid']||$live['gid']!==$st['gid'])))throw new RuntimeException('published ownership or permissions differ; backup retained');
            if(hash_file('sha256',$file)!==hash('sha256',$next))throw new RuntimeException('live verification failed; backup retained at '.$backup);
            $meta['published_stat']=$live;$meta['status']='CONFIGURED_WEB_UNVERIFIED';ph_php_meta($backup.'/meta.json',$meta);
        }catch(Throwable $e){
            $meta['status']=$published?'LIVE_UNVERIFIED_BACKUP_RETAINED':'REFUSED_BACKUP_RETAINED';
            try{ph_php_meta($backup.'/meta.json',$meta);}catch(Throwable $ignored){}
            throw $e;
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
            foreach(ph_php_policies() as $k=>$p){$cli=isset($all[$k])?(string)$all[$k]['local_value']:'UNSUPPORTED';$local=$parsed[$k]??'UNSET';if(!is_string($local))$local='COMPLEX VALUE; manual review';
                $local=ph_php_text((string)$local);$cli=ph_php_text($cli);if(strlen($local)>80)$local='UNEXPECTED VALUE';
                $owned=isset($all[$k])&&($all[$k]['access']&(INI_USER|INI_PERDIR))?'per-directory where supported':'hosting-managed/unsupported';
                echo "$k\t$cli\t$local\tUNKNOWN\t$owned; {$p[1]}\n";
            }
            echo "Status is inspection, not a claim of web-SAPI effectiveness or policy compliance.\n";
        }elseif(($argv[1]??'')==='set'&&$argc===6)exit(ph_php_apply($argv[2],$argv[3],$argv[4],$argv[5]));
        else throw new RuntimeException('invalid PHP policy arguments');
    }catch(Throwable $e){fwrite(STDERR,'PHP POLICY: '.ph_php_text($e->getMessage())."\n");exit(2);}
}
