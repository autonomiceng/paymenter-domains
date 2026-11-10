<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real completed holdings and engine, synthetic transport.
declare(strict_types=1);
require __DIR__.'/owner-management-ready.php';
require __DIR__.'/owner-site-nameservers.php';
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnerActionController, OwnedDomainController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains,OwnerPorts,OwnerSetup,TransferInputs,Refunds,DomainManagement};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
$changed=Operation::create(['domain_id'=>$d->id,'service_id'=>$d->service_id,'server_id'=>$d->server_id,'registrar_key'=>$d->registrar_key,'account_fingerprint'=>$d->account_fingerprint,'authority_generation'=>$d->authority_generation,'authorized_by'=>(int)$user->id,
    'operation_key'=>hash('sha256','owner-contact-change'),'action'=>'contacts','term'=>1,'currency'=>'USD','amount'=>'0.00','funding_basis'=>'non_billable_management','state'=>'succeeded','payload'=>['role'=>'registrant','contact'=>[...$registrant,'first_name'=>'Jane','last_name'=>'Doe','email_address'=>'jane@example.com']],'policy'=>[]]);
same(['name'=>'Jane Doe','email'=>'jane@example.com'],$owners->present($d->fresh())->owner,'A confirmed contact change replaces the stored registrant');
$saved=OwnedDomains::contacts($d->fresh());same(['Jane',$registrant['first_name']],[$saved['registrant']['first_name'],$saved['admin']['first_name']],'A one-role change leaves the other roles');
Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$changed->id)->delete();
$html=(new OwnedDomainController($owners))->show($r,$d->id)->render();$dom=storefrontDom($html);
$forms=$dom->query('//form[@method="POST"]');same(5,$forms->length,'Supported current management and renewal preference forms are offered');
foreach(['nameservers','contacts','lock','auth_code_email'] as $action){same(1,$dom->query('//form[@action="'.route('domains.domains.manage',[$d->id,$action]).'"]')->length,'Exactly one supported management form: '.$action);}
same(0,$dom->query('//li[@id="privacy"]|//form[contains(@action,"/manage/privacy_")]')->length,'MAIN hides new privacy choices while retaining existing engine state');
same($forms->length,$dom->query('//form/input[@name="_token"]')->length,'Every form has native CSRF');
same($forms->length,$dom->query('//form/input[@name="_request_key"]')->length,'Every form has single-use intent');
same(0,$dom->query('//input[@type="checkbox"]')->length,'Owner management forms have no checkboxes');
same(1,$dom->query('//li[@id="contacts"]/span[contains(@class,"domains-row__v")][contains(.,"'.$registrant['email_address'].'")]')->length,'Owner row shows the registrant email');
if(isset($dto->management['auth_code_email'])){same($free?'NameSilo emails it to the admin contact email':'Enom emails it to the registrant email',trim(preg_replace('/\s+/',' ',$dom->query('//li[@id="auth_code_email"]/span[contains(@class,"domains-row__v")]')->item(0)->textContent)),'Auth code row names who receives the code');}
same(0,$dom->query('//input[@name="confirmed"]')->length,'No form carries a ticked or hidden confirmation');
foreach(['nameservers','lock','auth_code_email']as$risky){if(isset($dto->management[$risky])&&!($risky==='lock'&&($dto->management['lock']['state']??null)===false)){same(1,$dom->query('//li[@id="'.$risky.'"]//form[@data-confirm]//dialog[@data-confirm-dialog][not(@open)]//button[@name="confirmed"]')->length,'Risky action has a closed confirm dialog: '.$risky);}}
foreach(['contacts']as$plain){if(isset($dto->management[$plain])){same(1,$dom->query('//li[@id="'.$plain.'"]')->length,'Plain action row rendered: '.$plain);same(0,$dom->query('//li[@id="'.$plain.'"]//dialog[@data-confirm-dialog]|//li[@id="'.$plain.'"]//form[@data-confirm]')->length,'Plain action has no confirm step: '.$plain);}}
foreach($free?['srv','auth_code_display']:['dns','srv','ds','auth_code_display']as$a){expect(!isset($dto->management[$a]),'Unsupported or disabled advanced/display forms are absent');}
if($free){same(['label'=>'DNSSEC','off'=>true,'actions'=>[],'url'=>null,'reason'=>\Paymenter\Extensions\Servers\Domains\Presentation\DsState::CUSTOMER,'progress'=>null,'records'=>null],$dto->management['ds'],'Unsupported DS stays visible without change controls');}
if($free){same(['label'=>'DNS records','unavailable'=>true,'actions'=>[],'url'=>null,'records'=>null,'fixed'=>[],'asOf'=>null,'progress'=>null],$dto->management['dns'],'Dormant NameSilo DNS shows as not available');}
if($free){ // Stopped reads never restart by themselves, so an unknown row names the step instead of a time.
    $d->update(['observation'=>null]);Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
    // The first registrar read is queued work for this holding; only that can promise Checking.
    $firstRead=Illuminate\Support\Facades\DB::table('domains_observation_work')->insertGetId(['server_id'=>$d->server_id,'account_fingerprint'=>$d->account_fingerprint,'domain_id'=>$d->id,'generation'=>$d->authority_generation,'phase'=>'holding','due_at'=>app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimestamp()]);
    $lockRow=static fn():string=>trim(preg_replace('/\s+/',' ',storefrontDom((new OwnedDomainController($owners))->show($r,$d->id)->render())->query('//li[@id="lock"]/span[@class="domains-row__v"]')->item(0)->textContent));
    same('Checking',$lockRow(),'An initial registrar read is Checking rather than Missing');
    expect(str_contains((new OwnedDomainController($owners))->show($r,$d->id)->render(),e(\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::INITIAL_READ)),'The real owner page explains the initial read');
    Illuminate\Support\Facades\DB::table('domains_observation_work')->where('id',$firstRead)->update(['due_at'=>0,'safe_code'=>'read_unavailable']);$stoppedRead=$firstRead;
    same([],$owners->present($d->fresh())->management['dns']['actions'],'This registrar has no record changes');
    same('Missing',$lockRow(),'A stopped read promises no time; the page notice says it once');
    expect(str_contains((new OwnedDomainController($owners))->show($r,$d->id)->render(),e(\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::READ_HELD)),'A stopped read explains recovery in the rendered page notice'); // slop-sweep
    Illuminate\Support\Facades\DB::table('domains_observation_work')->where('id',$stoppedRead)->delete();
}
if($free){expect(!str_contains($html,'privacy_purchase'),'Free privacy offers no paid action');commerceReject(fn()=>$driver->validate('nameservers',['use_default'=>true,'nameservers'=>[]],$d->domain));}
else {
    commerceReject(fn()=>$driver->validate('nameservers',['nameservers'=>[]],$d->domain));
    expect(str_contains($html,'Use site default nameservers'),'Saved site nameservers have a fill action');
    $reset=$management->request($user,$d->id,'nameservers',['nameservers'=>[],'use_default'=>true],hash('sha256','legacy-default-reset'),$driver);
    same(['nameservers'=>[],'use_default'=>true],$reset->payload,'Already-frozen legacy reset remains supported by the engine');
    $state->ns=['dns1.name-services.com','dns2.name-services.com'];$state->apply=false;Illuminate\Support\Facades\Cache::forget('domains-write-slot:'.$d->account_fingerprint);
    same('succeeded',$management->run($reset->id,$driver)->state,'Explicit default reset requires provider default readback');
    $writes=array_values(array_filter($state->calls,fn($call)=>$call[0]==='ModifyNS'));same('default',end($writes)[1]['UseDNS'],'The grant sends explicit default DNS intent');$state->apply=true;
}
// Exercise the real retirement and owner presenter together; raw operation state alone is rejected.
if (!$free) {
    [$cancelDomain,$cancelDriver]=$holding(placeholderComFixtureName('owner-cancel-privacy-placeholder.com'));
    storefrontViews();
    $state->privacy=false;$state->enabled=false;$state->cost='8.00';
    $product->server->settings()->updateOrCreate(['key'=>'domains_privacy_price'],['value'=>'5.00','type'=>'string']);
    $cancelled=$management->request($user,$cancelDomain->id,'privacy_purchase',[],hash('sha256','owner-cancel-privacy'),$cancelDriver);
    $cancelled=$management->retire($user,$cancelled->id);
    $history=$owners->present($cancelDomain->fresh(),false)->progress;
    same('Cancelled',$history[0]['state'],'Customer history shows the retired privacy purchase as Cancelled');
    same(null,$history[0]['note'],'Cancelled privacy has no failed-change message');
    same($history[0]['state'],\Paymenter\Extensions\Servers\Domains\Admin\Support\Labels::requestStatus($cancelled)['label'],'Customer and staff cancellation status match');
}
$nativeSuccessful=true;echo "Owner management presentation, native engine, replay and cancellation passed.\n";
