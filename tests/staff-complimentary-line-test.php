<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The domain price action in the customer header: one confirm that needs a reason and shows the last change, and each change in the native Audit Log.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Admin\Resources\UserResource\Pages\EditUser;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\ComplimentaryAccountSetting;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccountSchema,ComplimentaryTimeSchema,FoundationSchema};
/** A customer page that, like a store's own page, lists the domain price actions in its "..." menu. */
final class ComplimentaryMenuUserPage extends EditUser
{
    protected function getHeaderActions():array
    {
        return [Filament\Actions\ActionGroup::make([...parent::getHeaderActions(),...ComplimentaryAccountSetting::actions(fn():int=>(int)$this->getRecord()->getKey())])];
    }
}
config(['audit.enabled'=>true,'audit.console'=>true,'settings.company_name'=>'Example Hosting (test)']);
app()->instance(Clock::class,new class implements Clock {public function now():DateTimeImmutable{return new DateTimeImmutable('2026-11-06T18:52:00Z');}});
staffPanel();FoundationSchema::install();ComplimentaryAccountSchema::install();ComplimentaryTimeSchema::install();ComplimentaryAccountSetting::boot();
$staff=staffUser(['admin.domains.fund','admin.users.viewAny','admin.users.view','admin.users.update']);$staff->update(['first_name'=>'Ilya','last_name'=>'Kravchenko']);
$customer=App\Models\User::create(['first_name'=>'Demo','last_name'=>'Sandbox','email'=>'demo-sandbox@example.test','password'=>'synthetic-password']);
$line=fn()=>Livewire::actingAs($staff)->test(ComplimentaryAccountSetting::class,['customer'=>$customer->id]);
// The open confirm's own heading, words and reason field.
$confirm=static function($test,array $words){$a=$test->instance()->getMountedAction();$d=$a?->getModalDescription();
    $modal=html_entity_decode($a?->getModalHeading().' '.($d instanceof Illuminate\Contracts\Support\Htmlable?strip_tags($d->toHtml()):$d),ENT_QUOTES);foreach($words as $w){expect(str_contains($modal,$w),'Confirm says: '.$w);}return $test;};
// The last change as the next confirm shows it.
$last=function()use($line,$customer):string {$on=(bool)(Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::current($customer->id)['enabled']??false);
    return $line()->instance()->{$on?'turnOffAction':'turnOnAction'}()->getModalDescription()->toHtml();};
// Never set: only the turn on action, and no last change.
$line()->assertActionVisible('turnOn')->assertActionHidden('turnOff');expect(!str_contains($last(),'Turned'),'No last change before the first');
$confirm($line()->mountAction('turnOn'),['Make domains complimentary?','Example Hosting pays for new registrations, transfers and renewals for Demo Sandbox. Hosting is billed as usual.'])
    ->callMountedAction()->assertHasActionErrors(['reason'=>'required']);
same(0,DB::table('domains_complimentary_accounts')->count(),'No change without a reason');
same(0,$customer->audits()->count(),'No audit row without a change');
$long=str_repeat('Partner_account ',31).'end.';same(500,strlen($long),'Longest allowed reason');
$line()->callAction('turnOn',['reason'=>$long])->assertHasNoActionErrors()->assertActionVisible('turnOff')->assertActionHidden('turnOn');
// Turning off names what stays covered and the last change.
$confirm($line()->mountAction('turnOff'),['Orders and renewals from before now stay covered. New registrations, transfers and renewals use standard prices.','Turned on by Ilya Kravchenko, ','. Reason: '.$long])
    ->setActionData(['reason'=>'Partnership ended'])->callMountedAction()->assertHasNoActionErrors();
$confirm($line()->mountAction('turnOn'),['Turned off by Ilya Kravchenko, ','Reason: Partnership ended']);
same([1,0],DB::table('domains_complimentary_accounts')->where('user_id',$customer->id)->orderBy('id')->pluck('enabled')->map(fn($v)=>(int)$v)->all(),'Each change is kept');
// The stock Audit Log on the customer lists both changes, who made them and each whole reason.
same([['turned on complimentary domains for',$long,$staff->id],['turned off complimentary domains for','Partnership ended',$staff->id]],
    $customer->audits()->orderBy('id')->get()->map(fn($a)=>[$a->event,$a->new_values['reason']??null,(int)$a->user_id])->all(),'Native audit rows');
$log=preg_replace('/\s+/',' ',html_entity_decode(strip_tags(view('admin.actions.audits',['record'=>$customer->fresh(),'children'=>[]])->render()),ENT_QUOTES));
foreach(['Ilya Kravchenko turned on complimentary domains for User (#'.$customer->id.')','Ilya Kravchenko turned off complimentary domains for User (#'.$customer->id.')',
    'reason changed from -> '.$long,'reason changed from -> Partnership ended'] as $w){expect(str_contains($log,$w),'Audit Log shows: '.$w);}
