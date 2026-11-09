<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The domain price line under the customer title: state, last change, one confirm that needs a reason, and each change in the native Audit Log.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Admin\Resources\UserResource\Pages\EditUser;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\ComplimentaryAccountSetting;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccountSchema,FoundationSchema};
config(['audit.enabled'=>true,'audit.console'=>true,'settings.company_name'=>'Example Hosting (test)']);
app()->instance(Clock::class,new class implements Clock {public function now():DateTimeImmutable{return new DateTimeImmutable('2026-11-06T18:52:00Z');}});
staffPanel();FoundationSchema::install();ComplimentaryAccountSchema::install();ComplimentaryAccountSetting::boot();
$staff=staffUser(['admin.domains.fund','admin.users.viewAny','admin.users.view','admin.users.update']);$staff->update(['first_name'=>'Ilya','last_name'=>'Kravchenko']);
$customer=App\Models\User::create(['first_name'=>'Demo','last_name'=>'Sandbox','email'=>'demo-sandbox@example.test','password'=>'synthetic-password']);
$line=fn()=>Livewire::actingAs($staff)->test(ComplimentaryAccountSetting::class,['customer'=>$customer->id]);
// The open confirm's own heading, words and reason field.
$confirm=static function($test,array $words){$a=$test->instance()->getMountedAction();$d=$a?->getModalDescription();
    $modal=html_entity_decode($a?->getModalHeading().' '.($d instanceof Illuminate\Contracts\Support\Htmlable?strip_tags($d->toHtml()):$d),ENT_QUOTES);foreach($words as $w){expect(str_contains($modal,$w),'Confirm says: '.$w);}
    expect(str_contains(implode('',$test->effects['partials']??[]).$test->html(),'Shown on this page and in the audit log.'),'Reason says where it is shown');return $test;};
// The public heading hook puts the line between the title and the header buttons.
$page=Livewire::actingAs($staff)->test(EditUser::class,['record'=>$customer->id])->html();
$at=strpos($page,'Standard domain prices');
expect($at!==false&&strpos($page,'fi-header-heading')<$at&&$at<strpos($page,'fi-header-actions-ctn'),'The line sits under the title, before the header buttons');
// Never set: the state and its one button.
$line()->assertSee('Standard domain prices')->assertActionVisible('turnOn')->assertActionHidden('turnOff')->assertDontSee('Turned')
    ->assertSeeHtml('<p class="domains-comp-last">No changes yet.</p>');
// The history slot keeps two lines on narrow screens, so the header buttons under it do not move once history appears.
$css=view('domains::partials.staff-styles')->render();
expect(str_contains($css,'@media (max-width:39.99rem){.domains-comp-last{min-height:2.5rem}}'),'Narrow screens reserve the history slot');
$confirm($line()->mountAction('turnOn'),['Make domains complimentary?','Example Hosting pays for new registrations, transfers and renewals for Demo Sandbox. Hosting is billed as usual.'])
    ->callMountedAction()->assertHasActionErrors(['reason'=>'required']);
same(0,DB::table('domains_complimentary_accounts')->count(),'No change without a reason');
same(0,$customer->audits()->count(),'No audit row without a change');
$long=str_repeat('Partner_account ',31).'end.';same(500,strlen($long),'Longest allowed reason');
$line()->callAction('turnOn',['reason'=>$long])->assertHasNoActionErrors()->assertSee('Complimentary domains')
    ->assertSee('Turned on by Ilya Kravchenko, ')->assertSee('. Reason: '.$long)->assertActionVisible('turnOff')->assertActionHidden('turnOn');
// Turning off names what stays covered.
$confirm($line()->mountAction('turnOff'),['Turn off complimentary domains?','Orders and renewals from before now stay covered. New registrations, transfers and renewals use standard prices.'])
    ->setActionData(['reason'=>'Partnership ended'])->callMountedAction()->assertHasNoActionErrors()
    ->assertSee('Standard domain prices')->assertSee('Turned off by Ilya Kravchenko, ')->assertSee('Reason: Partnership ended');
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
// A store in Los Angeles keeps each change as its local wall time and shows the clock's own instant, in summer and in winter.
$priorZone=date_default_timezone_get();$priorAppZone=config('app.timezone');$priorClock=app(Clock::class);
date_default_timezone_set('America/Los_Angeles');config(['app.timezone'=>'America/Los_Angeles']);
try{foreach([['2026-07-01T03:30:00+00:00','2026-06-30 20:30:00'],['2026-11-06T18:52:00+00:00','2026-11-06 10:52:00']] as [$instant,$wall]){
    app()->instance(Clock::class,new class($instant) implements Clock {public function __construct(private string $at){}public function now():DateTimeImmutable{return new DateTimeImmutable($this->at);}});
    $on=(bool)(Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::current($customer->id)['enabled']??false);
    $line()->callAction($on?'turnOff':'turnOn',['reason'=>'Store time zone'])->assertHasNoActionErrors()->assertSeeHtml('<time datetime="'.$instant.'"');
    same($wall,DB::table('domains_complimentary_accounts')->where('user_id',$customer->id)->orderByDesc('id')->value('created_at'),'The row keeps store-zone wall time: '.$instant);}}
finally{date_default_timezone_set($priorZone);config(['app.timezone'=>$priorAppZone]);app()->instance(Clock::class,$priorClock);}
// Staff without the domain funding permission see nothing and cannot change it.
$plain=staffUser(['admin.users.viewAny','admin.users.view','admin.users.update']);
Livewire::actingAs($plain)->test(EditUser::class,['record'=>$customer->id])->assertDontSee('Standard domain prices')->assertDontSee('Partnership ended');
Livewire::actingAs($plain)->test(ComplimentaryAccountSetting::class,['customer'=>$customer->id])->assertDontSee('Standard domain prices')->assertActionHidden('turnOn');
$nativeSuccessful=true;echo "Complimentary domain line, confirm, audit log and permission passed.\n";
