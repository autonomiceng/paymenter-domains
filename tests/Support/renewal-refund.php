<?php
// Native renewal refund fixture; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/renewal-batch-fixture.php';
require __DIR__.'/retained-renewal-term.php';
use App\Models\{Credit, InvoiceTransaction, Role};
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{PricingRule, Refunds, RefundPayment, RenewalCycles, RenewalFunding, HoldingLocks, FinancialAdjustments};
\Paymenter\Extensions\Servers\Domains\Services\ReminderSchema::install();
\Paymenter\Extensions\Servers\Domains\Services\RenewalRetrySchema::install();
$refunds=new Refunds($clock); $staff=commerceUser();$corrections=new FinancialAdjustments($clock);
// Native SQLite is needed to exercise Paid/afterCommit against the retained invoice and operation bindings.
$presenter=new \Paymenter\Extensions\Servers\Domains\Services\NativePresenter(
    new \Paymenter\Extensions\Servers\Domains\Services\OwnedDomains($clock,new \Paymenter\Extensions\Servers\Domains\Services\OwnerPorts),$native,
    new \Paymenter\Extensions\Servers\Domains\Services\HolderPricing($prices));
$role=Role::create(['name'=>'Renewal refunds','permissions'=>['admin.domains.manage','admin.invoices.update','admin.invoice_transactions.create']]); $staff->update(['role_id'=>$role->id]);
$credit=static fn()=>PricingRule::minor((string)(Credit::where('user_id',$user->id)->where('currency_code','USD')->value('amount')??'0.00'));
