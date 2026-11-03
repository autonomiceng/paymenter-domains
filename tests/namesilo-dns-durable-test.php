<?php
// Synthetic provider adapter through durable management. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
$transferEnvironment=\Paymenter\Extensions\Servers\Domains\Data\Environment::Live;
require __DIR__.'/Support/management-namesilo.php';
$legacyTransferExchange=$exchange;$exchange=static fn($o,$cmd,$p,$t)=>$legacyTransferExchange(str_replace('https://www.namesilo.com','https://ote.namesilo.com',$o),$cmd,$p,$t);
$legacyManagementExchange=$managementExchange;$managementExchange=static fn($o,$cmd,$p,$t)=>$legacyManagementExchange(str_replace('https://www.namesilo.com','https://ote.namesilo.com',$o),$cmd,$p,$t);
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{RequestOrigin,Support};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\{DnsManagement,Dns};
use Paymenter\Extensions\Servers\Domains\Services\ManagementProgress;
expect(is_file(dirname(__DIR__).'/Domains/Registrars/NameSilo/DnsManagement.php'),'Dormant DNS adapter executes only independently granted deltas');
$wire=(object)['records'=>[],'writes'=>[],'fault'=>'','next'=>0,'lists'=>0];
$dnsExchange=static function($origin,$cmd,$p,$timeout)use($wire,$managementExchange):string {
    if(!str_starts_with($cmd,'dns')){return $managementExchange($origin,$cmd,$p,$timeout);}
    same(0,DB::transactionLevel(),'DNS wire remains outside holding locks');$reply=['code'=>300,'detail'=>'synthetic'];
    if($cmd==='dnsListRecords'){
        $wire->lists++;$rows=[];
        foreach($wire->records as $r){$rows[]=['record_id'=>$r['record_id'],'type'=>$r['type'],'host'=>($r['name']==='@'?'':$r['name'].'.').$p['domain'],'value'=>$r['address'],'ttl'=>$r['ttl'],'distance'=>$r['mx_pref']];}
        if($wire->fault!=='missing'){$reply['resource_record']=$rows;}
        if($wire->fault==='partial'){$reply['extra']='unknown';}
    }else{
        $wire->writes[]=$cmd;$old=null;
        if(isset($p['rrid'])){foreach($wire->records as $i=>$r){if($r['record_id']===$p['rrid']){$old=$r;unset($wire->records[$i]);}}}
        if($cmd!=='dnsDeleteRecord'){
            $id='made'.++$wire->next;$row=['name'=>$p['rrhost']===''?'@':$p['rrhost'],'type'=>$p['rrtype']??$old['type'],'address'=>$p['rrvalue'],'mx_pref'=>$p['rrdistance']??10,'record_id'=>$id,'ttl'=>$p['rrttl']];$wire->records[]=$row;
            if($wire->fault==='duplicate'){$wire->records[]=array_replace($row,['record_id'=>'duplicate']);}
            $reply['record_id']=$wire->fault==='wrongID'?'wrong':$id;
        }
        $wire->records=array_values($wire->records);
        if($wire->fault==='lost'){throw new RuntimeException('Synthetic lost DNS response');}
    }
    return json_encode(['request'=>['operation'=>$cmd],'reply'=>$reply],JSON_THROW_ON_ERROR);
};
$a=['name'=>'@','type'=>'A','address'=>'192.0.2.1','mx_pref'=>10,'record_id'=>'original','ttl'=>300];
$caa=['name'=>'@','type'=>'CAA','address'=>'0:issue:ca.example.com','mx_pref'=>10,'record_id'=>'opaque','ttl'=>600];
$setup=static function($label,array $rows)use($holding,&$binding,$wire,$dnsExchange){[$d,$ordinary]=$holding($label);same(Support::Unsupported,$ordinary->capabilities()->get('dns')->support,'Installed ordinary adapter still closes DNS editing');\App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>true]);$wire->records=$rows;$wire->writes=[];$wire->fault='';return [$d,new DnsManagement($binding,$dnsExchange)];};
[$d,$driver]=$setup('dns-durable',[$a,$caa]);$want=array_replace($a,['address'=>'192.0.2.2']);$add=['name'=>'verify','type'=>'TXT','address'=>'  "synthetic"  ','mx_pref'=>10,'ttl'=>7207];
$op=$submit($d,$driver,'dns',['records'=>[$want,$add]]);same([],$wire->writes,'One save reads inventory but sends no mutation');
$children=$d->operations()->where('action','dns')->orderBy('id')->get();same(2,$children->count(),'Update and add have separate durable grants');
$wire->fault='lost';$management->run($op->id,$driver);same(['dnsUpdateRecord'],$wire->writes,'Lost update is sent only once');
$wire->fault='';$ms->route='apibatch';$driver=new DnsManagement($binding,$dnsExchange);$management->run($op->id,$driver);
same('succeeded',$children->first()->fresh()->state,'Unique new ID readback recovers the update without its response');same(['dnsUpdateRecord'],$wire->writes,'Recovery only reads');
$proof=$children->first()->fresh()->attempt['management_reads'];$latest=end($proof);same('made1',$latest['facts']['sequence_preimage']['records'][1]['record_id'],'Replacement ID is durably retained for remaining preimage');
$ms->route='api';Cache::flush();same('succeeded',$management->run($op->id,$driver)->state,'Verified predecessor permits the separately granted add');
same(['dnsUpdateRecord','dnsAddRecord'],$wire->writes,'Exactly one provider mutation per delta');
expect(in_array($caa,$wire->records,true),'Unsupported CAA row and TTL remain unchanged');same(3,count($wire->records),'Only one requested TXT is added');
$made=array_values(array_filter($wire->records,fn($r)=>$r['type']==='TXT'))[0];same([$add['address'],7207],[$made['address'],$made['ttl']],'TXT bytes and explicit TTL survive');
$management->run($op->id,$driver);same(2,count($wire->writes),'No completed DNS replay');
foreach(['missing','partial'] as $fault){[$d,$driver]=$setup('inventory-'.$fault,[$a]);$wire->fault=$fault;try{$submit($d,$driver,'dns',['records'=>[]]);throw new LogicException('Incomplete preimage accepted');}catch(DomainException){}same([],$wire->writes,'Incomplete inventory creates no writer');same(0,$d->operations()->where('action','dns')->count(),'Incomplete preimage leaves no partial sequence');}
foreach(['duplicate','wrongID'] as $fault){[$d,$driver]=$setup('dns-'.$fault,[]);$op=$submit($d,$driver,'dns',['records'=>[$add]]);$wire->fault=$fault;same('uncertain',$management->run($op->id,$driver)->state,'Ambiguous or mismatched receipt cannot complete');$wire->fault='';$ms->route='apibatch';$management->reconcile($op->id,$driver);same('uncertain',$op->fresh()->state,'Readback cannot silently replace the original receipt or choose duplicate IDs');same(['dnsAddRecord'],$wire->writes,'Unknown add never repeats');expect(str_contains(ManagementProgress::for($d)['dns']['text'],'Contact support'),'Unknown DNS gives the shared support next step');}
[$d,$driver]=$setup('final-delete',[$a]);$op=$submit($d,$driver,'dns',['records'=>[]]);$wire->fault='lost';same('uncertain',$management->run($op->id,$driver)->state,'Lost deletion stays unknown');$ms->route='apibatch';$wire->fault='missing';same('uncertain',$management->reconcile($op->id,$driver)->state,'Missing inventory cannot prove empty');$wire->fault='';same('succeeded',$management->reconcile($op->id,$driver)->state,'Explicit empty inventory proves final deletion');same(['dnsDeleteRecord'],$wire->writes,'Final delete is never replayed');
// An exact synthetic reservation refuses before wire consumption, after the engine seal.
$refusal=static function()use(&$binding,$dnsExchange,$wire):DnsManagement {
    $reservation=new class($binding) implements \Paymenter\Extensions\Servers\Domains\Contracts\WriteReservation {
        public ?int $id=null;public bool $refused=false;
        public function __construct(private $binding){}
        public function bindsTo($b):bool{return $this->binding->matches($b);}
        public function run(string $feature,int $id,Closure $work):mixed {$this->id=$id;try{return $work();}finally{$this->id=null;}}
        public function reserve(string $command,array $parameters,?\Paymenter\Extensions\Servers\Domains\Data\Money $cost=null):void{}
        public function unconsumed(int $id):bool{return $this->id===$id && $this->refused;}
    };
    $driver=new DnsManagement($binding,static function($o,$cmd,$p,$t)use($dnsExchange,$wire,$reservation):string {
        if ($wire->fault==='prewire' && in_array($cmd,['dnsAddRecord','dnsUpdateRecord','dnsDeleteRecord'],true)) {$reservation->refused=true;throw new RuntimeException('Synthetic unconsumed reservation');}
        $reservation->refused=false;return $dnsExchange($o,$cmd,$p,$t);
    });$driver->useReservation($reservation);return $driver;
};
foreach([false,true] as $partial) {
    [$d,$driver]=$setup('unsent-'.($partial?'partial':'first'),[$a]);$wire->next=0;
    $driver=$refusal();$op=$submit($d,$driver,'dns',['records'=>[$want,$add]]);$ms->route='apibatch';
    if($partial){$management->run($op->id,$driver,RequestOrigin::Background);Cache::flush();}
    $wire->fault='prewire';$management->run($op->id,$driver,RequestOrigin::Background);
    $stuck=$d->operations()->where('action','dns')->where('safe_code','management_definitely_unsent')->sole();$evidence=$stuck->attempt;
    same('sealed',$evidence['seal'],'Unconsumed child retains its original seal');
    expect(!ManagementProgress::for($d)['dns']['pending'],'Proven-unsent child restores the existing save control');
    $wire->fault='';$ms->route='api';$driver=new DnsManagement($binding,$dnsExchange);Cache::flush();
    $desired=$partial?[$wire->records[0],$add]:[$want,$add];$fresh=$submit($d,$driver,'dns',['records'=>$desired]);
    expect($fresh->id!==$op->id,'Ordinary fresh save replaces the proven-unsent sequence');
    same('rejected',$stuck->fresh()->state,'Proven-unsent child closes locally');
    same($evidence,array_diff_key($stuck->fresh()->attempt,['management_terminal'=>true]),'Closing preserves every seal and reservation proof byte');
    $management->run($fresh->id,$driver);Cache::flush();$management->run($fresh->id,$driver);
    same('succeeded',$fresh->fresh()->state,'Fresh sequence finishes only the remaining deltas');
    same(['dnsUpdateRecord','dnsAddRecord'],$wire->writes,'Completed prefix and unsent old child never replay');
    $before=$wire->writes;$management->run($op->id,$driver);$management->run($stuck->id,$driver);same($before,$wire->writes,'Retired descriptors have no grant');
}
[$d,$driver]=$setup('unsent-preparation',[$a]);$op=$submit($d,$driver,'dns',['records'=>[$want]]);$wire->records[0]['ttl']=301;
$management->run($op->id,$driver);same('held',$op->fresh()->state,'Changed provider preimage refuses before send');
$fresh=$submit($d,$driver,'dns',['records'=>[$want]]);expect($fresh->id!==$op->id,'Fresh ordinary save replaces a failed unsent preparation');
Cache::flush();same('succeeded',$management->run($fresh->id,$driver)->state,'New snapshot permits the intended edit');same(['dnsUpdateRecord'],$wire->writes,'Unsent preparation cannot duplicate a mutation');
// Genuine admitted physical handoff, with namespace cURL fakes and no sockets.
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\AdmittedTransport;
foreach(['dnsListRecords'=>'catalogue','dnsAddRecord'=>'management','dnsUpdateRecord'=>'management','dnsDeleteRecord'=>'management'] as $command=>$feature) {
    $actual=null;try{$actual=AdmittedTransport::feature($command);}catch(\Paymenter\Extensions\Servers\Domains\Services\LiveReservationLimit){}
    same($feature,$actual,'DNS physical admission classifies each child and inventory read');
}
require __DIR__.'/Support/namesilo-write-wire.php';
\Paymenter\Extensions\Servers\Domains\Services\LiveReservationSchema::install();
[$d]=$setup('dns-physical',[$a,$caa]);$wire->next=0;
$transport=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\nsWriteTransport($binding,$clock);
$driver=new DnsManagement($binding,$transport->exchange());$driver->useReservation($transport->reservation);
$GLOBALS['nsReply']=static fn($cmd,$p)=>$dnsExchange('https://www.namesilo.com/'.$ms->route,$cmd,$p,25000);
$usage=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\nsUsage($binding);
$financial=static fn()=>array_map(fn($t)=>DB::table($t)->orderBy('id')->get()->toJson(),['invoices','invoice_items','invoice_transactions','credits','jobs','failed_jobs']);$money=$financial();
$op=$submit($d,$driver,'dns',['records'=>[$want,$add]]);$first=$d->operations()->where('action','dns')->orderBy('id')->first();$GLOBALS['nsOperation']=$first->id;
$ms->route='apibatch';$wire->fault='lost';$management->run($op->id,$driver,RequestOrigin::Background);same('uncertain',$first->fresh()->state,'Physical lost reply retains its original consumed grant');
$wire->fault='';$driver=new DnsManagement($binding,$transport->exchange());$driver->useReservation($transport->reservation);
$management->run($op->id,$driver,RequestOrigin::Background);same('succeeded',$first->fresh()->state,'Physical recovery reads the exact independent changed inventory');
Cache::flush();$GLOBALS['nsOperation']=$op->id;same('succeeded',$management->run($op->id,$driver,RequestOrigin::Background)->state,'Final DNS child consumes its separately sealed physical grant');
same(['dnsUpdateRecord','dnsAddRecord'],array_values(array_filter($GLOBALS['nsPhysical'],fn($cmd)=>AdmittedTransport::feature($cmd)==='management')),'Exactly two physical mutations, no replay');
$after=\Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\nsUsage($binding);same($usage['used']+12,$after['used'],'Every physical inventory/ownership read and mutation is accounted once');same($usage['spend_micros'],$after['spend_micros'],'DNS grants spend no money');same($money,$financial(),'DNS sequence does not create invoices, credits or another queue');
$physical=$GLOBALS['nsPhysical'];$management->run($op->id,$driver,RequestOrigin::Background);same($physical,$GLOBALS['nsPhysical'],'No physical completion replay');
$nativeSuccessful=true;echo "Dormant DNS durable execution passed\n";
