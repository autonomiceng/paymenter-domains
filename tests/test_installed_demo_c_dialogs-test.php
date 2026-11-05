<?php
// Native mounted dialogs need real checkout provenance; stubs cannot prove footer order or renewal eligibility.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
require __DIR__.'/Support/native-commerce.php';
require __DIR__.'/Support/offer-commerce.php';
require __DIR__.'/Support/storefront.php';
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,WatchResource};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DialogFooter,Staff};
use Paymenter\Extensions\Servers\Domains\Catalogue\{CallerRouting,StaffCatalogue,Capabilities};
use Paymenter\Extensions\Servers\Domains\Services\RenewalCycles;
extract(offerCommerceFixture());DialogFooter::boot();tldPanel();pinStaffClock($now);
$actor=staffUser(array_keys(Staff::permissions()));Auth::login($actor);
$probe=new ReflectionProperty(CallerRouting::class,'probe');
$probe->setValue(null,[StaffCatalogue::class=>new StaffCatalogue($ports,$clock),Capabilities::class=>new Capabilities($ports,$clock,static fn()=>null)]);
$row=(new StaffCatalogue($ports,$clock))->rows([$binding],'com')->sole();
$terms=Livewire\Livewire::actingAs($actor)->test(WhatYouSell::class);
// Mount already returns the real dialog partial; an extra refresh renders the whole page again.
$terms->mountTableBulkAction('edit',[$terms->instance()->getTableRecordKey($row)]);
$termsAction=$terms->instance()->getMountedAction();
same(['Cancel','Save terms'],array_values(array_map(fn($a)=>$a->getLabel(),$termsAction->getModalFooterActions())),'Terms footer has logical Cancel then Save order');
same(Filament\Support\Enums\Alignment::End,$termsAction->getModalFooterActionsAlignment(),'Terms actions align right');
$product=$storefront->offer('com','register',1)['product'];
file_put_contents($fixture.'/public/hot','http://localhost:5173');
session()->put('currency','USD');config(['settings.default_currency'=>'USD']);
$cart=commerceCart($actor);commerceAdd($cart,$product,placeholderComFixtureName('renewal-placeholder.com'));commerceCheckout($actor,$cart);
$d=Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain',placeholderComFixtureName('renewal-placeholder.com'))->sole();
DB::table('services')->where('id',$d->service_id)->update(['status'=>'active']);
$d->update(['status'=>'active','billing_managed'=>true,'authority_generation'=>1,'provider_expires_at'=>$now->modify('+1 year')]);
app()->instance(RenewalCycles::class,new RenewalCycles($bindings,$clock));
$options=app(RenewalCycles::class)->complimentaryOptions($d->id,$actor);
same([1],array_keys($options['terms']),'Complimentary retains one evidenced year');
$panel=Filament\Facades\Filament::getCurrentPanel();$panel->resources([DomainResource::class,WatchResource::class]);
Illuminate\Support\Facades\Route::name('filament.admin.')->prefix($panel->getPath())->group(function()use($panel){DomainResource::registerRoutes($panel);WatchResource::registerRoutes($panel);});
app('router')->getRoutes()->refreshNameLookups();
$gift=Livewire\Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id])->mountAction('complimentary_renewal');
$giftAction=$gift->instance()->getMountedAction();
same(['Cancel','Confirm complimentary renewal'],array_values(array_map(fn($a)=>$a->getLabel(),$giftAction->getModalFooterActions())),'Complimentary footer has logical Cancel then Confirm order');
same(Filament\Support\Enums\Alignment::End,$giftAction->getModalFooterActionsAlignment(),'Complimentary actions align right');
$dialog=$gift->effects['partials']['action-modals.0']??'';
$failures=[];
if(!str_contains($dialog,'before '.Carbon\CarbonImmutable::parse($options['horizon'])->format('M j, Y').'.')){$failures[]='Complimentary horizon uses readable month, day and year';}
// Optional offline browser artifacts contain actual native modal DOM and shipped CSS, with no application access.
if(isset($argv[1])){
    expect(is_dir($argv[1]),'Dialog output directory exists');
    $panelCss=getenv('DOMAINS_RENDER_PANEL_CSS')?:$fixture.'/vendor/filament/filament/dist/theme.css';
    $skinPath=getenv('DOMAINS_RENDER_STAFF_SKIN')?:null;
    expect(is_file($panelCss),'Supplied panel CSS exists');
    expect($skinPath===null||is_file($skinPath),'Supplied Landing skin exists');
    $css=file_get_contents($panelCss);
    $skin=$skinPath===null?'':'<style>'.file_get_contents($skinPath).'</style>';
    file_put_contents($argv[1].'/css-inputs.json',json_encode(['panel_sha256'=>hash_file('sha256',$panelCss),'skin_sha256'=>$skinPath===null?null:hash_file('sha256',$skinPath),'domains_sha256'=>hash('sha256',(string)view('domains::partials.staff-styles')->render())],JSON_PRETTY_PRINT));
    foreach(['terms'=>$terms,'complimentary'=>$gift] as $name=>$test){
        $body=$test->effects['partials']['action-modals.0']??'';
        $body=str_replace('class="fi-modal fi-absolute-positioning-context"','class="fi-modal fi-absolute-positioning-context fi-modal-open"',$body);
        $html='<!doctype html><html class="dark"><head><meta charset="utf-8"><style>'.$css.'</style>'.view('domains::partials.staff-styles')->render().$skin.'</head><body>'.$body.'</body></html>';
        file_put_contents($argv[1].'/'.$name.'.html',$html);
    }
}
$probe->setValue(null,null);
expect($failures===[],implode('; ',$failures));
$nativeSuccessful=true;echo "Native Demo C dialog footers and horizon passed.\n";
