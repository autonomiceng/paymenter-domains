<?php
// Live Enom bill clock calibration from a normal reply's own order; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/native-app.php';
$fixtureEnvironment = \Paymenter\Extensions\Servers\Domains\Data\Environment::Live;
config(['app.url'=>'https://billing.example.com']);
require __DIR__.'/Support/reconciliation-fixture.php';
use App\Models\Server;
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, DispatchSeal, Disposition, Environment, ExecutionRecord, PersistedExecution, Query, RequestBudget, RequestContext, RequestOrigin, SendState};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\{ProtocolResult, ReadCommand};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\{Driver, OrderCharge, ReadAdapter};
use Paymenter\Extensions\Servers\Domains\Services\{EnomBillZones, ReconciliationEvidence};
// The send window opens at 12:00:00 UTC and lasts 300 seconds. 6:00:01 AM fits as Mountain (MDT), 5:00:01 AM as Pacific (PDT).
[$op, $binding] = $prepare(placeholderComFixtureName('zone-calibration-placeholder.com'));
same(Environment::Live, $binding->environment, 'Live account');
$attempt = ReconciliationEvidence::attempt($op, $op->domain, $binding);
same([null, ExecutionRecord::Recorded, '9001'], [$attempt->billZone, $attempt->execution, $attempt->providerReference], 'An unmeasured account carries no bill zone; the normal reply names its order');
$driver = new Driver(new ReadAdapter($binding, 'account-enom', 'token', $wire), ['com']);
$sample = function (string $bill) use ($driver, $attempt, $observation, $binding, $clock): ?array {
    $observation->billDate = $bill; $observation->calls = [];
    $facts = $driver->orderCharge($attempt, $attempt->executionFacts, new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+250 seconds'), new RequestBudget(25), $clock))->evidence();
    same([ReadCommand::Order], $observation->calls, 'Calibration reads only the reply\'s own order detail');
    return $facts->billZoneSample;
};
same(['zone'=>'America/Denver', 'order'=>'9001'], $sample('10/18/2026 6:00:01 AM'), 'A bill date inside the window only as Mountain samples Mountain');
same(['zone'=>'America/Los_Angeles', 'order'=>'9001'], $sample('10/18/2026 5:00:01 AM'), 'A bill date inside the window only as Pacific samples Pacific');
same(null, $sample('10/18/2026 7:30:00 AM'), 'A bill date outside the window in both zones is no sample');
$result = $driver->orderCharge($attempt, $attempt->executionFacts, new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+250 seconds'), new RequestBudget(25), $clock));
$summary = json_encode($result).print_r($result, true).var_export((array) $result, true);
expect(!str_contains($summary, '9001'), 'The sampled order stays out of summaries');
// A lost reply has no order of its own; TEST is never sampled.
$test = new AccountBinding('enom', 9, Environment::Test, 'offline-test'); $query = new Query('example.com', Action::Register, 1);
$order = new ProtocolResult('success', data: ['order'=>['orderid'=>'9001', 'result'=>'true', 'orderbillamount'=>'$8.95', 'billdate'=>'10/18/2026 6:00:01 AM',
    'orderdetail'=>['description'=>'example.com', 'producttype'=>'Register', 'quantity'=>'1', 'status'=>'Successful', 'orderstatus'=>'Closed', 'amountpaid'=>'8.95']]]);
$start = new DateTimeImmutable('2026-10-18T12:00:00Z');
$testAttempt = new AttemptEvidence($test, $query, 'original', 1, ExecutionRecord::Recorded, SendState::Sent, $start, $start->modify('+300 seconds'),
    seal: DispatchSeal::Sealed, providerReference: '9001', executionFacts: new PersistedExecution($test, $query, 'original', Disposition::Accepted, SendState::Sent, '9001'));
same(true, OrderCharge::read($testAttempt, $testAttempt->executionFacts, $order) !== null, 'The TEST order itself is a valid charge');
same(null, OrderCharge::billZoneSample($testAttempt, $order), 'TEST is never sampled');
// End to end: reconciliation of the normal reply records the sample, with no write or resend.
$operations = Operation::count(); $preflights = $preflight->calls; $dispatched = $op->attempt['dispatch_attempted_at'];
$observation->billDate = '10/18/2026 6:00:01 AM'; $observation->calls = [];
same('succeeded', $reconcile->run($op->id)->state, 'The normal reply completes from its own order');
same([], array_values(array_diff(array_map(fn ($c) => $c->name, $observation->calls), ['Info', 'Nameservers', 'Expiry', 'Renewal', 'Lock', 'Privacy', 'Order'])), 'Reconciliation makes reads only');
same(1, count(array_filter($observation->calls, fn ($c) => $c === ReadCommand::Order)), 'One order detail read');
same([$operations, $preflights, $dispatched], [Operation::count(), $preflight->calls, $op->fresh()->attempt['dispatch_attempted_at']], 'Calibration starts no operation and sends nothing again');
same('America/Denver', EnomBillZones::measured($binding), 'One clean sample measures the account');
$setting = fn () => Server::find($binding->serverId)->settings()->where('key', EnomBillZones::KEY)->sole();
$stored = json_decode($setting()->value, true);
same([true, ['America/Denver'=>1], 1], [(bool)$setting()->encrypted, $stored['counts'], count($stored['orders'])], 'The encrypted server setting keeps zone counts and hashed order IDs');
expect(!str_contains(json_encode($stored), '9001'), 'Order IDs are stored only as hashes');
// The measured zone reaches every later attempt of the account.
[$next] = $prepare(placeholderComFixtureName('zone-measured-placeholder.com'));
same('America/Denver', ReconciliationEvidence::attempt($next, $next->domain, $binding)->billZone, 'A later attempt carries the measured zone');
// The same order again changes nothing.
EnomBillZones::record($binding, ['zone'=>'America/Denver', 'order'=>'9001']); EnomBillZones::record($binding, ['zone'=>'America/Los_Angeles', 'order'=>'9001']);
same(['America/Denver'=>1], json_decode($setting()->value, true)['counts'], 'A sample for an order already counted changes nothing');
// A conflicting sample from another order leaves the account unmeasured: matching falls back to configured Pacific.
EnomBillZones::record($binding, ['zone'=>'America/Los_Angeles', 'order'=>'9002']);
same([null, ['America/Denver'=>1, 'America/Los_Angeles'=>1]], [EnomBillZones::measured($binding), json_decode($setting()->value, true)['counts']], 'Conflicting samples refuse a measurement');
same(null, ReconciliationEvidence::attempt($next, $next->domain, $binding)->billZone, 'A conflicted account carries no bill zone');
EnomBillZones::record($binding, ['zone'=>'America/Denver', 'order'=>'9003']);
same(null, EnomBillZones::measured($binding), 'More agreeing samples never clear a conflict');
// A malformed setting is unmeasured and is never overwritten.
$row = $setting(); $row->value = '{"broken":true}'; $row->save();
EnomBillZones::record($binding, ['zone'=>'America/Denver', 'order'=>'9004']);
same([null, '{"broken":true}'], [EnomBillZones::measured($binding), $setting()->value], 'A malformed setting stays unmeasured and unwritten');
$nativeSuccessful = true; echo "Live Enom bill clock is measured from normal replies' own orders, read only, and a conflict refuses\n";
