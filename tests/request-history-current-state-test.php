<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. A request's History shows each event's own outcome, never the request's current state on its newest event.
declare(strict_types=1);
require __DIR__.'/Support/panel-kit.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Trace;
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestRows;
use Paymenter\Extensions\Servers\Domains\Presentation\State;
panelKitFixture();
$states = static fn (Trace $t): array => array_map(static fn (array $s): ?State => $s['state'], (new ReflectionProperty(Trace::class, 'steps'))->getValue($t));
$at = '2026-11-16T15:33:00+00:00';
// The request's current state is also passed, as callers once did; no step may take it.
// A delayed request: its newest events are a staff check and, before it, the request itself. Neither has an outcome of its own.
same([null, null], $states(RequestRows::trace([['time' => $at, 'event' => 'Registrar checked', 'sub' => null, 'by' => 'Staff'],
    ['time' => $at, 'event' => 'Request created', 'sub' => null, 'by' => 'System']], State::Delayed)), 'The newest event does not take the request state');
// An event that is an outcome keeps it.
same([State::Completed, null], $states(RequestRows::trace([['time' => $at, 'event' => State::Completed->label(), 'sub' => null, 'by' => 'System'],
    ['time' => $at, 'event' => 'Request created', 'sub' => null, 'by' => 'System']], State::Delayed)), 'Events with an outcome keep it inline');
echo "Request History keeps each event's own outcome.\n"; $nativeSuccessful = true;
