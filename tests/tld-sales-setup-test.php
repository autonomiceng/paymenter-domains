<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-tlds.php';

use Illuminate\Support\Facades\{Auth, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff, TldFacts};
use Paymenter\Extensions\Servers\Domains\Catalogue\{OfferState, Pricing, SalesSetup};
use Paymenter\Extensions\Servers\Domains\Services\StaffSettingsReview as Review;

extract(commerceFixture(true));
staffSalesFixture();
$actor = staffUser(array_keys(Staff::permissions()));
Auth::setUser($actor);
[$target, $other] = $tlds;
$id = (int) $target->server_id;
$reason = 'Set retail and sales for the reviewed TLDs.';
expect(class_exists(SalesSetup::class), 'One staff review must support pricing and seller selection.');
$setup = SalesSetup::routed();
$keep = ['register'=>true, 'transfer'=>true, 'pricing'=>'keep'];
$custom = [...$keep, 'pricing'=>'custom', 'rule'=>['mode'=>'markup_amount','markup'=>'1.20','round_up'=>'half']];
// A second writable suffix exercises whole-batch rollback after the first publication.
$net = $target->replicate();
$net->tld = 'net';
$net->save();
foreach (DB::table('domains_prices')->where('server_id', $id)->where('tld', 'com')->get() as $price) {
    $copy = (array) $price;
    unset($copy['id']);
    DB::table('domains_prices')->insert([...$copy, 'tld' => 'net']);
}
$missing = array_map(static fn ($letter) => 'test.'.$letter.$letter, range('a','v'));
$names = ['com','net','co.test', ...$missing];
$state = static fn () => array_map(static fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
    ['domains_tlds','domains_prices','domains_sales_policies','products','plans','prices']);
$before = $state();
$draft = $setup->preview($id, $names, $custom);
same($before, $state(), 'Preview leaves financial and seller state unchanged');
same('blocked', $draft['rows']['co.test']['status'], 'Missing target is explicitly refused');
$row = $draft['rows']['com'];
same('ready', $row['status'], 'Custom price review can save');
foreach (['register:1'=>'7.50','register:3'=>'26.50','renew:1'=>'9.50','transfer:1'=>'8.50'] as $key=>$amount) {
    same($amount,$row['prices'][$key]['after'],'Prospective retail for '.$key);
    same(true,$row['prices'][$key]['available'],'Eligible term for '.$key);
}
same(false,$row['prices']['register:2']['available'],'Missing register term cannot inherit a price');
$key = $draft['review_key'];
commerceReject(fn () => $setup->save($id, ['com'], $custom, $key, $reason));
commerceReject(fn () => $setup->save($id, $names, $keep, $key, $reason));
$clock->instant = $now->modify('+2 days');
commerceReject(fn () => $setup->save($id, $names, $custom, $key, $reason));
$clock->instant = $now;
same($before, $state(), 'Stale or substituted review saves no row');
$retained = $other->fresh()->catalogue_retained_annual_retail;
$keysBefore = DB::table(Review::KEYS)->count();
DB::statement("CREATE TRIGGER reject_last_price BEFORE UPDATE ON domains_prices WHEN OLD.tld='net' BEGIN SELECT RAISE(ABORT,'fixture publication failure'); END");
try {
    $setup->save($id, $names, $custom, $key, $reason);
    expect(false, 'Last-row failure must roll back the earlier publication');
} catch (Illuminate\Database\QueryException $error) {
    expect(str_contains($error->getMessage(),'fixture publication failure'), 'Synthetic failure reached the second publication');
}
DB::statement('DROP TRIGGER reject_last_price');
same($before, $state(), 'Earlier native, price and policy mutations roll back');
same($keysBefore,DB::table(Review::KEYS)->count(),'Failed batch releases its review key');
$result = $setup->save($id, $names, $custom, $key, $reason);
same(['com','net'], $result['rows'], 'Committed subset is exact');
same(['co.test', ...$missing], array_keys($result['failures']), 'Receipt retains all 23 refusals in the 25-row review');
same('9.50', $target->fresh()->catalogue_retained_annual_retail, 'Target standard renewal uses published price');
same($retained, $other->fresh()->catalogue_retained_annual_retail, 'Sibling standard renewal preserved');
same('26.50', $result['outcomes']['com']['prices']['register:3']['after'], 'Confirmed multi-year price');
$published = $state();
commerceReject(fn () => $setup->save($id, $names, $custom, $key, $reason));
same($published, $state(), 'Replay leaves native and catalogue revisions intact');
same(null, $setup->preview($id, $names, $custom)['review_key'], 'No writable change needs no confirmation');
$default = [...$keep, 'pricing'=>'default'];
$draft = $setup->preview($id, ['com'], $default);
$setup->save($id, ['com'], $default, $draft['review_key'], $reason);
same(null, Pricing::own($target->fresh()), 'Default removes the own rule');
same('7.80', $setup->preview($id, ['com'], $keep)['rows']['com']['prices']['register:1']['before'], 'Default retail published');

