<?php
// Synthetic Purchase refusal evidence and native payment returns; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$fixtureEnding='example.com';
require __DIR__.'/Support/registration-fixture.php';
require_once __DIR__.'/Support/reserved-fixtures.php';
$createOperation=$new;
$new=static function(string $name,bool $paid=true,bool $setup=true,string $action='register',int $term=1)use($createOperation){
    return $createOperation(reservedFixtureName($name),$paid,$setup,$action,$term);
};
$binding=$bindingFor(0);

// Each response starts from the same untouched paid fixture. Only test setup restores its raw operation row.
$negative=$new('saved-response.example.com'); $virgin=$negative->getRawOriginal();
$refunds=new \Paymenter\Extensions\Servers\Domains\Services\Refunds($clock);
// Error evidence survives the real driver, sealed finish and later recovery without authorizing a replay or credit.
foreach (['refusal','contradictory','lost'] as $case) {
    $calls=[];
    $wire=static function($origin,$command,$parameters,$timeout) use (&$calls,$case): string {
        $calls[]=$command;
        if ($case==='lost') { throw new RuntimeException('PRIVATE-TRANSPORT'); }
        $text='Error 545 - Name server does not exist';
        return '<interface-response><Command>Purchase</Command><ErrCount>1</ErrCount><Done>true</Done><RRPCode>545</RRPCode>'
            .'<errors><Err1>'.$text.'</Err1></errors><debug>PRIVATE-TOKEN</debug>'
            .($case==='contradictory'?'<OrderID>1234</OrderID><ExpirationDate>2027-10-18</ExpirationDate>':'').'</interface-response>';
    };
    $makeEngine($binding,$wire); $op=$negative->fresh(); $money=nativeFinancialSnapshot($op);
    $result=$runRegistration($op);
    same(['uncertain','sealed',$case==='lost'?null:'unknown','possibly_sent'],[$result->state,$result->attempt['seal'],$result->attempt['execution']['disposition']??null,
        $result->attempt['send_state']],'Purchase error/lost reply retains uncertain sent authority: '.$case);
    $expected=$case==='lost'?null:['code'=>'545','text'=>'Error 545 - Name server does not exist'];
    same($expected,$result->attempt['evidence']['registrarReply']??null,'Bounded original Purchase reply retained: '.$case);
    same(null,$refunds->creditRejected($result->id),'Ambiguous reply cannot enter the credit path');
    commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\RefundProof::requireReturnable($result,$result->domain));
    $saved=$result->fresh()->attempt;
    expect(!str_contains($result->getRawOriginal('attempt'),'Name server'),'Reply is retained inside encrypted attempt storage');
    expect(!str_contains(json_encode($saved),'PRIVATE-')&&!str_contains(json_encode($saved),'owner@example.test'),'No echoed private payload retained');
    expect(!array_key_exists('registration',$saved)&&!array_key_exists('terminal',$saved),'Error is neither completion nor terminal proof');
    $engine->execute($result->id);
    same(['Purchase'],$calls,'No ancillary send or replay for Purchase error: '.$case);
    same($saved,$result->fresh()->attempt,'Original reply is immutable on later execution');
    same($money,nativeFinancialSnapshot($result),'Error/lost reply does not credit or alter invoice history');
    if(array_key_exists('evidence',$saved)){commerceReject(fn()=>$result->update(['attempt'=>array_replace($saved,['evidence'=>[]])]));}
    Illuminate\Support\Facades\DB::table('domains_operations')->where('id',$op->id)->update($virgin);
    echo 'Uncertain Purchase retained: '.$case."\n";
}

