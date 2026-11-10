<?php
// A normal send reads as progress, not a problem: no amber head, the sent time on the activity row, no stale alert, and no Missing before the first read.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB, Schema};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, DomainHistory, NavigationCount, Staff, StaffDomainInputs};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\ObligationAlerts;
staffPanel(); [, $seed]=staffData(); Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$viewer=staffUser([Staff::VIEW,'admin.domains.manage']);Auth::setUser($viewer);
Schema::disableForeignKeyConstraints();
Account::where('server_id',$seed->server_id)->update(['processing_enabled'=>true]);
$n=0;
$row=static function(string $status, string $state, array $op=[]) use ($seed, &$n): Domain {
    $n++; $label='in-progress-'.$n;
    $d=$seed->replicate()->fill(['domain'=>$label.'.example.com','sld'=>$label,'service_id'=>4000+$n,'status'=>$status,'provider_expires_at'=>$status==='active'?today()->addYear()->format('Y-m-d'):null,'last_synced_at'=>null,'observation'=>[]]);
    $d->save();
    DB::table('services')->insert(['id'=>$d->service_id,'user_id'=>$d->user_id,'order_id'=>1,'product_id'=>1,'plan_id'=>1,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
    $seed->operations()->sole()->replicate()->fill(['domain_id'=>$d->id,'service_id'=>$d->service_id,'authority_generation'=>$d->authority_generation,
        'operation_key'=>$label,'action'=>'register','state'=>$state,'safe_code'=>null,'attempt'=>[],'policy'=>[],'payload'=>[],'cost'=>[],'funding'=>[],...$op])->save();
    return $d->fresh();
};
// Domain 3 two minutes after #5 was sent: created two weeks ago, sent just now, still with the registrar.
$sent=now()->subMinutes(2)->startOfSecond();
$d=$row('registration_pending','pending',['started_at'=>$sent]);
$op=$d->latestAcquisition; DB::table('domains_operations')->where('id',$op->id)->update(['created_at'=>now()->subDays(14)]); $op=$op->fresh();
same(null,DomainFacts::notice($d),'No amber "not confirmed" notice while the request is In progress');
$head=DomainFacts::head($d,DomainFacts::status($d),DomainFacts::for($d));
expect(!in_array($head['tone'],['warning','danger'],true),'The head is not amber or red during a normal send: '.$head['tone']);
expect(str_contains(strip_tags((string)$head['step']),'Registration in progress'),'The head says the registration is in progress: '.strip_tags((string)$head['step']));
same($sent->getTimestamp(),DomainHistory::row($op,$d,false)['time']?->getTimestamp(),'The activity row is dated when the request was sent, as the request page says');
// A DNSSEC request row uses the same time: when it was sent while it waits on the registrar, else when it was created.
$created=now()->subDays(3)->startOfSecond();
foreach(['ds_add'=>['pending',$sent],'ds_delete'=>['queued',$created]] as $action=>[$state,$expected]){
    $ds=$op->replicate()->fill(['operation_key'=>'in-progress-'.$action,'action'=>$action,'state'=>$state,'started_at'=>$state==='pending'?$sent:null,'policy'=>['management'=>['section'=>'dnssec']]]);
    $ds->save();DB::table('domains_operations')->where('id',$ds->id)->update(['created_at'=>$created]);
    same($expected->getTimestamp(),DomainHistory::row($ds->fresh(),$d,false)['time']?->getTimestamp(),'The '.$action.' row is dated as the request page says');
    DB::table('domains_operations')->where('id',$ds->id)->delete();
}
// A review alert left from before the send (a dispatch or balance refusal) does not put the domain under Open alerts.
ObligationAlerts::record($d,'operation_review',time()-900,(int)$op->id);
same([],StaffDomainInputs::for([$d->fresh()])[$d->id]['alerts'],'A request back with the registrar has no open review alert');
// The real Open alerts filter and the uncached menu count agree with the row.
$open=fn():array=>Livewire::actingAs($viewer)->test(ListDomains::class)->filterTable('alerts',true)->instance()->getTableRecords()->pluck('id')->all();
$badge=function():int{NavigationCount::forget('domains');return (int)DomainResource::getNavigationBadge();};
expect(!in_array($d->id,$open(),true),'The Open alerts filter leaves out a request back with the registrar');$quiet=$badge();
DB::table('domains_operations')->where('id',$op->id)->update(['state'=>'uncertain']);
same('operation_review',StaffDomainInputs::for([$d->fresh()])[$d->id]['alerts'][0]['code']??null,'An unconfirmed result still needs a check');
expect(in_array($d->id,$open(),true),'The Open alerts filter shows an unconfirmed result');same($quiet+1,$badge(),'The menu count adds the unconfirmed result');
// Domain 3 just completed: the first registrar read is queued, so Contacts wait for it instead of saying Missing.
$active=$row('active','succeeded',['started_at'=>$sent,'completed_at'=>now()]);
// The shared seed points at no customer account; give this domain a real one so only the read rows are in question.
$customer=App\Models\User::create(['first_name'=>'Read','last_name'=>'Waiting','email'=>'read-waiting@example.test','password'=>'synthetic-password']);
DB::table('domains_domains')->where('id',$active->id)->update(['user_id'=>$customer->id]);
DB::table('domains_observation_work')->insert(['server_id'=>$active->server_id,'account_fingerprint'=>$active->account_fingerprint,'domain_id'=>$active->id,
    'generation'=>$active->authority_generation,'phase'=>'holding','due_at'=>time()]);
// Staff contacts and registrar records use the same injected time, including overdue work.
$future=new DateTimeImmutable('+2 hours');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new class($future) implements Paymenter\Extensions\Servers\Domains\Contracts\Clock {
    public function __construct(private DateTimeImmutable $at){} public function now():DateTimeImmutable{return $this->at;}
});
$late=DomainFacts::for($active->fresh());
expect(!Missing::needsNothing($late['Contacts']['sub']??null),'An overdue first read cannot promise contacts will fill automatically');
$shared=DomainFacts::for($active->fresh(),families:['initial_read_pending'=>true]);
expect(Missing::needsNothing($shared['Contacts']['sub']??null),'Contacts reuse the record state already read for the page');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
$facts=DomainFacts::for($active->fresh());
expect(array_key_exists('Contacts',$facts)&&$facts['Contacts']['value']===null,'This fixture has no saved contacts yet');
expect(Missing::needsNothing($facts['Contacts']['sub']??null),'Contacts fill in after the first read: '.($facts['Contacts']['sub']??''));
$head=DomainFacts::head($active->fresh(),DomainFacts::status($active->fresh()),$facts);
expect(!str_contains((string)$head['step'],'Some details are Missing'),'No "Some details are Missing" while the first read runs: '.$head['step']);
echo "In-progress registration passed.\n";
$nativeSuccessful=true;
