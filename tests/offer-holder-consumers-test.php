<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Real frozen attempts/native invoices are required: a pure price test cannot prove retained money.
require __DIR__.'/Support/reconciliation-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Capabilities, CallerPorts, Projection, UpgradeConversion, Facts, OfferState};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Operation, Tld};
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, DomainBilling, HolderPricing, HoldingLocks, LateRenewalQuote, NativeDomainGuard};
expect((new ReflectionMethod(HoldingLocks::class,'take'))->getNumberOfParameters() >= 10, 'Holding consumers need the prepared offer snapshot before operation locks');
[$paid] = $prepare('paid-pending-offer-placeholder.com');
[$uncertain] = $prepare('uncertain-offer-placeholder.com', 'null');
$frozen = static fn ($op) => [DB::table('domains_operations')->where('id',$op->id)->sole(), DB::table('invoices')->where('id',$op->invoice_id)->sole(), DB::table('invoice_items')->where('id',$op->invoice_item_id)->sole()];
$before = serialize([$frozen($paid),$frozen($uncertain)]);
$ports = new CallerPorts(new Projection($native), $clock, $bindings, static fn()=>null, static fn()=>true);
$caps = new Capabilities($ports, $clock, static fn()=>null);
foreach (Account::all() as $a) { (new UpgradeConversion)->batch(CatalogueAcceptances::binding($a)); }
same('10.40', $caps->holder($binding,'com')->retail, 'Converted holder keeps contractual annual retail');
same($before, serialize([$frozen($paid),$frozen($uncertain)]), 'Conversion and capability reads preserve frozen money, funding and attempts byte for byte');
same(null, $claims->claim($uncertain->id), 'Uncertain operation cannot be retried when facts are unknown');
$observation->name=$paid->domain->domain;
same('succeeded', $reconcile->run($paid->id)->state, 'Paid pending reconciliation continues without public offer facts');
$d=$paid->domain->fresh();
DB::transaction(function () use ($caps,$d,$clock,$prices): void {
    $l=HoldingLocks::take($d,catalogue:$caps);
    same('10.40',$l['offer']['retail']->retail,'Holding prefix captures retained retail');
    same('8.0000',$l['offer']['cost']->total,'Holding prefix captures normalized annual observation');
    $guard=new NativeDomainGuard(new HolderPricing($prices,$caps),$clock);
    same('10.40',$guard->quote($l)['annual_net'],'Native quote uses retained locked evidence');
    $quote=['cost'=>['amount'=>'12.0000','currency'=>'USD']];
    same('14.40',LateRenewalQuote::price($l,$quote,['annual_net'=>'10.40'])['annual_net'],'Late quote preserves normalized dollar margin');
    commerceReject(fn()=>$caps->requireRenewal(CatalogueAcceptances::binding($l['account']),'com',$l['tld']));
});
DB::table('domains_tlds')->where('server_id',$binding->serverId)->update(['catalogue_facts'=>null,'catalogue_intent'=>OfferState::json(['sell'=>false]),'catalogue_published_revision'=>null]);
DB::table('domains_prices')->where('server_id',$binding->serverId)->update(['catalogue_observation'=>null,'wholesale'=>'999.0000']);
DB::transaction(function () use ($caps,$d,$clock,$prices): void {
    $l=HoldingLocks::take($d,catalogue:$caps); $guard=new NativeDomainGuard(new HolderPricing($prices,$caps),$clock);
    same('10.40',$guard->quote($l)['annual_net'],'Unsold, unknown-cost holder keeps annual retail');
    same(null,$l['offer']['cost'],'Obsolete wholesale never supplies a new renewal ceiling');
    commerceReject(fn()=>LateRenewalQuote::price($l,['cost'=>['amount'=>'12.0000']],['annual_net'=>'10.40']));
});
$nativeSuccessful=true;
echo "Offer holder consumers: ok\n";
