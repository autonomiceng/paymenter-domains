<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/operation-fixture.php';
// Fall-back duration arithmetic must not gain an hour while native timestamps stay local.
$siteTimezone = date_default_timezone_get(); $ordinaryInstant = $clock->instant;
try {
    foreach ([245, 246, 275] as $elapsed) {
        $entry = new DateTimeImmutable('2026-11-01T01:58:00-07:00');
        date_default_timezone_set($siteTimezone);
        $clock->instant = $ordinaryInstant; $op = $new('fall-back-'.$elapsed.'.com'); $before = $writer->calls;
        date_default_timezone_set('America/Los_Angeles');
        $clock->instant = $entry->setTimezone(new DateTimeZone('America/Los_Angeles'));
        $preflight->during = static fn () => $clock->instant = $entry->setTimezone(new DateTimeZone('UTC'))
            ->modify('+'.$elapsed.' seconds')->setTimezone(new DateTimeZone('America/Los_Angeles'));
        $result = $engine->execute($op->id);
        same($before + ($elapsed === 245 ? 1 : 0), $writer->calls, 'Fall-back cannot extend the last-send boundary');
        if ($elapsed === 245) {
            same($entry->getTimestamp() + 270, $writer->context->deadline->getTimestamp(), 'Fall-back dispatch leaves finish reserve');
        } else { same('absent', $result->attempt['seal'], 'Fall-back refuses unsealed after the elapsed boundary');
            same('seal_authority_invalid', $result->safe_code, 'Fall-back refuses because the finish margin is exhausted'); }
        $preflight->during = null;
    }
} finally {
    date_default_timezone_set($siteTimezone); $clock->instant = $ordinaryInstant; $preflight->during = null;
    Carbon\Carbon::setTestNow($ordinaryInstant); Carbon\CarbonImmutable::setTestNow($ordinaryInstant);
}
// Native datetime persistence drops offsets, so the monotonic clock must return site-local objects.
$siteTimezone = date_default_timezone_get();
foreach (['America/Los_Angeles', 'Asia/Kolkata'] as $timezone) {
    $roundTrip = $new('clock-roundtrip-'.($timezone === 'Asia/Kolkata' ? 'east' : 'west').'.com');
    try {
        date_default_timezone_set($timezone);
        $systemClock = new \Paymenter\Extensions\Servers\Domains\Data\SystemClock;
        $systemClock->withinExecution(function () use ($systemClock, $roundTrip): void {
            $instant = $systemClock->now();
            $roundTrip->update(['started_at' => $instant, 'lease_expires_at' => $instant->modify('+360 seconds')]);
            $stored = $roundTrip->fresh();
            same($instant->getTimestamp(), $stored->started_at->getTimestamp(), 'Execution start survives native timezone round trip');
            same($instant->getTimestamp() + 360, $stored->lease_expires_at->getTimestamp(), 'Claim lease survives native timezone round trip');
        });
    } finally { date_default_timezone_set($siteTimezone); }
}
echo 'Operation clock boundary checks passed'.PHP_EOL;
$nativeSuccessful = true;
