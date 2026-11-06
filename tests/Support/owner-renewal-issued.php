<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real issued debt shared by the independent owner matrices.
declare(strict_types=1);
use App\Models\Invoice;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
$setting('domains_manual_renewals_enabled',true);$setting('domains_billing_enabled',true);
$controller->renew($req($d,['years'=>'2']),$d->id);
$op=Operation::where('domain_id',$d->id)->where('action','renew')->sole();$invoice=Invoice::findOrFail($op->invoice_id);
same([2,'awaiting_payment','2027-10-11'],[(int)$op->term,$op->state,$invoice->due_at->format('Y-m-d')],'Matrix starts with real fixed two-year debt');
$issued=$ledger();$rawInvoice=$invoice->getRawOriginal();$rawItem=$invoice->items()->sole()->getRawOriginal();$rawOp=$op->getRawOriginal();
