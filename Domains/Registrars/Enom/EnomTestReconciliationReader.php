<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Contracts\CatalogueReader;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Action,AttemptEvidence,CatalogueAcceptance,CatalogueFreshness,Capabilities,DriverResult,Environment,Query,ReconciliationResult,RequestContext};
/** TEST runtime reader permits captured-attempt reconciliation while keeping ordinary domain polling closed. */
final readonly class EnomTestReconciliationReader implements CatalogueReader
{
    public function __construct(private CataloguePreview $preview,private Driver $reconciler) {}
    public function binding():AccountBinding{return $this->preview->binding();}
    public function accountCapabilities(RequestContext $c):Capabilities{return $this->preview->accountCapabilities($c);}
    public function balance(RequestContext $c):DriverResult{return $this->reconciler->balance($c);}
    public function quote(Query $q,RequestContext $c):DriverResult{return $this->preview->quote($q,$c);}
    public function availability(Query $q,RequestContext $c):DriverResult{return $this->preview->availability($q,$c);}
    public function readDomain(string $name,RequestContext $c):DriverResult{return $this->preview->readDomain($name,$c);}
    public function renewalAssessment(Query $q,RequestContext $c):DriverResult{return $this->preview->renewalAssessment($q,$c);}
    public function reconcile(AttemptEvidence $attempt,RequestContext $c):ReconciliationResult
    {
        if($attempt->binding->registrarKey!=='enom'||$attempt->binding->environment!==Environment::Test
            ||$attempt->query->action!==Action::Register||!is_string($attempt->providerReference)
            ||!preg_match('/^[1-9][0-9]{0,19}$/D',$attempt->providerReference)||!$attempt->executionFacts){
            throw new \DomainException('Captured TEST Purchase OrderID is required.');
        }
        $attempt->executionFacts->requireAttempt($attempt);
        return $this->reconciler->reconcile($attempt,$c);
    }
    public function catalogue(RequestContext $c):ReadResult{return $this->preview->catalogue($c);}
    public function catalogueBatch(CatalogueAcceptance $a,CatalogueFreshness $f,RequestContext $c):array{return $this->preview->catalogueBatch($a,$f,$c);}
}
