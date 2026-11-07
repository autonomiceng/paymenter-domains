<?php
// Full native initial-inventory premise; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/observation-records.php';
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,ManagementRequest,RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationProjection,ObservationRecords,ObservationSchema,ObservationWork,OwnerRecords,ReadDeferred};
[$d,$driver,$original]=$holding(placeholderComFixtureName('discovery-placeholder.com'));storefrontViews();
$clearRecords=static function()use(&$d):void{$fresh=$d->fresh();$p=$fresh->observation??[];unset($p['record_hold']);$fresh->update(['observation'=>$p]);};
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$before=$original->fresh()->getAttributes();$financial=[$d->provider_expires_at->format(DATE_ATOM),$d->confirmed_revision,$d->renewal_policy];
$beforeCount=Operation::count();$controller->manage($post('dns',['records'=>[['name'=>'@','type'=>'A','address'=>'8.8.8.8']],'preimage_digest'=>str_repeat('a',64)]),$d->id,'dns');
same($beforeCount,Operation::count(),'Forged DNS POST cannot bypass initial completeness gate');
$state->dns=[['name'=>'@','type'=>'TXT','address'=>' exact  TXT ','mx_pref'=>10]];
$state->srv=[['service'=>'sip','protocol'=>'tcp','priority'=>0,'weight'=>5,'port'=>443,'target'=>'sip.example.com.']];
$state->ds=[['algorithm'=>13,'digest_type'=>2,'key_tag'=>12,'digest'=>str_repeat('A',64)]];
same('refreshed',$observer->run($d->id,readRecords:true),'Initial record discovery succeeds through real parsers');
$r=$d->fresh()->observation['records'];same(ObservationRecords::identity($d),$r['identity'],'Inventory binds every current identity');
foreach(['dns','srv','ds']as$k){same('complete',$r['families'][$k]['state'],'Explicit complete '.$k);}
same('partial',$r['families']['glue']['state'],'No complete glue enumeration claim');
expect(!str_contains(DB::table('domains_domains')->where('id',$d->id)->value('observation'),'exact  TXT'),'Inventory encrypted at rest');
same(' exact  TXT ',OwnerRecords::forDomain($d->fresh())['dns'][0]['address'],'Exact TXT bytes reach safe DTO');
$io=count($state->calls)+count($observation->calls);$html=$page();
expect(str_contains($html,'/manage/dns')&&str_contains($html,'/manage/srv'),'Complete initial inventory unlocks forms');
same($io,count($state->calls)+count($observation->calls),'GET has no provider transport');
same($before,$original->fresh()->getAttributes(),'Acquisition/financial proof remains byte-identical');
same($financial,[$d->fresh()->provider_expires_at->format(DATE_ATOM),$d->fresh()->confirmed_revision,$d->fresh()->renewal_policy],'Confirmed expiry/revision/anchor retained');
$staleIdentity=ObservationRecords::identity($d);$staleIdentity['generation']=2;$count=Operation::count();
try{$management->request($user,$d->id,'dns',[],hash('sha256','stale-owner-records'),$driver,recordIdentity:$staleIdentity);expect(false,'Locked owner record identity rejects stale admission');}catch(DomainException){}
same($count,Operation::count(),'No owner operation created with stale inventory identity');
