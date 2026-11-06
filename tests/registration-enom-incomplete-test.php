<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-enom.php';
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, NameserverEvidence, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, DomainSummaries, WriteAcceptance, WriteFeatures};
$registrationModes=['pending','processing','queued','1300','missingid','invalidid','missingexpiry','invalidexpiry'];
require __DIR__.'/Support/registration-enom-matrix.php';
$nativeSuccessful=true;echo "Enom registration scenario passed.\n";
