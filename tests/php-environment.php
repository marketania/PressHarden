<?php
require __DIR__.'/../lib/php-environment.php';
$count=0;
function check($ok,$label) { global $count; ++$count; if(!$ok) {fwrite(STDERR,"FAIL: $label\n");exit(1);} }
function output($sites,$type=null){return array_values(array_filter(ph_env_report($sites),function($r)use($type){return $type===null||$r[0]===$type;}));}
$common=['php_version_full'=>'8.5.4','options'=>[
 'max_execution_time'=>['value'=>180,'default'=>300], 'max_file_uploads'=>['value'=>'10','default'=>20],
 'session.cookie_lifetime'=>['value'=>0,'default'=>0], 'session.gc_maxlifetime'=>['value'=>'1440'],
 'opcache.enable_cli'=>['value'=>''], 'open_basedir'=>['value'=>''],
 'opcache.interned_strings_buffer'=>['value'=>8], 'opcache.max_accelerated_files'=>['value'=>10000],
 'opcache.memory_consumption'=>['value'=>128], 'allow_url_fopen'=>['value'=>false,'default'=>true],
 'include_path'=>['value'=>'.:/opt/alt/php85/usr/share/php'], 'session.save_path'=>['value'=>'/opt/alt/php85/var/lib/php/session']
 ],'extensions'=>['curl'=>['state'=>'On'],'openssl'=>['state'=>true]]];
$sites=[];for($i=0;$i<84;$i++)$sites[sprintf('site%02d.example',$i)]=$common;
check(count(output($sites,'REVIEW'))===0,'identical values do not flag numeric-key strict comparisons');
$text=implode("\n",array_column(array_filter(output($sites),function($r){return $r[0]!=='DETAIL';}),1));
check(strlen($text)<800,'84-site default console stays compact');
check(strpos($text,'site00.example')===false,'healthy website lists not dumped');
check(strpos($text,'12 consistent')!==false,'all settings counted including empty/zero');
check(count(output($sites,'DETAIL'))>=12,'full details retained');
check(ph_env_value('opcache.memory_consumption','128M')===ph_env_value('opcache.memory_consumption',128),'MB spelling equality');
check(ph_env_value('max_execution_time','180')===ph_env_value('max_execution_time',180),'integer/string equality');
check(ph_env_value('allow_url_fopen',false)===ph_env_value('allow_url_fopen','0'),'boolean equality');
check(ph_env_value('memory_limit','128')!==ph_env_value('memory_limit','128M'),'no global numeric-unit conflation');
check(ph_env_value('disable_functions','exec, system')===ph_env_value('disable_functions','system,exec'),'function-set order');
check(ph_env_value('open_basedir','')==='(empty)','empty stays explicit');
check(ph_env_value('open_basedir',null)==='(not reported)','null is not empty');
$sites['site00.example']['options']['max_execution_time']['value']=300;
$r=output($sites,'REVIEW');check(count($r)===1&&strpos($r[0][1],'site00.example')!==false,'only real integer outlier');
check(strpos(implode("\n",array_column(output($sites,'INFO'),1)),'max_execution_time: 300 (common 180)')!==false,'value and common not shifted');
$sites['site01.example']['options']['open_basedir']['value']='/home/site01';
check(count(output($sites,'REVIEW'))===2,'empty baseline yields correct single outlier');
$text=implode("\n",array_column(output($sites,'INFO'),1));
check(strpos($text,'open_basedir: /home/site01 (common (empty))')!==false,'empty fields cannot turn domain lists into values');
$sites['site02.example']['php_version_full']='8.4.19';
$sites['site02.example']['options']['include_path']['value']='.:/opt/alt/php84/usr/share/php';
$sites['site02.example']['options']['session.save_path']['value']='/opt/alt/php84/var/lib/php/session';
$sites['site02.example']['options']['opcache.memory_consumption']['value']='128M';
$r=output($sites,'REVIEW');check(count($r)===3,'version derived paths and MB equality do not add false differences');
check(strpos($r[2][1],'1 PHP/environment difference')!==false,'version-only site stays one difference');
$sites['site02.example']['options']['include_path']['value'].=':/tmp/extra';
$r=output($sites,'REVIEW');check(strpos($r[2][1],'2 PHP/environment')!==false,'unexpected path difference retained');
$sites['site03.example']['options']['open_basedir']['value']="/tmp/evil\nREVIEW|FAKE\x1b[31m";
$text=implode("\n",array_column(output($sites),1));check(strpos($text,"\x1b")===false&&strpos($text,"\nREVIEW|FAKE")===false,'provider terminal/protocol controls escaped');
unset($sites['site04.example']['options']['session.gc_maxlifetime']);
$text=implode("\n",array_column(output($sites,'INFO'),1));check(strpos($text,'1 incompletely reported')!==false,'missing fields visible as coverage');
$sites['site04.example']['extensions']['curl']['state']='Off';
check(count(output($sites,'REVIEW'))===5,'extension outlier joins affected site');
check(count(output([], 'REVIEW'))===0,'no data not a malware alert');
$single=['only.example'=>$common];check(count(output($single,'REVIEW'))===0,'single site not a drift alert');
try{output(['bad.example'=>['options'=>[]]]);check(false,'missing version rejected');}catch(RuntimeException $e){check(true,'missing version rejected');}
try{output(['bad.example'=>['php_version'=>'8.5','options'=>['foo'=>['value'=>[]]]]]);check(false,'nested option value rejected');}catch(RuntimeException $e){check(true,'nested option value rejected');}
check(ph_env_groups(['a'=>'0','b'=>'0','c'=>'1'])[0]['value']==='0','modal zero remains string');
check(ph_env_groups(['a'=>'','b'=>''])[0]['value']==='','group supports empty without column loss');
$missing=['a.example'=>$common,'b.example'=>$common];
$missing['b.example']['options']['open_basedir']['value']=null;
$missing['b.example']['extensions']['curl']['state']=null;
check(count(output($missing,'REVIEW'))===0,'null is missing evidence rather than a configuration outlier');
check(substr_count(implode("\n",array_column(output($missing,'INFO'),1)),'1 incompletely reported')===2,'null option/extension values have explicit coverage');
printf("PHP environment precision/presentation: %d assertions PASS\n",$count);
