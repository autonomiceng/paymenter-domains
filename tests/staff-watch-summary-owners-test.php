<?php
// Multi-owner staff summaries over synthetic local evidence. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\{Auth,Crypt,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,WatchFindings};
use Paymenter\Extensions\Servers\Domains\Models\WatchName;
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries,DomainSummaryContext,StaffWatchWrites as Writes,WatchNoticePreferences};
use Paymenter\Extensions\Servers\Domains\Dns\DnsReading;
$f=noticeFixture();extract($f);require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
$reader=new DomainSummaries();$viewer=staffUser([Staff::VIEW,Writes::VIEW]);Auth::setUser($viewer);
noticeRegistry($f,$now,100);noticeDns($f,$now,'platform');
DB::table('domains_watch_work')->where('watch_id',$id)->where('kind','points')->update(['due_at'=>$now]);$ticket=$dns->begin($id,$uid,'points',$now);
expect($ticket!==null,'Synthetic points ticket');expect($dns->finish($ticket,new DnsReading('points',website:true,email:true),$now+1),'Synthetic points reading');
$clone=function(string $table,int $from,array $changes=[]):int{$row=(array)DB::table($table)->where('id',$from)->first();unset($row['id']);return (int)DB::table($table)->insertGetId([...$row,...$changes]);};
$sourceId=(int)DB::table('domains_watch_sources')->where('watch_id',$id)->value('id');
$make=function(int $ownerId,string $name,int $state=0)use($clone,$id,$service,$order,$property,$sourceId,$now):int{
    $wid=$clone('domains_watch_names',$id,['user_id'=>$ownerId,'canonical_name'=>$name,'registration'=>'external']);
    $sid=$clone('services',$service->id,['user_id'=>$ownerId,'order_id'=>$clone('orders',$order->id,['user_id'=>$ownerId])]);
    $clone('properties',$property->id,['model_id'=>$sid,'value'=>'www.'.$name]);$clone('domains_watch_sources',$sourceId,['user_id'=>$ownerId,'watch_id'=>$wid,'service_id'=>$sid,'hostname'=>'www.'.$name,'registrable_name'=>$name]);
    foreach(DB::table('domains_watch_work')->where('watch_id',$id)->pluck('id') as $job){$clone('domains_watch_work',(int)$job,['watch_id'=>$wid]);}
    if($state===5){return $wid;}
    $stamp=DomainSummaryContext::evidence('watch',$wid,$ownerId);
    foreach(DB::table('domains_watch_observations')->where('watch_id',$id)->get() as $row){$facts=json_decode(Crypt::decryptString($row->facts),true);$facts['presentation_stamp']=$stamp;
        $changes=['watch_id'=>$wid,'user_id'=>$ownerId,'facts'=>Crypt::encryptString(json_encode($facts,JSON_THROW_ON_ERROR))];
        if($row->check_kind==='delegation' && in_array($state,[1,2],true)){$changes['dns']='external';}
        if($row->check_kind==='points' && $state===3){$changes['partial']=true;$changes['email']=null;}
        if($state===4){$changes['valid_until']=$now+1;}
        $clone('domains_watch_observations',(int)$row->id,$changes);
    }
    $r=DB::table('domains_watch_registry')->where('watch_id',$id)->first();$facts=json_decode(Crypt::decryptString($r->facts),true);
    $facts['presentation_stamp']=DomainSummaryContext::evidence('watch',$wid,$ownerId,'registry');$facts['expiry']=gmdate('Y-m-d',$now+($state===6?-2:($state===7?10:100))*86400);
    if($state===8){$facts['status']=['redemptionPeriod'];}
    $changes=['watch_id'=>$wid,'user_id'=>$ownerId];
    if(in_array($state,[9,10],true)){$changes['result']=$state===9?'not_registered':'unknown';$changes['reason']=$state===9?'not_registered':'no_coverage';$changes['valid_until']=null;$facts['expiry']=null;}
    $clone('domains_watch_registry',(int)$r->id,[...$changes,'facts'=>Crypt::encryptString(json_encode($facts,JSON_THROW_ON_ERROR))]);
    if($state===1){DB::table('domains_watch_notice_state')->insert(['scope'=>WatchNoticePreferences::scope($ownerId,$name),'user_id'=>$ownerId,'data'=>Crypt::encryptString(json_encode(['target'=>['watch',$wid],'portal'=>['dns'=>['ns_changed']],'dns'=>['episode'=>$now]])),'updated_at'=>$now]);}
    if(in_array($state,[11,12],true)){DB::table('domains_watch_names')->where('id',$wid)->update(['state'=>$state===11?'paused':'retired']);}
    return $wid;
};
$count=function(callable $run):int{DB::flushQueryLog();DB::enableQueryLog();$run();$n=count(DB::getQueryLog());DB::disableQueryLog();return $n;};
$owners=[$owner];for($i=1;$i<25;$i++){$owners[]=staffUser([]);}
$groups=[];$before=[];$after=[];$findings=[];
foreach([1,5,25] as $n){$ids=[];for($i=0;$i<25;$i++){$ids[]=$make((int)$owners[$i%$n]->id,'owners'.$n.'-'.$i.'.example',$i%13);}$groups[$n]=$ids;
    $rows=WatchName::whereIn('id',$ids)->get();$perOwner=[];
    // Baseline: the exact per-owner summary calls made by the former staff path, with the same User lookup.
    $before[$n]=$count(function()use($rows,$reader,$now,&$perOwner){$users=\App\Models\User::whereIn('id',$rows->pluck('user_id'))->get()->keyBy('id');foreach($rows->groupBy('user_id') as $uid=>$watches){$perOwner+=$reader->batch($users[$uid],[],$watches->pluck('id')->all(),$now+2);}});
    $staff=[];$after[$n]=$count(function()use($reader,$ids,$now,&$staff){$staff=$reader->staffWatches($ids,$now+2);});
    ksort($perOwner);ksort($staff);same(array_keys($perOwner),array_keys($staff),'Identical summary keys for '.$n.' owners');
    foreach($perOwner as $key=>$summary){same((array)$summary,(array)$staff[$key],'Every DTO field matches for '.$key.' across '.$n.' owners');}
    if($n===1){$raw=function(mixed $v)use(&$raw):mixed{if($v instanceof \Illuminate\Database\Eloquent\Model){$v=$v->getRawOriginal();}if($v instanceof \Illuminate\Support\Collection){$v=$v->all();}if(is_array($v)){ksort($v);return array_map($raw,$v);}return $v;};$shape=function($c)use($raw){$v=get_object_vars($c);unset($v['owner'],$v['staff']);return $raw($v);};$expected=$shape(new DomainSummaryContext((int)$owners[0]->id,[],$ids));$actual=$shape(DomainSummaryContext::staffWatches($ids));same(array_keys($expected),array_keys($actual),'Every context property is present');foreach($expected as $key=>$value){same($value,$actual[$key],'Raw context property matches: '.$key);}}
    $findings[$n]=$count(fn()=>WatchFindings::batch($rows,$now+2));
    same(25,count($staff),'Every mixed-state watch has a summary');
}
same($after[1],$after[5],'Five owners have a constant summary query count');same($after[1],$after[25],'Twenty-five owners have a constant summary query count');
same($findings[1],$findings[5],'Five owners have a constant findings query count');same($findings[1],$findings[25],'Twenty-five owners have a constant findings query count');expect($after[25]<=32,'Bounded absolute summary queries');
// Identical canonical names for different owners must keep notices and observations separate.
$a=$make((int)$owners[0]->id,'shared.example',1);$b=$make((int)$owners[1]->id,'shared.example',0);$pair=[$a,$b];
$check=function(array $ids,string $label)use($reader,$now):array{$all=$reader->staffWatches($ids,$now+2);foreach(WatchName::whereIn('id',$ids)->get() as $w){$u=\App\Models\User::find($w->user_id);$expected=$u?$reader->one($u,'watch',(int)$w->id,$now+2):null;same($expected?(array)$expected:null,isset($all['watch:'.$w->id])?(array)$all['watch:'.$w->id]:null,$label.' '.$w->id);}return $all;};
$clean=$check($pair,'Same name owner isolation');same(['ns_changed'],$clean['watch:'.$a]->noticeKeys,'Owner A notice');same([],$clean['watch:'.$b]->noticeKeys,'Owner B has no A notice');
// Deliberately corrupt retained rows: valid-looking target stamps cannot substitute for row ownership.
$forgedDns=DomainSummaryContext::evidence('watch',$b,(int)$owners[1]->id);$forgedRegistry=DomainSummaryContext::evidence('watch',$b,(int)$owners[1]->id,'registry');
foreach(['domains_watch_observations','domains_watch_registry'] as $table){foreach(DB::table($table)->where('watch_id',$a)->get() as $r){$facts=json_decode(Crypt::decryptString($r->facts),true);$facts['presentation_stamp']=$table==='domains_watch_registry'?$forgedRegistry:$forgedDns;
    if($table==='domains_watch_registry'){$facts['expiry']='2040-01-01';}
    $clone($table,(int)$r->id,['watch_id'=>$b,'facts'=>Crypt::encryptString(json_encode($facts,JSON_THROW_ON_ERROR))]);}}
