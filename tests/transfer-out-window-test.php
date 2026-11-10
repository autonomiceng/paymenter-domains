<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The registry blocks transfers out for 60 days, so Transfer lock and Auth code say when transfers open.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
use Paymenter\Extensions\Servers\Domains\Services\OwnedDomains;
storefrontViews();
$now=new DateTimeImmutable('2026-11-10T07:00:00+00:00');
$op=static fn(string $action,string $state,?string $at)=>(new Operation)->forceFill(['action'=>$action,'state'=>$state,'completed_at'=>$at]);
$line=OwnedDomains::transferOutFrom($op('register','succeeded','2026-11-10T06:52:00+00:00'),$now);
same('Transfers to another registrar open on Jan 9, 2027, 60 days after registration.',$line,'A new registration says when transfers out open');
same('Transfers to another registrar open on Dec 10, 2026, 60 days after the transfer.',OwnedDomains::transferOutFrom($op('transfer','succeeded','2026-10-11T00:00:00+00:00'),$now),'A transfer in counts from its completion');
same(null,OwnedDomains::transferOutFrom($op('register','succeeded','2026-09-01T00:00:00+00:00'),$now),'Past the 60 days there is no line');
same(null,OwnedDomains::transferOutFrom($op('register','queued',null),$now),'An unfinished registration has no date yet');
same(null,OwnedDomains::transferOutFrom(null,$now),'A domain we did not register or transfer has no line');
$page=new OwnedDomain(name:'transfer-window-placeholder.com',status:'Active',tone:'ok',expiry:'2027-11-10',renewal:'On',renewalPrice:'$10.99',actions:['show'=>'/domains/90'],progress:[],setup:[],transfer:[],
    management:['lock'=>['label'=>'Transfer lock','state'=>true,'url'=>'/domains/90/lock','actions'=>['lock'=>'/domains/90/lock'],'transferFrom'=>$line],
        'auth_code_email'=>['label'=>'Auth code','recipient'=>'registrant email','sender'=>'Enom','url'=>'/domains/90/auth-code','actions'=>['auth_code_email'=>'/domains/90/auth-code'],'transferFrom'=>$line]],testEnvironment:true);
$dom=storefrontDom(view('domains::show',['domain'=>$page,'summary'=>null,'keys'=>['lock'=>'fixture-key','auth_code_email'=>'fixture-key']])->render());
foreach (['lock','auth_code_email'] as $row) {
    same(1,$dom->query('//li[@id="'.$row.'"]//*[contains(@class,"domains-sub")][normalize-space(.)="'.$line.'"]')->length,'The transfer date sits next to '.$row);
}
$nativeSuccessful=true;echo "Transfer-out window passed.\n";
