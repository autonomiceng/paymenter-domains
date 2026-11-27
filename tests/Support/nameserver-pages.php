<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic registrar exchanges only.
declare(strict_types=1);
require __DIR__.'/management.php';
require __DIR__.'/owner-pages.php';
require __DIR__.'/staff-panel.php';
use Illuminate\Support\Facades\{Auth, Cache, DB};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, Staff};
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
use Paymenter\Extensions\Servers\Domains\Operations\Authority\{OperationAttempts, OperationWriter, Transition};
staffPanel();
$viewer=staffUser([Staff::VIEW]);
$pages=static function($d, ?array $expected, string $why, bool $formsExpected=true) use($owners,$user,$clock): void {
    $summary=(new \Paymenter\Extensions\Servers\Domains\Services\DomainSummaries)->one($user,(int)$d->id,$clock->now()->getTimestamp());
    expect($summary!==null,$why.' customer summary exists');
    same($expected,($summary->nameservers['state']??null)==='observed'?$summary->nameservers['nameservers']:null,$why.' customer summary');
    $forms=$owners->present($d->fresh())->management;
    same($formsExpected,isset($forms['nameservers']['actions']['nameservers']),$why.' customer form availability');
    if (isset($forms['nameservers'])) { same($expected,$forms['nameservers']['current'],$why.' customer control'); }
    same($expected,DomainFacts::for($d->fresh())['Nameservers']['value'],$why.' staff');
};
$overflowFlag=static function($holding,string $section,string $fact,?bool $expected,string $why)use($owners):void {
    same($expected,\Paymenter\Extensions\Servers\Domains\Services\OwnedDomains::storedFlag($holding->fresh(),$section,$fact),$why.' shared customer flag');
    $label=$section==='lock'?'Transfer lock':'WHOIS privacy';
    same($expected===null||($label==='WHOIS privacy'&&!$expected)?null:($expected?'On':'Off'),DomainFacts::for($holding->fresh())[$label]['value']??null,$why.' staff flag');
    same([],array_filter($owners->present($holding->fresh())->management,fn($section)=>$section['actions']),$why.' keeps every action blocked');
};
\Paymenter\Extensions\Servers\Domains\Services\ObservationSchema::install();
$budget=new \Paymenter\Extensions\Servers\Domains\Services\AccountReadBudget($clock);
$observer=new \Paymenter\Extensions\Servers\Domains\Services\DomainObservation($readBindings,$clock,$budget);

// The projection boundary is history size, not repeated request admission. Retire one real
// unsent request, then recreate the retired rows and their operation-level proof.
$history=static function($d,$driver,string $why)use($submit,$management,$user,$state,$clock):void {
    $calls=$state->calls;$idle=$submit($d,$driver,'lock',['locked'=>false]);
    $retired=$management->retire($user,$idle->id);same('rejected',$retired->state,$why);
    $proof = OperationAttempts::evidence((int) $retired->id, null, 'terminal');
    same('management_retired', $proof['outcome'] ?? null, 'Native retirement records terminal proof');
    $writer = OperationWriter::at($clock->now());
    $native=nativeFinancialSnapshot($retired);$seq=0;
    while($d->operations()->count()<=100){
        $copy=$retired->replicate();$copy->operation_key='manage:'.$d->id.':'.hash('sha256','page-history-'.$d->id.'-'.++$seq);
        DB::transaction(static function () use ($copy, $writer, $proof): void {
            $copy->save();
            same(Transition::Applied, $writer->recordTerminal((int) $copy->id, $proof['outcome'], $proof['evidence']),
                'Each history row has its own retirement proof');
        });
        same('rejected',$copy->fresh()->state,$why);same($retired->attempt,$copy->fresh()->attempt,'History row preserves real retirement evidence');
    }
    same(101,$d->operations()->count(),'History exceeds the exact reconstruction bound');
    $after=nativeFinancialSnapshot($retired);
    same($native['operation_count']+$seq,$after['operation_count'],'Only the intended history rows are added');
    unset($native['operation_count'],$after['operation_count']);
    same($native,$after,'History setup preserves native finances');
    same($calls,$state->calls,'History setup performs no registrar work');
};
