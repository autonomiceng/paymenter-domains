<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Client pages show status-set colours through one tone mapping.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainStatus, StatusPill};

same(['success'=>'ok','info'=>'info','warning'=>'warn','danger'=>'bad','gray'=>'muted'],StatusPill::TONES,'One mapping from status colours to client tones');
foreach (DomainStatus::SET as $key=>[$label,$tone]) {
    expect(isset(StatusPill::TONES[$tone]),'Every domain status colour has a client tone: '.$label);
    expect(in_array($key,array_column(DomainStatus::STATES,0),true),'Every domain status is reachable: '.$label);
}
foreach (DomainStatus::STATES as $state=>[$key,$reason]) {
    $status=DomainStatus::for($state);
    same(['label'=>$status['label'],'tone'=>StatusPill::TONES[$status['tone']],'reason'=>$status['reason']],StatusPill::of($state),'Client pill keeps label and reason: '.$state);
}
same(['label'=>'Action required','tone'=>'warn','reason'=>'Invoice not paid'],StatusPill::of('awaiting_payment'),'Unpaid order');
same(['label'=>'Under review','tone'=>'warn','reason'=>'Transfer on hold'],StatusPill::of('held','Transfer on hold'),'A caller reason replaces the default');
same(['label'=>'Under review','tone'=>'warn','reason'=>'Status missing'],StatusPill::of('future_state'),'Unknown states say the status is missing');
