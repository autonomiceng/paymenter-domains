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

// Sanitized configured-nameserver refusal captured by the separate TEST probe on 2026-11-07.
$purchaseRefusal='<interface-response><RRPCode>541</RRPCode><RRPText>Data management policy violation; host ns2.provider.example is subordinate to provider.example, which does not exist yet</RRPText>'
    .'<Contacts><RegistrantEmailAddress>PRIVATE-CONTACT</RegistrantEmailAddress></Contacts><Command>PURCHASE</Command><APIType>API.NET</APIType><Language>eng</Language>'
    .'<ErrCount>1</ErrCount><errors><Err1>Validation error. Failed to create order.</Err1></errors><ResponseCount>1</ResponseCount>'
    .'<responses><response><ResponseNumber>309242</ResponseNumber><ResponseString>Validation error; failed to add; order(s)</ResponseString></response></responses>'
    .'<MinPeriod>1</MinPeriod><MaxPeriod>10</MaxPeriod><Server>TEST</Server><Site>Enom</Site><IsLockable>True</IsLockable><IsRealTimeTLD>True</IsRealTimeTLD>'
    .'<TimeDifference>+00.00</TimeDifference><ExecTime>0.01</ExecTime><Done>true</Done><TrackingKey>synthetic-tracking</TrackingKey>'
    .'<RequestDateTime>11/7/2026 6:42:36 AM</RequestDateTime><debug/></interface-response>';

// Each response starts from the same untouched paid fixture. Only test setup restores its raw operation row.
$negative=$new('saved-response.example.com'); $virgin=$negative->getRawOriginal();
$refunds=new \Paymenter\Extensions\Servers\Domains\Services\Refunds($clock);
// Error evidence survives the real driver, sealed finish and later recovery without authorizing a replay or credit.
$diagnostics=[];
$badCounts=['missing_errors_count','empty_errors_count','absent_count','incomplete','missing_response','zero_count','invalid_count','padded_errcount','nested_contact_control','contact_list','contact_attributes','contact_text'];
foreach ([...$badCounts,'refusal','contradictory','lost'] as $case) {
    $calls=[];
    $wire=static function($origin,$command,$parameters,$timeout) use (&$calls,$case,$purchaseRefusal): string {
        $calls[]=$command;
        if ($case==='lost') { throw new RuntimeException('PRIVATE-TRANSPORT'); }
        if(in_array($case,['missing_errors_count','empty_errors_count'],true)){
            $reply=preg_replace('#<errors>.*?</errors>#s',$case==='empty_errors_count'?'<errors/>':'',$purchaseRefusal);
            return str_replace('<ErrCount>1</ErrCount>','<ErrCount>'.($case==='empty_errors_count'?'3':'2').'</ErrCount>',$reply);
        }
        if($case==='padded_errcount'){return str_replace('<ErrCount>1</ErrCount>','<ErrCount>01</ErrCount>',$purchaseRefusal);}
        if(str_starts_with($case,'contact_')||$case==='nested_contact_control'){return preg_replace('#<Contacts>.*?</Contacts>#s',match($case){'nested_contact_control'=>'<Contacts><ErrCount>5</ErrCount></Contacts>','contact_list'=>'<Contacts><RegistrantEmailAddress>PRIVATE-ONE</RegistrantEmailAddress><RegistrantEmailAddress>PRIVATE-TWO</RegistrantEmailAddress></Contacts>','contact_attributes'=>'<Contacts id="1"><RegistrantEmailAddress>PRIVATE-CONTACT</RegistrantEmailAddress></Contacts>',default=>'<Contacts>PRIVATE-CONTACT</Contacts>'},$purchaseRefusal);}
        if($case==='absent_count'){return str_replace('<ResponseCount>1</ResponseCount>','',$purchaseRefusal);}
        if($case==='missing_response'){return preg_replace('#<responses>.*?</responses>#s','',$purchaseRefusal);}
        if(in_array($case,['incomplete','zero_count','invalid_count'],true)){
            return str_replace('<ResponseCount>1</ResponseCount>','<ResponseCount>'.match($case){'incomplete'=>'2','zero_count'=>'0',default=>'invalid'}.'</ResponseCount>',$purchaseRefusal);
        }
        if ($case==='contradictory') { return str_replace('</interface-response>','<OrderID>1234</OrderID></interface-response>',$purchaseRefusal); }
        $text='Error 545 - Name server does not exist';
        return '<interface-response><Command>Purchase</Command><ErrCount>1</ErrCount><Done>true</Done><RRPCode>545</RRPCode>'
            .'<errors><Err1>'.$text.'</Err1></errors><debug>PRIVATE-TOKEN</debug>'
            .'</interface-response>';
    };
    $makeEngine($binding,$wire); $op=$negative->fresh(); $money=nativeFinancialSnapshot($op);
    $result=$runRegistration($op);
    if(in_array($case,$badCounts,true)){
        $return=$refunds->creditRejected($result->id);
        echo $case.': '.json_encode(['state'=>$result->state,'return'=>$return,'service'=>App\Models\Service::findOrFail($result->service_id)->status])."\n";
        same(null,$return,'An incomplete refusal envelope cannot authorize a native payment return');
    }
    same(['uncertain','sealed',$case==='lost'?null:'unknown','possibly_sent'],[$result->state,$result->attempt['seal'],$result->attempt['execution']['disposition']??null,
        $result->attempt['send_state']],'Purchase error/lost reply retains uncertain sent authority: '.$case);
    $expected=match($case){'lost'=>null,
        'missing_errors_count','empty_errors_count'=>['code'=>'541','text'=>'Validation error; failed to add; order(s)'],'padded_errcount','nested_contact_control','contact_list','contact_attributes','contact_text','contradictory','absent_count','incomplete','missing_response','zero_count','invalid_count'=>['code'=>'541','text'=>'Validation error. Failed to create order.'],
        default=>['code'=>'545','text'=>'Error 545 - Name server does not exist']};
    $diagnostics[] = [$case, $expected, $result->attempt['evidence']['registrarReply'] ?? null];
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
require __DIR__.'/Support/owner-pages.php';
$page=$owners->present($result->domain->fresh());
same('credit',$page->refundTo,'Rejected customer page uses the actual return capability');
$expires=\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::expires($page,null,null);
same(['Not registered','Any refund goes to your account credit.'],[$expires['value']??null,$expires['note']??null],'Rejected registration has a final expiry value and honest return destination');
same(null,\Paymenter\Extensions\Servers\Domains\Presentation\DomainPage::nextInvoice($page,null,null),'Rejected registration predicts no future renewal invoice');
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

// Check the terminal outcome before diagnostics, so the old behavior fails on its unresolved paid order.
foreach ($diagnostics as [$case, $expected, $reply]) {
    same($expected, $reply, 'Bounded original Purchase reply retained: '.$case);
}

$nativeSuccessful=true;

echo "Purchase evidence and native refusal returns passed.\n";
