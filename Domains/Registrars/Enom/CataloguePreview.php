<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, AttemptEvidence, Capabilities, CatalogueFreshness, DriverResult, Query, ReconciliationResult, RequestContext};

/** Preview composition exposes only catalogue reads and availability; no holding/reconciliation reads. */
final readonly class CataloguePreview implements \Paymenter\Extensions\Servers\Domains\Contracts\RegistrarDriver, \Paymenter\Extensions\Servers\Domains\Contracts\AcquisitionPriceReader
{
    public function __construct(private Driver $driver) {}
    public function binding(): AccountBinding { return $this->driver->binding(); }
    public function accountCapabilities(RequestContext $context): Capabilities { return $this->driver->accountCapabilities($context); }
    public function catalogue(RequestContext $context): ReadResult { return $this->driver->catalogue($context); }
    public function bulkPricing(bool $domainPricing, int $years, RequestContext $context): ReadResult { return $this->driver->bulkPricing($domainPricing, $years, $context); }

    public function availability(Query $query, RequestContext $context): DriverResult { return $this->driver->availability($query, $context); }
    public function balance(RequestContext $context): DriverResult { throw new \DomainException('Use the separate account balance reader.'); }
    public function quote(Query $query, RequestContext $context): DriverResult { throw new \DomainException('Preview quotes require saved catalogue prices.'); }
    public function acquisitionQuote(Query $query, RequestContext $context): DriverResult { return $this->driver->acquisitionQuote($query, $context); }
    public function readDomain(string $name, RequestContext $context): DriverResult { throw new \DomainException('Domain reads require separate deployment composition.'); }
    public function readRenewalDomain(string $name, RequestContext $context): DriverResult { throw new \DomainException('Renewal reads require separate deployment composition.'); }
    public function renewalAssessment(Query $query, RequestContext $context): DriverResult { throw new \DomainException('Renewal reads require separate deployment composition.'); }
    public function reconcile(AttemptEvidence $attempt, RequestContext $context): ReconciliationResult { throw new \DomainException('Reconciliation reads require separate deployment composition.'); }
}
