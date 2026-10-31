<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Render the real owner DTO/partial and staff projection.
declare(strict_types=1);
require __DIR__.'/Support/transfer-completion-namesilo-fixture.php';
require __DIR__.'/Support/owner-pages.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
$op=$prepare('shared-message');app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$clock);
foreach([
    ['uncertain','reconciliation_required','transfer_pending','pending','keep checking'],
    ['held','transfer_needs_action','transfer_needs_action','needs_action','old registrar'],
    ['uncertain','reconciliation_required','transfer_deadline_passed','pending','support'],
] as [$stateName,$safe,$reason,$status,$words]){
    $op->update(['state'=>$stateName,'safe_code'=>$safe,'policy'=>[...$op->policy,'reconciliation'=>['reason'=>$reason,'transfer_status'=>$status,'staff_alert'=>$reason!=='transfer_pending','final_check'=>$reason==='transfer_deadline_passed'?'done':null]]]);
    $dto=$owners->present($op->domain->fresh(),false);$staffNotice=RequestFacts::notice($op,['unreadable'=>false]);
    $html=view('domains::partials.transfer-code',['domain'=>$dto,'keys'=>[]])->render();
    $text=html_entity_decode(strip_tags($html),ENT_QUOTES|ENT_HTML5,'UTF-8');
    expect(str_contains(strtolower($text),$words),'Customer receives the actual transfer reason and next step: '.$reason);
    expect(str_contains($text,$staffNotice['title'])&&str_contains($text,$staffNotice['body']),'Customer and staff use the same transfer message: '.$reason);
    if($reason==='transfer_deadline_passed'){expect(!str_contains($text,'don’t need to do anything')&&!str_contains($text,'nothing you need to do'),'Expired transfer never tells its owner no action is needed');}
}
echo "Customer and staff share pending, old-registrar and deadline instructions.\n";$nativeSuccessful=true;