// Like the registrar cost approval, a confirm only applies the setting it showed: a change made after it opened refuses with no row and no audit.
$stale=$line()->mountAction('turnOn')->setActionData(['reason'=>'Opened before the change']);
$other=staffUser(['admin.domains.fund']);$clock=app(Clock::class);
Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::set($customer->id,$other,true,'Second staff on',$clock);
Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::set($customer->id,$other,false,'Second staff off',$clock);
$stale->callMountedAction()->assertHasNoActionErrors()
    ->assertNotified(Filament\Notifications\Notification::make()->danger()->title('Complimentary domains changed after this opened')->body('Reload the customer and check the current setting.'));
same([4,'Second staff off',4],[DB::table('domains_complimentary_accounts')->where('user_id',$customer->id)->count(),
    Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::current($customer->id)['reason'],$customer->audits()->count()],'An unseen change stays as it is, with no extra row or audit');
// A same-state write changes nothing and adds no audit row.
Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::set($customer->id,$staff,false,'Again',app(Clock::class));
same(4,$customer->audits()->count(),'No audit row for a change that did not happen');
// A store in Los Angeles saves each change in UTC and shows the clock's own instant, in summer, in winter and in both runs of the repeated autumn hour.
$priorZone=date_default_timezone_get();$priorAppZone=config('app.timezone');$priorClock=app(Clock::class);
date_default_timezone_set('America/Los_Angeles');config(['app.timezone'=>'America/Los_Angeles']);
try{foreach([['2026-07-01T03:30:00+00:00','2026-07-01 03:30:00'],['2026-11-06T18:52:00+00:00','2026-11-06 18:52:00'],['2026-11-01T08:30:00+00:00','2026-11-01 08:30:00'],['2026-11-01T09:30:00+00:00','2026-11-01 09:30:00']] as [$instant,$utc]){
    app()->instance(Clock::class,new class($instant) implements Clock {public function __construct(private string $at){}public function now():DateTimeImmutable{return new DateTimeImmutable($this->at);}});
    $on=(bool)(Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::current($customer->id)['enabled']??false);
    $line()->callAction($on?'turnOff':'turnOn',['reason'=>'Store time zone'])->assertHasNoActionErrors();expect(str_contains($last(),'<time datetime="'.$instant.'"'),'The confirm shows the change time: '.$instant);
    same([$utc,'UTC'],array_values((array)DB::table('domains_complimentary_accounts')->where('user_id',$customer->id)->orderByDesc('id')->first(['created_at','time_zone'])),'The row keeps UTC: '.$instant);}
    // Changing the store time zone later does not move a saved change.
    date_default_timezone_set('Asia/Tokyo');config(['app.timezone'=>'Asia/Tokyo']);
    expect(str_contains($last(),'<time datetime="2026-11-01T09:30:00+00:00"'),'A later store zone keeps the saved time');}
finally{date_default_timezone_set($priorZone);config(['app.timezone'=>$priorAppZone]);app()->instance(Clock::class,$priorClock);}
// Staff without the domain funding permission see nothing and cannot change it.
$plain=staffUser(['admin.users.viewAny','admin.users.view','admin.users.update']);
Livewire::actingAs($plain)->test(EditUser::class,['record'=>$customer->id])->assertDontSeeHtml('class="domains-comp"')->assertDontSee('Partnership ended');
Livewire::actingAs($plain)->test(ComplimentaryAccountSetting::class,['customer'=>$customer->id])->assertActionHidden('turnOn')->assertActionHidden('turnOff');
// A page that lists the actions in its own "..." menu hosts them: the hook adds nothing, and the menu action switches only
// this customer, both ways, and only for staff with domain funding.
$other=App\Models\User::create(['first_name'=>'Other','last_name'=>'Customer','email'=>'other-customer@example.test','password'=>'synthetic-password']);
$on=static fn(App\Models\User $u):bool=>(bool)(Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::current($u->id)['enabled']??false);
$otherBefore=$on($other);$menu=fn($actor)=>Livewire::actingAs($actor)->test(ComplimentaryMenuUserPage::class,['record'=>$customer->id]);
expect(!str_contains($menu($staff)->html(),'class="domains-comp"'),'A page that hosts the actions gets no second copy');
$first=$on($customer);
$menu($staff)->callAction($first?'turnOff':'turnOn',['reason'=>'Menu switch'])->assertHasNoActionErrors();
same([!$first,$otherBefore],[$on($customer),$on($other)],'The menu action switches only this customer');
$menu($staff)->callAction($first?'turnOn':'turnOff',['reason'=>'Menu switch back'])->assertHasNoActionErrors();
same([$first,$otherBefore],[$on($customer),$on($other)],'The menu action switches back');
$menu($plain)->assertActionHidden('turnOn')->assertActionHidden('turnOff');
$nativeSuccessful=true;echo "Complimentary domain action, confirm, audit log and permission passed.\n";
