<?php
// Synthetic offline transfer observation; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/management-namesilo.php';
require_once __DIR__.'/observation-work.php';
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationCandidates,ObservationSchema,ObservationWork};
ObservationSchema::install();$budget=new AccountReadBudget($clock);$observer=new DomainObservation($readBindings,$clock,$budget);
[$d,$driver,$original]=$holding('pending-window',false);$observe->status='Pending at Registry';$before=$original->getAttributes();
$work=observationFixtureScheduler($observer,$clock,activeFixturePorts($bindings));$enom=$tlds[0]->server_id;$ns=$d->server_id;$work->enroll($enom);$work->enroll($ns);
