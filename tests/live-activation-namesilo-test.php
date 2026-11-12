<?php
// Synthetic local activation policy only; no registrar transport or Live acceptance.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\Server;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerPorts,Context,Cost,Facts,OfferReadiness,OfferState,Query,UpgradeGate};
use Paymenter\Extensions\Servers\Domains\Contracts\{Clock,CredentialAuthenticator};
use Paymenter\Extensions\Servers\Domains\Data\{Environment,SystemClock};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,Installation,LiveActivation,RegistrarRegistry,StaffSettingsReview as Review};
(new Installation)->install();
app()->instance(Clock::class,new SystemClock);
$registry=new RegistrarRegistry;
$registry->register('namesilo',['api_key'],new NameSilo\AccountVerifier,
    static fn()=>throw new LogicException('Activation must not construct a provider reader'),
    authenticator:new class implements CredentialAuthenticator {
        public function authenticate(Environment $environment,#[SensitiveParameter] array $credentials): void {}
    });
$bindings=new AccountBindings($registry);app()->instance(AccountBindings::class,$bindings);
$staff=staffUser(['admin.domains.view','admin.domains.manage-policy',Review::permission('configure'),Review::permission('live_transfer')]);
$server=Server::create(['name'=>'Synthetic activation','type'=>'server','extension'=>'Domains','enabled'=>true]);
config(['app.url'=>'https://billing.example.com','domains.namesilo_account.environment'=>'live']);
$configure=static fn()=>new Review($staff->id,$server->id,null,'configure',
    Review::ticket($staff->id,$server->id,null,'configure',Review::stamp($server->id,null)),'Configure synthetic local credentials');
$binding=$bindings->configure($server->id,'namesilo',Environment::Live,['api_key'=>'placeholder-key-one'],$configure());
$bindings->confirmLiveSite($server->id,'https://billing.example.com');
$a=Account::where('server_id',$server->id)->sole();
$history=['timezone'=>'UTC','lag'=>60,'retention'=>86400];
$evidence=['fingerprint'=>$binding->fingerprint,'site'=>'https://billing.example.com','confirmed_on'=>'2026-10-20',
    'reference'=>'OFFLINE synthetic NameSilo answer','sha256'=>hash('sha256','OFFLINE synthetic answer'),
    'currency'=>'USD','complete_history'=>true,'history'=>['registration'=>$history,'renewal'=>$history,'transfer'=>$history]];
$row=['fingerprint'=>$binding->fingerprint,'currency'=>'USD','balance_funding'=>true,
    'daily_call_cap'=>1000,'daily_spend_limit_usd'=>'100.00','evidence'=>$evidence];
config(['domains.namesilo_live'=>['site'=>'https://billing.example.com','catalogue'=>true,'registration'=>true,
    'renewal'=>true,'management'=>true,'transfer'=>true,'runtime_revision'=>'OFFLINE-v1','accounts'=>[$a->stable_identity=>$row]]]);
$engine=new LiveActivation(new NameSilo\ActivationEvidence);
same(true,Paymenter\Extensions\Servers\Domains\Services\EnomLiveActivation::defaults($a)['transfer'],
    'An unsaved transfer choice is included in the reviewed activation defaults');
expect(!Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::accepted($binding)&&!$a->fresh()->processing_enabled,
    'Reading proposed defaults grants neither account permission nor Orders');
$denied=static function(callable $work,string $message):void {
    try{$work();throw new LogicException($message);}catch(DomainException){}
};
$denied(fn()=>$engine->evidence($binding),'NameSilo activation requires portal-only controls');
$controls=['reject_invalid_contacts'=>true,'restore_disabled'=>true,'auto_replenishment_disabled'=>true];
$setEvidence=static function(array $value)use($a):void {config(['domains.namesilo_live.accounts.'.$a->stable_identity.'.evidence'=>$value]);};
$evidence['portal_controls']=$controls;$setEvidence($evidence);
same($evidence,$engine->evidence($binding),'Dated bound evidence retains the portal controls');
foreach(array_keys($controls) as $control){
    foreach([false,'true',1,null] as $invalid){
        $bad=$evidence;$bad['portal_controls'][$control]=$invalid;$setEvidence($bad);
        $denied(fn()=>$engine->evidence($binding),'Every portal control requires explicit true');
    }
}
foreach(['fingerprint'=>'wrong','site'=>'https://copied.example.com','confirmed_on'=>'2999-01-01','sha256'=>'not-a-digest','currency'=>'EUR','complete_history'=>false] as $key=>$value){
    $setEvidence([...$evidence,$key=>$value]);$denied(fn()=>$engine->evidence($binding),'Unbound, undated or incomplete evidence refuses');
}
$setEvidence($evidence);
$withoutTransfer=$evidence;unset($withoutTransfer['history']['transfer']);
$denied(fn()=>$engine->history($binding,$withoutTransfer,'transfer'),'Missing transfer history refuses NameSilo activation');
foreach(['registration','renewal','transfer'] as $feature){
    $h=$engine->history($binding,$engine->evidence($binding),$feature);
    same('namesilo_'.$feature.'_v1',$h->vocabulary,'NameSilo uses its own history vocabulary');
    $engine->requireHistory($h);
    $changed=new \Paymenter\Extensions\Servers\Domains\Data\HistoryAcceptance($binding,'UTC',61,86400,'namesilo_'.$feature.'_v1');
    $denied(fn()=>$engine->requireHistory($changed),'TEST or changed history cannot overwrite Live evidence');
}
$runtimeProblem='NameSilo runtime revision Missing. Ask your site admin to install the admitted runtime.';
$readiness=static fn()=>$engine->problems($a,['registration'=>true],static function():void {});
$readyProblems=$readiness();$runtimeProblems=[];
expect(!in_array($runtimeProblem,$readyProblems,true),'A valid runtime revision has no readiness problem');
foreach([null,'invalid revision'] as $invalid){
    config(['domains.namesilo_live.runtime_revision'=>$invalid]);
    $runtimeProblems[]=array_values(array_diff($readiness(),$readyProblems));
    try{$engine->receiptFor($binding);throw new LogicException('Invalid runtime revision must refuse a receipt');}
    catch(DomainException $e){same($runtimeProblem,$e->getMessage(),'Readiness and receipt use the same runtime revision problem');}
}
$runtimeMissing='Site setup Missing. Ask your site admin to install the registrar runtime.';
same([[$runtimeProblem,$runtimeMissing],[$runtimeProblem,$runtimeMissing]],$runtimeProblems,'Missing and malformed revisions also close the installed runtime');
config(['domains.namesilo_live.runtime_revision'=>'OFFLINE-v1']);
same($readyProblems,$readiness(),'Restoring a valid runtime revision restores readiness');
// Registration and transfer each need contact creation even with the staff management choice Off.
$contactProblem='Contact profiles Missing. Ask your site admin to enable NameSilo management in the deployment; registration and transfer need it to create contacts.';
foreach(['registration','transfer'] as $feature){
    $choices=['registration'=>false,'transfer'=>false,'renewal'=>false,'management'=>false,$feature=>true];
    $before=$engine->problems($a,$choices,static function():void {});
    config(['domains.namesilo_live.management'=>false]);
    $after=$engine->problems($a,$choices,static function():void {});
    expect(in_array($contactProblem,$after,true)&&!in_array($contactProblem,$before,true),'Activation refuses '.$feature.' when its deployment contact capability is Off');
    config(['domains.namesilo_live.management'=>true]);
}
$saveReceipt=static function()use($engine,$binding,$server):void {
    $server->settings()->updateOrCreate(['key'=>LiveActivation::KEY],['encrypted'=>true,'type'=>'string',
        'value'=>json_encode([...$engine->receiptFor($binding),'registration'=>true],JSON_THROW_ON_ERROR)]);
};
// A local reviewed Off is retained even before an activation receipt exists.
$offReview=new Review($staff->id,$server->id,null,'live_transfer',
    Review::ticket($staff->id,$server->id,null,'live_transfer',Review::stamp($server->id,null)),'Keep transfers Off');
Paymenter\Extensions\Servers\Domains\Services\NameSiloFeatureSettings::review($offReview,['accept'=>false],'transfer');
same(false,Paymenter\Extensions\Servers\Domains\Services\EnomLiveActivation::defaults($a)['transfer'],'Saved NameSilo transfer Off is not preselected');
$server->settings()->where('key','domains_namesilo_transfer')->delete();
$saveReceipt();
same(false,Paymenter\Extensions\Servers\Domains\Services\EnomLiveActivation::defaults($a)['transfer'],'Older reviewed accounts with no transfer setting retain Off');
expect($engine->current($binding)&&$engine->registrationAccepted($binding),'Synthetic current receipt is bound to the local key and deployment');
expect(!$a->fresh()->processing_enabled,'Reading readiness or receipts grants no processing authority');
$before=['registration'=>true,'renewal'=>false,'management'=>false,'transfer'=>false];
config(['domains.namesilo_live.runtime_revision'=>'OFFLINE-v2']);
expect(!$engine->current($binding),'Runtime changes invalidate the retained receipt');
same(['registration'=>true],$engine->enabling($a,$before,$before),'Stale receipt rechecks retained On choices');
same([],$engine->enabling($a,[...$before,'registration'=>false],$before),'Withdrawal needs no readiness');
config(['domains.namesilo_live.runtime_revision'=>'OFFLINE-v1']);expect($engine->current($binding),'Unchanged deployment retains the same evidence binding');
$adapter=new NameSilo\ActivationEvidence;
foreach(['catalogue','registration','renewal','management','transfer'] as $feature){
    expect($adapter->enabled($binding,$feature),'Explicit deployment feature is available locally');
    config(['domains.namesilo_live.'.$feature=>false]);expect(!$adapter->enabled($binding,$feature),'Deployment Off refuses the feature');
    expect(!$engine->current($binding),'Relevant deployment changes stale the receipt');config(['domains.namesilo_live.'.$feature=>true]);
}
$stamp=$engine->stamp($a);
$bindings->configure($server->id,'namesilo',Environment::Live,['api_key'=>'placeholder-key-two'],$configure());
expect(!$engine->current($binding),'Credential rotation invalidates the receipt while preserving lineage');
expect($stamp!==$engine->stamp($a),'Credential rotation also makes a displayed activation review stale');
echo "NameSilo local activation evidence and stale-receipt policy passed without registrar transport.\n";
staffPanel();
$full=staffUser(array_keys(Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()));
Illuminate\Support\Facades\Auth::setUser($full);
expect(Paymenter\Extensions\Servers\Domains\Admin\Support\LiveActivationAction::offered($a->fresh()),'NameSilo has the same reviewed Activate entry point');
$summary=Paymenter\Extensions\Servers\Domains\Admin\Support\LiveActivationAction::summary($a->fresh())->toHtml();
expect(str_contains($summary,'NameSilo takes domain costs')&&!str_contains($summary,'Enom'),'Activation summary uses this registrar and its readiness gates');
expect(str_contains($summary,'Renewals also enable scheduled invoices and customer early renewals.')&&!str_contains($summary,'Late renewal has a separate account switch.'),
    'NameSilo activation explains renewal billing without offering an unsupported late-renewal switch');
$panel=Filament\Facades\Filament::getCurrentPanel();
$pages=[Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class,Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarHistory::class];$panel->pages($pages);
Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$pages){foreach($pages as $page){$page::registerRoutes($panel);}});
app('router')->getRoutes()->refreshNameLookups();
$action=Filament\Actions\Testing\TestAction::make('activate_live')->schemaComponent('account_'.$server->id.'.live_sales','content');
$page=Livewire\Livewire::actingAs($full)->test($pages[0],['account'=>$server->id])->mountAction($action)->assertActionMounted($action);
$page->callMountedAction()->assertNotified('Could not activate');
expect(!$a->fresh()->processing_enabled,'Missing prices keep Live spending closed');
// Native installed routing is required: a unit reader cannot exercise CallerRouting's setup gates.
// OFFLINE price/fact rows model setup only; no activation or provider send is performed.
Paymenter\Extensions\Servers\Domains\Services\Runtime::register();
same('active',UpgradeGate::routeState()['state'],'Real fresh installation has already activated current catalogue');
$server->settings()->where('key',LiveActivation::KEY)->delete();
expect(!$engine->current($binding),'Setup review starts without an activation receipt');
$now=app(Clock::class)->now();
$key=['server_id'=>$server->id,'registrar_key'=>'namesilo','account_fingerprint'=>$binding->fingerprint,'tld'=>'com'];
DB::table('domains_tlds')->insert([...$key,'catalogue_converted'=>true,'catalogue_revision'=>1,
    'catalogue_intent'=>OfferState::json(['sell'=>true,'longest'=>1,'transfers'=>true]),
    'catalogue_published_revision'=>OfferState::json(['offer'=>1,'defaults'=>0,'selection'=>1,'retailRule'=>\Paymenter\Extensions\Servers\Domains\Catalogue\Retail::VERSION]),
    'catalogue_retained_annual_retail'=>'13.00',
    'catalogue_facts'=>OfferState::encodeFacts(new Facts(['register'=>[1],'renew'=>[1],'transfer'=>[1]],[],true,false,false,
        'offline_synthetic_setup',$now,$now->modify('+1 day')))]);
