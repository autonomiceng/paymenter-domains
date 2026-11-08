<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Filament\Notifications\Notification;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts;
staffPanel();
$staff=staffUser([Staff::VIEW,Staff::COSTS,'admin.domains.manage','admin.domains.fund']);
$hold=static function(string $name)use($makeRenewal,$user,$staff,$clock):array {
    ComplimentaryAccounts::set($user->id,$staff,false,'Prepare ordinary original registration',$clock);
    [$op,$d]=$makeRenewal(placeholderComFixtureName($name),false,1,false,static fn()=>ComplimentaryAccounts::set($user->id,$staff,true,'Gift account',$clock));
    $op->update(['state'=>'held','safe_code'=>'registrar_price_increased','policy'=>[...$op->policy,'complimentary_cost_review'=>['amount'=>'8.01','usual'=>'8.00']]]);
    return [$op,$d];
};
$failures=[];
$check=static function(string $name,Closure $test)use(&$failures):void {
    try{$test();}catch(Throwable $e){
        $root=$e;while($root->getPrevious()){$root=$root->getPrevious();}
        $frames=array_filter($root->getTrace(),static fn($f)=>str_contains($f['file']??'', '/Domains/'));
        $paths=array_map(static fn($f)=>basename($f['file']).':'.$f['line'],$frames);
        $failures[]=$name.': '.$e->getMessage().' '.implode(', ',$paths);
    }
};
[$op,$d]=$hold('approval-success-placeholder.com');
$check('Approval feedback',static function()use($staff,$d,$op):void {
    Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->callAction('approveComplimentaryCost',['reason'=>'Approved exact registrar quote'])->assertHasNoActionErrors()->assertNotified('Registrar cost approved');
    same('queued',$op->fresh()->state,'Successful approval releases its held request');
});
[$stale,$d]=$hold('approval-stale-placeholder.com');
$check('Stale approval feedback',static function()use($staff,$d,$stale):void {
    $page=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->mountAction('approveComplimentaryCost')->setActionData(['reason'=>'Approve shown quote']);
    $change=true;
    DB::listen(static function($q)use(&$change,$stale):void {
        if($change&&DB::transactionLevel()===0&&str_starts_with($q->sql,'select * from "domains_operations" where "domains_operations"."id" =')){
            $change=false;DB::table('domains_operations')->where('id',$stale->id)->update(['safe_code'=>'staff_review_required']);
        }
    });
    $before=DB::table('domains_complimentary_approvals')->count();
    $page->callMountedAction()->assertHasNoActionErrors()->assertNotified(Notification::make()->danger()->title('Could not approve registrar cost')->body('Reload the domain and check the current registrar cost and hold.'));
    expect(!$change,'The request changed before the locked approval read');
    same('staff_review_required',$stale->fresh()->safe_code,'A different hold is not released');
    same($before,DB::table('domains_complimentary_approvals')->count(),'A stale approval adds no audit entry');
});
// Native rendering and dispatch are required: a service-only check cannot detect a cost leak in either modal or callout.
$all=[Staff::VIEW,Staff::COSTS,'admin.domains.manage','admin.domains.fund'];
[$restricted,$d]=$hold('approval-cost-hidden-placeholder.com');
foreach([[Staff::VIEW,'admin.domains.fund'],[Staff::VIEW,'admin.domains.manage','admin.domains.fund']] as $permissions){
    $check('Costs hidden without cost permission '.count($permissions),static function()use($staff,$d,$permissions):void {
        $staff->role()->first()->update(['permissions'=>$permissions]);
        Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])
            ->assertActionHidden('approveComplimentaryCost')->assertDontSee('Needs approval: registrar cost')
            ->assertSee('Ask staff with access to domain costs to review it.');
    });
}
$staff->role()->first()->update(['permissions'=>$all]);
[$revoked,$d]=$hold('approval-cost-revoked-placeholder.com');
$check('Cost permission revoked after modal mount',static function()use($staff,$d,$revoked,$all):void {
    $page=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->mountAction('approveComplimentaryCost')
        ->setActionData(['reason'=>'Approve the displayed cost']);
    $change=true;$withoutCosts=array_values(array_diff($all,[Staff::COSTS]));
    DB::listen(static function($q)use(&$change,$revoked,$staff,$withoutCosts):void {
        if($change&&DB::transactionLevel()===0&&str_starts_with($q->sql,'select * from "domains_operations" where "domains_operations"."id" =')&&in_array($revoked->id,$q->bindings,true)){
            $change=false;$staff->role()->first()->update(['permissions'=>$withoutCosts]);
        }
    });
    $before=DB::table('domains_complimentary_approvals')->count();
    $page->callMountedAction()->assertHasNoActionErrors()->assertNotified(Notification::make()->danger()->title('Could not approve registrar cost')->body('Reload the domain and check the current registrar cost and hold.'));
    expect(!$change,'Cost access was revoked after action selection and before the locked permission read');
    same('held',$revoked->fresh()->state,'Revoked cost access cannot release the request');
    same($before,DB::table('domains_complimentary_approvals')->count(),'Revoked cost access adds no approval audit');
});
$staff->role()->first()->update(['permissions'=>$all]);
[$broken,$d]=$hold('approval-unreadable-placeholder.com');
DB::table('domains_operations')->where('id',$broken->id)->update(['policy'=>'unreadable-retained-policy']);
$check('Unreadable cost details',static function()use($staff,$d):void {
    Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertSee('Registrar cost details are unavailable.')->assertActionHidden('approveComplimentaryCost');
});
expect($failures===[],implode("\n",$failures));
echo "Native approval feedback, stale hold and unreadable policy passed\n";$nativeSuccessful=true;
