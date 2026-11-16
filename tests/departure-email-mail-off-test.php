<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
$nativeSuccessful=false;
// Site mail is off while the departure email is forced on: it cannot be sent, so staff get the template alert.
$emailAlert='template_failed';$emailDeliveries=[];
$emailFault=static function():Closure{
    App\Models\NotificationTemplate::where('key','domains_domain_departed')->firstOrFail()->update(['mail_enabled'=>App\Enums\NotificationEnabledStatus::Force]);
    $off=config('settings.mail_disable');config(['settings.mail_disable'=>true]);return static fn()=>config(['settings.mail_disable'=>$off]);
};
require __DIR__.'/Support/departure-email-failure.php';
echo "A forced departure email with site mail off leaves the staff alert and keeps the departure.\n";
$nativeSuccessful=true;
