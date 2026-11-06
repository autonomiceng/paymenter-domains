<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native issued debt and owner/list projections must agree; a DTO-only fixture misses invoice membership.
require __DIR__.'/Support/renewal-execution.php';
require __DIR__.'/Support/owner-pages.php';
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainListRow,DomainPage};
[$op,$d,$binding,$invoice]=$makeRenewal(placeholderComFixtureName('standard-summary-placeholder.com'));
$clock->instant=new DateTimeImmutable('2027-09-27T12:00:00Z');
$summary=fn()=>(new DomainSummaries)->one($user,'managed',$d->id,$clock->now()->getTimestamp());
$detail=fn($s)=>DomainPage::nextInvoice($owners->present($d->fresh()),$s,null);
$s=$summary();
same('Paid',$detail($s)['value'],'Valid standard invoice is Paid on the detail page');
same('Next invoice: Paid',DomainListRow::renewal($s)['note'],'Valid standard invoice is Paid on the list');
// Paid, but the registrar has not confirmed the renewal: the detail says Under review, so the list must too (demo R18b).
$state=$op->fresh()->state;$op->forceFill(['state'=>'uncertain'])->saveQuietly();
$s=$summary();
same('Under review',$detail($s)['value'],'Detail keeps an unconfirmed paid renewal under review');
same('Next invoice: Under review. There’s nothing you need to do.',DomainListRow::renewal($s)['note'],'List agrees with detail on an unconfirmed paid renewal');
expect(!$s->renewalInvoicePaid && !$s->renewalInvoiceMissing,'Unconfirmed paid renewal is neither Paid nor not issued');
$op->forceFill(['state'=>$state])->saveQuietly();
same('Next invoice: Paid',DomainListRow::renewal($summary())['note'],'Restored renewal is Paid on the list again');
// A zero extra line preserves the paid amount but invalidates the exact standard renewal debt shape.
// Privileged retained-state mutation: live billing guards already refuse this malformed shape.
App\Models\InvoiceItem::withoutEvents(fn()=>$invoice->items()->create(['description'=>'Synthetic extra renewal line','quantity'=>1,'price'=>'0.00','reference_type'=>null,'reference_id'=>null]));
same('paid',$invoice->fresh()->status,'Extra-line fixture retains the native paid status');
same(2,$invoice->items()->count(),'Extra-line fixture retains both invoice members');
$s=$summary();
same('Under review',$detail($s)['value'],'Detail refuses a standard invoice with an extra line');
$row=DomainListRow::renewal($s);
same('Next invoice: Under review. There’s nothing you need to do.',$row['note'],'List agrees with detail instead of trusting the Paid status');
expect(!$s->renewalInvoicePaid && !$row['invoiceMissing'],'Invalid issued debt is under review rather than Paid or not issued');
$nativeSuccessful=true;echo "Standard paid renewal invoice membership agrees on list and detail.\n";
