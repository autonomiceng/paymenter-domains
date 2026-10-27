<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{PricingEditorNotice,Staff};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\ViewTld;
use Paymenter\Extensions\Servers\Domains\Services\{StaffSettingsReview as Review,StaffSettingsWrites};
extract(commerceFixture(true)); tldPanel(); Carbon\Carbon::setTestNow($now);
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Tests\FakeClock($now));
$permissions=[Staff::VIEW,Staff::POLICY,Staff::COSTS,'admin.products.viewAny','admin.products.view','admin.products.update'];
foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions); Auth::setUser($u);
app()->instance(StaffSettingsWrites::class,new StaffSettingsWrites($native,$sellers,$bindings,true));
app()->instance(Paymenter\Extensions\Servers\Domains\Services\SellerPolicies::class,$sellers);
app()->instance(Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::class,$native);
app('view')->addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
same(null,PricingEditorNotice::missing(),'Pinned pricing notice hooks are available');
PricingEditorNotice::boot();
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$tlds[0]->id]);
expect(str_contains($page->html(),'A TLD is only for sale when its saved prices match its published prices.'),'Staff sees the consequence before editing');
expect(str_contains($page->html(),'Registration: For sale'),'Before editing registration is available');
expect(str_contains($page->html(),'Edit prices'),'Recovery names the available price editor');
expect(!str_contains(PricingEditorNotice::render($tlds[0]->fresh()),'Publish prices'),'Recovery does not name the removed publish button');
$page->mountAction('override')->setActionData(['pricing'=>'custom','mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed_register'=>'20.00','fixed_renew'=>'21.00','fixed_transfer'=>'22.00','reason'=>'Reviewed retail change'])->callMountedAction()->assertHasNoActionErrors();
// The approved pricing UX publishes on save. This notice must agree with either writer.
$clock=app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class);
$store=new Paymenter\Extensions\Servers\Domains\Services\Storefront($sellers,$prices,$native,$bindings,new Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility($bindings,$clock),$clock);
Illuminate\Support\Facades\Cache::flush();
expect(str_contains($page->html(),'Registration: For sale'),'Successful override publishes registration for sale');
$ending=collect($store->endings())->firstWhere('tld',$tlds[0]->tld);
expect($ending!==null,'The edited fixture TLD remains in the storefront');
expect($ending['terms']!==[],'The edited fixture TLD has registration terms');
// Retained pre-publication edits must also be clear when reopening the page.
$tlds[0]->fresh()->update(['fixed_register'=>'23.00']);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$tlds[0]->id]);
expect(str_contains($page->html(),'Registration: Not for sale'),'Reopening a saved unpublished rule shows paused registration');
$prices->republish($tlds[0]->fresh());
$native->publish($tlds[0]->id,$now);
$product=$native->product($tlds[0],'register');
$edit=Livewire::actingAs($u)->test(App\Admin\Resources\ProductResource\Pages\EditProduct::class,['record'=>$product->id])->assertStatus(200);
expect(str_contains($edit->html(),'Changing a price here can stop new orders for this TLD until its prices match again.'),'Native editor warns before a price edit');
expect(str_contains($edit->html(),'Registration: For sale'),'Publication restored registration');
foreach($edit->get('data.plan') as $planKey=>$plan){foreach($plan['pricing'] as $priceKey=>$price){
    $edit->set('data.plan.'.$planKey.'.pricing.'.$priceKey.'.price','19.00');
}}
$edit->call('save')->assertHasNoFormErrors();
expect(str_contains($edit->html(),'Registration: Not for sale'),'Native price save shows the resulting pause');
expect(str_contains($edit->html(),'To fix it, open this TLD in TLDs &amp; pricing, open Edit prices and save.'),'Native recovery is explicit after saving');
// Follow the notice using the existing pricing unchanged: saving restores native prices.
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$tlds[0]->id]);
$page->mountAction('override')->setActionData(['reason'=>'Restore saved TLD prices'])->callMountedAction()->assertHasNoActionErrors();
expect(str_contains($page->html(),'Registration: For sale'),'Saving Edit prices restores sale after a native price change');
$hosting=App\Models\Product::create(['name'=>'Ordinary','slug'=>'ordinary','category_id'=>$product->category_id,'allow_quantity'=>'combined']);
same('',PricingEditorNotice::render($hosting),'No pricing warning on unrelated products');
$nativeSuccessful=true; echo "Pricing consequences before and after Domains/native edits passed.\n";
