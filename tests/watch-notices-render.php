<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline real Blade/native mail shell capture.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
require __DIR__.'/Support/storefront.php';
use App\Models\NotificationTemplate;
use Paymenter\Extensions\Servers\Domains\Models\WatchNotice;
use Paymenter\Extensions\Servers\Domains\Mail\WatchNotice as NoticeMail;
use Paymenter\Extensions\Servers\Domains\Services\{WatchNoticeDelivery,DomainSummaries,DomainStates};
use Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary;
$f=noticeFixture();extract($f);require dirname(__DIR__).'/Domains/routes/web.php';require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();storefrontViews();$out=$argv[1];$shell=getenv('WATCH_NOTICE_SHELL');
$summary=(new DomainSummaries())->one($owner,'watch',$id);expect($summary!==null,'Fixture hosting domain has a summary');
$base=['name'=>str_repeat('a',63).'.example','kind'=>'watch','id'=>$id,'editable'=>true,'preferences'=>['account'=>['enabled'=>true,'revision'=>1],'target'=>['enabled'=>true,'revision'=>1]],
    'notices'=>[],'portal'=>[],'key'=>str_repeat('a',64)];
// One summary per state, as DomainSummaries would build it: the pill, dates and nameservers the page reads.
$with=static function(array $states,array $more=[])use($summary,$id){$status=DomainStates::watch($states,'2027-01-15',true);
    return new DomainSummary(...[...get_object_vars($summary),'name'=>str_repeat('a',63).'.example','stateKeys'=>$states,'statusState'=>$status[0]??null,'statusReason'=>$status[1]??null,'expiry'=>'2027-06-01','daysLeft'=>200,
        'nameservers'=>['nameservers'=>['ns1.beco.example','ns2.beco.example'],'at'=>'2027-01-10T08:00:00Z'],'noticeDates'=>['dns'=>1800000000],
        'relatedServices'=>[['label'=>'Web hosting','url'=>'/services/1']],'relatedOverflow'=>0,'urls'=>[...$summary->urls,'compareTransfer'=>'/domains/checks/watch/'.$id.'/transfer','support'=>'/tickets/create'],...$more]);};
$moved=['nameservers'=>['nameservers'=>['ns1.elsewhere.example','ns2.elsewhere.example'],'at'=>'2027-01-15T08:00:00Z']];
$cases=['active'=>[$with([]),[]],'expiring'=>[$with([],['expiry'=>'2026-11-01','daysLeft'=>9]),['notices'=>[['kind'=>'expiring','date'=>'November 1, 2026','company'=>'Example Hosting']]]],
    'expired'=>[$with(['watch.expired'],['expiry'=>'2026-10-01','daysLeft'=>-22,'urls'=>[...$summary->urls,'compareTransfer'=>null,'support'=>'/tickets/create']]),['notices'=>[['kind'=>'expired','date'=>'October 1, 2026','company'=>'Example Hosting']]]],
    'ns_changed'=>[$with(['watch.ns_changed'],$moved),['notices'=>[['kind'=>'ns_changed','date'=>'January 15, 2027','company'=>'Example Hosting']]]],
    'ns_restored'=>[$with([]),['notices'=>[['kind'=>'ns_changed','date'=>'January 15, 2027','company'=>'Example Hosting'],['kind'=>'ns_restored','date'=>'January 20, 2027','company'=>'Example Hosting']]]],
    'unknown'=>[$with([],['expiry'=>null,'daysLeft'=>null,'nameservers'=>null,'freshness'=>['delegation'=>['checkedAt'=>1790000000,'stale'=>true]]]),['portal'=>['expiry_unavailable','dns_unavailable']]],
    'pending'=>[$with(['watch.ns_pending'],$moved),['portal'=>['ns_pending']]],
    'alerts-off'=>[$with([]),['preferences'=>['account'=>['enabled'=>true,'revision'=>1],'target'=>['enabled'=>false,'revision'=>1]]]],
    'all-off'=>[$with([]),['preferences'=>['account'=>['enabled'=>false,'revision'=>1],'target'=>['enabled'=>true,'revision'=>1]]]],
    'monitoring-paused'=>[$with(['watch.paused'],['urls'=>[...$summary->urls,'compareTransfer'=>null]]),[]],'saved'=>[$with([]),[]],'save-error'=>[$with([]),[]]];
// Paymenter default theme colours (themes/default/views/layouts/colors.blade.php), so both modes read as they do in the client area.
$colors=':root{--color-primary:229 100% 64%;--color-neutral:220 25% 85%;--color-base:0 0% 0%;--color-muted:0 0% 40%;--color-success:142 71% 45%;--color-error:0 75% 60%;--color-warning:25 95% 53%;--color-info:210 100% 60%;--color-background:100 100% 100%;--color-background-secondary:0 0% 97%}'
    .'.dark{--color-neutral:0 0% 17%;--color-base:100 100% 100%;--color-muted:220 0% 53%;--color-background:240 18% 9%;--color-background-secondary:240 13% 11%}';
$strings=[];
foreach($cases as $state=>[$s,$more]) {
    $data=[...$base,'summary'=>$s,...$more];session()->forget(['watch_saved','errors']);view()->share('errors',new \Illuminate\Support\ViewErrorBag());
    if($state==='saved'){session()->put('watch_saved','Alerts turned on.');}
    if($state==='save-error'){view()->share('errors',(new \Illuminate\Support\ViewErrorBag())->put('default',new \Illuminate\Support\MessageBag(['preferences'=>'Couldn’t save. Try again.'])));}
    $html=view('domains::watch-notices',$data)->render();preg_match('#<body>(.*)</body>#s',$html,$body);$slot=$body[1]??$html;
    expect(!str_contains($html,'—'),'No em dashes: '.$state);
    if($shell){writeRenderArtifact($out.'/portal-'.$state.'.html',str_replace('@@SLOT@@',$slot,file_get_contents($shell)));}
    else{foreach(['dark','light']as$mode){writeRenderArtifact($out.'/portal-'.$state.'-'.$mode.'.html',str_replace(['<html>','<head>','</head>'],['<html lang="en" class="'.$mode.'">','<head><meta charset="utf-8">','<style>'.$colors.'body{margin:0;padding:32px;background:hsl(var(--color-background));font:15px/1.5 system-ui}</style></head>'],$html));}}
    $strings['portal.'.$state]=trim(preg_replace('/\s+/',' ',strip_tags($slot)));
}
foreach(['fallback'=>null,'named'=>'Example Hosting'] as $variant=>$company) {
config(['settings.company_name'=>$company]);
foreach(['expiring','expired','ns_changed','ns_restored']as$kind) {
    $n=new WatchNotice(['target_kind'=>'watch','watch_id'=>1,'kind'=>$kind,'data'=>['domain'=>str_repeat('a',63).'.example','date'=>'2027-01-15']]);
    $template=NotificationTemplate::where('key','domains_watch_'.$kind)->sole();$mail=new NoticeMail($template,WatchNoticeDelivery::data($n));
    $html=$mail->render();$text=view('domains::mail-text',$mail->content()->with)->render();
    writeRenderArtifact($out.'/email-'.$variant.'-'.$kind.'.html',$html);writeRenderArtifact($out.'/email-'.$variant.'-'.$kind.'.txt',$text);
    $strings['email.'.$variant.'.'.$kind]=['subject'=>$mail->envelope()->subject,'text'=>$text];
}
}
writeRenderArtifact($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$nativeSuccessful=true;echo "Real public notice Blade and all four native HTML/text emails rendered.\n";
