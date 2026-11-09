<?php
// Pending registration: the request owns recovery and its saved Purchase reply stays permission checked.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Services\ObservationRecords;
// Saving global settings reloads Paymenter settings from the table.
App\Models\Setting::updateOrCreate(['key'=>'company_name','settingable_type'=>null,'settingable_id'=>null],['value'=>'Example Hosting','type'=>'string','encrypted'=>false]);config(['settings.company_name'=>'Example Hosting']);

(new Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
staffPanel(); [$held,$active,$long]=staffData(); Paymenter\Extensions\Servers\Domains\Services\PslReviewSchema::install();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Data\SystemClock);
$viewer=staffUser([Staff::VIEW]); Auth::setUser($viewer);
$customer=App\Models\User::create(['first_name'=>'Ada','last_name'=>'Lovelace','email'=>'ada@example.test','password'=>'synthetic-password']);
DB::table('domains_domains')->where('id',$active->id)->update(['user_id'=>$customer->id]);$active=$active->fresh();

// An uncertain registration owns its next action even when an observation job has saved read failures.
$pending=$active->replicate(); $pending->fill(['domain'=>'unconfirmed.example.com','sld'=>'unconfirmed','status'=>'registration_pending','service_id'=>9901,
    'provider_expires_at'=>null,'provider_order_id'=>null,'provider_domain_id'=>null,'observation'=>[]]); $pending->save();
$purchaseReply=['code'=>'545','text'=>'Error 545 - Name server does not exist'];
$request=\Paymenter\Extensions\Servers\Domains\Models\Operation::create(['domain_id'=>$pending->id,'server_id'=>$pending->server_id,
    'registrar_key'=>$pending->registrar_key,'account_fingerprint'=>$pending->account_fingerprint,'service_id'=>$pending->service_id,
    'authority_generation'=>$pending->authority_generation,'operation_key'=>'saved-purchase-refusal','action'=>'register','state'=>'uncertain',
    'safe_code'=>'reconciliation_required','currency'=>'USD','amount'=>'18.00','payload'=>[],
    'attempt'=>['seal'=>'sealed','execution'=>['disposition'=>'unknown','send_state'=>'possibly_sent','reason'=>'unresolved','discrepancies'=>[]],
        'evidence'=>['registrarReply'=>$purchaseReply]],'policy'=>[],'funding'=>[]]);
$pendingWork=DB::table('domains_observation_work')->insertGetId(['server_id'=>$pending->server_id,'account_fingerprint'=>$pending->account_fingerprint,
    'domain_id'=>$pending->id,'generation'=>$pending->authority_generation,'due_at'=>time()+60,'safe_code'=>'read_failed','tries'=>4]);
$metadataViewer=staffUser([Staff::VIEW,Staff::METADATA]);
$pendingPage=Livewire::actingAs($metadataViewer)->test(ViewDomain::class,['record'=>$pending->id]);
$pendingPage->assertSee('Registration not confirmed by the registrar.')->assertSee('Check request #'.$request->id)
    ->assertSee('Error 545 - Name server does not exist')->assertDontSee('No read queued')->assertDontSee('Registrar reads keep failing')
    ->assertDontSee('The registrar read failed.')->assertActionHidden('resume_observation');
Livewire::actingAs($metadataViewer)->test(\Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation::class,['record'=>$request->id])
    ->assertSee('Registrar reply')->assertSee('Error 545 - Name server does not exist');
Livewire::actingAs($viewer)->test(ViewDomain::class,['record'=>$pending->id])->assertDontSee('Error 545 - Name server does not exist');
same(4,DB::table('domains_observation_work')->where('id',$pendingWork)->value('tries'),'Display preserves saved observation work');
$pending->update(['observation'=>['record_failure'=>['identity'=>ObservationRecords::identity($pending),'code'=>'domain_not_in_account','at'=>gmdate(DATE_ATOM)]]]);
Livewire::actingAs($metadataViewer)->test(ViewDomain::class,['record'=>$pending->id])->assertSee('Registration not confirmed by the registrar.')
    ->assertSee('Check request #'.$request->id)->assertDontSee('Register or transfer the domain')->assertDontSee('No read queued');
// Resume sync waits for the registration, so a stopped sync links the running registration request that the status line does not name.
DB::table('domains_operations')->where('id',$request->id)->update(['state'=>'running','safe_code'=>null]);
$pending->update(['observation'=>[]]);ObservationRecords::hold($pending->fresh(),'records_unavailable',new DateTimeImmutable());
Livewire::actingAs($metadataViewer)->test(ViewDomain::class,['record'=>$pending->id])
    ->assertSee('Registrar records can sync after registration is confirmed.')->assertSee('Check request #'.$request->id);
DB::table('domains_observation_work')->where('id',$pendingWork)->delete();
DB::table('domains_operations')->where('id',$request->id)->delete(); DB::table('domains_domains')->where('id',$pending->id)->delete(); Auth::setUser($viewer);

echo "Pending registration staff evidence passed.\n"; $nativeSuccessful=true;
