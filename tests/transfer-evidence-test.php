<?php
// Synthetic transfer decisions without commerce setup; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/FakeRegistrar.php';
// Completion uses Carbon's calendar arithmetic. Load the pinned vendor autoloader only, without booting Laravel or a database.
$fixture = (string) getenv('PAYMENTER_TEST_FIXTURE');
expect($fixture !== '', 'Runner-created fixture required for Carbon');
require $fixture.'/vendor/autoload.php';
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, Action, AttemptEvidence, DateEvidence, DispatchSeal, DomainSnapshot, Environment, Evidence, ExecutionRecord, ExpiryPrecision, HistoryAcceptance, Money, OrderEvidence, Query, RequestBudget, RequestContext, RequestOrigin, SendState, TransferStatus};
use Paymenter\Extensions\Servers\Domains\Services\ReconciliationEvidence;
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
$start = new DateTimeImmutable('2026-10-18T12:00:00Z');
$clock = new FakeClock($start->modify('+6 minutes'));
$date = static fn (string $value) => new DateEvidence($value, ExpiryPrecision::Date, 'synthetic');
$attemptFor = static function (AccountBinding $binding, string $fault = '') use ($start, $date): AttemptEvidence {
    return new AttemptEvidence($binding, new Query('example.com', Action::Transfer, 1), 'transfer:1', 1,
        ExecutionRecord::Recorded, SendState::PossiblySent, $start, $start->modify('+300 seconds'),
        beforeExpiry: $fault === 'baseline' ? null : $date('2027-01-01'), expectedCharge: new Money('99.00', 'USD'), seal: DispatchSeal::Sealed,
        historyAcceptance: $fault === 'nohistory' ? null : new HistoryAcceptance($binding, 'America/Los_Angeles', $fault === 'lag' ? 600 : 0, 86400, $binding->registrarKey.'_transfer_v1'),
        transferReference: $binding->registrarKey === 'enom' ? '7001' : null, expectedPrivacy: $fault === 'sealedprivacy' ? null : false);
};
foreach (['enom', 'namesilo'] as $registrar) {
    $binding = new AccountBinding($registrar, 1, Environment::Test, 'account-'.$registrar);
    require __DIR__.'/Support/transfer-'.$registrar.'-observation.php';
    $driver = $registrar === 'enom'
        ? new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Driver(new Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\Client($binding, 'account-enom', 'synthetic', $wire), ['com'])
        : new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Driver(new Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Client($binding, 'synthetic', $wire), ['renew'], $clock);
    $faults = $registrar === 'enom'
        ? ['full','pending','status','charge','duplicate','short','owner','expiry','late','nohistory','baseline']
        : ['full','no-status-expiry','inactive','auto','privacy','short','owner','nocharge','duplicate','late','credited','unknowncharge','paged','nohistory','lag',
            'retention','baseline','sealedprivacy','expiration','badexpiry','nullexpiry','pending','unknown'];
    foreach ($faults as $fault) {
        $clock->instant = $start->modify($fault === 'retention' ? '+2 days' : '+6 minutes');
        $attempt = $attemptFor($binding, $fault);
        $observe->name = 'example.com'; $observe->fault = $fault; $observe->calls = [];
        if ($registrar === 'namesilo') {
            $observe->status = match ($fault) { 'pending' => 'Pending at Registry', 'unknown' => 'Completed', default => 'Transfer Completed' };
            $observe->expiration = match ($fault) {
                'no-status-expiry' => [], 'expiration' => ['expiration'=>'2029-01-01'],
                'badexpiry' => ['expiration'=>'2028-02-30'], 'nullexpiry' => ['expiration'=>null], default => ['expiration'=>'2028-01-01'],
            };
        }
        $context = new RequestContext($binding, RequestOrigin::Background, $clock->now()->modify('+250 seconds'), new RequestBudget(25), $clock);
        $facts = $driver->reconcile($attempt, $context)->result()->evidence();
        $proof = ReconciliationEvidence::completion($attempt, $facts, $clock->now());
        $accepted = in_array($fault, ['full', 'no-status-expiry'], true);
        same($accepted, $proof !== null, $registrar.' completion decision: '.$fault);
        expect($observe->calls !== [], 'Every wire variation reaches the real registrar reader: '.$registrar.'/'.$fault);
        if ($accepted) {
            same(['9001', ['amount'=>$registrar === 'enom' ? '8.95' : '99.00', 'currency'=>'USD'], '2028-01-01', 'accepted_history'],
                [$proof['order_reference'], $proof['charge'], $proof['expiry'], $proof['source']], 'Exact transfer proof: '.$registrar.'/'.$fault);
        }
        if ($registrar === 'enom') {
            same($accepted, in_array('GetDNS', $observe->calls, true), 'Only proven transfer evidence reads DNS: '.$fault);
        }
    }
    // Direct DTO cases isolate the completion guards from protocol parsing and include a positive control.
    $clock->instant = $start->modify('+6 minutes'); $attempt = $attemptFor($binding);
    $processed = new DateEvidence('2026-10-18T12:00:01+00:00', ExpiryPrecision::Instant, 'synthetic', 'UTC');
    $domain = new DomainSnapshot($binding, 'example.com', true, $date('2028-01-01'), false, false, active: true);
    $order = new OrderEvidence($binding, $attempt->query, new Money('8.95', 'USD'), '9001', $processed, $date('2028-01-01'));
    $evidence = static fn (array $changes = []) => new Evidence(...array_replace([
        'domain'=>$domain, 'candidates'=>[$order], 'historyComplete'=>true, 'transfer'=>TransferStatus::Completed,
        'transferReference'=>$registrar === 'enom' ? '7001' : null,
    ], $changes));
    expect(ReconciliationEvidence::completion($attempt, $evidence(), $clock->now()) !== null, 'Valid typed proof: '.$registrar);
    foreach (TransferStatus::cases() as $status) {
        same($status === TransferStatus::Completed, ReconciliationEvidence::completion($attempt, $evidence(['transfer'=>$status]), $clock->now()) !== null,
            'Only completed transfer status proves completion: '.$registrar.'/'.$status->value);
    }
    foreach (['zero'=>[], 'duplicate'=>[$order,$order]] as $fault=>$orders) {
        same(null, ReconciliationEvidence::completion($attempt, $evidence(['candidates'=>$orders]), $clock->now()), 'Exactly one charged order: '.$registrar.'/'.$fault);
    }
    same(null, ReconciliationEvidence::completion($attempt, $evidence(['historyComplete'=>false]), $clock->now()), 'Incomplete transfer history refuses: '.$registrar);
    foreach (['baseline', 'nohistory', ...($registrar === 'namesilo' ? ['sealedprivacy'] : [])] as $fault) {
        same(null, ReconciliationEvidence::completion($attemptFor($binding, $fault), $evidence(), $clock->now()), 'Missing sealed facts refuse: '.$registrar.'/'.$fault);
    }
    foreach (['2027-01-01','2027-12-31','2028-01-02','2029-01-01'] as $expiry) {
        $changed = new DomainSnapshot($binding, 'example.com', true, $date($expiry), false, false, active: true);
        same(null, ReconciliationEvidence::completion($attempt, $evidence(['domain'=>$changed]), $clock->now()), 'Transfer requires exactly the full added year: '.$registrar.'/'.$expiry);
    }
    // A missing baseline must also refuse a term that could be fabricated from the dispatch date.
    $unanchoredExpiry = $date('2027-10-18');
    $unanchoredDomain = new DomainSnapshot($binding, 'example.com', true, $unanchoredExpiry, false, false, active: true);
    $unanchoredOrder = new OrderEvidence($binding, $attempt->query, $order->charge, '9001', $processed, $unanchoredExpiry);
    same(null, ReconciliationEvidence::completion($attemptFor($binding, 'baseline'),
        $evidence(['domain'=>$unanchoredDomain, 'candidates'=>[$unanchoredOrder]]), $clock->now()), 'Never invent a baseline from dispatch: '.$registrar);
    if ($registrar === 'enom') {
        foreach ([null, '7002'] as $reference) {
            same(null, ReconciliationEvidence::completion($attempt, $evidence(['transferReference'=>$reference]), $clock->now()), 'Enom transfer reference must match');
        }
    } else {
        foreach (['active'=>[false,null], 'providerAutoRenew'=>[true,null], 'privacy'=>[true,null]] as $field=>$values) {
            foreach ($values as $value) {
                $changed = new DomainSnapshot(...array_replace(['binding'=>$binding, 'name'=>'example.com', 'owned'=>true,
                    'expiry'=>$date('2028-01-01'), 'providerAutoRenew'=>false, 'privacy'=>false, 'active'=>true], [$field=>$value]));
                same(null, ReconciliationEvidence::completion($attempt, $evidence(['domain'=>$changed]), $clock->now()), 'NameSilo requires known matching '.$field);
            }
        }
    }
}
echo "Transfer evidence decisions and registrar normalization passed without commerce setup.\n";
