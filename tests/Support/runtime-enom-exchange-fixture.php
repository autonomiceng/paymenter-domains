<?php
// Fresh ZIP composition and native paid work; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/commerce.php';require __DIR__.'/native-commerce.php';
use Illuminate\Support\Facades\{Cache,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Contracts\{AccountVerifier,Clock};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,Capabilities,CostUnit,Disposition,DriverResult,Environment,Evidence,Money,QuoteEvidence,ResultReason,SendState};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, AcquisitionPort, AcquisitionPorts, CartAdmission, CheckoutEligibility, InvoiceBinding, NativeCatalogue, OperationClaims, PriceCatalogue, Rdap, Refunds, RegistrarRegistry, RegistrationProfiles, RenewalAcceptance, RuntimeComposition, RuntimePorts, RuntimeWork, SellerPolicies, TransferInputs, TransferPrecheck, WriteAcceptance, WriteFeatures};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Registration,Transfer,Management};
use Paymenter\Extensions\Servers\Domains\Tests\FakeRegistrar;
expect(!is_link(base_path('extensions/Servers/Domains')),'Acquisition tests use extracted ZIP bytes');
foreach([AcquisitionPort::class,AcquisitionPorts::class,RuntimeComposition::class] as $type){
    expect(str_starts_with((new ReflectionClass($type))->getFileName(),base_path('extensions/Servers/Domains/')),'Composition is installed runtime');
}
extract(commerceFixture(true,static fn()=>null));
$clock=new class($now) implements Clock {
    public function __construct(public DateTimeImmutable $at){}
    public function now(): DateTimeImmutable {return $this->at;}
};
app()->instance(Clock::class,$clock);
require __DIR__.'/runtime-enom-exchange-setup.php';
