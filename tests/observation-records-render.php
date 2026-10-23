<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Actual native observer and owner presenter.
declare(strict_types=1);
require_once __DIR__.'/Support/reserved-fixtures.php';
require __DIR__.'/Support/owner-records.php';
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationSchema};
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
$out=$argv[1];$shell=file_get_contents($argv[2]);storefrontViews();ObservationSchema::install();
[$d,$driver]=$holding(placeholderComFixtureName(str_pad('placeholder',63,'a').'.com'));
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$clearRecords=static function()use(&$d):void{$fresh=$d->fresh();$p=$fresh->observation??[];unset($p['record_hold']);$fresh->update(['observation'=>$p]);};
$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports);
$state->dns=[['name'=>'@','type'=>'TXT','address'=>str_repeat('exact TXT value ',6),'mx_pref'=>10],['name'=>'mail','type'=>'MX','address'=>str_repeat('m',63).'.example.com.','mx_pref'=>10]];
$state->srv=[['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>5,'port'=>443,'target'=>str_repeat('t',63).'.example.com.']];
$state->ds=[['algorithm'=>13,'digest_type'=>2,'key_tag'=>123,'digest'=>str_repeat('A',64)]];
$emit=static function(string $stateName)use(&$d,$owners,$out,$shell):void {
 $dto=$owners->present($d->fresh());storefrontViews();app('view')->getFinder()->flush();
 foreach(['dns','srv','ds','glue']as$kind){
  $copy=new OwnedDomain(...[...get_object_vars($dto),'management'=>[$kind=>$dto->management[$kind]]]);
  $html=view('domains::show',['domain'=>$copy,'keys'=>array_fill_keys(\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::ACTIONS,str_repeat('a',64))])->render();
  preg_match('#<body>(.*)</body>#s',$html,$body);$content=$body[1]??$html;
  writeRenderArtifact($out.'/'.$kind.'-'.$stateName.'.html',str_replace('@@SLOT@@',$content,$shell));
 }
};
$observer->run($d->id);
$op=$submit($d,$driver,'glue_add',['host'=>'ns1.'.$d->domain,'ip'=>'8.8.8.8']);$management->run($op->id,$driver);$emit('complete');
$state->fault='GetHosts';$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);$emit('unknown');
$clearRecords();$state->fault='GetDomainSRVHosts';$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);$emit('partial');
$clearRecords();$state->fault='';$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);
$saved=$d->fresh()->observation;$stale=$saved;$stale['records']['identity']['generation']=0;
$d->fresh()->update(['observation'=>$stale]);$emit('stale');$d->fresh()->update(['observation'=>$saved]);
$state->dns=array_map(fn($i)=>['name'=>'record'.$i,'type'=>'TXT','address'=>str_repeat('TXT bytes ',8),'mx_pref'=>10],range(1,50));
$state->srv=array_map(fn($i)=>['service'=>'sip'.$i,'protocol'=>'tcp','priority'=>0,'weight'=>5,'port'=>443,'target'=>'sip.example.com.'],range(1,50));
$state->ds=array_map(fn($i)=>['algorithm'=>13,'digest_type'=>2,'key_tag'=>$i,'digest'=>str_repeat('A',64)],range(1,50));
foreach(range(2,12)as$i){Illuminate\Support\Facades\Cache::flush();$op=$submit($d,$driver,'glue_add',['host'=>'ns'.$i.'.'.$d->domain,'ip'=>'8.8.8.8']);$management->run($op->id,$driver);}
$clock->instant=$clock->now()->modify('+61 seconds');$observer->run($d->id);$emit('many');
$nativeSuccessful=true;echo "Native record observation pages rendered.\n";
