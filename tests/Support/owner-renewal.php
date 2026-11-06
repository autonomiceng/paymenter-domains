<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Reuse main's batched native owner registrations.
declare(strict_types=1);
$registrationNames=($ownerRenewalDomains??2)===0
    ? ['owner-last-day-on-placeholder.com','owner-last-day-off-placeholder.com','owner-late-credited-placeholder.com']
    : (($ownerRenewalDomains??2)===1 ? ['owner-renew-placeholder.com'] : ['owner-renew-placeholder.com','owner-renew-late-placeholder.com']);
$ownerCalendarTerms=($ownerRenewalDomains??2)===0;
require __DIR__.'/owner-renewal-fixture.php';
$clock->instant=new DateTimeImmutable('2027-09-01T12:00:00Z');
