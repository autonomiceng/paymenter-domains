<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Paymenter\Extensions\Servers\Domains\Data\RenewalCode;
use Paymenter\Extensions\Servers\Domains\Services\BalanceRetries;
use Paymenter\Extensions\Servers\Domains\Staff\Release\ReleaseFacts;

/** Every hold code staff can see: the Labels::reason vocabulary, every RenewalCode, no code and a code added later. */
function releaseRowCodes(): array
{
    $tokens=token_get_all((string)file_get_contents(dirname(__DIR__,2).'/Domains/Admin/Support/Labels.php'));
    $start=null;
    foreach($tokens as $i=>$token){
        if(is_array($token)&&$token[0]===T_FUNCTION){
            $j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE){$j++;}
            if(is_array($tokens[$j])&&$tokens[$j][1]==='reason'){$start=$j;break;}
        }
    }
    expect($start!==null,'Labels::reason is found');
    $codes=['future_hold',null,'name_unavailable','premium_name','order_not_supported'];$depth=0;$opened=false;
    for($i=$start;isset($tokens[$i]);$i++){
        $token=$tokens[$i];
        if($token==='{'||is_array($token)&&in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true)){$depth++;$opened=true;}
        if($token==='}'){$depth--;if($opened&&$depth===0){break;}}
        if(!$opened||!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING||!preg_match("/^'([a-z][a-z0-9_]+)'$/D",$token[1],$m)){continue;}
        $j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE){$j++;}
        if(is_array($tokens[$j]??null)&&$tokens[$j][0]===T_DOUBLE_ARROW){$codes[]=$m[1];}
    }
    return array_values(array_unique([...$codes,...array_map(fn($c)=>$c->value,RenewalCode::cases())],SORT_REGULAR));
}

/** Balance holds that automatic recovery owns on a fresh paid purchase. */
const RELEASE_AUTOMATIC_BALANCE=['usd_balance_required','enom_test_transfer_usd_balance_required','registrar_balance_insufficient','registrar_price_missing','registrar_read_failed'];

/** The facts a fresh paid, unsent request on an Enom TEST account has while held with $code. */
function releaseFixtureFacts(string $kind,bool $enabled,?string $code): ReleaseFacts
{
    $acquisition=$kind!=='renew';
    if($code==='contact_details_required'){return new ReleaseFacts('held',$kind,$code);}
    if($acquisition&&in_array($code,RELEASE_AUTOMATIC_BALANCE,true)){return new ReleaseFacts('held',$kind,$code,balancePending:true);}
    $review=in_array($code,BalanceRetries::REVIEW_CODES,true)?($acquisition?'pre_send_interrupted':'balance_retry_authority_changed'):null;
    return new ReleaseFacts('held',$kind,$code,'enom','test',deployment:$acquisition&&$enabled,
        renewalCap:$kind==='renew'&&$enabled&&$code==='enom_test_renewal_call_cap',balanceReview:$review,
        // A held price increase keeps its unsent claim.
        testCallRetry:$review===null&&$acquisition&&$code==='registrar_price_increased');
}

/** Codes staff may release on that request, by kind and by whether this site has Enom TEST purchases on. */
function releaseOffered(string $kind,bool $enabled): array
{
    $any=['staff_review_required','funding','renewal_invoice_unpaid','renewal_spend_paused','processing','renewal_authority_invalid','namesilo_account_guard',
        'transfer_setup_review_required','write_disabled','configuration_unavailable','write_acceptance_required','renewal_funding_or_authority','balance','registrar_price_increased'];
    if($kind==='renew'){
        return [...$any,'provider_membership_unconfirmed','provider_auto_renew_unconfirmed','renewal_state_unconfirmed','renewal_term_refused','renewal_cost_unconfirmed',
            'registrar_price_missing','registrar_read_failed','registrar_balance_insufficient',...($enabled?['enom_test_renewal_call_cap']:[])];
    }
    // A price increase without the purchase type on keeps its hold: the unsent claim needs the deployment.
    return $enabled?[...$any,'pre_send_interrupted','claim_authority_invalid','seal_authority_invalid','enom_test_transfer_setup_required','enom_test_setup_required',
        'write_rate_limited','nameservers_required','registry_details_unsupported']:array_values(array_diff($any,['registrar_price_increased']));
}
