<?php
// Synthetic local activation policy only; no registrar transport or Live acceptance.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\Server;
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
$staff=staffUser(['admin.domains.view','admin.domains.manage-policy',Review::permission('configure')]);
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
same([[$runtimeProblem],[$runtimeProblem]],$runtimeProblems,'Missing and malformed runtime revisions appear in readiness');
config(['domains.namesilo_live.runtime_revision'=>'OFFLINE-v1']);
same($readyProblems,$readiness(),'Restoring a valid runtime revision restores readiness');
$saveReceipt=static function()use($engine,$binding,$server):void {
    $server->settings()->updateOrCreate(['key'=>LiveActivation::KEY],['encrypted'=>true,'type'=>'string',
        'value'=>json_encode([...$engine->receiptFor($binding),'registration'=>true],JSON_THROW_ON_ERROR)]);
};
$saveReceipt();expect($engine->current($binding)&&$engine->registrationAccepted($binding),'Synthetic current receipt is bound to the local key and deployment');
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
$full=staffUser(array_keys(Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()));
Illuminate\Support\Facades\Auth::setUser($full);
expect(Paymenter\Extensions\Servers\Domains\Admin\Support\LiveActivationAction::offered($a->fresh()),'NameSilo has the same reviewed Activate entry point');
$summary=Paymenter\Extensions\Servers\Domains\Admin\Support\LiveActivationAction::summary($a->fresh())->toHtml();
expect(str_contains($summary,'NameSilo takes domain costs')&&!str_contains($summary,'Enom'),'Activation summary uses this registrar and its readiness gates');
$nativeSuccessful=true;
