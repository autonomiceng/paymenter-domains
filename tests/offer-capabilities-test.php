<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native rows are needed to distinguish retained contractual retail from obsolete price columns.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/commerce.php';
require __DIR__.'/Support/native-commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Capabilities, OfferState};
use Paymenter\Extensions\Servers\Domains\Models\Tld;
expect(class_exists(Capabilities::class), 'Independent current capability facts adapter required');
require __DIR__.'/Support/offer-commerce.php';
extract(offerCommerceFixture());
$allowed = true;
$caps = new Capabilities($ports, $clock, static function () use (&$allowed): void { if (!$allowed) { throw new DomainException('Revoked'); } });
same(null, Capabilities::routed(), 'Production remains legacy before complete checked composition');
same(['com'], $caps->endings($binding, 'transfer'), 'Capability endings come from current independent facts');
DB::table('domains_sales_policies')->where('tld', 'com')->update(['register_enabled'=>false, 'transfer_enabled'=>false]);
DB::table('domains_tlds')->where('id', $tlds[0]->id)->update(['catalogue_intent'=>OfferState::json(['sell'=>false]), 'catalogue_published_revision'=>null]);
$t = Tld::findOrFail($tlds[0]->id);
same([1,3], $caps->facts($binding, 'com')->terms['register'], 'Deselection and staff stops do not withdraw provider capabilities');
same('10.40', $caps->holder($binding, 'com')->retail, 'Holder retail survives public withdrawal');
same('8.0000', $caps->cost($binding, 'com')->total, 'Cost uses normalized observation');
DB::table('domains_prices')->where('server_id', $binding->serverId)->update(['wholesale'=>'999.0000', 'retail'=>'999.00']);
same('8.0000', $caps->cost($binding, 'com')->total, 'Obsolete wholesale does not affect current costs');
DB::transaction(function () use ($caps, $binding, $t): void {
    $t = Tld::whereKey($t->id)->lockForUpdate()->sole();
    $rows = Paymenter\Extensions\Servers\Domains\Models\Price::where('server_id', $binding->serverId)->where('tld','com')->lockForUpdate()->get();
    $snapshot = $caps->holding($binding, $t, $rows);
    $queries = []; DB::listen(static function ($q) use (&$queries): void { $queries[]=$q->sql; });
    same('10.40', $snapshot['retail']->retail, 'Locked retained retail uses prelocked snapshot');
    same('8.0000', $snapshot['cost']?->total, 'Locked current cost uses prelocked snapshot');
    same(true, $caps->facts($binding, 'com', $t)->renewalSafe, 'Locked capability uses prelocked TLD');
    same([], $queries, 'No catalogue lock acquisition after holding/operation prefix');
});
$clock->instant = $now->modify('+2 days');
commerceReject(fn () => $caps->facts($binding, 'com'));
same(null, $caps->cost($binding, 'com'), 'Expired normalized cost is unknown');
same('10.40', $caps->holder($binding, 'com')->retail, 'Stale facts/cost retain annual retail');
DB::table('domains_tlds')->where('id', $t->id)->update(['catalogue_facts'=>null]);
same([], $caps->endings($binding), 'Legacy acceptance cannot manufacture missing provider facts');
$allowed = false; commerceReject(fn () => $caps->holder($binding, 'com'));
same(0, $reads->calls, 'Capabilities and holders never read a provider');
$nativeSuccessful = true;
echo "Offer capabilities: ok\n";
