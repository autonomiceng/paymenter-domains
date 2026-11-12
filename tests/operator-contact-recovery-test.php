<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Services\ContactRecovery;
// Scheduler recovery uses the same paid, unsent and ownership checks, without a staff click.
$user->properties()->where('key','phone')->update(['value'=>'']);
$automatic=$new(placeholderComFixtureName('automatic-contact-recovery-placeholder.com'),true,true,'register');ContactRecovery::hold($automatic->id);
$financial=nativeFinancialSnapshot($automatic);
app(Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch::class)->enqueue($automatic->id);
same('held',$automatic->fresh()->state,'Incomplete account stays held');
$user->properties()->where('key','phone')->update(['value'=>'+1.2025550100']);
app(Paymenter\Extensions\Servers\Domains\Services\RuntimeDispatch::class)->enqueue($automatic->id);
same('queued',$automatic->fresh()->state,'Valid account details automatically readmit the original registration');
same($financial,nativeFinancialSnapshot($automatic),'Automatic contact recovery never changes money');
same('account_automatic',$automatic->fresh()->policy['contact_readmissions'][0]['source']??null,'System recovery is identified in the audit');
same(0,$state->writes,'Automatic contact recovery never sends directly');
$nativeSuccessful=true;
