<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
expect(is_file(dirname(__DIR__).'/Domains/Catalogue/Capabilities.php'),'Independent transfer capabilities required');
require __DIR__.'/Support/transfer-completion-enom-fixture.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Catalogue\{Capabilities, CallerPorts, Projection, UpgradeConversion};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueAcceptances, OfferSchema, OfferWorkSchema};
OfferSchema::install(); OfferWorkSchema::install();
$pending=$prepare(placeholderComFixtureName('offer-paid-transfer-placeholder.com'));
$observe->fault='pending'; $waiting=$reconcile->run($pending->id);
same('transfer_pending',$waiting->domain->status,'Paid transfer remains pending without invented completion');
same('7001',$waiting->attempt['evidence']['transferReference'],'Pending transfer keeps its acknowledged reference');
$clock->instant=$now; $uncertain=$newTransfer(placeholderComFixtureName('offer-uncertain-transfer-placeholder.com')); $makeTransfer(); $state->mode='lost';
same('uncertain',$engine->execute($uncertain->id)->state,'Lost transfer reply retains uncertain attempt');
$frozen=static function(): string {
    $rows=[]; foreach(['domains_operations','invoices','invoice_items','invoice_transactions','credits'] as $table){$rows[$table]=DB::table($table)->orderBy('id')->get()->all();}
    return serialize($rows);
};
$before=$frozen(); $writes=$state->calls;
foreach(Account::all() as $account){(new UpgradeConversion)->batch(CatalogueAcceptances::binding($account));}
$caps=new Capabilities(new CallerPorts(new Projection($native),$clock,$bindings,static fn()=>null,static fn()=>true),$clock,static fn()=>null);
same('10.40',$caps->holder($binding,'com')->retail,'Transfer holder keeps retained annual retail with unknown facts');
commerceReject(fn()=>$caps->requireOrder($binding,'com','transfer',1));
same($before,$frozen(),'Conversion and capability reads preserve both frozen transfer attempts and all native money');
$engine->execute($uncertain->id); same($writes,$state->calls,'Unknown facts cannot authorize uncertain transfer replay');
$clock->instant=new DateTimeImmutable('@'.$pending->fresh()->policy['reconciliation']['next_at']); $observe->name=$pending->domain->domain; $observe->fault='';
same('succeeded',$reconcile->run($pending->id)->state,'Paid pending transfer reconciles original charge with unknown public facts');
same($writes,$state->calls,'Transfer completion is read only');
$nativeSuccessful=true;
echo "Offer frozen transfers: ok\n";
