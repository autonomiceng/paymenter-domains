<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native staff domain page and action boundaries.
declare(strict_types=1);
require __DIR__.'/Support/staff-domain-edits.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource\Pages\ViewOperation;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainEditActions,DomainFacts,Staff};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainManagement,DomainObservation,ManagementForms,ObservationSchema,OwnedDomains,OwnerPorts};
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations};
// Corrupt encrypted storage must not take down staff pages or either customer projection.
// These real native models/Livewire pages exercise casts and action construction, not a mocked read.
$pages=[\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::class,\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarBalances::class,\Paymenter\Extensions\Servers\Domains\Admin\Pages\ActivationStatus::class,\Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList::class];
$panel=\Filament\Facades\Filament::getCurrentPanel();$panel->pages($pages);
\Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel,$pages){foreach($pages as $page){$page::registerRoutes($panel);}});app('router')->getRoutes()->refreshNameLookups();
\Paymenter\Extensions\Servers\Domains\Services\BalanceSchema::install();\Paymenter\Extensions\Servers\Domains\Services\BalanceAlertSchema::install();ObservationSchema::install();
$hot=storage_path('framework/domains-assets-hot');file_put_contents($hot,'http://assets.example.test');app(\Illuminate\Foundation\Vite::class)->useHotFile($hot);
$unreadable=[];$check=static function(string $label,\Closure $run)use(&$unreadable):void{try{$run();}catch(\Throwable $e){$unreadable[$label]=$e::class.': '.$e->getMessage();fwrite(STDERR,$label.': '.$unreadable[$label]."\n");}};
$stored=$d->fresh()->getRawOriginal('renewal_policy');DB::table('domains_domains')->where('id',$d->id)->update(['renewal_policy'=>'batch6-explicit-unreadable-fixture']);
$check('staff domain',function()use($view,$d):void{$view()->assertSuccessful()->assertSee(DomainFacts::UNREADABLE);same([],\Paymenter\Extensions\Servers\Domains\Admin\Support\HoldingActions::make($d->fresh()),'Unreadable policy offers no stop or resume');});
$check('registrar account',fn()=>Livewire::actingAs($actor)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$d->server_id])->assertSuccessful()->assertSee(DomainFacts::UNREADABLE_RENEWAL));
$customer=$d->service->user;$presenter=new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter($owners,$native,new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices));
// /dashboard calls this exact native service DTO adapter before rendering its renewal strip.
$check('customer dashboard',function()use($presenter,$customer,$d):void{$dto=$presenter->services($customer,[$d->service_id])[$d->service_id];expect($dto->renewal!=='On'&&$dto->renewNow===[]&&$dto->autoRenew===[]&&!$dto->autoPay,'Unreadable policy cannot offer renewal');});
$check('customer /domains',function()use($customer,$d):void{Auth::setUser($customer);config(['settings.default_currency'=>'USD']);$request=\Illuminate\Http\Request::create('/domains');$request->setUserResolver(fn()=>$customer);expect(str_contains(app(\Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController::class)->index($request)->render(),$d->domain),'Customer list retains unreadable domain');});
DB::table('domains_domains')->where('id',$d->id)->update(['renewal_policy'=>$stored]);Auth::setUser($actor);
$observation=$d->fresh()->getRawOriginal('observation');DB::table('domains_domains')->where('id',$d->id)->update(['observation'=>'batch6-explicit-unreadable-fixture']);
$check('staff unreadable observation',fn()=>$view()->assertSuccessful()->assertSee(DomainFacts::UNREADABLE));DB::table('domains_domains')->where('id',$d->id)->update(['observation'=>$observation]);
$acquisition=$d->operations()->whereIn('action',['register','transfer'])->orderByDesc('id')->firstOrFail();$payload=$acquisition->getRawOriginal('payload');DB::table('domains_operations')->where('id',$acquisition->id)->update(['payload'=>'batch6-explicit-unreadable-fixture']);
$check('staff unreadable contacts',function()use($view,$d):void{$view()->assertSuccessful()->assertSee(DomainFacts::UNREADABLE);expect(!isset(DomainEditActions::for($d->fresh())['rows']['Contacts']),'No contact edit without a current digest');});DB::table('domains_operations')->where('id',$acquisition->id)->update(['payload'=>$payload]);
$attempt=$acquisition->fresh()->getRawOriginal('attempt');DB::table('domains_operations')->where('id',$acquisition->id)->update(['attempt'=>'d29-explicit-unreadable-fixture']);
$check('staff unreadable attempt',function()use($view,$acquisition,$actor):void{$view()->assertSuccessful();foreach(['transfer','register'] as $action){DB::table('domains_operations')->where('id',$acquisition->id)->update(['action'=>$action]);Livewire::actingAs($actor)->test(ViewOperation::class,['record'=>$acquisition->id])->assertSuccessful()->assertActionHidden('enom_answer');}});
DB::table('domains_operations')->where('id',$acquisition->id)->update(['attempt'=>$attempt,'action'=>$acquisition->action]);
same([],$unreadable,'Unreadable saved data renders without granting an edit or renewal');

echo "Unreadable staff and customer domain pages passed.\n";$nativeSuccessful=true;