// Changing registrar with Keep preserves the native legacy own rule and both holder prices.
$raw = OfferState::decode($other->fresh()->catalogue_intent);
unset($raw['pricing_rule']);
DB::table('domains_tlds')->where('id', $other->id)->update(['use_default_rule'=>false, 'pricing_mode'=>'markup_amount',
    'markup'=>'2.00','round_up'=>'none','catalogue_intent'=>OfferState::json($raw),'catalogue_retained_annual_retail'=>'35.00']);
$weak = staffUser([Staff::VIEW,Staff::POLICY,Review::permission('seller')]);
Auth::setUser($weak);
commerceReject(fn () => $setup->preview($id, ['com'], $default));
commerceReject(fn () => $setup->preview($id, ['com'], $custom));
commerceReject(fn () => $setup->preview($id, ['com'], [...$keep,'longest'=>2]));
$oldAnnual = $target->fresh()->catalogue_retained_annual_retail;
$rebind = $setup->preview($other->server_id, ['com'], $keep);
expect(!array_key_exists('cost',$rebind['rows']['com']['prices']['renew:1']), 'Seller-only preview omits costs');
expect(!empty($rebind['rows']['com']['previous_sales']), 'Review includes displaced public terms');
$result = $setup->save($other->server_id, ['com'], $keep, $rebind['review_key'], $reason);
same(['com'], $result['rows'], 'Seller-only actor switches the one reviewed suffix');
same(false, array_key_exists('pricing_rule',OfferState::decode($other->fresh()->catalogue_intent)), 'Keep preserves legacy rule representation');
same('35.00', $other->fresh()->catalogue_retained_annual_retail, 'New seller retains holder annual');
same($oldAnnual, $target->fresh()->catalogue_retained_annual_retail, 'Outgoing seller retains holder annual');
same($reason,TldFacts::history($id,$target->id)[0]['reason'],'Outgoing registrar history includes switch');
Auth::setUser($actor);

// A required nameserver blocks a whole row even when the existing intent already allows registration.
$facts = OfferState::decode($target->fresh()->catalogue_facts);
$facts['requiresNameservers'] = true;
DB::table('domains_tlds')->where('id',$target->id)->update(['catalogue_facts'=>OfferState::json($facts)]);
$blocked = $setup->preview($id,['com','co.test'],$custom);
same(['blocked','blocked'],array_column($blocked['rows'],'status'),'Known unsupported and missing offers are each refused');
same(null,$blocked['review_key'],'No review is issued for all-refused rows');
$names25 = array_map(static fn ($letter) => 'test.'.$letter.$letter, range('a','y'));
same(25,count($setup->preview($id,$names25,$keep)['rows']),'All 25 requested dispositions returned');
foreach ([[],['com','com'],[...$names25,'test.zz']] as $invalid) {
    commerceReject(fn () => $setup->preview($id,$invalid,$keep));
}
same(0,$reads->calls,'No provider I/O');
$nativeSuccessful = true;
echo "Reviewed single and bulk TLD sales setup passed.\n";
