<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline mail capture with synthetic records.
declare(strict_types=1);
$out=$argv[1]??'';
// The departure email from the regression's own confirmation (an unpaid renewal, moved to another registrar).
require dirname(__DIR__).'/departure-email-test.php';
$nativeSuccessful=false;expect(is_dir($out),'Render output exists');
file_put_contents($out.'/email-departed.html',Illuminate\Support\Facades\Mail::queued(Paymenter\Extensions\Servers\Domains\Mail\DomainNotice::class)->first()->render());
$nativeSuccessful=true;echo "Departure email rendered offline.\n";
