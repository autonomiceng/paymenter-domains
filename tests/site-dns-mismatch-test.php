<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,DomainSummaries,WatchNoticeDelivery,WatchNoticeTemplates};
use Paymenter\Extensions\Servers\Domains\Presentation\{WatchPage,StatusPill};
use Paymenter\Extensions\Servers\Domains\Models\WatchNotice;
// The native owner/source, stamped observations, planner, DTO and Blade renderers must agree on the same history.
config(['domains.site_dns_default.nameservers'=>['ns1.example.test','ns2.example.test'],'settings.company_name'=>'Example Hosting']);
$f=noticeFixture();extract($f);require dirname(__DIR__).'/Domains/routes/owned.php';app('router')->getRoutes()->refreshNameLookups();
DB::table('domains_watch_names')->where('id',$id)->update(['ns_profile_id'=>null,'expected_dns'=>null,'profile_revision'=>0]);
$dns->enroll($id,$uid,$now);noticeRegistry($f,$now,100);$reader=new DomainSummaries();
$check=static function(int $at,bool $matched)use($planner,$id,$uid,$owner,$reader):void {
    $planner->plan('watch',$id,$uid,$at);$n=WatchNotice::where('kind','ns_changed')->where('state','open')->sole();
    $s=$reader->one($owner,'watch',$id,$at);expect($s!==null,'Inherited watch has a native portal summary');
    $data=WatchNoticeDelivery::data($n);$app=WatchNoticeDelivery::prepare($n,'app',$owner);$mail=WatchNoticeDelivery::prepare($n,'mail',$owner);
    expect($app!==null && $mail!==null,'Explicit opt-in prepares both native channels');
    foreach([$app['body'],view('domains::mail-text',$mail['mail']->content()->with)->render()]as$body){
        expect(!preg_match('/ {2}(?:does not use|no longer uses)/',$body),'Nameserver notice wording has one separating space in both channels');
    }
    $notice=WatchPage::notice($s,[$data+['kind'=>'ns_changed']],['ns_changed']);$history=WatchPage::history([$data+['kind'=>'ns_changed']]);
    $copy=$notice['text'].' '.$history[0]['label'].' '.view('domains::partials.summary',['summary'=>$s])->render().' '.(StatusPill::summary($s)['label']??'').' '.$app['title'].' '.$app['body'].' '.$mail['mail']->envelope()->subject.' '.$mail['mail']->render().' '.view('domains::mail-text',$mail['mail']->content()->with)->render();
    if($matched){expect(str_contains($notice['text'],'stopped using Example Hosting nameservers')&&str_contains($app['body'],'no longer uses Example Hosting nameservers')&&str_contains($app['title'],'Nameservers changed'),'Earlier match permits change wording in portal and notices');}
    else{expect(str_contains($notice['text'],'does not use Example Hosting nameservers')&&str_contains($app['body'],'does not use Example Hosting nameservers'),'Never-matched portal and opt-in notice explain current mismatch');
        foreach(['stopped using','no longer uses','Nameservers changed','Change them back']as$claim){expect(!str_contains($copy,$claim),'No unproved change claim: '.$claim);}}
};
noticeDns($f,$now);noticeDns($f,$now+900);$check($now+902,false);
// A real earlier match, then another confirmed mismatch, permits historical wording.
noticeDns($f,$now+1800,'platform');$planner->plan('watch',$id,$uid,$now+1802);
noticeDns($f,$now+2700,'platform');$planner->plan('watch',$id,$uid,$now+2702);
noticeDns($f,$now+3600);$planner->plan('watch',$id,$uid,$now+3602);noticeDns($f,$now+4500);$check($now+4502,true);
// Upgrades recognize the immediately previous defaults, while retaining staff customizations.
$template=\App\Models\NotificationTemplate::where('key','domains_watch_ns_changed')->sole();
$template->update(['subject'=>'Nameservers changed for {{ $domain }}','in_app_body'=>'Custom staff copy']);WatchNoticeTemplates::ensure();
expect($template->fresh()->subject!=='Nameservers changed for {{ $domain }}','Legacy default subject upgrades');same('Custom staff copy',$template->fresh()->in_app_body,'Custom staff copy survives');
// The exact previous default upgrades in both channels while a staff-edited subject stays intact.
$definitions=WatchNoticeTemplates::definitions()['domains_watch_ns_changed'];
$old='As of {{ $date }}, {{ $domain }} @if($previously_matched)no longer uses @else does not use @endif{{ $nameservers }}. If you didn’t expect this, contact support.';
$template->update(['subject'=>'Custom watch subject','in_app_body'=>$old,'body'=>str_replace($definitions['in_app_body'],$old,$definitions['body'])]);
WatchNoticeTemplates::ensure();$template->refresh();same('Custom watch subject',$template->subject,'Spacing upgrade preserves staff subject customizations');
foreach([false=>'does not use',true=>'no longer uses']as$matched=>$phrase){
    $data=['date'=>'Jan 1, 2030','domain'=>'example.com','nameservers'=>'Example Hosting nameservers','domain_url'=>'https://example.com/domains','previously_matched'=>(bool)$matched];
    $expected='As of Jan 1, 2030, example.com '.$phrase.' Example Hosting nameservers. If you didn’t expect this, contact support.';
    same($expected,\Illuminate\Support\Facades\Blade::render($template->in_app_body,$data),'Stored default in-app wording upgrades with exact spacing');
    expect(str_contains(\Illuminate\Support\Facades\Blade::render($template->body,$data),$expected),'Stored default mail wording upgrades with exact spacing');
}
$nativeSuccessful=true;echo "Inherited DNS mismatch and proven change wording passed.\n";
