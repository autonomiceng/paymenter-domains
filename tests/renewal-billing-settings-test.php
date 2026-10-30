<?php
// Native staff renewal billing review; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\{BillingCalendar,RenewalBillingSettings as Billing,StaffSettingsReview as Review,StaffSettingsWrites};
extract(commerceFixture(true));tldPanel();Carbon\Carbon::setTestNow($now);$t=$tlds[0];$server=App\Models\Server::find($t->server_id);
$permissions=[Staff::VIEW,Staff::POLICY,Review::permission('renewal_billing')];
$u=staffUser($permissions);Auth::setUser($u);$writer=new StaffSettingsWrites($native,$sellers,$bindings);app()->instance(StaffSettingsWrites::class,$writer);
$name=setupAction('renewal_billing',$t->server_id);
$rows=static fn()=>DB::table('settings')->where('settingable_type',$server->getMorphClass())->where('settingable_id',$t->server_id)->whereIn('key',Billing::KEYS)->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
$review=static fn(string $reason='Review renewal billing for synthetic account')=>new Review($u->id,$t->server_id,null,'renewal_billing',Review::ticket($u->id,$t->server_id,null,'renewal_billing',Review::stamp($t->server_id,null)),$reason);
$valid=['scheduled'=>true,'manual'=>true,'issue_days'=>30,'due_days'=>10,'confirmed'=>true];

// Absent rows display the calendar defaults and keep both switches off.
same([],$rows(),'Fixture starts without renewal billing rows');
same(['Scheduled renewal invoices'=>'Off','Owner renewal requests'=>'Off','Renewal invoice calendar'=>'Issued 21 days before expiry · due 7 days before (defaults)'],Billing::facts($t->server_id),'Absent rows show effective defaults');
Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->assertSee('Customer can renew early')->assertSee('21 days before expiry')->assertSee('7 days before expiry')->assertDontSee('(defaults)')->assertActionVisible($name);
$page=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->mountAction($name)->assertActionMounted($name);$defaults=$page->get('mountedActions.0.data');$ticket=$defaults['review_key'];
same([false,false,21,7],[$defaults['scheduled'],$defaults['manual'],(int)$defaults['issue_days'],(int)$defaults['due_days']],'Modal prefills effective defaults');

// Owner requests stay open until the confirmed expiry date (DomainBilling::requireRequest), not the due day.
$copy=Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions::details('renewal_billing');
expect(str_contains($copy,'Owner requests let a customer ask for a renewal invoice for an active domain at any time before the confirmed expiry date.'),'Owner request copy states the expiry cutoff');
expect(!str_contains($copy,'before the due day'),'Owner request copy drops the old due-day cutoff');

