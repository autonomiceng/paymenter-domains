<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The domain price line under the customer title: state, last change and one confirm that needs a reason.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Admin\Resources\UserResource\Pages\EditUser;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\ComplimentaryAccountSetting;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccountSchema,FoundationSchema};
app()->instance(Clock::class,new class implements Clock {public function now():DateTimeImmutable{return new DateTimeImmutable('2026-11-06T18:52:00Z');}});
staffPanel();FoundationSchema::install();ComplimentaryAccountSchema::install();ComplimentaryAccountSetting::boot();
$staff=staffUser(['admin.domains.fund','admin.users.viewAny','admin.users.view','admin.users.update']);$staff->update(['first_name'=>'Ilya','last_name'=>'Kravchenko']);
$customer=App\Models\User::create(['first_name'=>'Demo','last_name'=>'Sandbox','email'=>'demo-sandbox@example.test','password'=>'synthetic-password']);
$line=fn()=>Livewire::actingAs($staff)->test(ComplimentaryAccountSetting::class,['customer'=>$customer->id]);
// The open confirm's own heading and words.
$confirm=static function($test,array $words){$a=$test->instance()->getMountedAction();$d=$a?->getModalDescription();
    $modal=html_entity_decode($a?->getModalHeading().' '.($d instanceof Illuminate\Contracts\Support\Htmlable?strip_tags($d->toHtml()):$d),ENT_QUOTES);foreach($words as $w){expect(str_contains($modal,$w),'Confirm says: '.$w);}return $test;};
// The public heading hook puts the line between the title and the header buttons.
$page=Livewire::actingAs($staff)->test(EditUser::class,['record'=>$customer->id])->html();
$at=strpos($page,'Standard domain prices');
expect($at!==false&&strpos($page,'fi-header-heading')<$at&&$at<strpos($page,'fi-header-actions-ctn'),'The line sits under the title, before the header buttons');
// Never set: the state and its one button.
$line()->assertSee('Standard domain prices')->assertActionVisible('turnOn')->assertActionHidden('turnOff')->assertDontSee('Turned');
$confirm($line()->mountAction('turnOn'),['Make domains complimentary?','Beco pays for new registrations, transfers and renewals for Demo Sandbox. Hosting is billed as usual.'])
    ->callMountedAction()->assertHasActionErrors(['reason'=>'required']);
same(0,DB::table('domains_complimentary_accounts')->count(),'No change without a reason');
$long=str_repeat('Partner account ',31).'end.';same(500,strlen($long),'Longest allowed reason');
$line()->callAction('turnOn',['reason'=>$long])->assertHasNoActionErrors()->assertSee('Complimentary domains')
    ->assertSee('Turned on by Ilya Kravchenko, ')->assertSee('. Reason: '.$long)->assertActionVisible('turnOff')->assertActionHidden('turnOn');
// Turning off shows the whole last reason and what stays covered.
$confirm($line()->mountAction('turnOff'),['Turn off complimentary domains?','Turned on by Ilya Kravchenko, ','. Reason: '.$long,
    'Beco still pays for what it already covers. New registrations, transfers and renewals use standard prices.'])
    ->setActionData(['reason'=>'Partnership ended'])->callMountedAction()->assertHasNoActionErrors()
    ->assertSee('Standard domain prices')->assertSee('Turned off by Ilya Kravchenko, ')->assertSee('Reason: Partnership ended');
same([1,0],DB::table('domains_complimentary_accounts')->where('user_id',$customer->id)->orderBy('id')->pluck('enabled')->map(fn($v)=>(int)$v)->all(),'Each change is kept');
// Staff without the domain funding permission see nothing and cannot change it.
$plain=staffUser(['admin.users.viewAny','admin.users.view','admin.users.update']);
Livewire::actingAs($plain)->test(EditUser::class,['record'=>$customer->id])->assertDontSee('Standard domain prices')->assertDontSee('Partnership ended');
Livewire::actingAs($plain)->test(ComplimentaryAccountSetting::class,['customer'=>$customer->id])->assertDontSee('Standard domain prices')->assertActionHidden('turnOn');
$nativeSuccessful=true;echo "Complimentary domain line, confirm and permission passed.\n";