// Sanitized configured-nameserver refusal captured by the separate TEST probe on 2026-11-07.
$purchaseRefusal='<interface-response><RRPCode>541</RRPCode><RRPText>Data management policy violation; host ns2.provider.example is subordinate to provider.example, which does not exist yet</RRPText>'
    .'<Contacts><RegistrantEmailAddress>PRIVATE-CONTACT</RegistrantEmailAddress></Contacts><Command>PURCHASE</Command><APIType>API.NET</APIType><Language>eng</Language>'
    .'<ErrCount>1</ErrCount><errors><Err1>Validation error. Failed to create order.</Err1></errors><ResponseCount>1</ResponseCount>'
    .'<responses><response><ResponseNumber>309242</ResponseNumber><ResponseString>Validation error; failed to add; order(s)</ResponseString></response></responses>'
    .'<MinPeriod>1</MinPeriod><MaxPeriod>10</MaxPeriod><Server>TEST</Server><Site>Enom</Site><IsLockable>True</IsLockable><IsRealTimeTLD>True</IsRealTimeTLD>'
    .'<TimeDifference>+00.00</TimeDifference><ExecTime>0.01</ExecTime><Done>true</Done><TrackingKey>synthetic-tracking</TrackingKey>'
    .'<RequestDateTime>11/7/2026 6:42:36 AM</RequestDateTime><debug/></interface-response>';
$calls=[];
$makeEngine($binding,static function($origin,$command,$parameters,$timeout)use(&$calls,$purchaseRefusal):string{$calls[]=$command;return $purchaseRefusal;});
$op=$new('confirmed-order-refusal.example.com'); $money=$nativeMoney();
$creditBefore=\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)App\Models\Credit::sum('amount'));
$result=$runRegistration($op);
echo "Order-creation refusal recorded.\n";
same('rejected',$result->state,'The exact failed-to-create-order envelope follows registration refusal recovery');
same(['rejected','sent'],[$result->attempt['execution']['disposition'],$result->attempt['execution']['send_state']],
    'A registrar refusal is sent provider proof, never definitely unsent');
same(['Purchase'],$calls,'Definite refusal performs one original Purchase and no ancillary send');
same($money,$nativeMoney(),'Refusal recording preserves native invoice and payment history');
commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\RefundProof::requireUnsent($result,$result->domain));
// Missing or foreign stored proof cannot authorize credit, even on a rejected-looking row.
foreach (['missing','claim_key','account_fingerprint','operation_key'] as $changed) {
    $foreign=clone $result; $attempt=$foreign->attempt;
    if($changed==='missing'){unset($attempt['evidence']['registrationRefusal']);}
    else{$attempt['evidence']['registrationRefusal']['record'][$changed]='foreign';}
    $foreign->attempt=$attempt;
    commerceReject(fn()=>\Paymenter\Extensions\Servers\Domains\Services\RefundProof::requireReturnable($foreign,$foreign->domain));
}
$refunds=new \Paymenter\Extensions\Servers\Domains\Services\Refunds($clock);
same(['amount'=>$result->amount,'currency'=>'USD'],$refunds->creditRejected($result->id),'Existing rejected-registration return handles the paid line');
same($creditBefore+\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($result->amount),
    \Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)App\Models\Credit::sum('amount')),'One exact native account credit');
$after=$nativeMoney(); foreach(['invoices','invoice_items','invoice_transactions'] as $table){same($money[$table],$after[$table],'Original invoice history stays unchanged: '.$table);}
same(null,$refunds->creditRejected($result->id),'Repeated recovery cannot credit twice');
$beforeCallback=$result->fresh()->attempt; $claims->finish($result);
same($beforeCallback,$result->fresh()->attempt,'A repeated completion callback cannot replace the refusal');
same(null,$refunds->creditRejected($result->id),'Callback retry cannot create another credit');
$engine->execute($result->id);same(['Purchase'],$calls,'Terminal refusal never replays the original Purchase');
same('registration_rejected',$result->domain->fresh()->status,'Existing credit recovery retains the rejected registration');
expect(\Paymenter\Extensions\Servers\Domains\Services\OperationTerminalProof::safe($result->fresh(),$result->domain->fresh()),'Terminal provider proof stays bound to its original holding');
\Paymenter\Extensions\Servers\Domains\Services\DomainReservations::releaseTerminal($result->domain->domain);
same(null,$result->domain->fresh()->reservation_name,'Only completed refusal and credit can release the name');

$nativeSuccessful=true;

echo "Purchase evidence and native refusal returns passed.\n";
