<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Delivery history uses accepted evidence.
declare(strict_types=1);
require __DIR__.'/departure-email-test.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DepartedHistory,DomainHistory};

$nativeSuccessful=false;
$held=$d->fresh();$entry=DepartedHistory::entry($held);
$confirmed=(new DateTimeImmutable($held->renewal_policy['departure']['confirmed_at']))->getTimestamp();
$accepted=$confirmed+300;
DB::table('domains_reminder_deliveries')->where('delivery_key',$entry['email']['delivery_key'])->update(['accepted_at'=>$accepted]);
$fact=DepartedHistory::email($held,$entry);
same($accepted,$fact['at']??null,'The delivery fact uses the later acceptance');
$rows=array_values(array_filter(DomainHistory::entries($held),fn($row)=>is_array($row)&&($row['event']??null)==='Email sent'));
same($accepted,$rows[0]['time']?->getTimestamp(),'Email history records acceptance rather than confirmation');
same($confirmed,(new DateTimeImmutable($held->renewal_policy['departure']['confirmed_at']))->getTimestamp(),'The saved confirmation time stays frozen');
echo "Email history records the actual acceptance time independently of confirmation.\n";
$nativeSuccessful=true;
