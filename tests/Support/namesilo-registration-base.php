<?php
// Synthetic acceptance/profile/correction contract; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require __DIR__.'/namesilo-registration-fixture.php';
require __DIR__.'/owner-pages.php';
storefrontViews();
use Paymenter\Extensions\Servers\Domains\Contracts\RegistrationTransport;
use Paymenter\Extensions\Servers\Domains\Data\{Action, AdmittedOperation, DriverResult, Money, Query, RequestBudget, RequestContext, RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaries;
$ownerPage = static function ($result) use ($user, $clock): string {
    $summary = (new DomainSummaries)->one($user, 'managed', (int) $result->domain->id, $clock->now()->getTimestamp());
    return view('domains::partials.summary', ['summary' => $summary, 'summaryOnManaged' => true])->render();
};
