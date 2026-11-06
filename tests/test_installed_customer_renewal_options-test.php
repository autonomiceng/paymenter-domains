<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native ownership, encrypted account binding and Blade must run together to catch hidden renewal actions.
require __DIR__.'/Support/reconciliation-fixture.php';
$cataloguePorts=$ports;
require __DIR__.'/Support/owner-pages.php';
use App\Models\{Invoice,Role};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;
use Paymenter\Extensions\Servers\Domains\Services\{ComplimentaryAccounts};

[$registration]=$prepare(placeholderComFixtureName('customer-renewal-options-placeholder.com'));
same('succeeded',$reconcile->run($registration->id)->state,'Registration has its confirmed billing anchor');
$d=$registration->domain()->firstOrFail();$server=$product->server;
storefrontViews();$session=app('request')->session();
$server->settings()->updateOrCreate(['key'=>'domains_manual_renewals_enabled'],['value'=>true,'type'=>'boolean']);
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
activeCommerceFacts($clock->now(),$clock->now()->modify('+30 days'),$cataloguePorts);
$staff=commerceUser();$role=Role::create(['name'=>'Funding reviewer','permissions'=>['admin.domains.manage','admin.domains.fund']]);$staff->update(['role_id'=>$role->id]);
$request=Request::create('/domains/'.$d->id,'GET');$request->setUserResolver(fn()=>$user);$request->setLaravelSession($session);
$render=fn()=>(new OwnedDomainController($owners))->show($request,$d->id)->render();
foreach([false,true] as $complimentary){
    if($complimentary){ComplimentaryAccounts::set($user->id,$staff,true,'Synthetic funding authorization',$clock);}
    $before=Invoice::count();same(0,DB::transactionLevel(),'Customer page starts outside financial locks');
    $html=$render();
    expect(str_contains($html,'>Renew now</a>'),'Eligible '.($complimentary?'complimentary':'paid').' customer sees Renew now');
    expect(str_contains($html,'<option value="1"'),'Customer renewal offers its supported annual term');
    same([1],$ownerBilling->requestOptions($d->fresh())['terms'],'Advisory terms agree with the native page');
    same($complimentary?'0.00':'10.40',$ownerBilling->renewalChoices($d->fresh())['next_annual'],'Customer sees the authorized renewal price');
    same($before,Invoice::count(),'Advisory page creates no invoice');
    $bindings->setProcessing($server->id,false);
    expect(!str_contains($render(),'>Renew now</a>'),'Processing off still hides customer renewal');
    commerceReject(fn()=>$ownerBilling->invoice($d->id,$user,1));
    same($before,Invoice::count(),'Processing off cannot issue debt');
    $bindings->setProcessing($server->id,true,syntheticActivation());
}
// Read-only advice never relaxes the transaction requirement of the default writer guard.
$account=Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server->id)->sole();
try{$bindings->requireProcessingLocked($server->fresh(),$account);expect(false,'Writer guard requires a transaction');}
catch(LogicException $e){same('Locked processing requires an outer transaction.',$e->getMessage(),'Writer guard preserved');}
$invoice=$ownerBilling->invoice($d->id,$user,1);
same(['paid',0.0],[$invoice->status,(float)$invoice->total],'Authorized complimentary renewal still uses the guarded invoice writer');
$nativeSuccessful=true;echo "Native paid and complimentary customer renewal options passed.\n";
