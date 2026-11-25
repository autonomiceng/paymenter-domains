<?php
// Native upload, boot and retained-data acceptance; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$lifecycleStarted=hrtime(true);
$bootStarted=$lifecycleStarted;
require __DIR__.'/Support/installed-app.php';
$bootSeconds=(hrtime(true)-$bootStarted)/1e9;
require_once __DIR__.'/Support/template-defaults.php';
$traceTiming=static function(string $stage,int $started)use($lifecycleStarted,$bootSeconds):void {
    if(getenv('PACKAGE_TIMINGS')==='1'){
        fwrite(STDERR,json_encode(['timing'=>'package_lifecycle','phase'=>(string)getenv('PACKAGE_PHASE'),
            'stage'=>$stage,'seconds'=>round((hrtime(true)-$started)/1e9,3),
            'boot_seconds'=>round($bootSeconds,3),'total_seconds'=>round((hrtime(true)-$lifecycleStarted)/1e9,3)],JSON_THROW_ON_ERROR)."\n");
    }
};
if(getenv('PACKAGE_TIMINGS')==='1'){
    register_shutdown_function(static function()use($lifecycleStarted):void {
        $usage=getrusage();$gc=gc_status();
        fwrite(STDERR,json_encode(['timing'=>'package_resources','phase'=>(string)getenv('PACKAGE_PHASE'),
            'seconds'=>(hrtime(true)-$lifecycleStarted)/1e9,
            'cpu_seconds'=>$usage['ru_utime.tv_sec']+$usage['ru_utime.tv_usec']/1e6+$usage['ru_stime.tv_sec']+$usage['ru_stime.tv_usec']/1e6,
            'gc_seconds'=>$gc['collector_time'],'gc_runs'=>$gc['runs'],'peak_bytes'=>memory_get_peak_usage(true)],JSON_THROW_ON_ERROR)."\n");
    });
}
use Illuminate\Support\Facades\{Artisan,DB,Event,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,Runtime,AccountBindings,NativeCatalogue,PriceCatalogue,SellerPolicies};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
$phase=(string)getenv('PACKAGE_PHASE');$receipt=(string)getenv('PACKAGE_RETAINED');
const TEMPLATE_UPGRADE_AT='2026-10-23 12:00:00';
const TEMPLATE_BEFORE_AT='2026-10-22 12:00:00';
function retained(): array
{
    $tables=array_filter(Schema::getTableListing(null,false),fn($t)=>str_starts_with($t,'domains_')||in_array($t,['users','orders','services','invoices','invoice_items','invoice_transactions','credits','settings','email_templates','notification_templates'],true));
    $result=[];sort($tables);
    foreach($tables as $table){
        $query=DB::table($table);
        // Verification is derived state replaced by every successful install or explicit check.
        if($table==='settings'){$query->whereNot(static fn($q)=>$q->whereNull('settingable_type')->whereNull('settingable_id')->where('key',Installation::VERIFIED_KEY));}
        $rows=$query->get()->map(function($r)use($table){$row=(array)$r;if($table==='domains_accounts'){unset($row['updated_at']);}ksort($row);return $row;})->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$result[$table]=$rows;
    }
    expect(isset($result['domains_accounts'],$result['domains_operations'],$result['users']),'Retained snapshot includes Domains and native tables');
    return $result;
}
function assertRetained(string $receipt): void
{
    $before=json_decode(file_get_contents($receipt),true,512,JSON_THROW_ON_ERROR);$after=retained();
    foreach($before as $table=>$rows){
        expect(array_key_exists($table,$after),'Retained table exists: '.$table);
        // Only template fields still holding an exact earlier shipped default may change, with the save timestamp.
        // Every other raw value, row identity and multiplicity must survive.
        $prior=priorReminderDefaults();
        $current=Paymenter\Extensions\Servers\Domains\Services\ReminderTemplates::definitions();
        foreach($rows as &$row){
            if($table==='notification_templates' && isset($prior[$row['key']??null])){
                $changed=false;
                foreach($prior[$row['key']] as $field=>$versions){
                    if(array_key_exists($field,$row) && in_array($row[$field],$versions,true) && $row[$field]!==$current[$row['key']][$field]){
                        $row[$field]=$current[$row['key']][$field];
                        $changed=true;
                    }
                }
                if($changed){$row['updated_at']=TEMPLATE_UPGRADE_AT;}
            }
        }
        unset($row);
        $columns=array_fill_keys(array_keys($rows[0]??[]),true);
        $counts=array_count_values(array_map(fn($row)=>json_encode(array_intersect_key($row,$columns),JSON_THROW_ON_ERROR),$after[$table]));
        foreach($rows as $row){
            $key=json_encode($row,JSON_THROW_ON_ERROR);
            if(($counts[$key]??0)<1){
                $match=array_values(array_filter($after[$table],fn($a)=>isset($row['id'])&&($a['id']??null)===$row['id']))[0]??[];
                expect(false,'Retained raw rows unchanged: '.$table.' id '.($row['id']??'?').' '.($row['key']??'').' fields '.implode(',',array_keys(array_diff_assoc(array_map('json_encode',$row),array_map('json_encode',array_intersect_key($match,$columns))))));
            }
            $counts[$key]--;
        }
    }
}
// Reinstall clears it before the extension classes exist again.
const VERIFIED_KEY='domains_schema_verified';
/** Only a complete verification writes this record, so a cleared record that returns proves the native call verified. */
function clearVerification(): void
{
    DB::table('settings')->whereNull('settingable_type')->whereNull('settingable_id')->where('key',VERIFIED_KEY)->delete();
}
function assertVerified(string $message): void
{
    same(VERIFIED_KEY,Installation::VERIFIED_KEY,'Lifecycle checks the shipped verification record');
    expect(Paymenter\Extensions\Servers\Domains\Installation\Verification::current(),$message);
}
function assertBooted(): void
{
    same(1,count(Event::getListeners('navigation')),'One native navigation listener');
    same(1,count(Event::getListeners('navigation.dashboard')),'One native dashboard listener');
    $events=app(Illuminate\Console\Scheduling\Schedule::class)->events();
    same(1,count(array_filter($events,fn($e)=>$e->description==='domains-runtime')),'One native runtime scheduler');
    $panel=Filament\Facades\Filament::getPanel('admin');
    expect(in_array(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class,$panel->getPages(),true),'Native panel discovers installed pages');
    app('router')->getRoutes()->refreshNameLookups();
    expect(app('router')->getRoutes()->getByName('domains.domains.search')!==null,'Installed storefront route');
    foreach([Installation::class,Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class,Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain::class] as $class){
        expect(str_starts_with((new ReflectionClass($class))->getFileName(),base_path('extensions/Servers/Domains/')),'Class resolves from ZIP: '.$class);
    }
}
if($phase==='server-delete'){
    $server=App\Models\Server::where('extension','Domains')->firstOrFail();
    // Paymenter's native DeleteAction calls disabled before attempting deletion.
    App\Helpers\ExtensionHelper::call($server,'disabled',[$server],mayFail:true);
    try{$server->delete();expect(false,'Retained server deletion must refuse');}catch(DomainException){}
    assertRetained($receipt);
    // The extension's separate uninstall hook also promises retained history.
    App\Helpers\ExtensionHelper::call($server,'uninstalled',mayFail:true);assertRetained($receipt);
    echo "Native disabled/delete refused and uninstall hook retained data.\n";
}elseif(in_array($phase,['install','upload','reinstall'],true)){
    Illuminate\Support\Carbon::setTestNow(TEMPLATE_UPGRADE_AT);
    if(in_array($phase,['install','reinstall'],true)){expect(!is_dir(base_path('extensions/Servers/Domains')),'Native Install branch requires absent runtime files');}
    $zip=(string)getenv('PACKAGE_ZIP');expect(is_file($zip),'Verified upload copy exists');
    if($phase!=='install'){clearVerification();}
    $installStarted=hrtime(true);
    same('server',app(App\Services\Extensions\UploadExtensionService::class)->handle($zip),'Native uploader classifies and installs server extension');
    $traceTiming('zip_install',$installStarted);
    expect(!file_exists($zip),'Native uploader consumed upload');
    expect(is_file(base_path('extensions/Servers/Domains/manifest.json')),'Native extraction retained embedded manifest');
    // Upstream commands catch exceptions: the lifecycle's own complete verification must have recorded this schema.
    assertVerified('Native '.$phase.' verified the complete installed schema');
    if($phase==='install'){
        same((int)getenv('PACKAGE_MIGRATIONS'),count(glob(base_path('extensions/Servers/Domains/database/migrations/*.php'))),'Installed migration inventory');
        foreach(glob(base_path('extensions/Servers/Domains/database/migrations/*.php')) as $migration){same(1,DB::table('migrations')->where('migration',basename($migration,'.php'))->count(),'Native install ran each extension migration once');}
        same(0,Account::count(),'Fresh package creates no activated accounts');
        same('active',Paymenter\Extensions\Servers\Domains\Catalogue\CatalogueActivation::routeState()['state'],'Fresh native installation activates the offer catalogue');
        echo "Native upload/extract/Install branch passed.\n";
    }elseif($phase==='reinstall'){
        same(1,DB::table('migrations')->where('migration',Installation::BASELINE)->count(),'Reinstall retains the baseline migration record');
        assertRetained($receipt);echo "Native reinstall retained all recorded data and the TEST send limit schema.\n";
    }else{assertRetained($receipt);echo "Native upload retained all recorded data (".$phase.").\n";}
}elseif($phase==='enom-live'){
    require __DIR__.'/Support/enom-live.php';
}elseif(str_starts_with($phase,'enom-test-transfer-group-')){
    $group=substr($phase,strlen('enom-test-transfer-group-'));expect(in_array($group,['arrival','capacity','preflight','contacts'],true),'Known transfer guard group');
    require __DIR__.'/Support/enom-transfer-'.$group.'-cases.php';
}elseif($phase==='enom-test-transfer-restart'){
    require __DIR__.'/Support/enom-transfer-restart.php';
}elseif($phase==='enom-test-management'){
    $setupStarted=hrtime(true);
    require __DIR__.'/Support/enom-test-management-fixture.php';
    $traceTiming('management_fixture_setup',$setupStarted);
    $managementStarted=hrtime(true);
    require __DIR__.'/Support/enom-test-management.php';
    $traceTiming('management_assertions',$managementStarted);
}elseif($phase==='enom-test-management-restart'){
    require __DIR__.'/Support/enom-management-restart.php';
}elseif($phase==='enom-test-renewal-group-seed'){
    require __DIR__.'/Support/enom-renewal-seed.php';
}elseif(str_starts_with($phase,'enom-test-renewal-group-')){
    $group=substr($phase,strlen('enom-test-renewal-group-'));
    expect(in_array($group,['policy','confirmation','cap','refusals','history','replacement','race'],true),'Known renewal guard group');
    require __DIR__.'/Support/enom-renewal-'.$group.'-cases.php';
}elseif($phase==='enom-test-renewal-restart'){
    require __DIR__.'/Support/enom-renewal-restart.php';
}elseif(str_starts_with($phase,'enom-test-renewal-race-')){
    require __DIR__.'/Support/enom-renewal-race.php';
}elseif(str_starts_with($phase,'enom-test-registration-group-')){
    $group=substr($phase,strlen('enom-test-registration-group-'));
    expect(in_array($group,['contracts','work','contacts','cap','balance','reconciliation','scope','checkout'],true),'Known registration guard group');
    require __DIR__.'/Support/enom-registration-'.$group.'-cases.php';
    file_put_contents($receipt.'.enom-sales-fixture',json_encode(['staff'=>$staff->id,'server'=>$server->id,'user'=>$user->id,'tld'=>$tld->id,'at'=>$clock->now()->format(DATE_ATOM)],JSON_THROW_ON_ERROR));
}elseif($phase==='enom-test-calls'){
    require __DIR__.'/Support/installed-test-counter.php';
}elseif($phase==='enom-test-call-count'){
    same(7,DB::table(Paymenter\Extensions\Servers\Domains\Services\EnomTestSendLimitSchema::SENDS)->where('account',Paymenter\Extensions\Servers\Domains\Services\EnomTestSendLimit::account('counter-race-uid'))->count(),'TEST send attempts remain retained across process restart and reinstall');
}elseif($phase==='held'){
    require __DIR__.'/Support/registration-held.php';
    require __DIR__.'/Support/runtime-activation.php';
}elseif($phase==='namesilo-enrollment'){
    require __DIR__.'/Support/namesilo-enrollment-wire.php';
    $enrollmentInstalledSmoke=true;
    require __DIR__.'/Support/namesilo-enrollment-staff.php';
    expect(str_starts_with((new ReflectionClass(Paymenter\Extensions\Servers\Domains\Services\NameSiloSandbox::class))->getFileName(),base_path('extensions/Servers/Domains/')),'Enrollment uses installed ZIP');
}elseif(str_starts_with($phase,'identity-group-')){
    $identityStaffGroup=substr($phase,strlen('identity-group-'));
    expect(in_array($identityStaffGroup,['balance','rotation','runtime'],true),'Known identity guard group');
    require __DIR__.'/Support/enom-identity.php';$GLOBALS['identityCalls']=[];
    require __DIR__.'/Support/enom-identity-staff.php';
}elseif($phase==='catalogue'){
    require __DIR__.'/Support/enom-catalogue-wire.php';
    require __DIR__.'/Support/enom-catalogue.php';
    file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));
}elseif($phase==='catalogue-retained'){
    assertRetained($receipt);
    $before=retained();
    $rows=app(Paymenter\Extensions\Servers\Domains\Services\Storefront::class)->endings();
    same([],$rows,'Reinstalled paused catalogue remains unavailable to new sales');
    expect(\Paymenter\Extensions\Servers\Domains\Models\Tld::where('tld','com')->whereNotNull('catalogue_facts')->exists(),'Reinstall retains independent active facts');
    same($before,retained(),'Reinstalled page projection changes no retained data');
    expect(Account::where('processing_enabled',true)->doesntExist(),'Reinstall preserves processing off');
    echo "Installed catalogue, prices, seller and one-use audit survive reinstall.\n";
}elseif($phase==='template-defaults'){
    same(1,DB::table('notification_templates')->where('key','domains_domain_expiring')->update([...previousReminderDefaults(),'updated_at'=>TEMPLATE_BEFORE_AT]),'Seed both exact previous defaults');
    file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));
}elseif($phase==='exchange-contracts'){
    require __DIR__.'/Support/enom-wire.php';require __DIR__.'/Support/enom-exchange.php';
}elseif(in_array($phase,['namesilo-registration','namesilo-renewal'],true)){
    $installedFixture=true;$fixtureActivation=static fn()=>null;
    foreach([\Paymenter\Extensions\Servers\Domains\Services\RegistryInputs::class,
        \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Registration::class,
        \Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalCompletion::class,
        \Paymenter\Extensions\Servers\Domains\Registrars\Dispatch\RenewalWriter::class] as $type){
        expect(str_starts_with((new ReflectionClass($type))->getFileName(),base_path('extensions/Servers/Domains/')),'NameSilo preparation uses installed ZIP runtime');
    }
    if($phase==='namesilo-registration'){
        require __DIR__.'/Support/namesilo-registration-fixture.php';
        require __DIR__.'/Support/namesilo-nexus.php';
    }else{require __DIR__.'/renewal-execution-namesilo-test.php';}
}elseif(str_starts_with($phase,'acquisition-group-')){
    $group=substr($phase,strlen('acquisition-group-'));expect(in_array($group,['guards','dispatch'],true),'Known acquisition guard group');
    require __DIR__.'/Support/runtime-acquisition-'.$group.'.php';
}elseif(str_starts_with($phase,'enom-exchange-group-')){
    $group=substr($phase,strlen('enom-exchange-group-'));expect(in_array($group,['dispatch','authority','reconciliation'],true),'Known exchange guard group');
    require __DIR__.'/Support/runtime-enom-exchange-'.$group.'.php';
}elseif($phase==='seed'){
    require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';
    expect(!is_link(base_path('extensions/Servers/Domains')),'Runtime is installed files, never a source link');
    $entry=new Paymenter\Extensions\Servers\Domains\Domains;
    require __DIR__.'/Support/installed-fresh.php';extract(installedFreshCommerceFixture());
    $clock=new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now);
    app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
    foreach([AccountBindings::class=>$bindings,NativeCatalogue::class=>$native,PriceCatalogue::class=>$prices,SellerPolicies::class=>$sellers] as $type=>$instance){app()->instance($type,$instance);}
    $entry->boot();$entry->boot();
    $user=commerceUser();$cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[0],'register'),'retained-package-placeholder.com');
    commerceCheckout($user,$cart)->assertHasNoErrors();
    $op=Operation::sole();$op->update(['state'=>'uncertain','attempt'=>['execution'=>null],'safe_code'=>'staff_review_required']);
    App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded','transaction_id'=>'package-payment']);
    App\Models\Credit::create(['user_id'=>$user->id,'currency_code'=>'USD','amount'=>'4.25']);
    foreach($tlds as $t){$bindings->setProcessing($t->server_id,false);}
    expect(Account::where('processing_enabled',true)->doesntExist(),'Processing parked for retained acceptance');
    same(1,DB::table('notification_templates')->where('key','domains_domain_expiring')->update([
        'subject'=>'Retained synthetic operator subject','body'=>'Retained synthetic operator content.',
        'in_app_body'=>previousReminderDefaults()['in_app_body'],'updated_at'=>TEMPLATE_BEFORE_AT,
        'enabled'=>false,'mail_enabled'=>'never',
        'in_app_enabled'=>'never','in_app_title'=>'Retained title','in_app_url'=>'/retained','edit_preference_message'=>'Retained preference',
    ]),'Seed custom email with exact previous in-app default');
    same(1,DB::table('notification_templates')->where('key','domains_domain_expired')->update(previousReminderDefaults()),'Other template keeps matching historical text as custom content');
    same(1,DB::table('notification_templates')->where('key','domains_transfer_code_reminder')->update(['subject'=>'Retained operator transfer subject','updated_at'=>TEMPLATE_BEFORE_AT]),'Seed one edited transfer field beside shipped defaults');
    file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));
    file_put_contents((string)getenv('PACKAGE_MIGRATION_RECEIPT'),json_encode(DB::table('migrations')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all(),JSON_THROW_ON_ERROR));
    echo "Native checkout, uncertain evidence, invoice/payment/credit and reservations seeded.\n";
}elseif($phase==='verify'){
    assertBooted(); // Native provider discovery must supply these before any manual boot.
    same('active',Paymenter\Extensions\Servers\Domains\Catalogue\CatalogueActivation::routeState()['state'],'Fresh catalogue remains active across upload, upgrade and reinstall');
    // The upload recorded its verification; a cold boot must still match it before any second upgrade can repair it.
    assertVerified('Installed schema, migrations and package still match the upload-time verification');
    assertRetained($receipt);
    $before=json_decode(file_get_contents((string)getenv('PACKAGE_MIGRATION_RECEIPT')),true,512,JSON_THROW_ON_ERROR);
    $after=DB::table('migrations')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
    same($before,array_slice($after,0,count($before)),'Original migration history unchanged');
    same((int)getenv('PACKAGE_MIGRATIONS'),count(glob(base_path('extensions/Servers/Domains/database/migrations/*.php'))),'Installed baseline migration count');
    foreach(glob(base_path('extensions/Servers/Domains/database/migrations/*.php')) as $migration){same(1,DB::table('migrations')->where('migration',basename($migration,'.php'))->count(),'Each installed migration ran once');}
    if(class_exists(Paymenter\Extensions\Servers\Domains\Services\EnomLive::class)){
        $oldLiveConfig=config('domains.enom_live');$oldIdentity=config('domains.enom_identity.environment');$oldUrl=config('app.url');
        same(false,Paymenter\Extensions\Servers\Domains\Services\EnomLive::requested(),'Upgrade keeps every live opt-in off');
        same(0,DB::table(Paymenter\Extensions\Servers\Domains\Services\EnomLiveCallSchema::TABLE)->count(),'Upgrade creates no live call or spend authority');
        foreach(Account::all() as $account){foreach(['catalogue','registration','renewal','management'] as $action){
            expect(!Paymenter\Extensions\Servers\Domains\Services\EnomLive::enabled(\Paymenter\Extensions\Servers\Domains\Services\AccountBindings::binding($account),$action),'Retained account gains no live action');
        }}
        config(['domains.enom_live'=>$oldLiveConfig,'domains.enom_identity.environment'=>$oldIdentity,'app.url'=>$oldUrl]);
    }
    // A second native command is solely an idempotency check, after proving upload.
    $rawBefore=retained();clearVerification();
    Artisan::call(App\Console\Commands\Extension\Upgrade::class,['type'=>'server','name'=>'Domains','oldVersion'=>'0.1.0']);
    assertVerified('Repeated native upgrade verified the complete installed schema');
    same($after,DB::table('migrations')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all(),'Repeated native upgrade changes no migration history');
    same($rawBefore,retained(),'Repeated native upgrade changes no raw retained rows');
    assertRetained($receipt);
    file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));
    file_put_contents((string)getenv('PACKAGE_MIGRATION_RECEIPT'),json_encode($after,JSON_THROW_ON_ERROR));
    $entry=new Paymenter\Extensions\Servers\Domains\Domains;$entry->boot();$entry->boot();
    assertBooted();
    expect(Account::where('processing_enabled',true)->doesntExist(),'Boot keeps processing parked');
    echo "Fresh installed boot: migrations verified, data retained, nav/pages/routes/scheduler registered once.\n";
}else{throw new RuntimeException('Unknown package fixture phase');}
$nativeSuccessful=true;
