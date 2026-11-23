<?php
// Static captures of TLDs & pricing and the TLD page: synced, sync failed, no registrar for new orders; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';require __DIR__.'/Support/panel-render.php';
use Illuminate\Support\Facades\{Auth,DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\WhatYouSell;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages\{SetUpSales,ViewTld};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{ObservationSchema,StaffSettingsReview as Review};
$out=$argv[1]??'';expect(is_dir($out)&&is_writable($out),'Output directory required');
$f=commerceFixture(nameSiloLocal:false,identities:['namesilo'=>'demo-namesilo']);extract($f);staffSalesFixture();tldPanel();ObservationSchema::install();Carbon\Carbon::setTestNow($now);
Illuminate\Support\Facades\View::addNamespace('domains',dirname(__DIR__).'/Domains/resources/views');
[$enom,$silo]=$tlds;
// Several registrar accounts: Enom takes new orders, the NameSilo account is demo data.
DB::table('extensions')->where('id',$enom->server_id)->update(['name'=>'Enom TEST identity check']);
DB::table('extensions')->where('id',$silo->server_id)->update(['name'=>'OFFLINE DEMO NameSilo (synthetic)']);
Domain::create(['server_id'=>$enom->server_id,'registrar_key'=>'enom','account_fingerprint'=>$enom->account_fingerprint,
    'user_id'=>900,'service_id'=>900,'domain'=>'holder-placeholder.com','sld'=>'holder-placeholder','tld'=>'com','status'=>'active']);
$permissions=[Staff::VIEW,Staff::COSTS,Staff::POLICY];foreach(array_keys(Review::ACTIONS) as $key){$permissions[]=Review::permission($key);}
$u=staffUser($permissions);Auth::setUser($u);
panelTheme($fixture);
$render=static function(string $class,array $params,string $name,array $query=[],?Closure $prepare=null)use($u,$out):void {
    $test=Livewire::actingAs($u)->withQueryParams($query)->test($class,$params)->assertSuccessful();
    if ($prepare) { $prepare($test); }
    writePanelPage($out.'/'.$name.'.html', $test);
};
$render(WhatYouSell::class,['allTlds'=>true],'list-synced');$render(ViewTld::class,['record'=>$enom->id],'tld-synced');
$other = $silo->replicate();
$other->tld = 'net';
$other->save();
$url = SetUpSales::selectionUrl([$enom->id => $enom, $other->id => $other], (int) $enom->server_id, TldResource::getUrl('index'));
parse_str(parse_url($url, PHP_URL_QUERY), $selection);
$render(SetUpSales::class, [], 'setup-review', $selection, static function ($test): void {
    $test->set('draft.pricing', 'custom')->set('draft.mode', 'markup_amount')
        ->set('draft.markup', '1.20')->set('draft.round_up', 'half')->call('reviewChanges')->assertHasNoErrors();
    same(['com', 'net'], array_keys($test->get('review.rows')), 'The capture includes the reviewed and refused TLDs');
});
DB::table('domains_observation_queues')->insert(['server_id'=>$enom->server_id,'account_fingerprint'=>$enom->account_fingerprint,
    'catalogue_hold'=>'catalogue_reader_unavailable','catalogue_audit'=>json_encode([['code'=>'catalogue_reader_unavailable','server_id'=>$enom->server_id,
        'account_fingerprint'=>$enom->account_fingerprint,'at'=>'2026-10-18T13:05:00+00:00']])]);
DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->update([
    'offer_work'=>json_encode(['upgrade_generation'=>\Paymenter\Extensions\Servers\Domains\Catalogue\CatalogueActivation::generation(),'feed_due'=>$now->modify('+1 day')->getTimestamp()]),
    'offer_feed_succeeded_at'=>$now->getTimestamp(),'offer_feed_failure'=>'catalogue_reader_unavailable',
    'offer_feed_failed_at'=>(new DateTimeImmutable('2026-10-18T13:05:00+00:00'))->getTimestamp()]);
$render(WhatYouSell::class,['allTlds'=>true],'list-sync-failed');$render(ViewTld::class,['record'=>$enom->id],'tld-sync-failed');
DB::table('domains_observation_queues')->where('server_id',$enom->server_id)->delete();
DB::table('domains_sales_policies')->where('tld','com')->update(['server_id'=>null]);
$render(WhatYouSell::class,['allTlds'=>true],'list-no-registrar');$render(ViewTld::class,['record'=>$enom->id],'tld-no-registrar');
echo "TLDs & pricing catalogue, TLD detail and setup review captures rendered.\n";$nativeSuccessful=true;
