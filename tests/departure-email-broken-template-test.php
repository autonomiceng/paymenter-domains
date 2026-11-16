<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
$nativeSuccessful=false;
// A template edit that fails to render: the departure still commits and staff get the template alert.
$emailAlert='template_failed';$emailDeliveries=[];
$emailFault=static fn()=>App\Models\NotificationTemplate::where('key','domains_domain_departed')->update(['body'=>'{{ departure_email_missing_helper() }}']);
require __DIR__.'/Support/departure-email-failure.php';
echo "A departure email that fails to render leaves the staff alert and keeps the departure.\n";
$nativeSuccessful=true;
