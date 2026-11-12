<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-paid-cases.php';
use App\Models\{Credit,Invoice,InvoiceTransaction,Service};
use Illuminate\Support\Facades\{DB,Event,Queue};
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,ContactRecovery,ContactSetup,NativeActivationInformation,NativePaidHooks,Refunds,UnpaidAcquisitionProof};
use Paymenter\Extensions\Servers\Domains\Admin\Support\AcquisitionPaymentReview;
// Arrange the three visible states through native payment and closure paths.
$mixed=$new(placeholderComFixtureName('paid-mixed-placeholder.com'),paid:false);$paid($mixed);
$failed=$new(placeholderComFixtureName('paid-failed-placeholder.com'),paid:false);$preflight->supported=false;$paid($failed);$preflight->supported=true;
$refunds->refundHeld($staff,$failed->id,'customer_cancelled');
DB::table('invoices')->where('id',$failed->invoice_id)->update(['status'=>'pending']);$paid($failed);
$retired=$new(placeholderComFixtureName('paid-retired-placeholder.com'),paid:false);
DB::table('invoices')->where('id',$retired->invoice_id)->update(['status'=>'cancelled']);DB::table('services')->where('id',$retired->service_id)->update(['status'=>'cancelled']);$retirement->inspect($retired->domain_id);$paid($retired);
// Actual staff queue and detail surfaces, including invoice permission narrowing.
$late=Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains::class)->filterTable('status',['failed','cancelled']);
$late->set('tableDeferredFilters',$late->get('tableFilters'))->call('applyTableFilters')->assertCanSeeTableRecords([$failed->domain,$retired->domain]);
// The mixed order keeps its own step (registrar result not confirmed), not the late money one.
same('Registrar has not confirmed the result. Check request #'.$mixed->id.'.',Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($mixed->domain->fresh())['step'],'Mixed order keeps its own next step');
same('Payment activity after the order closed. Check the invoice.',Paymenter\Extensions\Servers\Domains\Admin\Support\DomainFacts::status($failed->domain->fresh())['step'],'Late payment shows its next step');
$invoiceUrl=App\Admin\Resources\InvoiceResource::getUrl('edit',['record'=>$failed->invoice_id]);
// The page head says the notice in place of the short step, with its invoice link.
Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$failed->domain_id])->assertSee('Payment activity after the order closed. Check the invoice and payments before contacting the customer.')->assertSee('Open invoice')->assertSeeHtml('href="'.e($invoiceUrl).'"');
Livewire\Livewire::actingAs($staff)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\RelationManagers\RequestsRelationManager::class,['ownerRecord'=>$failed->domain,'pageClass'=>Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class])->assertSee('Request #'.$failed->id);
$viewOnly=staffUser(['admin.domains.view']);Livewire\Livewire::actingAs($viewOnly)->test(Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain::class,['record'=>$failed->domain_id])->assertSee('Payment activity after the order closed.')->assertDontSee('Open invoice')->assertDontSeeHtml('href="'.e($invoiceUrl).'"');

$nativeSuccessful=true;
