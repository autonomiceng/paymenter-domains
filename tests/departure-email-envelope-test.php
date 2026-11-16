<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic customized template failure.
declare(strict_types=1);
$nativeSuccessful=false;
$emailAlert='template_failed';$emailDeliveries=['handoff'];
$emailFault=static function():void {
    // The first two subject renders succeed; rendering the body changes the template's dependency.
    App\Models\NotificationTemplate::where('key','domains_domain_departed')->firstOrFail()->update([
        'subject'=>'@if(!empty($GLOBALS["departureBodyRendered"])) @php throw new \RuntimeException("Subject dependency unavailable."); @endphp @endif {{ $domain }}',
        'body'=>'@php $GLOBALS["departureBodyRendered"]=true; @endphp Departure notice.',
    ]);
};
require __DIR__.'/Support/departure-email-failure.php';
echo "A subject failure after mail reservation preserves the departure and records a template alert.\n";
$nativeSuccessful=true;
