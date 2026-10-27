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
expect(str_contains($page->html(),'A pricing rule without matching published prices pauses new sales.'),'Staff sees the consequence before editing');
expect(str_contains($page->html(),'Public registration: available'),'Before editing registration is available');
$page->mountAction('override')->setActionData(['pricing'=>'custom','mode'=>'fixed','markup'=>'0','round_up'=>'none','fixed_register'=>'20.00','fixed_renew'=>'21.00','fixed_transfer'=>'22.00','reason'=>'Reviewed retail change'])->callMountedAction()->assertHasNoActionErrors();
// The approved pricing UX publishes on save. This notice must agree with either writer.
$clock=app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class);
$store=new Paymenter\Extensions\Servers\Domains\Services\Storefront($sellers,$prices,$native,$bindings,new Paymenter\Extensions\Servers\Domains\Services\CheckoutEligibility($bindings,$clock),$clock);
Illuminate\Support\Facades\Cache::flush();
$expected=collect($store->endings())->contains(fn($row)=>$row['terms']!==[])?'available':'unavailable';
expect(str_contains($page->html(),'Public registration: '.$expected),'After saving staff sees the actual registration state');
// Retained pre-publication edits must also be clear when reopening the page.
$tlds[0]->fresh()->update(['fixed_register'=>'23.00']);
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$tlds[0]->id]);
expect(str_contains($page->html(),'Public registration: unavailable'),'Reopening a saved unpublished rule shows paused registration');
$prices->republish($tlds[0]->fresh());
$native->publish($tlds[0]->id,$now);
$product=$native->product($tlds[0],'register');
$edit=Livewire::actingAs($u)->test(App\Admin\Resources\ProductResource\Pages\EditProduct::class,['record'=>$product->id])->assertStatus(200);
expect(str_contains($edit->html(),'Editing native plan prices can pause new sales.'),'Native editor warns before a price edit');
expect(str_contains($edit->html(),'Public registration: available'),'Publication restored registration');
foreach($edit->get('data.plan') as $planKey=>$plan){foreach($plan['pricing'] as $priceKey=>$price){
    $edit->set('data.plan.'.$planKey.'.pricing.'.$priceKey.'.price','19.00');
}}
$edit->call('save')->assertHasNoFormErrors();
expect(str_contains($edit->html(),'Public registration: unavailable'),'Native price save shows the resulting pause');
expect(str_contains($edit->html(),'Publish prices from TLDs &amp; pricing to restore matching native prices.'),'Native recovery is explicit after saving');
$hosting=App\Models\Product::create(['name'=>'Ordinary','slug'=>'ordinary','category_id'=>$product->category_id,'allow_quantity'=>'combined']);
same('',PricingEditorNotice::render($hosting),'No pricing warning on unrelated products');
$nativeSuccessful=true; echo "Pricing consequences before and after Domains/native edits passed.\n";
