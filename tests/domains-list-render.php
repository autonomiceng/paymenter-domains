<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Renders the client Domains list from synthetic DTOs, dark and light; no public I/O.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';
use Illuminate\Pagination\LengthAwarePaginator;
use Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels;
use Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary;
use Paymenter\Extensions\Servers\Domains\Services\DomainSummaryStrings;
storefrontViews();$out=$argv[1]??'';expect(is_dir($out),'Output directory required');
$strings=DomainSummaryStrings::all();$unknown=DnsSignalLabels::keys(null,null,null);
$hosting=[['id'=>1,'label'=>'Starter hosting','url'=>'/services/1'],['id'=>2,'label'=>'Mail hosting','url'=>'/services/2']];
$dto=static fn(string $name,string $kind,array $more)=>new DomainSummary($name,null,$kind,'domain.registration.unknown',$unknown,null,null,[],$strings,...[
    'expiry'=>null,'expiryPrecision'=>null,'expirySource'=>null,'freshness'=>[],'renewalDueAt'=>null,'renewalPrice'=>null,'relatedServices'=>[],'hostnames'=>[],
    'urls'=>['detail'=>'/domains/'.$name,'support'=>'/tickets/create'],...$more]);
$rows=[
    $dto('real.example','managed',['expiry'=>'2027-03-01','daysLeft'=>129,'renewalPrice'=>'$14.99','statusState'=>'active']),
    $dto('renewal-off.example','managed',['expiry'=>'2027-01-20','daysLeft'=>89,'renewalPrice'=>'$14.99','renewalOff'=>true,'statusState'=>'active']),
    $dto('demo-held.example','managed',['demo'=>true,'expiryWaits'=>'transfer','renewalPrice'=>'$15.99','statusState'=>'held','statusReason'=>'Transfer on hold']),
    $dto('test-expiring.example','managed',['testEnvironment'=>true,'expiry'=>'2026-11-02','daysLeft'=>10,'renewalInvoiceMissing'=>true,'statusState'=>'active']),
    $dto('missing-expiry.example','managed',['renewalPrice'=>'$14.99','statusState'=>'active']),
    $dto('watch-expired.example','watch',['expiry'=>'2026-10-21','daysLeft'=>-2,'relatedServices'=>$hosting,'relatedOverflow'=>1,'statusState'=>'watch.expired','statusReason'=>'Renew at your registrar']),
    $dto('hosting-only.example','watch',['relatedServices'=>array_slice($hosting,0,1)]),
];
// Paymenter default theme colours (themes/default/views/layouts/colors.blade.php), so both modes read as they do in the client area.
$colors=':root{--color-primary:229 100% 64%;--color-neutral:220 25% 85%;--color-base:0 0% 0%;--color-muted:0 0% 40%;--color-success:142 71% 45%;--color-error:0 75% 60%;--color-warning:25 95% 53%;--color-info:210 100% 60%;--color-background:100 100% 100%;--color-background-secondary:0 0% 97%}'
    .'.dark{--color-neutral:0 0% 17%;--color-base:100 100% 100%;--color-muted:220 0% 53%;--color-background:240 18% 9%;--color-background-secondary:240 13% 11%}';
$html=view('domains::index',['domains'=>new LengthAwarePaginator($rows,count($rows),25),'filterCounts'=>[''=>count($rows),'active'=>4,'under_review'=>1,'expired'=>1]])->render();
foreach(['dark','light']as$mode){
    $doc=str_replace(['<html>','</head>'],['<html lang="en" class="'.$mode.'">','<style>'.$colors.'body{margin:0;padding:32px;background:hsl(var(--color-background));font:15px/1.5 system-ui}</style></head>'],$html);
    writeRenderArtifact($out.'/domains-list-'.$mode.'.html',$doc);
}
$nativeSuccessful=true;echo "Client Domains list rendered dark and light.\n";
