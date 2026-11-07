<?php
// Synthetic Enom unresolved outcomes; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$enomModes = ['unknown', 'pending', 'processing', 'queued', '1300', 'missingid', 'invalidid', 'missingexpiry', 'invalidexpiry'];
$runEnomRegistrationTail = false;
require __DIR__.'/Support/enom-registration-suite.php';

$nativeSuccessful=false;
// Error evidence survives the real driver, sealed finish and later recovery without authorizing a replay or credit.
foreach (['refusal','contradictory','lost','private'] as $case) {
    $calls=[];
    $wire=static function($origin,$command,$parameters,$timeout) use (&$calls,$case): string {
        $calls[]=$command;
        if ($case==='lost') { throw new RuntimeException('PRIVATE-TRANSPORT'); }
        $text=$case==='private'?'PRIVATE-ACCOUNT PRIVATE-TOKEN owner@example.test':'Error 545 - Name server does not exist';
        return '<interface-response><Command>Purchase</Command><ErrCount>1</ErrCount><Done>true</Done><RRPCode>545</RRPCode>'
            .'<errors><Err1>'.$text.'</Err1></errors><debug>PRIVATE-TOKEN</debug>'
            .($case==='contradictory'?'<OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate>':'').'</interface-response>';
    };
    $makeEngine($binding,$wire); $op=$new('saved-'.$case.'.example.com'); $money=nativeFinancialSnapshot($op);
    $result=$runRegistration($op);
    same(['uncertain','sealed',$case==='lost'?null:'unknown','possibly_sent'],[$result->state,$result->attempt['seal'],$result->attempt['execution']['disposition']??null,
        $result->attempt['send_state']],'Purchase error/lost reply retains uncertain sent authority: '.$case);
    $expected=$case==='lost'?null:['code'=>'545','text'=>$case==='private'?null:'Error 545 - Name server does not exist'];
    same($expected,$result->attempt['evidence']['registrarReply']??null,'Bounded original Purchase reply retained: '.$case);
    $saved=$result->fresh()->attempt;
    expect(!str_contains($result->getRawOriginal('attempt'),'Name server'),'Reply is retained inside encrypted attempt storage');
    expect(!str_contains(json_encode($saved),'PRIVATE-')&&!str_contains(json_encode($saved),'owner@example.test'),'No echoed private payload retained');
    expect(!array_key_exists('registration',$saved)&&!array_key_exists('terminal',$saved),'Error is neither completion nor terminal proof');
    $engine->execute($result->id);
    same(['Purchase'],$calls,'No ancillary send or replay for Purchase error: '.$case);
    same($saved,$result->fresh()->attempt,'Original reply is immutable on later execution');
    same($money,nativeFinancialSnapshot($result),'Error/lost reply does not credit or alter invoice history');
    if(array_key_exists('evidence',$saved)){commerceReject(fn()=>$result->update(['attempt'=>array_replace($saved,['evidence'=>[]])]));}
}

$nativeSuccessful=true;
