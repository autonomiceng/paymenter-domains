<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Renders the client domain page from synthetic DTOs, dark and light; no public I/O.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController;
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainSummary, OwnedDomain};
use Paymenter\Extensions\Servers\Domains\Services\{ContactDetails, DomainSummaryStrings};
storefrontViews();$out=$argv[1]??'';expect(is_dir($out),'Output directory required');
\Carbon\CarbonImmutable::setTestNow('2026-10-23 12:00:00');\Carbon\Carbon::setTestNow('2026-10-23 12:00:00');
app('view')->share('keys',array_fill_keys(['setup','recovery','code','renew','auto-renew',...OwnerActionController::ACTIONS],str_repeat('a',64)));
app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$m=[];
foreach(['nameservers'=>'Nameservers','dns'=>'DNS records','glue'=>'Glue records','ds'=>'DNSSEC','lock'=>'Transfer lock','privacy'=>'WHOIS privacy','contacts'=>'Contacts','auth_code_email'=>'Auth code']as$cap=>$label){
    $m[$cap]=['label'=>$label,'url'=>'/domains/1/manage/'.$cap,'actions'=>[$cap=>'/domains/1/manage/'.$cap]];
}
$m['nameservers']+=['max'=>12,'defaults'=>true,'defaultServers'=>['ns1.beco.example','ns2.beco.example'],'current'=>['ns1.beco.example','ns2.beco.example']];
$m['dns']+=['records'=>[['name'=>'@','type'=>'A','address'=>'192.0.2.1'],['name'=>'www','type'=>'CNAME','address'=>'@'],['name'=>'@','type'=>'MX','address'=>'mail.example.com']],'digest'=>str_repeat('b',64),'recordCheckHeld'=>false];
$m['glue']+=['records'=>[],'recordCheckHeld'=>false];$m['glue']['actions']=['glue_add'=>'/domains/1/manage/glue_add'];
$m['ds']+=['records'=>[],'recordCheckHeld'=>false];$m['ds']['actions']=['ds_add'=>'/domains/1/manage/ds_add'];
$m['lock']['state']=true;
$m['privacy']+=['free'=>true,'price'=>null,'state'=>true];$m['privacy']['actions']=['privacy_enable'=>'/domains/1/manage/privacy_enable','privacy_disable'=>'/domains/1/manage/privacy_disable'];
$m['contacts']+=['staged'=>false,'limits'=>ContactDetails::limits('enom')];
$m['auth_code_email']['recipient']='registrant';
$history=[['number'=>'INV-1042','status'=>'Paid','url'=>'/invoices/1042','date'=>'2027-01-04']];
$progress=[['label'=>'Nameservers','state'=>'Done','date'=>'2026-10-20'],['label'=>'Registration','state'=>'Done','date'=>'2026-03-01']];
$domain=static fn(string $name,array $more=[])=>new OwnedDomain(...[...['name'=>$name,'status'=>'Active','tone'=>'ok','expiry'=>'2027-03-01','renewal'=>'On','renewalPrice'=>'$14.99',
    'actions'=>['show'=>'/domains/1'],'progress'=>$progress,'setup'=>[],'transfer'=>[],'management'=>$m,'testEnvironment'=>false,'history'=>$history,'owner'=>['name'=>'Jane Doe','email'=>'jane@example.com'],
    'renewNow'=>['url'=>'/domains/1/renew','terms'=>[1,2,3],'order_by'=>'2027-02-28'],'autoRenew'=>['url'=>'/domains/1/auto-renew','enabled'=>true]],...$more]);
$summary=static fn(OwnedDomain $d,array $more=[])=>new DomainSummary($d->name,null,'managed','domain.registration.platform',[],null,null,[],DomainSummaryStrings::all(),...[
    'expiry'=>$d->expiry,'expiryPrecision'=>'date','expirySource'=>null,'freshness'=>[],'renewalDueAt'=>'2027-02-01','renewalPrice'=>$d->renewalPrice,'relatedServices'=>[],'hostnames'=>[],
    'urls'=>['detail'=>'/domains/1','support'=>'/tickets/create'],...$more]);
$paused=$m;$paused['dns']['records']=null;$paused['ds']['records']=null;
$cases=[
    'active'=>[$domain('beco-example.com'),['daysLeft'=>129]],
    'auto-renew-off'=>[$domain('renewal-off.example',['renewal'=>'Off','renewalOff'=>'owner','autoRenew'=>['url'=>'/domains/1/auto-renew','enabled'=>false],'expiry'=>'2027-01-20']),['daysLeft'=>89,'renewalOff'=>true,'renewalDueAt'=>null]],
    'demo-sync-paused'=>[$domain('demo-paused.example',['demo'=>true,'syncPaused'=>true,'management'=>$paused]),['daysLeft'=>129,'demo'=>true]],
    'test-mode'=>[$domain('test-expiring.example',['testEnvironment'=>true,'expiry'=>'2026-11-02']),['daysLeft'=>10,'testEnvironment'=>true,'renewalDueAt'=>null,'renewalInvoiceMissing'=>true,'renewalIssueAt'=>'2026-10-03']],
    'missing-expiry'=>[$domain('missing-expiry.example',['expiry'=>null,'renewNow'=>[],'owner'=>null]),['renewalDueAt'=>null]],
    'pending'=>[$domain('pending-transfer.example',['status'=>'Pending transfer','tone'=>'info','renewal'=>'','expiry'=>null,'management'=>[],'renewNow'=>[],'autoRenew'=>[],'progress'=>[['label'=>'Transfer','state'=>'In progress']],'history'=>[],
        'actions'=>['show'=>'/domains/1','code'=>'/domains/1/transfer-code'],'transfer'=>['open'=>true,'received'=>false,'phase'=>'editing']]),['expiryWaits'=>'transfer','renewalDueAt'=>null]],
];
// Paymenter default theme colours (themes/default/views/layouts/colors.blade.php), so both modes read as they do in the client area.
$colors=':root{--color-primary:229 100% 64%;--color-neutral:220 25% 85%;--color-base:0 0% 0%;--color-muted:0 0% 40%;--color-success:142 71% 45%;--color-error:0 75% 60%;--color-warning:25 95% 53%;--color-info:210 100% 60%;--color-background:100 100% 100%;--color-background-secondary:0 0% 97%}'
    .'.dark{--color-neutral:0 0% 17%;--color-base:100 100% 100%;--color-muted:220 0% 53%;--color-background:240 18% 9%;--color-background-secondary:240 13% 11%}';
foreach($cases as $case=>[$d,$more]){
    $html=view('domains::show',['domain'=>$d,'summary'=>$summary($d,$more)])->render();
    foreach(['enom','namesilo']as$registrar){expect(!str_contains(strtolower(strip_tags(preg_replace('~<(style|script)\b[^>]*>.*?</\1>~si','',$html))),$registrar),'No registrar names: '.$case);}
    expect(!str_contains($html,'—'),'No em dashes: '.$case);
    foreach(['dark','light']as$mode){
        $doc=str_replace(['<html>','<head>','</head>'],['<html lang="en" class="'.$mode.'">','<head><meta charset="utf-8">','<style>'.$colors.'body{margin:0;padding:32px;background:hsl(var(--color-background));font:15px/1.5 system-ui}</style></head>'],$html);
        writeRenderArtifact($out.'/domain-page-'.$case.'-'.$mode.'.html',$doc);
    }
}
$nativeSuccessful=true;echo "Client domain page rendered dark and light.\n";
