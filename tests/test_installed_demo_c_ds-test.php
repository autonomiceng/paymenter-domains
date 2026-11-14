<?php
// Native DS presentation: no provider or registry grants. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\{DsState,OwnedDomain};
$failures=[];
$check=static function(bool $ok,string $message)use(&$failures):void{if(!$ok){$failures[]=$message;}};
$same=static function($expected,$actual,string $message)use($check):void{$check($expected===$actual,$message);};
(new Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
storefrontViews();require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
$d=new Domain(['id'=>1,'domain'=>'orbit-example.com','status'=>'active','registrar_key'=>'namesilo','authority_generation'=>1,'account_fingerprint'=>str_repeat('a',64),'observation'=>[]]);
$check(DsState::off($d),'Empty deployment map holds NameSilo DS Off');
$base=new OwnedDomain(name:$d->domain,status:'Active',tone:'ok',expiry:'2027-11-20',renewal:'On',renewalPrice:'$14.00',actions:['show'=>'/domains/1'],progress:[],setup:[],transfer:[],management:[],testEnvironment:false);
$page=DsState::owner($base,$d,true);$html=view('domains::partials.owner-management',['domain'=>$page,'caps'=>['ds']])->render();
$check(str_contains($html,'Off')&&str_contains($html,'DS changes are not offered'),'Customer DS has the Off reason');
$check(!str_contains($html,'domains-missing')&&!str_contains($html,'<form')&&!str_contains($html,'Registry'),'Off has no missing mark, editor or staff reason');
$check(str_contains($html,'aria-disabled="true"'),'Blocked DS action remains visible');
$check(str_contains(DsState::staff($d)['sub'],'Registry DS rules for this TLD have not been checked'),'Staff sees missing-registry-facts next step');
foreach(['registration_pending','transfer_pending','expired','closed'] as $status){$d->status=$status;$check(!isset(DsState::owner($base,$d,true)->management['ds']),'No DS Off row on '.$status);
 $sub=DsState::staff($d)['sub']??'';$check(!str_contains($sub,'Use Sync now'),'Inactive domain never offers Sync now: '.$status);
 if(str_ends_with($status,'pending')){$check(str_contains($sub,'after registration or transfer finishes'),'Pending acquisition explains when records can be read');}
}
$d->status='active';$check(!isset(DsState::owner($base,$d,false)->management['ds']),'No DS row without form/capability context');
$d->registrar_key='enom';$check(!DsState::off($d),'NameSilo policy does not disable Enom DS');
$op=new Operation(['id'=>84,'action'=>'ds_add','state'=>'queued','attempt'=>[],'policy'=>[],'created_at'=>now(),'authority_generation'=>1,'account_fingerprint'=>str_repeat('a',64)]);
$same('Waiting',DsState::event($op,$d)['state'],'A queued request waits before its first read');
$check(str_contains(DsState::event($op,$d)['text'],'Nothing was sent')&&str_contains(DsState::event($op,$d,true)['text'],'Waiting'),'Before-send copy distinguishes unknown inventory for each audience');
$op->attempt=['seal'=>'sealed'];$check(!str_contains(DsState::event($op,$d)['text'],'Sent.'),'A seal alone does not prove dispatch');$op->attempt=['seal'=>'sealed','dispatch_attempted_at'=>now()->toIso8601String()];$check(str_contains(DsState::event($op,$d,true)['text'],'independent registrar read'),'Staff sent copy names independent evidence');$check(!str_contains(DsState::event($op,$d)['text'],'Sent.'),'Seal timestamp is not transport evidence');
$op->created_at=now()->subSeconds(31);$check(str_contains(DsState::event($op,$d)['text'],'longer'),'After 30 seconds keeps checking');
$op->state='uncertain';$same('info',DsState::event($op,$d)['tone'],'Customer result not confirmed is cyan');
$same('warning',DsState::event($op,$d,true)['tone'],'Staff readback incomplete needs review');
$check(str_contains(DsState::event($op,$d,true)['text'],'Check request #84'),'Staff gets the request number');
$op->state='rejected';$same('Under review',DsState::event($op,$d)['state'],'Unconfirmed rejection never claims nothing changed');
$op->state='succeeded';$op->domain_id=$d->id;$op->service_id=$d->service_id;$op->server_id=$d->server_id;$op->registrar_key=$d->registrar_key;$op->operation_key='ds-proof';$op->policy=['management'=>['action'=>'ds_add']];$op->completed_at=now();
$op->attempt=['seal'=>'sealed','management_reads'=>[['operation_key'=>'ds-proof','generation'=>1,'action'=>'ds_add','confirmed'=>true,'facts'=>['records'=>[]]]]];
$check(str_contains(DsState::event($op,$d,true)['text'],'all other DS records were checked'),'Staff confirmed copy checks siblings');
$same('Completed',DsState::event($op,$d)['state'],'Independent confirmed readback completes the DS change');
$op->action='ds_delete';$op->attempt=['seal'=>'sealed','management_reads'=>[['operation_key'=>'ds-proof','generation'=>1,'action'=>'ds_delete','confirmed'=>true,'facts'=>['records'=>[]]]]];
$check(str_contains(DsState::event($op,$d)['text'],'last DS record'),'Confirmed empty readback names final deletion');
$check(str_contains(DsState::event($op,$d,true)['text'],'complete independent read confirmed the empty list'),'Staff final deletion names complete independent empty read');
require __DIR__.'/Support/staff-panel.php';
staffPanel();[$held,$active,$long]=staffData();
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
Illuminate\Support\Facades\Auth::login(staffUser([Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::VIEW]));
$html=Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$active->id])->html();
$check(str_contains($html,'DNSSEC')&&str_contains($html,'DS records'),'Real staff domain sheet includes the DS section');
$pending=Operation::create(['domain_id'=>$active->id,'service_id'=>$active->service_id,'server_id'=>$active->server_id,'registrar_key'=>$active->registrar_key,'authority_generation'=>$active->authority_generation,'account_fingerprint'=>$active->account_fingerprint,'operation_key'=>'ds-pending-ui','action'=>'ds_add','state'=>'queued','amount'=>'0.00','currency'=>'USD','policy'=>['management'=>['action'=>'ds_add']],'attempt'=>['seal'=>'sealed','dispatch_attempted_at'=>now()->toIso8601String()]]);
$fact=DsState::staff($active);$same('Checking the result…',$fact['value'],'Staff DS row shows in-flight state');
$check(str_contains($fact['progress']['text'],'independent registrar read'),'Staff DS row has staff progress words');
$same('We’re checking this change with the registrar. Nothing to do yet.',DsState::progress($active)['ds']['text'],'Customer polling does not infer dispatch from the seal');
$held->update(['status'=>'active']);
Operation::create(['domain_id'=>$held->id,'service_id'=>$held->service_id,'server_id'=>$held->server_id,'registrar_key'=>$held->registrar_key,'authority_generation'=>$held->authority_generation,'account_fingerprint'=>$held->account_fingerprint,'operation_key'=>'ds-before-send-ui','action'=>'ds_add','state'=>'queued','amount'=>'0.00','currency'=>'USD','policy'=>['management'=>['action'=>'ds_add']],'attempt'=>[]]);
$same('Waiting',DsState::progress($held)['ds']['state'],'Customer polling waits before the first read');
$unknown=DsState::owner(new OwnedDomain(...[...get_object_vars($base),'name'=>$held->domain]),$held,true);
$html=view('domains::partials.owner-management',['domain'=>$unknown,'caps'=>['ds']])->render();
$check(str_contains($html,'data-ds-state>Waiting</strong>')&&str_contains($html,'Nothing was sent'),'Real stored pre-send operation renders Unknown in the customer row');
$tuple=['key_tag'=>12345,'algorithm'=>13,'digest_type'=>2,'digest'=>str_repeat('A',64)];
$inventory=clone $held;$inventory->registrar_key='namesilo';$inventory->user_id=10;
$inventory->observation=['records'=>['identity'=>Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::identity($inventory),'at'=>now()->toIso8601String(),'sequence'=>0,'format'=>1,'capability_revision'=>'namesilo-records-1','expected_families'=>['dns'=>'supported','srv'=>'unsupported','ds'=>'supported','glue'=>'supported'],'families'=>['ds'=>['state'=>'complete','records'=>[$tuple]]]]];
$observed=DsState::owner($base,$inventory,true);
$html=view('domains::partials.owner-management',['domain'=>$observed,'caps'=>['ds']])->render();
$check(str_contains($html,'12345')&&str_contains($html,str_repeat('A',64))&&!str_contains($html,'<form'),'Policy Off keeps observed DS tuples read-only');
$staffFact=DsState::staff($inventory);$check(str_contains($staffFact['sub'],'12345'),'Staff Off policy retains observed DS tuple');
$by=DsState::owner($base,$active,true)->progress;
$same('Staff',$by[0]['by']??null,'DS history preserves staff attribution');
$row=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::row($held->operations()->where('action','ds_add')->sole(),$held,true);
$check(str_contains($row['sub'],'Turn them on in Registrars.'),'Queued DS history preserves account pause guidance');
// Stored unreadable evidence must reach the DS-specific review state on both staff surfaces.
$queued=$held->operations()->where('action','ds_add')->sole();
foreach(['attempt','policy','funding'] as $field){
    $raw=$queued->getRawOriginal($field);
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$queued->id)->update([$field=>'unreadable-synthetic-evidence']);
    try{
        $unreadable=$queued->fresh();
        $row=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::row($unreadable,$held,true);
        $same('Under review',$row['status']['label'],'Unreadable '.$field.' retains the staff DS review state');
        $check(str_contains($row['sub'],'DS evidence cannot be read'),'Unreadable '.$field.' has the staff DS explanation');
        $steps=Paymenter\Extensions\Servers\Domains\Admin\Support\DomainHistory::steps($held,[$unreadable]);
        $check(count($steps)===1,'Unreadable '.$field.' still produces an Activity step');
    }catch(Illuminate\Contracts\Encryption\DecryptException){$check(false,'Unreadable '.$field.' broke staff History and Activity');}
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$queued->id)->update([$field=>$raw]);
}
expect($failures===[],implode("; ",$failures));
$nativeSuccessful=true;echo "Demo C DS UI passed.\n";
