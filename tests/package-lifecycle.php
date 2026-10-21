<?php
// Native upload, boot and retained-data acceptance; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/installed-app.php';
require __DIR__.'/Support/installed-upgrade.php';
use Illuminate\Support\Facades\{Artisan,DB,Event,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{Installation,Runtime,AccountBindings,NativeCatalogue,PriceCatalogue,SellerPolicies};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
$phase=(string)getenv('PACKAGE_PHASE');$receipt=(string)getenv('PACKAGE_RETAINED');
function retained(): array
{
    $tables=array_filter(Schema::getTableListing(null,false),fn($t)=>str_starts_with($t,'domains_')||in_array($t,['users','orders','services','invoices','invoice_items','invoice_transactions','credits','settings','email_templates','notification_templates'],true));
    $result=[];sort($tables);
    foreach($tables as $table){$rows=DB::table($table)->get()->map(function($r)use($table){$row=(array)$r;if($table==='domains_accounts'){unset($row['updated_at']);}ksort($row);return $row;})->all();usort($rows,fn($a,$b)=>strcmp(json_encode($a),json_encode($b)));$result[$table]=$rows;}
    expect(isset($result['domains_accounts'],$result['domains_operations'],$result['users']),'Retained snapshot includes Domains and native tables');
    return $result;
}
function assertRetained(string $receipt): void
{
    $before=json_decode(file_get_contents($receipt),true,512,JSON_THROW_ON_ERROR);$after=retained();
    foreach($before as $table=>$rows){
        expect(array_key_exists($table,$after),'Retained table exists: '.$table);
        // Additive migrations may append columns or template/default rows. Every
        // original row must survive with all its original raw values unchanged.
        $columns=array_fill_keys(array_keys($rows[0]??[]),true);
        $counts=array_count_values(array_map(fn($row)=>json_encode(array_intersect_key($row,$columns),JSON_THROW_ON_ERROR),$after[$table]));
        foreach($rows as $row){$key=json_encode($row,JSON_THROW_ON_ERROR);expect(($counts[$key]??0)>0,'Retained raw rows unchanged: '.$table);$counts[$key]--;}
    }
}
function seedPsl(): void
{
    if(!Schema::hasTable('domains_psl_snapshots')||DB::table('domains_psl_snapshots')->exists()){return;}
    $body="// Synthetic retained fixture\ncom\n";
    DB::table('domains_psl_snapshots')->insert(['revision'=>hash('sha256',$body),'state'=>'current','source_url'=>'https://example.test/synthetic-list',
        'byte_size'=>strlen($body),'icann_count'=>1,'private_count'=>0,'fetched_at'=>1,'validated_at'=>1,'compressed_body'=>base64_encode(gzencode($body))]);
    DB::table('domains_psl_status')->where('id',1)->update(['checked_at'=>1,'last_result'=>'synthetic_retained']);
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
    if(in_array($phase,['install','reinstall'],true)){expect(!is_dir(base_path('extensions/Servers/Domains')),'Native Install branch requires absent runtime files');}
    $zip=(string)getenv('PACKAGE_ZIP');expect(is_file($zip),'Verified upload copy exists');
    same('server',app(App\Services\Extensions\UploadExtensionService::class)->handle($zip),'Native uploader classifies and installs server extension');
    expect(!file_exists($zip),'Native uploader consumed upload');
    expect(is_file(base_path('extensions/Servers/Domains/manifest.json')),'Native extraction retained embedded manifest');
    if($phase==='install'){
        // Upstream commands catch exceptions: assert schema and migration history directly.
        app(Installation::class)->verify();
        same((int)getenv('PACKAGE_MIGRATIONS'),count(glob(base_path('extensions/Servers/Domains/database/migrations/*.php'))),'Installed migration inventory');
        foreach(glob(base_path('extensions/Servers/Domains/database/migrations/*.php')) as $migration){same(1,DB::table('migrations')->where('migration',basename($migration,'.php'))->count(),'Native install ran each extension migration once');}
        assertInstalledMigrationOrder();
        same(0,Account::count(),'Fresh package creates no activated accounts');
        echo "Native upload/extract/Install branch passed.\n";
    }else{assertRetained($receipt);echo "Native upload retained all recorded data (".$phase.").\n";}
}elseif($phase==='held'){
    require __DIR__.'/Support/registration-held.php';
    require __DIR__.'/Support/runtime-activation.php';
}elseif($phase==='identity'){
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
    same(['com'],array_column($rows,'tld'),'Reinstalled TEST catalogue remains published');
    same(true,$rows[0]['testEnvironment'],'Reinstalled catalogue keeps TEST provenance');
    same($before,retained(),'Reinstalled page projection changes no retained data');
    expect(Account::where('processing_enabled',true)->doesntExist(),'Reinstall preserves processing off');
    echo "Installed catalogue, prices, seller and one-use audit survive reinstall.\n";
}elseif($phase==='watch-seed'){
    assertRetained($receipt);require __DIR__.'/Support/installed-watch.php';seedInstalledWatch();
    file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));
    echo "Installed watch observations, notices and adoption preview seeded with all gates off.\n";
}elseif($phase==='exchange-contracts'){
    require __DIR__.'/Support/enom-wire.php';require __DIR__.'/Support/enom-exchange.php';
}elseif(in_array($phase,['acquisition','enom-exchange'],true)){
    require __DIR__.'/Support/runtime-acquisition.php';
}elseif($phase==='seed'){
    require __DIR__.'/Support/commerce.php';require __DIR__.'/Support/native-commerce.php';
    expect(!is_link(base_path('extensions/Servers/Domains')),'Runtime is installed files, never a source link');
    $entry=new Paymenter\Extensions\Servers\Domains\Domains;
    extract(commerceFixture(true,static fn()=>null));
    $clock=new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now);
    app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
    foreach([AccountBindings::class=>$bindings,NativeCatalogue::class=>$native,PriceCatalogue::class=>$prices,SellerPolicies::class=>$sellers] as $type=>$instance){app()->instance($type,$instance);}
    $entry->boot();$entry->boot();
    $user=commerceUser();$cart=commerceCart($user);commerceAdd($cart,$native->product($tlds[0],'register'),'retained-package.com');
    commerceCheckout($user,$cart)->assertHasNoErrors();
    $op=Operation::sole();$op->update(['state'=>'uncertain','attempt'=>['execution'=>null],'safe_code'=>'staff_review_required']);
    App\Models\InvoiceTransaction::create(['invoice_id'=>$op->invoice_id,'amount'=>$op->amount,'fee'=>'0.00','status'=>'succeeded','transaction_id'=>'package-payment']);
    App\Models\Credit::create(['user_id'=>$user->id,'currency_code'=>'USD','amount'=>'4.25']);
    foreach($tlds as $t){$bindings->setProcessing($t->server_id,false);}
    expect(Account::where('processing_enabled',true)->doesntExist(),'Processing parked for retained acceptance');
    seedPsl();
    same(1,DB::table('notification_templates')->where('key','domains_domain_expiring')->update(['subject'=>'Retained synthetic operator subject','body'=>'Retained synthetic operator content.']),'Seed customized notification template');
    if(getenv('PACKAGE_STANDING_UPGRADE')){seedInstalledUpgrade();}
    file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));
    file_put_contents((string)getenv('PACKAGE_MIGRATION_RECEIPT'),json_encode(DB::table('migrations')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all(),JSON_THROW_ON_ERROR));
    echo "Native checkout, uncertain evidence, invoice/payment/credit and reservations seeded.\n";
}elseif($phase==='verify'){
    assertBooted(); // Native provider discovery must supply these before any manual boot.
    // Prove the upload-time upgrade succeeded before any second upgrade can repair it.
    app(Installation::class)->verify();
    require_once __DIR__.'/Support/installed-watch.php';assertInstalledWatchGates();assertRetained($receipt);
    $before=json_decode(file_get_contents((string)getenv('PACKAGE_MIGRATION_RECEIPT')),true,512,JSON_THROW_ON_ERROR);
    $after=DB::table('migrations')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all();
    same($before,array_slice($after,0,count($before)),'Original migration history unchanged');
    same((int)getenv('PACKAGE_MIGRATIONS'),count(glob(base_path('extensions/Servers/Domains/database/migrations/*.php'))),'Installed additive migration count');
    foreach(glob(base_path('extensions/Servers/Domains/database/migrations/*.php')) as $migration){same(1,DB::table('migrations')->where('migration',basename($migration,'.php'))->count(),'Each installed migration ran once');}
    assertInstalledMigrationOrder();
    if(getenv('PACKAGE_STANDING_UPGRADE')){assertInstalledUpgradeAudit();assertInstalledUpgradePages();assertRetained($receipt);}
    // A second native command is solely an idempotency check, after proving upload.
    Artisan::call(App\Console\Commands\Extension\Upgrade::class,['type'=>'server','name'=>'Domains','oldVersion'=>'0.1.0']);
    same($after,DB::table('migrations')->orderBy('id')->get()->map(fn($r)=>(array)$r)->all(),'Repeated native upgrade changes no migration history');
    assertRetained($receipt);
    seedPsl();file_put_contents($receipt,json_encode(retained(),JSON_THROW_ON_ERROR));
    file_put_contents((string)getenv('PACKAGE_MIGRATION_RECEIPT'),json_encode($after,JSON_THROW_ON_ERROR));
    $entry=new Paymenter\Extensions\Servers\Domains\Domains;$entry->boot();$entry->boot();
    assertBooted();
    expect(Account::where('processing_enabled',true)->doesntExist(),'Boot keeps processing parked');
    echo "Fresh installed boot: migrations verified, data retained, nav/pages/routes/scheduler registered once.\n";
}else{throw new RuntimeException('Unknown package fixture phase');}
$nativeSuccessful=true;
