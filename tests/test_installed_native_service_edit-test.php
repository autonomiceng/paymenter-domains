<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use App\Models\{Category,Product,Service};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Auth,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\NativeEditorNotice;
// The banner only needs route identity, a domain id and permission. Use real native models,
// SQL and Blade, without checkout, reconciliation or full Livewire editor rendering.
staffPanel();
Schema::create('domains_domains',static function(Blueprint $table):void {$table->id();$table->unsignedBigInteger('service_id');});
$category=Category::create(['name'=>'Domains','slug'=>'domains']);
$product=Product::create(['category_id'=>$category->id,'name'=>'Domain','slug'=>'domains-namesilo-s4-service-edit-registration']);
$service=(new Service)->forceFill(['id'=>96,'product_id'=>$product->id]);
DB::table('domains_domains')->insert(['id'=>43,'service_id'=>96]);
Auth::login(staffUser(['admin.domains.view']));
$html=NativeEditorNotice::render($service);
$text=preg_replace('/\s+/',' ',trim(strip_tags($html)));
same('Renewal, contacts and DNS settings are on this domain’s page.',$text,'Banner names the settings');
expect(str_contains($html,'href="'.e(DomainResource::getUrl('view',['record'=>43])).'"'),'Banner links the individual domain');
same('',NativeEditorNotice::render(new Service),'No banner for hosting');
Auth::login(staffUser([]));
$html=NativeEditorNotice::render($service);
expect(!str_contains($html,'href='),'No domain or list link without domain-view permission');
NativeEditorNotice::boot();
expect(Filament\Support\Facades\FilamentView::hasRenderHook(Filament\View\PanelsRenderHook::PAGE_START,[App\Admin\Resources\ServiceResource\Pages\EditService::class]),'Public native editor hook registered');
echo "Native domain banner, direct link, permission and hosting controls passed.\n";
$nativeSuccessful=true;
