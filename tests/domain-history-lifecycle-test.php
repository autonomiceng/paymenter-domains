<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/staff-operation-fixture.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainHistory,StaffReviewHistory};
use Paymenter\Extensions\Servers\Domains\Presentation\State;
$op=$new(placeholderComFixtureName('history-lifecycle-placeholder.com'));
$sent=$clock->now()->modify('+1 day');$ended=$sent->modify('+5 minutes');
$op->forceFill(['state'=>'succeeded','started_at'=>$clock->now(),'completed_at'=>$ended,'attempt'=>['dispatch_attempted_at'=>$sent->format(DATE_ATOM),'execution'=>['send_state'=>'sent']]])->saveQuietly();
$events=[StaffReviewHistory::SENT,State::Completed->label()];
$rows=collect(DomainHistory::entries($op->domain))->filter(fn($row)=>is_array($row))->keyBy('event');
same([$sent->getTimestamp(),$ended->getTimestamp()],array_map(fn($event)=>($rows[$event]['time']??null)?->getTimestamp(),$events),'The domain activity retains the actual send and completion instants after retries');
$op->forceFill(['state'=>'held','completed_at'=>null,'attempt'=>['send_state'=>'definitely_unsent']])->saveQuietly();
$rows=collect(DomainHistory::entries($op->domain))->filter(fn($row)=>is_array($row))->keyBy('event');
expect(!isset($rows[$events[0]])&&!isset($rows[$events[1]]),'An unsent claim cannot gain send or completion events');
$nativeSuccessful=true;echo "Domain request lifecycle passed.\n";
