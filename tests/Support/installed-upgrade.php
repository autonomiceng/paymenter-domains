<?php
// Standing ZIP upgrade evidence; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Auth,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{AuditArchives,StaffSettingsReview};
function seedInstalledUpgrade(): void
{
    // Use only APIs and tables supplied by the installed baseline; never create
    // current schema or import the source-tree runtime into the older process.
    require_once __DIR__.'/staff-panel.php';
    $staff=staffUser(['admin.domains.view','admin.domains.manage-policy','admin.domains.settings.default_rule']);
    if(Schema::hasTable('domains_staff_review_keys')&&class_exists(StaffSettingsReview::class)){
        $t=Paymenter\Extensions\Servers\Domains\Models\Tld::firstOrFail();
        $ticket=StaffSettingsReview::ticket($staff->id,$t->server_id,$t->id,'default_rule',StaffSettingsReview::stamp($t->server_id,$t->id));
        $review=new StaffSettingsReview($staff->id,$t->server_id,$t->id,'default_rule',$ticket,'Synthetic retained upgrade review');
        $review->local(fn()=>AuditArchives::append($t->server_id,'staff_settings',json_encode([['key'=>'synthetic-retained-upgrade']])));
        expect(DB::table('domains_staff_review_keys')->exists(),'Baseline retained used staff review');
        expect(DB::table('domains_audit_archives')->exists(),'Baseline retained encrypted staff archive');
    }
    // Recent baselines have all watch fixtures; older published baselines still
    // exercise the accounts/settings/catalogue/policy/native ledger fixture.
    if(Schema::hasTable('domains_watch_deliveries')){
        require_once __DIR__.'/installed-watch.php';seedInstalledWatch();
    }
    $populated=[];
    foreach(Schema::getTableListing(null,false) as $table){if(str_starts_with($table,'domains_')){$populated[$table]=DB::table($table)->count();}}
    echo 'Baseline retained fixture rows: '.json_encode($populated)."\n";
}
function assertInstalledMigrationOrder(): void
{
    $files=glob(base_path('extensions/Servers/Domains/database/migrations/*.php'));sort($files);
    $expected=array_map(static fn($file)=>basename($file,'.php'),$files);
    $actual=DB::table('migrations')->whereIn('migration',$expected)->orderBy('id')->pluck('migration')->all();
    same($expected,$actual,'Installed migrations applied exactly once in filename order');
    if(class_exists(\Paymenter\Extensions\Servers\Domains\Services\UserDeletionSchema::class)){
        \Paymenter\Extensions\Servers\Domains\Services\UserDeletionSchema::verify();
        same(0,DB::table(\Paymenter\Extensions\Servers\Domains\Services\UserDeletionSchema::TABLE)->count(),'Upgrade creates deletion storage without backfill');
    }
}
function assertInstalledUpgradeAudit(): void
{
    $row=DB::table('domains_audit_archives')->where('family','staff_settings')->first();
    if(!$row){return;} // A baseline predating staff storage has no archive to retain.
    $json=AuditArchives::latest($row->server_id,'staff_settings');
    same([['key'=>'synthetic-retained-upgrade']],json_decode($json,true,512,JSON_THROW_ON_ERROR),'Retained archive decrypts through upgraded code');
    $history=StaffSettingsReview::historyRows(App\Models\Server::findOrFail($row->server_id));
    same('synthetic-retained-upgrade',$history[0]['key'],'Upgraded staff history includes retained archive');
    same('Synthetic retained upgrade review',$history[1]['reason'],'Retained encrypted current audit batch decodes');
}
function assertInstalledUpgradePages(): void
{
    // Native routes, middleware, controllers and installed Blade files, with no
    // replacement templates or manual route registration.
    // The pristine upstream source has no compiled frontend assets. Supply only
    // synthetic asset URLs; rendering does not fetch them or replace any Blade.
    $hot=storage_path('framework/domains-assets-hot');file_put_contents($hot,'http://assets.example.test');
    app(Illuminate\Foundation\Vite::class)->useHotFile($hot);
    $kernel=app(Illuminate\Contracts\Http\Kernel::class);
    foreach(['/domains/register','/domains/pricing'] as $path){
        $request=Illuminate\Http\Request::create($path,'GET');
        $response=$kernel->handle($request);
        same(200,$response->getStatusCode(),'Installed route renders: '.$path.'; status '.$response->getStatusCode().'; '.($response->exception?->getMessage()??'unknown error'));
        expect(str_contains($response->getContent(),'<html'),'Installed theme renders HTML');
        $kernel->terminate($request,$response);
    }
    $role=App\Models\Role::where('name','like','Synthetic role %')->first();
    expect($role!==null,'Retained staff role exists');
    Auth::login(App\Models\User::where('role_id',$role->id)->firstOrFail());
    PHPUnit\TextUI\Configuration\Registry::init((new PHPUnit\TextUI\CliArguments\Builder)->fromParameters([]),PHPUnit\TextUI\XmlConfiguration\DefaultConfiguration::create());
    Filament\Facades\Filament::setCurrentPanel(Filament\Facades\Filament::getPanel('admin'));
    Livewire\Livewire::test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class)->assertSuccessful()->assertSee('Registrar setup');
    echo "Installed storefront routes and retained staff page render successfully.\n";
}
