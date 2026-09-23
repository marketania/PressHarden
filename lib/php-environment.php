<?php
/** Optional provider-data presentation; pure comparisons, no remote requests. */
function ph_env_text($v) {
    if (is_bool($v)) $v=$v?'On':'Off';
    elseif ($v===null) $v='(not reported)';
    elseif (is_array($v)) $v=json_encode($v, JSON_UNESCAPED_SLASHES);
    $v=trim((string)$v);
    $v=preg_replace_callback('~[\x00-\x1f\x7f|]~', function($m){return sprintf('\\x%02X',ord($m[0]));},$v);
    return $v===''?'(empty)':$v;
}
function ph_env_value($name,$value) {
    if (is_array($value) || is_object($value)) throw new RuntimeException('Invalid option value');
    $v=ph_env_text($value);
    $bool=['allow_url_fopen','allow_url_include','display_errors','display_startup_errors','expose_php','file_uploads','log_errors','short_open_tag','zlib.output_compression','opcache.enable','opcache.enable_cli','session.cookie_httponly','session.cookie_secure','session.use_strict_mode','session.use_only_cookies','@extension'];
    if (in_array($name,$bool,true)) {
        if (in_array(strtolower($v),['1','on','true','yes'],true)) return 'On';
        if (in_array(strtolower($v),['0','off','false','no'],true)) return 'Off';
    }
    // These directives use megabytes; do not normalize arbitrary numeric fields
    // or filesystem paths as quantities. 128 and 128M denote the same setting.
    if (in_array($name,['opcache.memory_consumption','opcache.interned_strings_buffer'],true)
        && preg_match('~^([0-9]{1,9})m?$~iD',$v,$m)) return (string)(int)$m[1].'M';
    if ($name==='disable_functions' && preg_match('~^[A-Za-z0-9_, ]+$~D',$v)) {
        $a=array_unique(array_filter(array_map('trim',explode(',',$v)))); sort($a,SORT_STRING); return implode(', ',$a);
    }
    return $v;
}
function ph_env_groups($by) {
    $groups=[];
    foreach($by as $domain=>$value) {
        $key='v:'.$value; // Numeric string keys MUST NOT become integers.
        if(!isset($groups[$key])) $groups[$key]=['value'=>$value,'domains'=>[]];
        $groups[$key]['domains'][]=$domain;
    }
    $groups=array_values($groups);
    foreach($groups as &$g) sort($g['domains'],SORT_STRING); unset($g);
    usort($groups,function($a,$b){return (count($b['domains'])<=>count($a['domains']))?:strcmp($a['value'],$b['value']);});
    return $groups;
}
function ph_env_version_path($value,$version) {
    if(!preg_match('~^(\d+)\.(\d+)(?:\.|$)~D',$version,$m)) return $value;
    return str_replace('/opt/alt/php'.$m[1].$m[2].'/','/opt/alt/php{version}/',$value);
}
function ph_env_report(array $sites,$cap=20) {
    if(!$sites) return [['INFO','No per-site PHP API data available.']];
    ksort($sites,SORT_STRING); $versions=[]; $options=[]; $defaults=[]; $ranges=[]; $extensions=[];
    foreach($sites as $domain=>$j) {
        if(!is_string($domain)||!preg_match('~^[A-Za-z0-9.-]+$~D',$domain)||!is_array($j)) throw new RuntimeException('Invalid domain data');
        $version=$j['php_version_full']??$j['php_version']??null;
        if(!is_string($version)&&!is_numeric($version)) throw new RuntimeException('Missing PHP version');
        $versions[$domain]=ph_env_text($version);
        foreach(['options','extensions'] as $collection) if(isset($j[$collection])&&(!is_array($j[$collection])||count($j[$collection])>4096)) throw new RuntimeException('Option limit');
        foreach(($j['options']??[]) as $name=>$x) {
            if(!is_string($name)||!preg_match('~^[A-Za-z0-9_.-]{1,128}$~D',$name)) throw new RuntimeException('Invalid setting name');
            if(is_array($x)) {
                if(!array_key_exists('value',$x)) { $options[$name]=$options[$name]??[]; continue; }
                $value=$x['value'];
                if(array_key_exists('default',$x)||array_key_exists('default_value',$x)) $defaults[$name][$domain]=ph_env_value($name,$x['default']??$x['default_value']??null);
                if(isset($x['max'])||isset($x['range'])) $ranges[$name][$domain]=ph_env_text($x['max']??$x['range']);
            } else $value=$x;
            if($value===null) { $options[$name]=$options[$name]??[]; continue; }
            $options[$name][$domain]=ph_env_value($name,$value);
        }
        foreach(($j['extensions']??[]) as $name=>$x) {
            if(!is_string($name)||!preg_match('~^[A-Za-z0-9_.-]{1,128}$~D',$name)) throw new RuntimeException('Invalid extension');
            if($x===null||(is_array($x)&&(!array_key_exists('state',$x)||$x['state']===null))) { $extensions[$name]=$extensions[$name]??[]; continue; }
            $extensions[$name][$domain]=ph_env_value('@extension',is_array($x)?$x['state']:$x);
        }
    }
    $records=[]; $details=[]; $changes=[]; $n=count($sites);
    $vg=ph_env_groups($versions); $baseVersion=$vg[0]['value'];
    $records[]=['INFO','PHP profile: '.$baseVersion.' on '.count($vg[0]['domains']).'/'.$n.' covered websites.'];
    foreach($vg as $i=>$g) {
        $details[]='PHP '.$g['value'].' ('.count($g['domains']).'/'.$n.'): '.implode(', ',$g['domains']);
        if($i>0) foreach($g['domains'] as $d) $changes[$d]['PHP']=$g['value'].' (common '.$baseVersion.')';
    }
    $consistent=0; $different=0; $partial=0; $custom=0; $derived=0;
    ksort($options,SORT_STRING);
    foreach($options as $name=>$by) {
        if(count($by)<$n) ++$partial;
        if(!$by) { $details[]=$name.': no values reported'; continue; }
        $groups=ph_env_groups($by); $base=$groups[0]['value']; $baseDomain=$groups[0]['domains'][0];
        $hasDifference=false;
        foreach($groups as $i=>$g) {
            $details[]=$name.' = '.$g['value'].' ('.count($g['domains']).'/'.$n.'): '.implode(', ',$g['domains']);
            if($i>0) foreach($g['domains'] as $d) {
                if(in_array($name,['include_path','session.save_path'],true)
                    && $versions[$d]!==$versions[$baseDomain]
                    && ph_env_version_path($g['value'],$versions[$d])===ph_env_version_path($base,$versions[$baseDomain])) { ++$derived; continue; }
                $changes[$d][$name]=$g['value'].' (common '.$base.')'; $hasDifference=true;
            }
        }
        if($hasDifference) ++$different; elseif(count($by)===$n) ++$consistent;
        $customBy=[];
        foreach($by as $d=>$v) if(isset($defaults[$name][$d])&&$defaults[$name][$d]!==$v) $customBy[$d]=$v.' (provider default '.$defaults[$name][$d].')';
        if($customBy) {
            ++$custom;
            foreach(ph_env_groups($customBy) as $g) $details[]='CUSTOM '.$name.' = '.$g['value'].' on '.count($g['domains']).' website(s): '.implode(', ',$g['domains']);
        }
        foreach(['default'=>$defaults[$name]??[],'max/range'=>$ranges[$name]??[]] as $label=>$meta) {
            foreach(ph_env_groups($meta) as $g) $details[]=$name.' '.$label.' = '.$g['value'].' ('.count($g['domains']).' website(s))';
        }
    }
    $records[]=['INFO','Settings: '.$consistent.' consistent; '.$different.' with differences; '.$partial.' incompletely reported.'];
    if($custom) $records[]=['INFO',$custom.' setting(s) customized from provider defaults; domain lists kept in the full report.'];
    if($derived) $records[]=['INFO','Version-specific PHP paths are grouped with their PHP version differences.'];
    $ec=0; $ed=0; $ep=0;
    ksort($extensions,SORT_STRING);
    foreach($extensions as $name=>$by) {
        if(count($by)<$n) ++$ep;
        if(!$by) continue;
        $groups=ph_env_groups($by); $base=$groups[0]['value'];
        if(count($groups)===1&&count($by)===$n) ++$ec;
        if(count($groups)>1) ++$ed;
        foreach($groups as $i=>$g) {
            $details[]='Extension '.$name.' = '.$g['value'].' ('.count($g['domains']).'/'.$n.'): '.implode(', ',$g['domains']);
            if($i>0) foreach($g['domains'] as $d) $changes[$d]['extension '.$name]=$g['value'].' (common '.$base.')';
        }
    }
    $records[]=['INFO','Extensions: '.$ec.' consistent; '.$ed.' with differences; '.$ep.' incompletely reported.'];
    ksort($changes,SORT_STRING); $shown=0;
    $priority=['PHP','allow_url_include','allow_url_fopen','expose_php','display_errors','session.cookie_secure','session.cookie_httponly','session.use_strict_mode','session.cookie_samesite'];
    foreach($changes as $d=>$diff) {
        // Always emit a short finding per affected website, even beyond the
        // expanded-details cap; do not silently reduce finding counts.
        $records[]=['REVIEW',$d.' — '.count($diff).' PHP/environment difference(s)'];
        uksort($diff,function($a,$b)use($priority){$x=array_search($a,$priority,true);$y=array_search($b,$priority,true);return (($x===false?999:$x)<=>($y===false?999:$y))?:strcmp($a,$b);});
        if(++$shown<=$cap) {
            $lines=0;
            foreach($diff as $name=>$v) {
                if(++$lines>4) break;
                if(strlen($v)>140) $v=substr($v,0,137).'...';
                $records[]=['INFO','  '.$name.': '.$v];
            }
            if(count($diff)>4) $records[]=['INFO','  +'.(count($diff)-4).' more in the full report.'];
        }
    }
    if(!$changes) $records[]=['INFO','No differences among reported PHP/environment values.'];
    $records[]=['INFO','Differences are configuration review items, not proof of a vulnerability.'];
    foreach($details as $line) $records[]=['DETAIL',$line];
    return $records;
}
if(isset($argv[0])&&realpath($argv[0])===__FILE__) {
    try {
        if($argc!==2) throw new RuntimeException('Arguments');
        $list=@file_get_contents($argv[1],false,null,0,4194305);
        if($list===false||strlen($list)>4194304) throw new RuntimeException('List limit');
        $sites=[]; $bytes=0;
        foreach(explode("\n",rtrim($list,"\n")) as $line) {
            if($line==='') continue;
            $p=explode("\t",$line);
            if(count($p)!==2||isset($sites[$p[0]])||count($sites)>=10000||!is_file($p[1])||is_link($p[1])) throw new RuntimeException('Invalid input');
            $raw=@file_get_contents($p[1],false,null,0,2097153);
            if($raw===false||strlen($raw)>2097152||($bytes+=strlen($raw))>67108864) throw new RuntimeException('Input limit');
            $j=json_decode($raw,true);
            if(!is_array($j)||json_last_error()!==JSON_ERROR_NONE) throw new RuntimeException('Invalid JSON');
            $sites[$p[0]]=$j;
        }
        foreach(ph_env_report($sites) as $r) {
            // No empty TSV columns cross a shell read. Escape delimiters/control
            // bytes in text, preserving On/Off, numeric strings and empty values.
            $line=$r[0].'|'.str_replace('|','\\x7C',$r[1])."\n";
            if(fwrite(STDOUT,$line)!==strlen($line)) throw new RuntimeException('Write failed');
        }
    } catch(Throwable $e) {
        fwrite(STDERR,"INCOMPLETE: PHP environment summary could not be produced; raw provider data was not printed.\n"); exit(2);
    }
}
