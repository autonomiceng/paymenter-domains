<?php
// Staff release decision rows; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/Support/release-decision-rows.php';
use Paymenter\Extensions\Servers\Domains\Services\BalanceRetries;
use Paymenter\Extensions\Servers\Domains\Staff\Release\{ReleaseDecision,ReleaseFacts,ReleaseProof,ReleaseRefusal};

// Every hold code on a fresh paid, unsent request. staff-release-reasons-test.php proves the native facts equal these rows.
$codes=releaseRowCodes();expect(count($codes)>150,'The hold vocabulary is read');
$offered=0;
foreach(['register','transfer','renew'] as $kind){
    foreach([true,false] as $enabled){
        $allowed=releaseOffered($kind,$enabled);
        foreach($codes as $code){
            $decision=ReleaseDecision::decide(releaseFixtureFacts($kind,$enabled,$code));
            $label=$kind.':'.($enabled?'on':'off').':'.($code??'null');
            if(!in_array($code,$allowed,true)){
                // A balance retry whose purchase proof changed says so; every other hold needs its original recovery.
                same(in_array($code,BalanceRetries::REVIEW_CODES,true)?ReleaseRefusal::PurchaseProof:ReleaseRefusal::OriginalRecovery,$decision,'Refused hold: '.$label);continue;
            }
            $offered++;
            same($kind!=='renew'&&$code==='registrar_price_increased'?ReleaseProof::Unsent:ReleaseProof::Unattempted,$decision,'Released hold and its proof: '.$label);
        }
    }
}
same(115,$offered,'Every offered row was checked');

// Paths the fresh request cannot reach. Each changes one fact of a held Enom TEST registration.
$row=static fn(string $code,array $facts=[],string $action='register'):ReleaseFacts=>new ReleaseFacts(...['state'=>'held','action'=>$action,'code'=>$code,
    'registrar'=>'enom','environment'=>'test',...$facts]);
$rows=[
    'queued request'=>[$row('staff_review_required',['state'=>'queued']),ReleaseRefusal::OriginalRecovery],
    'privacy purchase'=>[$row('staff_review_required',[],'privacy_purchase'),ReleaseRefusal::OriginalRecovery],
    'changed balance retry proof'=>[$row('balance_retry_funding_changed',['balanceReview'=>'balance_retry_funding_changed','balanceRetry'=>true]),ReleaseRefusal::PurchaseProof],
    'matching balance retry proof'=>[$row('balance_retry_funding_changed',['balanceRetry'=>true]),ReleaseProof::BalanceRetry],
    'balance retry after a shortfall'=>[$row('registrar_balance_insufficient',['balanceRetry'=>true,'testCallRetry'=>true]),ReleaseProof::BalanceRetry],
    'blocked contact step'=>[$row('processing',['contactBlocked'=>'Contact creation is off.','contactRetry'=>true]),ReleaseRefusal::ContactStep],
    'unsent contact step with purchases off'=>[$row('write_rate_limited',['contactRetry'=>true]),ReleaseProof::Unsent],
    'call cap with its unsent claim'=>[$row('enom_test_call_cap',['deployment'=>true,'testCallRetry'=>true]),ReleaseProof::Unsent],
    'call cap with purchases off'=>[$row('enom_test_call_cap',['testCallRetry'=>true]),ReleaseRefusal::OriginalRecovery],
    'call cap on a live account'=>[$row('enom_test_call_cap',['deployment'=>true,'testCallRetry'=>true,'environment'=>'live']),ReleaseProof::Unsent],
    'call cap without an account'=>[$row('enom_test_call_cap',['deployment'=>true,'testCallRetry'=>true,'environment'=>null]),ReleaseRefusal::OriginalRecovery],
    'unsent claim on an ordinary hold with purchases off'=>[$row('write_disabled',['testCallRetry'=>true]),ReleaseRefusal::OriginalRecovery],
    'unsent claim on an ordinary hold without an account'=>[$row('write_disabled',['deployment'=>true,'testCallRetry'=>true,'environment'=>null]),ReleaseRefusal::OriginalRecovery],
    'NameSilo account guard retry'=>[$row('namesilo_account_guard',['registrar'=>'namesilo','accountGuardRetry'=>true]),ReleaseProof::Unsent],
    'NameSilo preflight hold'=>[$row('pre_send_interrupted',['registrar'=>'namesilo','deployment'=>true]),ReleaseRefusal::OriginalRecovery],
    'renewal call cap without an account'=>[$row(ReleaseDecision::RENEWAL_CAP,['renewalCap'=>true,'environment'=>null],'renew'),ReleaseRefusal::OriginalRecovery],
    'renewal call cap at NameSilo'=>[$row(ReleaseDecision::RENEWAL_CAP,['renewalCap'=>true,'registrar'=>'namesilo'],'renew'),ReleaseRefusal::OriginalRecovery],
];
foreach($rows as $name=>[$facts,$expected]){same($expected,ReleaseDecision::decide($facts),'Release row: '.$name);}
same('Contact creation is off.',ReleaseRefusal::ContactStep->message($rows['blocked contact step'][0]),'A blocked contact step says why');
expect(ReleaseDecision::renewalAdmission('renew','renewal_term_refused')&&!ReleaseDecision::renewalAdmission('register','renewal_term_refused')
    &&!ReleaseDecision::renewalAdmission('renew','renewal_authority_invalid'),'Only renewal admission checks count as an admission hold');
echo "Staff release decision rows passed.\n";
