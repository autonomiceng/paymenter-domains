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
[$notice,$d]=$hold('approval-cost-reader-placeholder.com');
foreach([[Staff::VIEW,Staff::COSTS],[Staff::VIEW,Staff::COSTS,'admin.domains.manage'],[Staff::VIEW,Staff::COSTS,'admin.domains.fund']] as $permissions){
    $check('Cost reader notice '.implode(',',$permissions),static function()use($staff,$d,$permissions):void {
        $staff->role()->first()->update(['permissions'=>$permissions]);
        $page=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertActionHidden('approveComplimentaryCost');
        $html=$page->html();
        expect(str_contains($html,'Needs approval: registrar cost $8.01 is above the usual $8.00.'),'Cost-authorized staff see the approval notice');
        expect(str_contains($html,'Ask staff with domain cost, management and funding permissions to approve this cost.'),'Staff without the full grant are directed to an authorized approver');
        expect(!str_contains($html,'Use Approve registrar cost'),'Notice never offers an approval the current staff cannot perform');
    });
}
$staff->role()->first()->update(['permissions'=>$all]);
[$fractional,$d]=$hold('approval-exact-placeholder.com');
$fractional->update(['policy'=>[...$fractional->policy,'complimentary_cost_review'=>['amount'=>'8.0040','usual'=>'8.0000']]]);
$check('Exact stored approval costs',static function()use($staff,$d,$fractional):void {
    $page=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id]);
    $message='Needs approval: registrar cost $8.0040 is above the usual $8.0000.';
    expect(str_contains($page->html(),$message),'The cost notice preserves both stored decimal amounts');
    $page->mountAction('approveComplimentaryCost')->setActionData(['reason'=>'Approve the exact displayed amount']);
    expect(str_contains((string)$page->instance()->getAction('approveComplimentaryCost')->getModalDescription(),$message),'The approval modal preserves both stored decimal amounts');
    expect(str_contains($page->effects['partials']['action-modals.0']??'',$message),'The rendered approval modal shows the exact amount to authorize');
    $page->callMountedAction()->assertHasNoActionErrors()->assertNotified('Registrar cost approved');
    same('queued',$fractional->fresh()->state,'The displayed exact quote can be approved');
    same(0,\Paymenter\Extensions\Servers\Domains\Services\WriteMoney::compare('8.0040',DB::table('domains_complimentary_approvals')->where('operation_id',$fractional->id)->sole()->amount),'The approval audit retains the exact displayed amount');
});
[$changed,$d]=$hold('approval-replaced-quote-placeholder.com');
$check('Quote changed after modal opens',static function()use($staff,$d,$changed):void {
    $page=Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->mountAction('approveComplimentaryCost')
        ->setActionData(['reason'=>'Approve the displayed quote']);
    expect(str_contains($page->effects['partials']['action-modals.0']??'','registrar cost $8.01'),'The mounted modal displayed the original quote');
    $changed->update(['policy'=>[...$changed->policy,'complimentary_cost_review'=>['amount'=>'8.02','usual'=>'8.00']]]);
    $before=DB::table('domains_complimentary_approvals')->count();
    $page->callMountedAction()->assertHasNoActionErrors();
    same(['held',$before],[$changed->fresh()->state,DB::table('domains_complimentary_approvals')->count()],'An unseen replacement quote must remain held without an approval audit');
    $page->assertNotified(Notification::make()->danger()->title('Could not approve registrar cost')->body('Reload the domain and check the current registrar cost and hold.'));
    same('8.02',$changed->fresh()->policy['complimentary_cost_review']['amount'],'The replacement quote remains available for a fresh review');
    same($before,DB::table('domains_complimentary_approvals')->count(),'An unseen replacement quote adds no approval audit');
});
[$broken,$d]=$hold('approval-unreadable-placeholder.com');
DB::table('domains_operations')->where('id',$broken->id)->update(['policy'=>'unreadable-retained-policy']);
$check('Unreadable cost details',static function()use($staff,$d):void {
    Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertSee('Registrar cost details are unavailable.')->assertActionHidden('approveComplimentaryCost');
});
expect($failures===[],implode("\n",$failures));
echo "Native approval feedback, stale hold and unreadable policy passed\n";$nativeSuccessful=true;
