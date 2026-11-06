<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
use Paymenter\Extensions\Servers\Domains\Catalogue\{ExpansionFacts,OfferState};
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment,Support};
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DsFacts;
$now=new DateTimeImmutable('2026-11-04T12:00:00+00:00');
$proof=['source'=>'synthetic_registry','version'=>'rule-1','reviewed_at'=>'2026-11-01T00:00:00+00:00','valid_until'=>'2026-12-01T00:00:00+00:00'];
$term=$proof+['terms'=>[2],'price_unit'=>'term_total','rule'=>'exact-term-1'];
$family=$proof+['terms'=>[1,2],'price_unit'=>'term_total','rule'=>'renew-1','states'=>['Active'],
    'expiry_precision'=>'date','horizon_years'=>10,'safe_boundary'=>'utc-two-days','restoration_disabled'=>true];
foreach (['enom','namesilo'] as $registrar) {
    $binding=new AccountBinding($registrar,1,Environment::Test,'synthetic-account');
    $facts=['terms'=>['register'=>[1,2],'renew'=>[1],'transfer'=>[1]],'registryFields'=>[],
        'renewalSafe'=>true,'requiresNameservers'=>false,'autoRenewOnly'=>false,'source'=>'synthetic',
        'observedAt'=>$proof['reviewed_at'],'freshUntil'=>$proof['valid_until']];
    same([1],OfferState::facts(json_encode($facts))->terms['renew'],'Old annual facts survive for '.$registrar);
    same(null,ExpansionFacts::term($facts,'renew',2,$now),'Old rows grant no extra renewal term');
    same(null,ExpansionFacts::family($facts,'grace',$now),'Old rows grant no grace');
    $facts['term_evidence']=['format'=>1,'entries'=>['register'=>[2=>$term],'renew'=>[2=>$term]]];
    $facts['renewal_families']=['format'=>1,'entries'=>['ordinary'=>$family,'grace'=>$family]];
    same($term,OfferState::termEvidence(json_encode($facts),'register',2,$now),'Reviewed exact registration evidence is readable');
    same($term,ExpansionFacts::term($facts,'renew',2,$now),'Reviewed exact renewal evidence is readable');
    $annual=$facts;$annual['term_evidence']['entries']['renew'][2]['price_unit']='annual';
    same('annual',ExpansionFacts::term($annual,'renew',2,$now)['price_unit']??null,'Existing annual cost unit remains readable');
    $annual['term_evidence']['entries']['renew'][2]['price_unit']='per_year';
    same(null,ExpansionFacts::term($annual,'renew',2,$now),'Unknown price unit cannot expand terms');
    same($family,OfferState::familyEvidence(json_encode($facts),'ordinary',$now),'Reviewed ordinary family is readable');
    same($family,ExpansionFacts::family($facts,'grace',$now),'Reviewed grace family is readable');
    same([1],OfferState::facts(json_encode($facts))->terms['renew'],'Readers alone do not expand evaluator terms');
    foreach ([null,[],['format'=>2,'entries'=>['renew'=>[2=>$term]]],['format'=>'1','entries'=>[]]] as $bad) {
        $copy=$facts;$copy['term_evidence']=$bad;$copy['renewal_families']=$bad;
        same(null,ExpansionFacts::term($copy,'renew',2,$now),'Malformed/versioned term expansion closed');
        same(null,ExpansionFacts::family($copy,'grace',$now),'Malformed/versioned family expansion closed');
    }
    foreach (['version'=>null,'price_unit'=>'guess','terms'=>[1],'valid_until'=>$now->format(DATE_ATOM),
        'reviewed_at'=>'2026-02-30T00:00:00+00:00','rule'=>''] as $key=>$bad) {
        $copy=$facts;$copy['term_evidence']['entries']['renew'][2][$key]=$bad;
        same(null,ExpansionFacts::term($copy,'renew',2,$now),'Invalid exact-term proof closes '.$key);
    }
    foreach (['states'=>[],'expiry_precision'=>'unknown','horizon_years'=>0,'safe_boundary'=>'','restoration_disabled'=>false] as $key=>$bad) {
        $copy=$facts;$copy['renewal_families']['entries']['grace'][$key]=$bad;
        same(null,ExpansionFacts::family($copy,'grace',$now),'Invalid grace safety closes '.$key);
    }
    $driver=$registrar==='enom'?new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($binding,static fn()=>throw new RuntimeException('No wire')):
        new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Management($binding,static fn()=>throw new RuntimeException('No wire'));
    expect($driver instanceof \Paymenter\Extensions\Servers\Domains\Contracts\ObservationReader,'Management provides the compatible read port');
    $contract=$driver->observationCapabilities();
    same($registrar.'-records-1',$contract['revision'],'Provider names its read contract');
    same('supported',$contract['families']['glue'],'Current glue discovery retained');
    same($registrar==='enom'?'supported':'unsupported',$contract['families']['ds'],'Read discovery is independent of DS mutation');
    same(null,$driver->dsMutationPolicy('example.com'),'No new DS mutation policy installed');
    if($registrar==='namesilo'){same([],$driver->dsRegistryFacts($now),'Default DS deployment facts stay empty');same(Support::Unsupported,$driver->capabilities()->get('ds')->support,'NameSilo DS remains Off');}
}
same(null,OfferState::termEvidence('{bad json','renew',2,$now),'Malformed JSON refuses expansion');
same([],DsFacts::read([], $now),'Default deployment map grants nothing');
$ds=$proof+['suffix'=>'com','input'=>'ds','algorithms'=>[13],'digest_types'=>[2],
    'combinations'=>[['algorithm'=>13,'digest_type'=>2]],'expiry_policy'=>'refuse'];
$map=['format'=>1,'entries'=>['com'=>$ds]];
same(['com'=>$ds],DsFacts::read($map,$now),'Versioned reviewed DS facts can be read without enabling writes');
foreach ([['format'=>9,'entries'=>['com'=>$ds]],['com'=>$ds],['format'=>1,'entries'=>'bad']] as $bad) {
    same([],DsFacts::read($bad,$now),'Unknown/legacy DS map grants nothing');
}
foreach (['input'=>'unknown','algorithms'=>['13'],'combinations'=>[], 'expiry_policy'=>'ignore','suffix'=>'net',
    'valid_until'=>$now->format(DATE_ATOM),'reviewed_at'=>'2027-01-01T00:00:00+00:00'] as $key=>$bad) {
    $copy=$map;$copy['entries']['com'][$key]=$bad;same([],DsFacts::read($copy,$now),'Invalid DS facts close '.$key);
}
echo "Demo C contracts passed.\n";
