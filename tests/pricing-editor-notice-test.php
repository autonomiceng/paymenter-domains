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
$shown=static function(string $html):string{preg_match_all("/content: '([^']*)'/",$html,$m);return html_entity_decode($html,ENT_QUOTES).' '.implode(' ',array_map(fn($t)=>json_decode('"'.$t.'"'),$m[1]));};
$below=static fn(string $html)=>expect(($title=strpos($html,'fi-header-heading'))!==false&&($notice=strpos($html,'Registration: '))!==false&&$title<$notice,'The notice sits below the breadcrumb and title');
$below($page->html());
Livewire::actingAs($u)->test(Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$tlds[0]->server_id])->assertDontSee('Price changes can stop domain sales')->assertDontSee('Registration: ');
expect(str_contains($page->html(),'Registration: For sale'),'Before editing registration is available');
expect(!str_contains(PricingEditorNotice::render($tlds[0]->fresh()),'Publish prices'),'Recovery does not name the removed publish button');
// Paragraphs use the panel's prose spacing; sale status lines stay together.
$noticeDom=static function(string $html):DOMXPath{$d=new DOMDocument;@$d->loadHTML('<?xml encoding="utf-8" ?>'.$html);return new DOMXPath($d);};
$x=$noticeDom(PricingEditorNotice::render($tlds[0]->fresh()));
same(1,$x->query('//*[contains(concat(" ",normalize-space(@class)," ")," fi-prose ")]/p//strong[.="Price changes can stop domain sales"]')->length,'Notice text is spaced as panel prose');
same(2,$x->query('//*[contains(concat(" ",normalize-space(@class)," ")," fi-prose ")]/p')->length,'One line and the sale status, no text wall');
same(1,$x->query('//p[strong[.="Registration: For sale"] and strong[.="Transfer: For sale"]]')->length,'Sale status lines are grouped');
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
// The sweep puts a saved rule on sale by itself and says so in the history.
$native->keepCurrent($tlds[0]->fresh(),$now,false);
expect(str_contains(Livewire::actingAs($u)->test(ViewTld::class,['record'=>$tlds[0]->id])->html(),'Registration: For sale'),'The sweep restores sale when saved and published prices differ');
same(['Prices published','System'],(static fn($row)=>[$row['change'],$row['by']])(Paymenter\Extensions\Servers\Domains\Admin\Support\TldFacts::history($tlds[0]->server_id,$tlds[0]->id)[0]),'The audit log shows the system did it');
$product=$native->product($tlds[0],'register');
$edit=Livewire::actingAs($u)->test(App\Admin\Resources\ProductResource\Pages\EditProduct::class,['record'=>$product->id])->assertStatus(200);
expect(str_contains($edit->html(),'Don’t change this product’s server'),'What not to change stays on the page');
expect(str_contains($edit->html(),'Registration: For sale'),'Publication restored registration');
foreach($edit->get('data.plan') as $planKey=>$plan){foreach($plan['pricing'] as $priceKey=>$price){
    $edit->set('data.plan.'.$planKey.'.pricing.'.$priceKey.'.price','19.00');
}}
$edit->call('save')->assertHasNoFormErrors();
expect(str_contains($edit->html(),'Registration: Not for sale'),'Native price save shows the resulting pause');
expect(str_contains($edit->html(),'To fix it, open this TLD in TLDs &amp; pricing, open Edit prices and save.'),'Native recovery stays on the page once sales stop');
// Product editors may lack Domains rights; the fix line must match fresh action permissions.
$ask='Ask a staff member who can edit domain pricing to open this TLD in TLDs & pricing, open Edit prices and save.';
$direct='To fix it, open this TLD in TLDs & pricing, open Edit prices and save.';
foreach([
    [[],true],
    [[Staff::POLICY],false],
    [[Staff::VIEW],false],
    [[Review::permission('override')],false],
    [[Review::permission('remove_override')],true],
    [[Review::permission('publish')],false],
    [[Review::permission('import')],true],
]as[$revoked,$canEdit]){
    // Keep the acting user's loaded role stale while revoking its persisted grants.
    Auth::setUser($u);$u->load('role');
    Illuminate\Support\Facades\DB::table('roles')->where('id',$u->role_id)->update(['permissions'=>json_encode(array_values(array_diff($permissions,$revoked)))]);
    $nativeNotice=$shown(PricingEditorNotice::render($product->fresh()));
    expect(str_contains($nativeNotice,$canEdit?$direct:$ask),'Native recovery matches fresh edit permissions');
    expect(!str_contains($nativeNotice,$canEdit?$ask:$direct),'Native recovery omits the unavailable instruction');
    Illuminate\Support\Facades\DB::table('roles')->where('id',$u->role_id)->update(['permissions'=>json_encode($permissions)]);
}
// Follow the notice using the existing pricing unchanged: saving restores native prices.
$page=Livewire::actingAs($u)->test(ViewTld::class,['record'=>$tlds[0]->id]);
$page->mountAction('override')->setActionData(['reason'=>'Restore saved TLD prices'])->callMountedAction()->assertHasNoActionErrors();
expect(str_contains($page->html(),'Registration: For sale'),'Saving Edit prices restores sale after a native price change');
$hosting=App\Models\Product::create(['name'=>'Ordinary','slug'=>'ordinary','category_id'=>$product->category_id,'allow_quantity'=>'combined']);
same('',PricingEditorNotice::render($hosting),'No pricing warning on unrelated products');
$nativeSuccessful=true; echo "Pricing consequences before and after Domains/native edits passed.\n";
