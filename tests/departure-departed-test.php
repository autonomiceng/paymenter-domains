<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/departure-credit-test.php';
use App\Models\User;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts;
use Paymenter\Extensions\Servers\Domains\Presentation\{State,StatusPill};
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;

$nativeSuccessful=false;
// The departure above is confirmed as moved to another registrar, with the paid unsent renewal returned as credit.
$owner=User::findOrFail($d->user_id);
$row=(new DomainSummaries)->listed($owner,$clock->now()->getTimestamp())['managed:'.$d->id]??null;
expect($row!==null,'The departed domain stays with its owner');
$pill=StatusPill::summary($row);
same(['Transferred away','Moved to another registrar'],[$pill['label']??null,$pill['reason']??null],'Customers read the saved departure as transferred away with its reason');
// The customer list shows it in All and in the Inactive tab.
foreach(['PslSchema','PslReviewSchema','WatchSchema','WatchDnsSchema','ManagedPublicWorkSchema','ManagedPublicSchema','WatchScheduleSchema','WatchNoticeSchema','AdoptionSchema','NativeIntentSchema'] as $schema){('Paymenter\\Extensions\\Servers\\Domains\\Services\\'.$schema)::install();}
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();view()->addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
$listed=static function(string $query)use($owner):array{
    $request=Illuminate\Http\Request::create('/domains'.$query);$request->setUserResolver(static fn()=>$owner);
    $data=app(Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController::class)->index($request)->getData();
    return [array_map(static fn($row)=>$row->name,$data['domains']->items()),$data['filterCounts']['inactive']??0,$data['filterCounts']['']??0];};
same([[$d->domain],1,1],$listed(''),'All lists and counts the departed domain, and the Inactive tab counts it');
same([[$d->domain],1,1],$listed('?status=inactive'),'The Inactive tab lists the departed domain');
// Staff read the same word and reason, with no step, on the list and the domain page.
$status=DomainFacts::status($d->fresh());
same(['transferred_away','Transferred away','Moved to another registrar',null,false],[$status['key'],$status['label'],$status['reason'],$status['step'],$status['act']],'Staff read the saved departure with nothing to do');
same(State::TransferredAway,State::tryFrom($status['key']),'The staff head shows the transferred away status');
// The staff page reads the saved departure: its staff note and who confirmed it, without the review's sections.
$staffName=trim($staff->first_name.' '.$staff->last_name);
Livewire\Livewire::actingAs($staff)->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful()
    ->assertSee($d->fresh()->renewal_policy['departure']['note'])->assertSee($staffName)
    // Nothing on the page changes the departed domain: no registrar read, departure step or free renewal is offered.
    ->assertActionHidden('sync_now')->assertActionHidden('check_departure')->assertActionHidden('confirm_departure')
    ->assertActionHidden('resume_observation')->assertActionHidden('complimentary_renewal');
same([false,[]],[DomainFacts::syncNow($d->fresh()),array_values(array_filter(Paymenter\Extensions\Servers\Domains\Admin\Support\HoldingActions::make($d->fresh()),fn($a)=>$a->isVisible()))],
    'A departed domain offers no sync and no renewal invoice action');
// History keeps the departure's saved outcomes, with the exact saved credit, after the earlier registrar reads.
$saved=$d->fresh()->renewal_policy['departure'];
$departed=array_values(array_filter(Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::entries($d->fresh()),fn($r)=>is_array($r)&&str_starts_with((string)($r['__key']??''),'departed-')));
$name=(fn(string $n)=>ctype_digit($n)?'Invoice #'.$n:$n)(Paymenter\Extensions\Servers\Domains\Admin\Support\Facts::invoiceName((int)$invoice->id));
same(['Departure confirmed',$name.' cancelled','Credit added','Name released','Email sent'],array_column($departed,'event'),'History lists the confirmation, the closed invoice, the credit, the released name and the sent email');
same(trim($staff->first_name.' '.$staff->last_name)?:'Staff',$departed[0]['by'],'The confirmation row names the staff member who confirmed');
expect(str_contains((string)$departed[2]['sub'],'$'.number_format($saved['credit_minor']/100,2)),'The Credit added row carries the saved credit amount');
// A dispute lost after the departure opens an account hold on the departed domain. Billing offers Release hold, which clears it.
$hold=Illuminate\Support\Facades\DB::table('domains_obligation_alerts')->insertGetId(['alert_key'=>Paymenter\Extensions\Servers\Domains\Services\DisputeRecoveries::key('account_money_hold','synthetic_departed'),
    'domain_id'=>$d->id,'operation_id'=>$op->id,'generation'=>$op->authority_generation,'code'=>'account_money_hold','first_seen'=>1,'last_seen'=>1]);
$staff->role->update(['permissions'=>[...$staff->role->permissions,Paymenter\Extensions\Servers\Domains\Services\StaffOperationReview::permission('release'),'admin.invoices.update']]);
Livewire\Livewire::actingAs($staff->fresh())->test(ViewDomain::class,['record'=>$d->id])->assertSuccessful()
    ->assertActionVisible('release_money_hold_'.$hold)->callAction('release_money_hold_'.$hold)->assertHasNoActionErrors();
expect(Illuminate\Support\Facades\DB::table('domains_obligation_alerts')->where('id',$hold)->value('resolved_at')!==null,'Release hold on a departed domain clears its account hold');
echo "A confirmed departure reads as transferred away on customer, list and staff pages, with its saved history.\n";
$nativeSuccessful=true;
