<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/managed-public.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{ManagedPublicObservation,WatchName};
use Paymenter\Extensions\Servers\Domains\Services\{ManagedPublicSignals,ManagedPublicReconciliation,ManagedPublicWorkSchema,PublicDnsQuota};
extract(managedFixture(hosting:false));$id=(int)$d->id;$uid=(int)$owner->id;$now=$time->now;
$off=new ManagedPublicReconciliation(new ManagedPublicSignals($fake));same(0,$off->sweep($now),'Default-off sweep does not accept A3 rows');
$sweep=new ManagedPublicReconciliation($signals);same(1,$sweep->sweep($now,1),'Bounded daily pass accepts old A3 rows');same(0,WatchName::count(),'Source-free managed holding never creates watch');
$work=DB::table(ManagedPublicWorkSchema::TABLE)->where('kind','delegation')->first();same('ready',$work->safe_code,'Existing A3 dormant row accepted only in enabled sweep');
$fail=true;
DB::listen(static function($q)use(&$fail){
    if ($fail && str_contains($q->sql,'domains_managed_public_config') && str_starts_with($q->sql,'select')) {
        $fail=false;throw new Illuminate\Database\QueryException('sqlite',$q->sql,[],new RuntimeException('Synthetic lock timeout'));
    }
});
expect(!$signals->enqueue($id,$uid,$now),'Transient enrollment database failure returns safely');expect(!$fail,'Synthetic failure injected');
expect(DB::table(ManagedPublicWorkSchema::TABLE)->whereNull('retired_at')->count()===2,'Transient failure never retires valid work');
same(2,count($sweep->due($now)),'Transient failure leaves durable work dispatchable');
$old=(array)$work;unset($old['id']);$old['generation']=0;$old['safe_code']='ready';DB::table(ManagedPublicWorkSchema::TABLE)->insert($old);
expect($signals->enqueue($id,$uid,$now),'Current generation repaired');expect(DB::table(ManagedPublicWorkSchema::TABLE)->where('generation',0)->value('retired_at')!==null,'Prior generation retired atomically');
DB::table(ManagedPublicWorkSchema::TABLE)->where('generation',0)->update(['retired_at'=>null]);
$hints=$sweep->due($now);same(2,count($hints),'At most two kinds per owner');same(['delegation','points'],array_column($hints,'kind'),'No RDAP kind');
// A permanently broken native marker must not consume both owner hints forever.
$marker=$product->settings()->where('key','domains_action')->sole();$savedMarker=$marker->getRawOriginal();
DB::table('settings')->where('id',$marker->id)->delete();
$second=(array)$d->fresh()->getRawOriginal();$second['id']=2000;$second['domain']='second.com';$second['reservation_name']='second.com';$second['sld']='second';$second['service_id']=2000;
DB::table('domains_domains')->insert($second);
foreach(['delegation','points']as$kind) { $copy=(array)$work;unset($copy['id']);$copy['domain_id']=2000;$copy['kind']=$kind;DB::table(ManagedPublicWorkSchema::TABLE)->insert($copy); }
foreach(['delegation','points']as$kind) { expect(!$signals->observe($id,$uid,$kind),'Broken marker safely yields dispatch turn'); }
$blocked=DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('generation',$d->authority_generation)->get();
foreach($blocked as$row) { same(null,$row->retired_at,'Unavailable authority retained for repair');same(1,(int)$row->attempts,'Unavailable capture advances failure count');expect($row->due_at>=$now+900,'Unavailable capture backs off');same('authority_unavailable',$row->safe_code,'Unavailable authority has distinct safe reason'); }
same([2000,2000],array_column($sweep->due($now),'domain_id'),'Other holding of same owner gets next dispatch turn');
DB::table('settings')->insert($savedMarker);DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',2000)->delete();DB::table('domains_domains')->where('id',2000)->delete();
DB::table(ManagedPublicWorkSchema::TABLE)->where('domain_id',$id)->where('generation',$d->authority_generation)->update(['due_at'=>$now,'backoff_until'=>null]);
$fake->answers['example.com|NS']=managedNs();expect($signals->observe($id,$uid,'delegation'),'Apex without any hosting source observed');same(['example.com|NS'],$fake->calls,'No registrar inventory or hosting lookup required');
same(0,$sweep->sweep($now,1),'Completed pass resets daily cursor');same($now+86400,(int)DB::table(PublicDnsQuota::TABLE)->value('sweep_due_at'),'Daily revisit durable');
same(0,$sweep->sweep($now+1),'No unbounded repeat pass');
DB::table('domains_domains')->where('id',$id)->delete();same(0,$sweep->sweep($now+86400),'Missing ledger row swept');
expect(DB::table(ManagedPublicWorkSchema::TABLE)->whereNull('retired_at')->doesntExist(),'Deleted holding work retired');same(1,ManagedPublicObservation::count(),'Deleted holding facts retained');
$owners=[];for($i=0;$i<7;$i++) {
    $u=staffUser([]);$owners[]=(int)$u->id;$key=1000+$i;
    DB::table('domains_domains')->insert(['id'=>$key,'service_id'=>$key,'user_id'=>$u->id,'server_id'=>$d->server_id,'registrar_key'=>'enom',
        'account_fingerprint'=>$d->account_fingerprint,'domain'=>'fair'.$i.'.com','reservation_name'=>'fair'.$i.'.com','sld'=>'fair'.$i,'tld'=>'com','status'=>'active','authority_generation'=>1]);
    foreach(['delegation','points']as$kind){DB::table(ManagedPublicWorkSchema::TABLE)->insert(['domain_id'=>$key,'user_id'=>$u->id,'generation'=>1,
        'account_fingerprint'=>$d->account_fingerprint,'kind'=>$kind,'due_at'=>$now,'target_stamp'=>str_repeat('a',64),'safe_code'=>'ready']);}
}
$first=$sweep->due($now+86400);$second=$sweep->due($now+86400);
same(10,count($first),'One dispatch turn bounded to ten hints');same(4,count($second),'Next turn serves remaining owners');
same($owners,array_values(array_unique(array_column([...$first,...$second],'user_id'))),'Durable cursor prevents owner starvation');
foreach(array_count_values(array_column($first,'user_id'))as$count){same(2,$count,'At most two hints per owner');}
$nativeSuccessful=true;echo "Source-free apex, daily bounded sweep, old-generation retirement, repair and DNS-only due hints passed.\n";
