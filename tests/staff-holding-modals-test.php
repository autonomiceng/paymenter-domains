<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/Support/renewal-initialized.php';
$fixtureInitialization=initializedRenewalFixture(...);
require_once __DIR__.'/Support/scenario-inventory.php';
$scenarios=new NativeScenarioInventory(['actions','unregistered','invalid-reason','stop','stale','permission','history-full','ownership:missing-anchor','ownership:unconfirmed-anchor','ownership:immediate-cancellation','view-only','audit','gateway-create','gateway-immutable']);
ini_set('memory_limit','256M');
require_once __DIR__.'/Support/reserved-fixtures.php';
// Modal ownership and payment cases use Enom holdings only.
$fixtureRegistrars=['enom'];
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/staff-panel.php';
require_once __DIR__.'/Support/converted-holding.php';
use App\Models\ServiceCancellation;
use Illuminate\Support\Facades\{Auth,DB,View};
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks,NativeCancellation,NativeIdentity,NativeIntentSchema,StaffHoldingReview};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{HoldingActions,DomainFacts};
app()->instance(Paymenter\Extensions\Servers\Domains\Services\InvoiceBinding::class,$invoiceBinding);
NativeIntentSchema::install();NativeIdentity::boot();NativeCancellation::boot();
View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Services\NativeDomainGuard::class,$guard);
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
$review=new StaffHoldingReview($clock);app()->instance(StaffHoldingReview::class,$review);
$permissions=['admin.domains.view','admin.domains.manage','admin.domains.fund',...array_map(StaffHoldingReview::permission(...),array_keys(StaffHoldingReview::ACTIONS))];
$staff=staffUser($permissions);Auth::login($staff);
$ticket=static fn($d,$action,$actor=null)=>StaffHoldingReview::ticket((int)($actor??$staff)->id,(int)$d->id,$action,StaffHoldingReview::stamp(HoldingLocks::take($d->fresh(),advisory:true)),$clock);
[$original]=$prepare(placeholderComFixtureName('holding-review-placeholder.com'));same('succeeded',$reconcile->run($original->id)->state,'Holding has original proof');$d=$original->domain->fresh();$s=$d->service;
// Native ownership, encrypted policy, one-use reviews and actual Filament modals need this native fixture.
// Real owned action modals and refusal/success copy, with no sensitive model form fill.
Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
staffPanel();Auth::login($staff);$pageClass=Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class;
$page=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->assertSuccessful();
$page->assertActionDoesNotExist('reprice');$page->assertActionVisible('stop')->mountAction('stop')->assertActionMounted('stop');$page->unmountAction();$scenarios->completed('actions');
$afterRenewal=$clock->instant;$clock->instant=new DateTimeImmutable('2026-10-18T12:00:00Z');activeCommerceFacts($clock->now(),$clock->now()->modify('+1 day'),activeFixturePorts($bindings));
$unregistered=$new(placeholderComFixtureName('holding-unregistered-placeholder.com'),paid:false);$clock->instant=$afterRenewal;
Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$unregistered->domain_id])->assertActionHidden('stop');$scenarios->completed('unregistered');
$beforeInvalidReason=$d->fresh()->getRawOriginal();
$page->callAction('stop',['reason'=>"Reviewed\u{2029}renewal"])->assertHasActionErrors(['reason'=>'regex']);
same($beforeInvalidReason,$d->fresh()->getRawOriginal(),'Renewal reason field error changes no holding');$scenarios->completed('invalid-reason');
$page->unmountAction();
$stopPage=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->mountAction('stop');
same("Stops new renewal invoices. Invoices already sent stay payable, and renewal requests already created may still run.",$stopPage->instance()->getMountedAction()->getModalDescription(),'Actual stop dialog explains retained debt and issued requests');
$stopPage->setActionData(['reason'=>'Stop through owned staff modal'])->callMountedAction()->assertNotified('Renewal stopped')->assertRedirect(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('view',['record'=>$d->id]));same(true,$d->fresh()->renewal_policy['renewals_stopped'],'Actual modal invokes owned action');$scenarios->completed('stop');
$resumePage=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->mountAction('resume')->assertActionMounted('resume');
$stalePage=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->mountAction('resume');
$s->fresh()->forceFill(['label'=>'Changed after modal opened'])->save();$stalePage->setActionData(['reason'=>'Stale modal refusal control'])->callMountedAction()->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't update renewal")->body('This domain changed. Reload the page and try again.'))->assertSet('mountedActions',[]);$scenarios->completed('stale');
$permissionPage=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->mountAction('resume')->setActionData(['reason'=>'Permission changed after review']);$beforeRevocation=$d->fresh()->getRawOriginal('renewal_policy');$staff->role->update(['permissions'=>['admin.domains.view']]);
$permissionPage->call('callMountedAction')->assertActionDoesNotExist('resume');same($beforeRevocation,$d->fresh()->getRawOriginal('renewal_policy'),'Revoked role cannot execute mounted action');$staff->role->update(['permissions'=>$permissions]);$scenarios->completed('permission');
$policy=$d->fresh()->renewal_policy;$d->fresh()->update(['renewal_policy'=>[...$policy,'staff_holdings'=>array_fill(0,100,$d->fresh()->renewal_policy['staff_holdings'][0])]]);
$fullPage=Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->mountAction('resume');$fullPage->setActionData(['reason'=>'Audit retention limit control'])->callMountedAction()->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't update renewal")->body('History for this domain is full. Ask your site admin.'))->assertSet('mountedActions',[]);
$d->fresh()->update(['renewal_policy'=>$policy]);same(true,$d->fresh()->renewal_policy['renewals_stopped'],'Full history refusal changes no holding policy');$scenarios->completed('history-full');
Auth::login($staff);
// Fresh dialogs reach the shared ownership refusal for resume.
foreach(['missing-anchor','unconfirmed-anchor','immediate-cancellation'] as $fault){
    $savedPolicy=$d->fresh()->renewal_policy;$savedOp=$original->fresh()->getRawOriginal();
    if($fault==='missing-anchor'){$changed=$savedPolicy;unset($changed['billing_anchor']);$d->fresh()->update(['renewal_policy'=>$changed]);}
    elseif($fault==='unconfirmed-anchor'){DB::table('domains_operations')->where('id',$original->id)->update(['state'=>'pending']);}
    else{DB::table('service_cancellations')->insert(['service_id'=>$d->service_id,'type'=>'immediate','reason'=>'Retained cancellation requires review','created_at'=>now(),'updated_at'=>now()]);}
    $policyBefore=$d->fresh()->getRawOriginal('renewal_policy');
    Livewire\Livewire::actingAs($staff)->test($pageClass,['record'=>$d->id])->mountAction('resume')->assertActionMounted('resume')
        ->setActionData(['reason'=>'Review confirmed ownership before renewal changes'])->callMountedAction()
        ->assertNotified(Filament\Notifications\Notification::make()->danger()->title("Couldn't update renewal")->body("The service isn't active, the registration isn't confirmed yet, or the service has an immediate cancellation. Check the service, then try again."));
    same($policyBefore,$d->fresh()->getRawOriginal('renewal_policy'),$fault.' keeps renewal policy');
    $d->fresh()->update(['renewal_policy'=>$savedPolicy]);DB::table('domains_operations')->where('id',$original->id)->update($savedOp);
    $scenarios->completed('ownership:'.$fault);
    if($fault==='immediate-cancellation'){DB::table('service_cancellations')->where('service_id',$d->service_id)->delete();}
}
$viewOnly=staffUser(['admin.domains.view']);$readonly=Livewire\Livewire::actingAs($viewOnly)->test($pageClass,['record'=>$d->id]);$readonly->assertActionDoesNotExist('stop');$scenarios->completed('view-only');
Auth::login($staff);Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager::class,['ownerRecord'=>$d->fresh(),'pageClass'=>$pageClass])
    ->assertSee('Renewal stopped')->assertSee('Stop through owned staff modal')->assertSee(trim($staff->first_name.' '.$staff->last_name)?:'Staff');$scenarios->completed('audit');
// Native guards and the same Eloquent instance are required: wasRecentlyCreated survives its first gateway save.
$gateway=\App\Models\Gateway::firstOrCreate(['extension'=>'Manual'],['name'=>'Manual','type'=>'gateway']);
$payment=\App\Models\InvoiceTransaction::create(['invoice_id'=>$original->invoice_id,'amount'=>'1.00','fee'=>'0.00','status'=>'succeeded']);
$payment->gateway()->associate($gateway);$payment->save();
same($gateway->id,$payment->fresh()->gateway_id,'A new staff payment can save its first gateway');$scenarios->completed('gateway-create');
$refused=false;try{$payment->update(['gateway_id'=>null]);}catch(\DomainException){$refused=true;}
expect($refused,'A retained payment keeps its gateway on the same model instance');
same($gateway->id,$payment->fresh()->gateway_id,'Refused replacement leaves the original gateway in storage');$scenarios->completed('gateway-immutable');
$scenarios->finish();
echo "Owned holding review, snapshots, audit, debt preservation, paid execution and real modals passed.\n";$nativeSuccessful=true;
