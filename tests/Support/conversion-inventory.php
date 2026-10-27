<?php
// Direct Linux collector contracts on isolated filesystem fixtures. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require ($argv[2]??dirname(__DIR__,2).'/scripts/conversion').'/AppInventory.php';
use DomainsConversion\AppInventory;
function inventoryExpect(bool $ok,string $label): void {if(!$ok){throw new LogicException($label);}}
function inventoryRefuses(Closure $call,string $message): void {
    try{$call();}catch(DomainException $e){inventoryExpect(str_contains($e->getMessage(),$message),$message);return;}throw new LogicException('Missing refusal: '.$message);
}
$base=$argv[1];$proc=$base.'/proc';$app=$base.'/app';mkdir($proc);mkdir($app);mkdir($proc.'/self');mkdir($proc.'/'.getmypid());
$uid=fileowner($base);$status="Uid:\t$uid\t$uid\t$uid\t$uid\nPPid:\t1\n";
file_put_contents($proc.'/self/status',$status);file_put_contents($proc.'/'.getmypid().'/status',$status);
file_put_contents($proc.'/mounts',"proc /proc proc rw 0 0\n");mkdir($proc.'/2');file_put_contents($proc.'/2/status',$status);
file_put_contents($app.'/runner.php','<?php');symlink($app.'/runner.php',$base.'/alias.php');symlink('/',$proc.'/2/cwd');
file_put_contents($proc.'/2/cmdline',"php\0".$base."/alias.php\0");
$i=AppInventory::processes($app,$proc);inventoryExpect($i['own_peers']===1&&count($i['processes'])===1,'Own peer and ancestry');
inventoryExpect($i['processes'][0]['own']===true&&$i['processes'][0]['cwd']==='/','Own uid and cwd');
inventoryExpect(in_array($app.'/runner.php',$i['processes'][0]['paths'],true),'Absolute symlink argv resolves into application');
unlink($proc.'/2/cwd');symlink($base,$proc.'/2/cwd');file_put_contents($proc.'/2/cmdline',"php\0alias.php\0");
$i=AppInventory::processes($app,$proc);inventoryExpect(in_array($app.'/runner.php',$i['processes'][0]['paths'],true),'Relative argv resolves against actual cwd');
unlink($proc.'/2/cwd');symlink($app,$proc.'/2/cwd');$i=AppInventory::processes($app,$proc);inventoryExpect($i['processes'][0]['cwd']===$app,'Application cwd collected');
unlink($proc.'/2/cwd');inventoryRefuses(fn()=>AppInventory::processes($app,$proc),'Required process cwd');
file_put_contents($proc.'/2/cmdline','');inventoryRefuses(fn()=>AppInventory::processes($app,$proc),'Required process cwd');
file_put_contents($proc.'/2/status',$status."State:\tZ (zombie)\n");inventoryExpect(AppInventory::processes($app,$proc)['own_peers']===0,'Zombie omitted');
file_put_contents($proc.'/2/status',$status);symlink('/',$proc.'/2/cwd');file_put_contents($proc.'/2/cmdline','php idle');
mkdir($proc.'/3');file_put_contents($proc.'/3/status',"Uid:\t99999\t99999\t99999\t99999\n");file_put_contents($proc.'/3/cmdline','nginx: worker');
$i=AppInventory::processes($app,$proc);inventoryExpect($i['foreign_cwd_unreadable']===['3'],'Foreign inaccessible cwd recorded');
inventoryExpect($i['processes'][1]['own']===false&&$i['processes'][1]['cwd']===null,'Foreign uid never own');
file_put_contents($proc.'/3/cmdline','');inventoryExpect(count(AppInventory::processes($app,$proc)['processes'])===1,'Foreign empty command omitted');
file_put_contents($proc.'/3/cmdline','nginx: worker');
foreach(['self/status'=>'Effective uid','2/status'=>'Process uid',getmypid().'/status'=>'Process ancestry']as$file=>$message){
    $old=file_get_contents($proc.'/'.$file);file_put_contents($proc.'/'.$file,'malformed');inventoryRefuses(fn()=>AppInventory::processes($app,$proc),$message);file_put_contents($proc.'/'.$file,$old);
}
foreach(['2/status'=>'Required process status','2/cmdline'=>'Required process command','mounts'=>'Required app inventory']as$file=>$message){
    $old=file_get_contents($proc.'/'.$file);unlink($proc.'/'.$file);inventoryRefuses(fn()=>AppInventory::processes($app,$proc),$message);file_put_contents($proc.'/'.$file,$old);
}
file_put_contents($proc.'/mounts',"proc /proc proc rw,hidepid=2 0 0\n");
inventoryExpect(AppInventory::processes($app,$proc)['visibility']==='own processes only (hidepid)','Native hidepid option recorded');
file_put_contents($proc.'/mounts',"proc /proc proc rw 0 0\n");inventoryExpect(AppInventory::processes($app,$proc)['visibility']!=='own processes only (hidepid)','No fabricated hidepid');
$etc=$base.'/etc';mkdir($etc);mkdir($etc.'/cron.d');file_put_contents($etc.'/crontab','# system cron');file_put_contents($etc.'/cron.d/readable','* * * * * root /other/task');
file_put_contents($etc.'/cron.d/unreadable','private');chmod($etc.'/cron.d/unreadable',0000);
$cron=static function(int $code,string $out,string $err)use($base,$etc):array{
    file_put_contents($base.'/out',$out);file_put_contents($base.'/err',$err);file_put_contents($base.'/code',(string)$code);return AppInventory::cron($etc);
};
$c=$cron(0,'# own cron','');inventoryExpect($c['user_cron']==='# own cron'&&count($c['system_cron'])===2,'Real crontab stdout and system files');
inventoryExpect(in_array($etc.'/cron.d/unreadable',$c['cron_unreadable'],true),'Unreadable system file recorded');
inventoryExpect($cron(1,'',"no crontab for fixture\n")['user_cron']==='','Exact native no-crontab accepted');
foreach([[1,'','permission denied'],[2,'','no crontab for fixture'],[1,'unexpected','no crontab for fixture'],[1,'',"no crontab for fixture\nother error"]]as$args){inventoryRefuses(fn()=>$cron(...$args),'User crontab unavailable');}
chmod($etc.'/cron.d/unreadable',0600);unlink($etc.'/cron.d/unreadable');unlink($etc.'/cron.d/readable');rmdir($etc.'/cron.d');
inventoryExpect(in_array($etc.'/cron.d',$cron(0,'','')['cron_unreadable'],true),'Unreadable system directory recorded');
inventoryRefuses(fn()=>AppInventory::read($base.'/missing'),'Required app inventory');
echo "Native process paths, uid, ancestry, visibility and crontab subprocess contracts passed.\n";
