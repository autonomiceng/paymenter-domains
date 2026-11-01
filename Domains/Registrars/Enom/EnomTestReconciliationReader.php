<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Contracts\CatalogueReader;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,AttemptEvidence,CatalogueAcceptance,CatalogueFreshness,Capabilities,DriverResult,Environment,Query,ReconciliationResult,RequestContext};
/** Built-in reader permits captured-attempt reconciliation while ordinary domain polling stays closed. */
final readonly class EnomTestReconciliationReader implements CatalogueReader
{
    public function __construct(private CataloguePreview $preview,private Driver $reconciler) {}
    public function binding():AccountBinding{return $this->preview->binding();}
    public function accountCapabilities(RequestContext $c):Capabilities{return $this->preview->accountCapabilities($c);}
    public function balance(RequestContext $c):DriverResult{return $this->reconciler->balance($c);}
    public function quote(Query $q,RequestContext $c):DriverResult{return $this->preview->quote($q,$c);}
    public function availability(Query $q,RequestContext $c):DriverResult{return $this->preview->availability($q,$c);}
    public function readDomain(string $name,RequestContext $c):DriverResult{return \Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::enabled($this->binding())||\Paymenter\Extensions\Servers\Domains\Services\EnomTestManagement::accepted($this->binding())?$this->reconciler->readDomain($name,$c):$this->preview->readDomain($name,$c);}
    public function readRenewalDomain(string $name,RequestContext $c):DriverResult{return \Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::enabled($this->binding())?$this->reconciler->readRenewalDomain($name,$c):$this->preview->readRenewalDomain($name,$c);}
    public function renewalAssessment(Query $q,RequestContext $c):DriverResult{return \Paymenter\Extensions\Servers\Domains\Services\EnomTestRenewal::enabled($this->binding())?$this->reconciler->renewalAssessment($q,$c):$this->preview->renewalAssessment($q,$c);}
    public function reconcile(AttemptEvidence $attempt,RequestContext $c):ReconciliationResult
    {
        $renewal=$attempt->query->action===Action::Renew&&\Paymenter\Extensions\Servers\Domains\Services\TestCatalogue::enabled($this->binding());
        $transfer=$attempt->query->action===Action::Transfer&&(\Paymenter\Extensions\Servers\Domains\Services\EnomTestTransfer::enabled($this->binding())||\Paymenter\Extensions\Servers\Domains\Services\EnomLive::enabled($this->binding(),'catalogue'))&&is_string($attempt->transferReference)&&preg_match('/^[1-9][0-9]{0,19}$/D',$attempt->transferReference);
        $captured=is_string($attempt->providerReference)&&preg_match('/^[1-9][0-9]{0,19}$/D',$attempt->providerReference)&&$attempt->executionFacts;
        $history=$renewal&&$attempt->historyAcceptance?->covers($attempt,$c->now());
        if($attempt->binding->registrarKey!=='enom'||($attempt->binding->environment!==Environment::Test&&!\Paymenter\Extensions\Servers\Domains\Services\EnomLive::enabled($this->binding(),'catalogue'))
            ||!($attempt->query->action===Action::Register||$renewal||$transfer)||!($captured||$history||$transfer)){
            throw new \DomainException($renewal?'Captured TEST order or accepted renewal history is required.':'Captured TEST Purchase OrderID is required.');
        }
        $attempt->executionFacts?->requireAttempt($attempt);
        return $this->reconciler->reconcile($attempt,$c);
    }
    public function catalogue(RequestContext $c):ReadResult{return $this->preview->catalogue($c);}
    public function bulkPricing(bool $domainPricing, int $years, RequestContext $context): ReadResult { return $this->preview->bulkPricing($domainPricing, $years, $context); }
    public function catalogueBatch(CatalogueAcceptance $a,CatalogueFreshness $f,RequestContext $c):array{return $this->preview->catalogueBatch($a,$f,$c);}
}
