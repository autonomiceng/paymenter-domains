<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-enom.php';
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, NameserverEvidence, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{DomainOperations, DomainSummaries, WriteAcceptance, WriteFeatures};
$registrationModes=['clean','defaultdns','lost','mismatch','unknown','authority','finishfailure'];
require __DIR__.'/Support/registration-enom-matrix.php';
commerceReject(fn () => NameserverEvidence::observed(['ns1.provider.example'], 'registration_request', $clock->now()));
commerceReject(fn () => NameserverEvidence::requested(['ns1.provider.example'], 'enom_getdns', $clock->now()));
commerceReject(fn () => NameserverEvidence::observed(['ns1.provider.example', 'ns1.provider.example'], 'enom_getdns', $clock->now()));
commerceReject(fn () => NameserverEvidence::observed(['invalid nameserver'], 'enom_getdns', $clock->now()));
same(null, NameserverEvidence::fromArray(['nameservers' => ['ns1.provider.example'], 'state' => 'observed', 'source' => 'enom_getdns', 'at' => 'not-a-time']), 'Malformed stored nameserver timestamps are ignored');
expect($completionFailures >= 2, 'Transient completion retried with original acceptance and correction evidence');
same(['NS1' => 'ns1.child.example', 'NS2' => 'ns2.child.example'], \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration::nameservers(['NS1.Child.example.', 'ns2.child.example']), 'Enom source accepts normalized child hosts');
commerceReject(fn () => \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration::nameservers(['ns1.provider.example', 'NS1.provider.example.']));
$nativeSuccessful=true;echo "Enom registration scenario passed.\n";
