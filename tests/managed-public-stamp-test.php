<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Dns\DnsReading;
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicObservation,ManagedPublicConfig};
use Paymenter\Extensions\Servers\Domains\Services\{ManagedPublicSignals,ManagedPublicWorkSchema};
extract(managedFixture());$id=(int)$d->id;$uid=(int)$owner->id;$now=$time->now;
expect($signals->enqueue($id,$uid),'Stamp fixture enrolls');$fake->answers['example.com|NS']=managedNs();
expect($signals->observe($id,$uid,'delegation'),'Baseline public fact');$count=ManagedPublicObservation::count();
foreach([
    ['domains_domains',$id,['authority_generation'=>$d->authority_generation+1],['authority_generation'=>$d->authority_generation]],
    ['domains_domains',$id,['user_id'=>$other->id],['user_id'=>$uid]],
    ['domains_domains',$id,['account_fingerprint'=>str_repeat('f',64)],['account_fingerprint'=>$d->account_fingerprint]],
    ['domains_domains',$id,['confirmed_revision'=>$d->confirmed_revision+1],['confirmed_revision'=>$d->confirmed_revision]],
    ['services',$service->id,['user_id'=>$other->id],['user_id'=>$uid]],
    ['domains_watch_profiles',$profile->id,['revision'=>3],['revision'=>2]],
    ['domains_managed_public_config',$id,['revision'=>2],['revision'=>1]],
]as[$table,$key,$changes,$restore]) {
    managedDue($id,$now);$field=$table==='domains_managed_public_config'?'domain_id':'id';
    $fake->answers['example.com|NS']=function()use($table,$key,$changes,$field){same(0,DB::transactionLevel(),'Mutation happens outside locks');DB::table($table)->where($field,$key)->update($changes);return managedNs();};
    expect(!$signals->observe($id,$uid,'delegation'),'Stale target discarded: '.$table);same($count,ManagedPublicObservation::count(),'Changed stamp cannot append observations');
    DB::table($table)->where($field,$key)->update($restore);
}
managedDue($id,$now);$first=$signals->begin($id,$uid,'delegation',$now);expect($first!==null,'First synthetic claim');
$second=$signals->begin($id,$uid,'delegation',$now+121);same(null,$second,'Abandoned managed read enters backoff');$retry=(int)DB::table('domains_managed_public_work')->where('domain_id',$id)->where('kind','delegation')->value('due_at');expect($retry>=$now+1021,'Abandoned managed read yields owner turn');$now=$retry-121;$second=$signals->begin($id,$uid,'delegation',$retry);expect($second!==null,'Expired lease reclaimed after backoff');
expect(!$signals->finish($first,new DnsReading('delegation','external'),$now+122),'Old claim cannot overwrite replacement');
expect($signals->finish($second,new DnsReading('delegation','platform'),$now+122),'Replacement exact claim commits');
managedDue($id,$now+122);$late=$signals->begin($id,$uid,'delegation',$now+122);expect($late!==null,'Deadline fixture');
expect(!$signals->finish($late,new DnsReading('delegation','external'),$now+167),'Forty-five second deadline rejects values');
$time->now=$now+180;DB::transaction(function()use($id){$c=ManagedPublicConfig::find($id);$c->profile_id=null;$c->revision++;$c->save();});
managedDue($id,$time->now);$calls=count($fake->calls);expect($signals->observe($id,$uid,'delegation'),'Missing profile records unknown');
$last=ManagedPublicObservation::orderByDesc('id')->first();same('unknown',$last->result,'Profile absence remains unknown');same(null,$last->dns,'No registrar defaults inferred');same($calls,count($fake->calls),'No profile performs no DNS');
DB::table('services')->where('id',$service->id)->update(['user_id'=>$other->id]);
expect(!$signals->enqueue($id,$uid,$time->now),'Moved native owner retires managed work');
expect(DB::table(ManagedPublicWorkSchema::TABLE)->whereNull('retired_at')->doesntExist(),'Moved holding cannot keep active work');
$nativeSuccessful=true;echo "Managed owner/generation/revision/profile stamp discard, claim replacement, deadline and profile absence passed.\n";
