<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic registrar exchanges only.
declare(strict_types=1);
require __DIR__.'/Support/management.php';
require __DIR__.'/Support/owner-pages.php';
use Paymenter\Extensions\Servers\Domains\Services\{DomainSummaries, RenewalCycles};
// An early renewal that closed unpaid leaves the scheduled invoice to come, so the customer sees its planned date, not Missing.
[$d,,$original]=$holding('closed-renewal-placeholder.com');$d=$d->fresh();
$op=$original->replicate();
$op->forceFill(['action'=>'renew','state'=>'rejected','safe_code'=>'renewal_cancelled_unpaid','operation_key'=>hash('sha256','closed-renewal'),'base_expires_at'=>$d->provider_expires_at,'policy'=>['unpaid_retirement'=>['reason'=>'Early renewal not paid']]])->save();
expect(RenewalCycles::closedEarly($op->fresh()),'The renewal closed early');
$summary=(new DomainSummaries)->one($user,'managed',(int)$d->id,$clock->now()->getTimestamp());
same([false,true],[$summary->renewalInvoiceMissing,$summary->renewalDueAt!==null],'Next invoice shows the planned date');
$nativeSuccessful=true;echo "Closed renewal summary passed\n";
