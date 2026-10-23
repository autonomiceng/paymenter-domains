<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Offline real Blade/native mail shell capture.
declare(strict_types=1);
require __DIR__.'/Support/watch-notices.php';
require __DIR__.'/Support/storefront.php';
use App\Models\NotificationTemplate;
use Paymenter\Extensions\Servers\Domains\Models\WatchNotice;
use Paymenter\Extensions\Servers\Domains\Mail\WatchNotice as NoticeMail;
use Paymenter\Extensions\Servers\Domains\Services\WatchNoticeDelivery;
$f=noticeFixture();extract($f);storefrontViews();$out=$argv[1];$shell=getenv('WATCH_NOTICE_SHELL');
$base=['name'=>str_repeat('a',63).'.example','kind'=>'watch','id'=>1,'preferences'=>['account'=>['enabled'=>true,'revision'=>1],'target'=>['enabled'=>true,'revision'=>1]],
    'company'=>'Example Hosting','notices'=>[],'portal'=>[],'checked'=>1800000000,'statusDate'=>'January 15, 2027','expiryDate'=>'January 15, 2027','episodeDate'=>'January 15, 2027','key'=>str_repeat('a',64)];
$strings=[];
foreach(['expiring','expired','ns_changed','ns_restored','unknown','pending','paused','account-off','all-off','saved','save-error']as$state) {
    $data=$base;session()->forget(['watch_saved','errors']);view()->share('errors',new \Illuminate\Support\ViewErrorBag());
    if($state==='saved'){session()->put('watch_saved','Domain check preferences saved.');}
    if($state==='save-error'){view()->share('errors',(new \Illuminate\Support\ViewErrorBag())->put('default',new \Illuminate\Support\MessageBag(['preferences'=>'We could not save these preferences. Reload and try again.'])));}
    if(in_array($state,['unknown','pending'],true)) {$data['portal']=$state==='unknown'?['expiry_unavailable','dns_unavailable','registry_expiry_reached','ns_changed']:['ns_pending'];}
    elseif(in_array($state,['account-off','all-off'],true)){$data['preferences']['account']['enabled']=false;$data['preferences']['target']['enabled']=$state!=='all-off';}
    elseif($state==='paused'){$data['preferences']['target']['enabled']=false;}
    elseif(!in_array($state,['saved','save-error'],true)){$data['notices']=[['kind'=>$state,'date'=>'January 15, 2027','company'=>'Example Hosting']];}
    $html=view('domains::watch-notices',$data)->render();preg_match('#<body>(.*)</body>#s',$html,$body);$slot=$body[1]??$html;
    if($shell){$html=str_replace('@@SLOT@@',$slot,file_get_contents($shell));}
    file_put_contents($out.'/portal-'.$state.'.html',$html);$strings['portal.'.$state]=trim(preg_replace('/\s+/',' ',strip_tags($slot)));
}
foreach(['fallback'=>null,'named'=>'Example Hosting'] as $variant=>$company) {
config(['settings.company_name'=>$company]);
foreach(['expiring','expired','ns_changed','ns_restored']as$kind) {
    $n=new WatchNotice(['target_kind'=>'watch','watch_id'=>1,'kind'=>$kind,'data'=>['domain'=>$base['name'],'date'=>'2027-01-15']]);
    $template=NotificationTemplate::where('key','domains_watch_'.$kind)->sole();$mail=new NoticeMail($template,WatchNoticeDelivery::data($n));
    $html=$mail->render();$text=view('domains::mail-text',$mail->content()->with)->render();
    file_put_contents($out.'/email-'.$variant.'-'.$kind.'.html',$html);file_put_contents($out.'/email-'.$variant.'-'.$kind.'.txt',$text);
    $strings['email.'.$variant.'.'.$kind]=['subject'=>$mail->envelope()->subject,'text'=>$text];
}
}
file_put_contents($out.'/strings.json',json_encode($strings,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
$nativeSuccessful=true;echo "Real public notice Blade and all four native HTML/text emails rendered.\n";
