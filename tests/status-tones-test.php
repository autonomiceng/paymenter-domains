<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Client pages show status-set colours through one tone mapping.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Admin\Support\StatusMark;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainStatus, StatusPill};

same(['success'=>'ok','info'=>'info','warning'=>'warn','danger'=>'bad','gray'=>'muted'],StatusPill::TONES,'One mapping from status colours to client tones');
foreach (DomainStatus::SET as $key=>[$label,$tone]) {
    expect(isset(StatusPill::TONES[$tone]),'Every domain status colour has a client tone: '.$label);
    expect(in_array($key,array_column(DomainStatus::STATES,0),true),'Every domain status is reachable: '.$label);
}
foreach (DomainStatus::STATES as $state=>[$key,$reason]) {
    $status=DomainStatus::for($state);
    $tone=$key==='under_review'||isset(DomainStatus::STOPPED[$state])?'info':StatusPill::TONES[$status['tone']];
    same(['label'=>$status['label'],'tone'=>$tone,'reason'=>$status['reason']],StatusPill::of($state),'Client pill keeps label and reason: '.$state);
}
same(['label'=>'Awaiting payment','tone'=>'info','reason'=>'Invoice not paid'],StatusPill::of('awaiting_payment'),'Unpaid order');
same(['label'=>'Under review','tone'=>'info','reason'=>'Transfer on hold'],StatusPill::of('held','Transfer on hold'),'Customers cannot act on a review, so it stays blue');
same(['label'=>'Under review','tone'=>'info','reason'=>DomainStatus::ON_IT],StatusPill::of('future_state'),'A customer never reads Status missing, which has no step of their own');
same(['label'=>'Action required','tone'=>'warn','reason'=>'Auth code needed'],StatusPill::of('awaiting_code'),'Amber stays on what a customer must do');
same(['Under review','warning'],DomainStatus::SET['under_review'],'Staff keep the same words in amber');
foreach (['registration_held'=>'On hold','registration_unconfirmed'=>'Under review'] as $state=>$label) {
    same(['label'=>$label,'tone'=>'info','reason'=>DomainStatus::ON_IT],StatusPill::of($state),'A customer reads a stopped registration in blue, with no step of their own');
    same([$label,'warning'],[DomainStatus::for($state)['label'],DomainStatus::for($state)['tone']],'Staff read the same words in amber');
    same('pending_registration',DomainStatus::key($state),'A stopped registration stays in the Pending registration filter');
}
same(['success'=>'ok','gray'=>'off','info'=>'prog','warning'=>'warn','danger'=>'err'],StatusMark::TONES,'One mapping from status colours to staff marks');
same(['data-beco-tone'=>null,'data-beco-record'=>'domain-1'],StatusMark::attributes('pink','domain-1'),'An unknown colour gets no mark, so it keeps stock styling');