// Successful native submission writes exactly four plain rows and one audit entry.
$page->setActionData(['scheduled'=>true,'manual'=>true,'issue_days'=>'30','due_days'=>'10','confirmed'=>true,'reason'=>'Turn on renewal billing after review'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified('Saved')->assertSet('mountedActions',[]);
same([Billing::SCHEDULED=>['1','boolean',0],Billing::MANUAL=>['1','boolean',0],Billing::ISSUE=>['30','integer',0],Billing::DUE=>['10','integer',0]],
    collect($rows())->mapWithKeys(fn($r)=>[$r['key']=>[$r['value'],$r['type'],(int)$r['encrypted']]])->all(),'One plain row per renewal billing setting');
same(['issue'=>30,'due'=>10],BillingCalendar::timing($server->settings()->get()),'Billing calendar reads the saved timing');
same(['Scheduled renewal invoices'=>'On','Owner renewal requests'=>'On','Renewal invoice calendar'=>'Issued 30 days before expiry · due 10 days before'],Billing::facts($t->server_id),'Saved values replace defaults');
$audit=array_values(array_filter(Review::historyRows($server->fresh()),fn($r)=>$r['action']==='Edit renewal invoices'));
same(1,count($audit),'One audit row per change');$entry=$audit[0];
same([$u->id,'completed','Turn on renewal billing after review'],[$entry['actor_id'],$entry['phase'],$entry['reason']],'Audit names actor, phase and reason');
$input=$entry['input'];ksort($input);same(['due_days'=>10,'issue_days'=>30,'manual'=>true,'scheduled'=>true],array_map(fn($v)=>is_bool($v)?$v:(int)$v,$input),'Audit input keeps only reviewed fields');
same(['scheduled'=>false,'manual'=>false,'issue_days'=>21,'due_days'=>7,'calendar_saved'=>false],$entry['before']['renewal_billing'],'Audit before shows effective defaults');
same(['scheduled'=>true,'manual'=>true,'issue_days'=>30,'due_days'=>10,'calendar_saved'=>true],$entry['after']['renewal_billing'],'Audit after shows saved values');
Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->mountAction($name)->assertSet('mountedActions.0.data.issue_days',30)->assertSet('mountedActions.0.data.due_days',10)->assertSet('mountedActions.0.data.scheduled',true);

// Replay of a used review refuses; unchanged values refuse without a second audit row.
$used=new Review($u->id,$t->server_id,null,'renewal_billing',$ticket,'Replay must refuse');
tldRefusal(fn()=>$writer->run($used,$valid),'Settings changed. Reload this page.');
$saved=$rows();$history=count(Review::historyRows($server->fresh()));
tldRefusal(fn()=>$writer->run($review(),[...$valid,'issue_days'=>'30']),'Renewal billing unchanged.');
same([$saved,$history],[$rows(),count(Review::historyRows($server->fresh()))],'Unchanged review writes nothing');

// Calendar and input bounds: each mutation of a valid submission refuses exactly and leaves rows and audit untouched.
$bounds=[
    [['issue_days'=>10,'due_days'=>10],'Valid billing calendar required.'],[['issue_days'=>9,'due_days'=>10],'Valid billing calendar required.'],
    [['issue_days'=>366],'Valid billing calendar required.'],[['due_days'=>0],'Valid billing calendar required.'],[['issue_days'=>-21],'Valid billing calendar required.'],
    [['issue_days'=>'21.5'],'Invalid renewal timing.'],[['due_days'=>'seven'],'Invalid renewal timing.'],[['issue_days'=>null],'Invalid renewal timing.'],[['due_days'=>[7]],'Invalid renewal timing.'],
    [['scheduled'=>'1'],'Invalid renewal billing input.'],[['manual'=>null],'Invalid renewal billing input.'],[['scheduled'=>1],'Invalid renewal billing input.'],
    [['confirmed'=>false],'Explicit renewal billing confirmation required.'],[['confirmed'=>'1'],'Explicit renewal billing confirmation required.'],
];
foreach($bounds as [$change,$message]){tldRefusal(fn()=>$writer->run($review(),[...$valid,'scheduled'=>false,...$change]),$message);}
foreach(array_keys($valid) as $key){$missing=$valid;unset($missing[$key]);commerceReject(fn()=>$writer->run($review(),$missing));}
same([$saved,$history],[$rows(),count(Review::historyRows($server->fresh()))],'Refused bounds write nothing');
foreach([[365,364],[2,1]] as [$issue,$due]){$writer->run($review(),[...$valid,'issue_days'=>$issue,'due_days'=>$due]);same(['issue'=>$issue,'due'=>$due],BillingCalendar::timing($server->settings()->get()),'Calendar edge '.$issue.'/'.$due.' saves');}
$saved=$rows();$history=count(Review::historyRows($server->fresh()));

// Native refusal toast for a bound violation; stale page refuses after another change.
Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->mountAction($name)->setActionData(['scheduled'=>true,'manual'=>true,'issue_days'=>'7','due_days'=>'7','confirmed'=>true,'reason'=>'Refuse an equal issue and due day'])
    ->callMountedAction()->assertHasNoActionErrors()->assertNotified(Notification::make()->danger()->title("Couldn't save")->body('Issue days must be more than due days. Issue days can be at most 365 and due days at least 1. Nothing was saved.'));
$stale=Livewire::actingAs($u)->test(RegistrarAccount::class,['account'=>$t->server_id])->mountAction($name);$writer->run($review(),[...$valid,'manual'=>false,'issue_days'=>2,'due_days'=>1]);$saved=$rows();$history=count(Review::historyRows($server->fresh()));
$stale->setActionData([...$valid,'issue_days'=>'40','reason'=>'Stale page must refuse'])->callMountedAction()->assertNotified(Notification::make()->danger()->title("Couldn't save")->body('Someone else changed this. Nothing was saved. Try again.'));
same([$saved,$history],[$rows(),count(Review::historyRows($server->fresh()))],'Refused native submissions write nothing');

// An invalid saved calendar is reported, never silently repaired.
DB::table('settings')->where('settingable_type',$server->getMorphClass())->where('settingable_id',$t->server_id)->where('key',Billing::DUE)->update(['value'=>'2']);
same('Saved calendar invalid · new renewal invoices refused',Billing::facts($t->server_id)['Renewal invoice calendar'],'Invalid saved calendar shown as invalid');
same([null,null],[Billing::read($t->server_id)['issue_days'],Billing::read($t->server_id)['due_days']],'Invalid saved calendar has no effective days');
DB::table('settings')->where('settingable_type',$server->getMorphClass())->where('settingable_id',$t->server_id)->where('key',Billing::DUE)->update(['value'=>'1']);same($saved,$rows(),'Calendar restored');

// Permission: hidden without the action permission or policy, and the writer refuses a minted ticket.
foreach([[Staff::VIEW,Staff::POLICY],[Staff::VIEW,Review::permission('renewal_billing')]] as $reduced){
    $u->role->update(['permissions'=>$reduced]);
    Livewire::actingAs($u->fresh())->test(RegistrarAccount::class,['account'=>$t->server_id])->assertActionDoesNotExist($name);
    $denied=$review('Permission revoked before submission');commerceReject(fn()=>$writer->run($denied,[...$valid,'issue_days'=>50]));
}
$u->role->update(['permissions'=>$permissions]);
same([$saved,$history],[$rows(),count(Review::historyRows($server->fresh()))],'Denied submissions write nothing');
$other=staffUser([Staff::VIEW,Staff::POLICY,Review::permission('processing')]);
commerceReject(fn()=>$writer->run(new Review($other->id,$t->server_id,null,'renewal_billing',Review::ticket($other->id,$t->server_id,null,'renewal_billing',Review::stamp($t->server_id,null)),'Other permission only'),$valid));
commerceReject(fn()=>$writer->run(new Review($u->id,$t->server_id,null,'renewal_billing',Review::ticket($other->id,$t->server_id,null,'renewal_billing',Review::stamp($t->server_id,null)),'Ticket bound to another actor'),$valid));
commerceReject(fn()=>$writer->run(new Review($u->id,$t->server_id,null,'renewal_billing',Review::ticket($u->id,$t->server_id,null,'processing',Review::stamp($t->server_id,null)),'Ticket bound to another action'),$valid));
same([$saved,$history],[$rows(),count(Review::historyRows($server->fresh()))],'Foreign tickets write nothing');
echo "Renewal billing review defaults, audit, bounds, replay, stale and permission refusals passed.\n";$nativeSuccessful=true;
