<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
use App\Models\{Order,Service,User};
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{ContactRecovery,ContactSetup,HoldingIdentityLock,HoldingLocks};
// A live paid request proves each fixture would otherwise be admissible.
foreach(['services','orders','users'] as $table){foreach(['before_claim','during_preflight'] as $phase){
    $op=$new('missing-'.$table.'-'.str_replace('_','-',$phase).'.com');
    $nativeControls=static function()use($op,$user):void {
        $s=Service::findOrFail($op->service_id);
        $rows=DB::transaction(fn()=>HoldingLocks::take($op->domain));
        same((int)$s->id,(int)$rows['service']->id,'Healthy holding authority succeeds');
        $owners=DB::transaction(fn()=>HoldingIdentityLock::lockForAdoption((int)$s->order_id,(int)$s->id)->owners((int)$user->id));
        same((int)$user->id,$owners['order_user_id'],'Healthy adoption identity succeeds');
    };
    $nativeControls();
    $raw=$op->getAttributes();expect($claims->claim($op->id)!==null,'Healthy paid request can claim');
    DB::table('domains_operations')->where('id',$op->id)->update($raw);
    ContactSetup::recoveryWrite(fn()=>$op->fresh()->update(['state'=>'held','safe_code'=>'contact_details_required']));
    same('queued',ContactRecovery::replace($user,$op->id,'Healthy recovery control')->state,'Healthy readmission works');
    DB::table('domains_operations')->where('id',$op->id)->update($raw);
    $service=Service::findOrFail($op->service_id);$id=match($table){'services'=>$service->id,'orders'=>$service->order_id,'users'=>$user->id};
    $row=(array)DB::table($table)->where('id',$id)->sole();
    // Isolate each missing-parent validator. Real event-enabled FK cascade is tested separately.
    $remove=static function()use($table,$id):void {
        DB::statement('PRAGMA foreign_keys = OFF');
        try{DB::table($table)->where('id',$id)->delete();}finally{DB::statement('PRAGMA foreign_keys = ON');}
    };
    if($phase==='before_claim'){$remove();}else{$preflight->during=$remove;}
    $money=$nativeMoney();$writes=$writer->calls;$checks=$preflight->calls;
    try{$engine->execute($op->id);}catch(DomainException|Illuminate\Database\Eloquent\ModelNotFoundException){}
    $preflight->during=null;
    same($writes,$writer->calls,'Missing '.$table.' cannot spend');
    same($checks+($phase==='during_preflight'?1:0),$preflight->calls,'No preclaim provider preflight for missing '.$table);
    $held=$op->fresh();expect($held->state!=='running'&&$held->lease_expires_at===null,'Missing parent has no live claim');
    same($phase==='before_claim'?null:'absent',$held->attempt['seal']??null,'No seal after missing parent');
    if($phase==='before_claim'){same([], $held->attempt??[],'No claim was created');}
    same($money,$nativeMoney(),'Missing parent never mutates native money/jobs');
    foreach([
        fn()=>DB::transaction(fn()=>HoldingLocks::take($op->domain)),
        fn()=>DB::transaction(fn()=>HoldingIdentityLock::lockForAdoption((int)$service->order_id,(int)$service->id)->owners($user->id))
    ] as $attempt){$refused=false;try{$attempt();}catch(DomainException|Illuminate\Database\Eloquent\ModelNotFoundException){$refused=true;}expect($refused,'Missing '.$table.' refuses native authority');}
    // Isolate missing identity from the separate already-attempted recovery prohibition.
    DB::table('domains_operations')->where('id',$op->id)->update($raw);
    ContactSetup::recoveryWrite(fn()=>$op->fresh()->update(['state'=>'held','safe_code'=>'contact_details_required']));
    $before=$op->fresh()->getAttributes();$refused=false;
    try{ContactRecovery::replace($user,$op->id,'Missing parent recovery');}catch(DomainException|Illuminate\Database\Eloquent\ModelNotFoundException){$refused=true;}
    expect($refused,'Missing '.$table.' refuses contact readmission');same($before,$op->fresh()->getAttributes(),'Readmission retains exact request');
    $checks=$preflight->calls;try{$engine->execute($op->id);}catch(DomainException|Illuminate\Database\Eloquent\ModelNotFoundException){}
    same([$writes,$checks],[$writer->calls,$preflight->calls],'Retry cannot readmit or dispatch missing identity');
    DB::table($table)->insert($row);
    same('queued',ContactRecovery::replace($user,$op->id,'Restored identity control')->state,'Restored parent can readmit');
    $nativeControls();
}}
echo "Missing User, Order and Service fail closed before claim and after preflight: no send, seal or readmission.\n";
$nativeSuccessful=true;
