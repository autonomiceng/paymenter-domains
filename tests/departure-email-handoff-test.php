<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
$nativeSuccessful=false;
// The mail handoff throws after the decision committed: the delivery is marked uncertain and staff get that alert.
$emailAlert='delivery_uncertain';$emailDeliveries=['uncertain'];
$emailFault=static fn()=>App\Models\EmailLog::creating(static function():void{throw new RuntimeException('Mail handoff unavailable.');});
require __DIR__.'/Support/departure-email-failure.php';
echo "A failed departure email handoff leaves the staff alert and keeps the departure.\n";
$nativeSuccessful=true;