$isolated=$check($pair,'Foreign-owner latest and last-good evidence excluded');same((array)$clean['watch:'.$b],(array)$isolated['watch:'.$b],'No foreign DNS, registry value or timestamp appears on B');
// A foreign notice row with the other owner's valid scope and target still cannot enter that owner's DTO.
DB::table('domains_watch_notice_state')->insert(['scope'=>WatchNoticePreferences::scope((int)$owners[1]->id,'shared.example'),'user_id'=>$owners[0]->id,'data'=>Crypt::encryptString(json_encode(['target'=>['watch',$b],'portal'=>['dns'=>['ns_changed']],'dns'=>['episode'=>$now]])),'updated_at'=>$now]);
$notices=$check($pair,'Foreign notice owner excluded');same((array)$clean['watch:'.$b],(array)$notices['watch:'.$b],'No foreign notice episode on B');
// A foreign source must not poison the stamp, or leak its hosting link/hostname.
$foreignService=$clone('services',$service->id);$foreign=$clone('domains_watch_sources',$sourceId,['watch_id'=>$b,'service_id'=>$foreignService]);$check($pair,'Foreign source excluded before bounded source selection');DB::table('domains_watch_sources')->where('id',$foreign)->delete();
// A correct-owner source cannot claim another owner's service or order, even when that identity is in this batch.
$bs=DB::table('domains_watch_sources')->where('watch_id',$b)->first();$as=DB::table('domains_watch_sources')->where('watch_id',$a)->first();
$foreignService=$clone('services',(int)$as->service_id,['order_id'=>DB::table('services')->where('id',$bs->service_id)->value('order_id')]);$bp=DB::table('properties')->where('model_type',\App\Models\Service::class)->where('model_id',$bs->service_id)->first();$clone('properties',(int)$bp->id,['model_id'=>$foreignService]);
DB::table('domains_watch_sources')->where('id',$bs->id)->update(['service_id'=>$foreignService]);$broken=$check($pair,'Foreign native service excluded');same([],$broken['watch:'.$b]->relatedServices,'Foreign service link omitted');
DB::table('domains_watch_sources')->where('id',$bs->id)->update(['service_id'=>$bs->service_id]);$bo=DB::table('services')->where('id',$bs->service_id)->value('order_id');$ao=DB::table('services')->where('id',$as->service_id)->value('order_id');
DB::table('services')->where('id',$bs->service_id)->update(['order_id'=>$ao]);$broken=$check($pair,'Foreign native order excluded');same([],$broken['watch:'.$b]->relatedServices,'Foreign order link omitted');DB::table('services')->where('id',$bs->service_id)->update(['order_id'=>$bo]);
// Same-name managed suppression must join owner, service owner and order owner together.
$did=DB::table('domains_domains')->insertGetId(['user_id'=>$owners[0]->id,'service_id'=>$as->service_id,'server_id'=>$server->id,'registrar_key'=>'enom','account_fingerprint'=>str_repeat('f',64),'domain'=>'shared.example','sld'=>'shared','tld'=>'example','status'=>'registration_pending']);
$checked=$check($pair,'Managed suppression is per owner');expect(!isset($checked['watch:'.$a]) && isset($checked['watch:'.$b]),'Only the managed owner loses its watch summary');
DB::table('domains_domains')->where('id',$did)->update(['service_id'=>$bs->service_id]);$checked=$check($pair,'Foreign managed service cannot suppress');expect(isset($checked['watch:'.$a]),'Foreign managed service leaves watch visible');
DB::table('domains_domains')->where('id',$did)->update(['service_id'=>$as->service_id]);DB::table('services')->where('id',$as->service_id)->update(['order_id'=>$bo]);$checked=$check($pair,'Foreign managed order cannot suppress');expect(isset($checked['watch:'.$a]),'Foreign managed order leaves watch visible');DB::table('services')->where('id',$as->service_id)->update(['order_id'=>$ao]);DB::table('domains_domains')->where('id',$did)->delete();
$orphan=$clone('domains_watch_names',$b,['user_id'=>999999,'canonical_name'=>'orphan.example']);same([],$reader->staffWatches([$orphan],$now+2),'Missing native owner is unavailable');same(['staff.unavailable'],WatchFindings::batch([WatchName::find($orphan)],$now+2)[$orphan],'Orphan findings stay unavailable');
same(null,$reader->one($owners[0],'watch',$b,$now+2),'Customer path still refuses a foreign watch');
// Fresh permission checks cover both public staff entry points, including revocation on a retained User object.
$denied=function(callable $run,string $label):void{try{$run();}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){same(403,$e->getStatusCode(),$label);return;}throw new RuntimeException($label);};
Auth::setUser($owners[0]);$denied(fn()=>$reader->staffWatches($pair,$now+2),'Customer cannot invoke staff summary');$denied(fn()=>DomainSummaryContext::staffWatches($pair),'Customer cannot load staff context');$denied(fn()=>WatchFindings::batch([]),'Customer cannot invoke staff findings');
Auth::setUser($viewer);$cachedRole=$viewer->role;$freshRole=\App\Models\Role::findOrFail($cachedRole->id);$freshRole->update(['permissions'=>[Staff::VIEW]]);expect(in_array(Writes::VIEW,$cachedRole->permissions,true),'Retained role still has the revoked permission');$denied(fn()=>$reader->staffWatches($pair,$now+2),'Revoked watch permission takes effect');$freshRole->update(['permissions'=>[Staff::VIEW,Writes::VIEW]]);
try{$reader->staffWatches(range(1,251),$now+2);throw new RuntimeException('Oversized batch accepted');}catch(InvalidArgumentException){}
// A later failed read retains the same independent last-good axes in both paths.
$failure=DB::table('domains_watch_observations')->where('watch_id',$b)->where('user_id',$owners[1]->id)->where('check_kind','points')->first();
$clone('domains_watch_observations',(int)$failure->id,['website'=>null,'email'=>null,'partial'=>true,'valid_until'=>null,'result'=>'unknown','started_at'=>$now+2]);$check($pair,'Failed latest read retains marked last-good values');
// Read-only calls leave all retained rows unchanged.
$tables=['domains_watch_names','domains_watch_sources','domains_watch_work','domains_watch_observations','domains_watch_registry','domains_watch_notice_state'];$snapshot=fn()=>array_map(fn($t)=>DB::table($t)->orderBy('id')->get()->toJson(),array_diff($tables,['domains_watch_notice_state']))+[99=>DB::table('domains_watch_notice_state')->orderBy('scope')->get()->toJson()];
$saved=$snapshot();$reader->staffWatches($groups[25],$now+2);WatchFindings::batch(WatchName::whereIn('id',$groups[25])->get(),$now+2);same($saved,$snapshot(),'Staff projection writes nothing');
// The Status filter crosses the former 250 bound and stops honestly at 1,000 active names.
while(WatchName::where('state','active')->count()<1001){$clone('domains_watch_names',$id,['canonical_name'=>'cap'.WatchName::count().'.example']);}
$active=WatchName::where('state','active')->orderBy('id')->pluck('id')->all();$attention=WatchFindings::statusIds('monitoring');expect(in_array((int)$active[999],$attention,true),'Thousandth active watch evaluated');expect(!in_array((int)$active[1000],$attention,true),'Beyond labelled cap omitted');
$filtered=WatchFindings::filter(WatchName::query(),'monitoring',new class($attention){public function __construct(private array $ids){}public function statusIds(string $status):array{return $status==='monitoring'?$this->ids:[];}})->orderBy('id')->pluck('id')->all();same($attention,$filtered,'Full bounded status ID list executes through the table filter');
$nativeSuccessful=true;echo 'Staff multi-owner summaries passed; 25 rows across 1/5/25 owners: baseline '.implode('/',$before).', summary '.implode('/',$after).', findings '.implode('/',$findings)." queries.\n";