DB::table('domains_sales_policies')->insert([...$key,'revision'=>1,'register_enabled'=>true,'transfer_enabled'=>true]);
foreach(['register','renew','transfer'] as $action){
    $cost=new Cost($action,1,true,'8.0000',Paymenter\Extensions\Servers\Domains\Data\CostUnit::TermTotal,
        'offline_synthetic_setup',$now,$now->modify('+1 day'));
    DB::table('domains_prices')->insert([...$key,'action'=>$action,'term'=>1,'currency'=>'USD','retail'=>'13.00',
        'unit'=>'term_total','source'=>$cost->source,'observed_at'=>$now->format('Y-m-d H:i:s'),
        'catalogue_observation'=>OfferState::json(OfferState::encodeCost($cost))]);
}
// Isolate feature readiness from the separately tested Orders precondition; always restore Orders Off.
DB::table('domains_accounts')->where('id',$a->id)->update(['processing_enabled'=>true]);
try{
    $ready=OfferReadiness::routed();$features=['registration'=>false,'renewal'=>true,'transfer'=>true];
    $review=$ready->review($a->fresh(),$features);
    same(null,$review['missing'],'Live NameSilo renewal and transfer setup prices do not require an activation receipt');
    same(['transfer','renew'],array_column($review['costs'],'action'),'Setup reviews both transfer and renewal prices');
    same($review,DB::transaction(fn()=>$ready->review($a->fresh(),$features,true)),
        'Locked activation price readiness uses the same receipt-independent feature gates');
    foreach(['renew','transfer'] as $action){
        expect(!CallerPorts::routed()->loadBatch([new Query($binding,['com'],$action)],Context::Staff)[0][0]->decision->available,
            'Receipt-independent setup grants no '.$action.' sales permission');
    }
    foreach(['renewal','transfer'] as $feature){
        config(['domains.namesilo_live.'.$feature=>false]);
        expect($ready->review($a->fresh(),$features)['missing']!==null,'Deployment Off still refuses '.$feature.' setup');
        config(['domains.namesilo_live.'.$feature=>true]);
    }
    expect($ready->review($a->fresh(),['registration'=>true])['missing']!==null,'Live registration stays outside the TEST-only sales gate');
    expect(!$engine->current($binding),'Reading setup prices never creates an activation receipt');
}finally{DB::table('domains_accounts')->where('id',$a->id)->update(['processing_enabled'=>false]);}
$nativeSuccessful=true;
