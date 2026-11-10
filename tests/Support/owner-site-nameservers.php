<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Native form, confirmation and synthetic registrar transport.
declare(strict_types=1);
use App\Models\Setting;
use Illuminate\Support\Facades\{Cache,DB};
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault;
$siteServers=['ns1.site.example.org','ns2.site.example.org'];
config(['domains.site_dns_default.nameservers'=>['ns1.deploy.example.net','ns2.deploy.example.net']]);
$siteProfile=DB::transaction(fn()=>WatchProfile::create(['name'=>'Saved site DNS','nameservers'=>$siteServers,'website_endpoints'=>[],
    'email_endpoints'=>[],'enabled'=>true,'edited_by'=>$user->id,'reason'=>'Synthetic saved site default']));
$siteSetting=Setting::whereNull('settingable_type')->whereNull('settingable_id')->where('key',SiteDnsDefault::KEY);
$siteSetting->delete();Setting::create(['key'=>SiteDnsDefault::KEY,'type'=>'string','encrypted'=>false,'value'=>(string)$siteProfile->id]);
$nsSection=$owners->present($d->fresh())->management['nameservers'];
same($siteServers,$nsSection['defaultServers'],'Existing-domain management uses the saved site default for either registrar');
same(true,$nsSection['defaults'],'Either registrar offers a usable site default');
$registrar=$free?'NameSilo':'Enom';
// An existing domain whose first registrar read finished: its rows are not Checking, so the editor opens.
$readAt=$clock->now()->format(DATE_ATOM);
\Paymenter\Extensions\Servers\Domains\Models\Domain::withoutEvents(static fn()=>$d->fresh()->update(['observation'=>[...($d->fresh()->observation??[]),'records'=>['identity'=>\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::identity($d->fresh()),'at'=>$readAt,'last_complete_at'=>$readAt,'sequence'=>0,'families'=>[]]]]));
$editor=storefrontDom((new \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($r,$d->id)->render());
same(1,$editor->query('//dialog[@id="edit-nameservers"]/form/p[normalize-space()="Registrar: '.$registrar.'."]')->length,'Editor identifies the bound registrar once');
same(0,$editor->query('//input[@name="use_default"]|//button[@name="use_default"]')->length,'Customer UI has no registrar-reset field');
$before=$d->operations()->count();
$filled=$controller->manage($post('nameservers',['fill_site_default'=>'1','confirmed'=>'0']),$d->id,'nameservers');
same(422,$filled->getStatusCode(),'No-script fill retains the existing confirmation');
same($before,$d->operations()->count(),'Fill alone queues no change');
$confirm=storefrontDom($filled->getContent());
$shown=$confirm->query('//textarea[@name="nameservers"]')->item(0)->textContent;
same(implode("\n",$siteServers),$shown,'Confirmation retains the explicit nameservers filled without script');
same($siteServers,array_map(fn($n)=>$n->textContent,iterator_to_array($confirm->query('//dialog[@id="confirm-nameservers"]//div[dt="New"]//span'))),'Confirmation names the exact replacement hosts');
same(1,$confirm->query('//dialog[@id="confirm-nameservers"]/p[normalize-space()="Registrar: '.$registrar.'."]')->length,'Confirmation identifies the bound registrar once');
// Changing the saved profile while confirmation is open cannot change the submitted list.
$siteProfile->nameservers=['ns1.changed.example.org','ns2.changed.example.org'];$siteProfile->saveQuietly();
$wireState=$free?$ms:$state;
$nsSave=static function(array $hosts,array $extra=[])use($post,$controller,$d,$management,$driver,$free,$wireState):void {
    $controller->manage($post('nameservers',['nameservers'=>implode("\n",$hosts),...$extra]),$d->id,'nameservers');
    $saved=$d->operations()->where('action','nameservers')->latest('id')->firstOrFail();
    same(['nameservers'=>$hosts],$saved->payload,'The UI freezes ordinary explicit nameservers, never a registrar reset');
    Cache::forget('domains-write-slot:'.$d->account_fingerprint);
    same('succeeded',$management->run($saved->id,$driver)->state,'Explicit nameservers pass the native engine and synthetic readback');
    $calls=$wireState->calls;$command=$free?'changeNameServers':'ModifyNS';
    $writes=array_values(array_filter($calls,fn($call)=>$call[0]===$command));$args=end($writes)[1];
    same($hosts,array_values(array_filter($args,fn($key)=>preg_match($free?'/^ns[0-9]+$/D':'/^NS[0-9]+$/D',$key),ARRAY_FILTER_USE_KEY)),'Registrar receives exactly the confirmed nameservers');
    expect(!isset($args['UseDNS']),'The UI never sends UseDNS=default');
};
$nsSave(explode("\n",$shown));
// JavaScript filling the same list and a subsequent manual edit use the ordinary field too.
$nsSave($siteServers);
$manualServers=['ns1.custom.example.net','ns2.custom.example.net'];$nsSave($manualServers,['use_default'=>'1']);
// Missing or disabled saved defaults cannot fall back to deployment or registrar DNS.
foreach(['missing','disabled','invalid'] as $unavailable){
    if($unavailable==='missing'){$siteSetting->delete();}
    else{Setting::updateOrCreate(['key'=>SiteDnsDefault::KEY,'settingable_type'=>null,'settingable_id'=>null],['type'=>'string','encrypted'=>false,'value'=>(string)$siteProfile->id]);
        $siteProfile->enabled=$unavailable!=='disabled';$siteProfile->nameservers=$unavailable==='invalid'?['only.example.org']:$siteServers;$siteProfile->saveQuietly();}
    $section=$owners->present($d->fresh())->management['nameservers'];same([],$section['defaultServers'],'Unusable default exposes no substitute: '.$unavailable);
    same(false,$section['defaults'],'Unusable default offers no fill action: '.$unavailable);
    $page=(new \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($r,$d->id)->render();
    // A saved set the registrar refuses has its own recovery; retrying cannot fix it.
    $hint=$unavailable==='invalid'?'The site default nameservers can’t be used with '.$registrar.'. Enter your own nameservers, or contact support.':'Site default nameservers are not available. Enter your own nameservers.';
    same(1,substr_count(html_entity_decode($page,ENT_QUOTES),$hint),'Unusable default explains custom editing once: '.$unavailable);
    expect(!str_contains($page,'try again later'),'Unusable default is not called a lookup failure: '.$unavailable);
    $count=$d->operations()->count();$controller->manage($post('nameservers',['fill_site_default'=>'1']),$d->id,'nameservers');same($count,$d->operations()->count(),'Missing default queues no reset');
    $nsSave($manualServers);
}
// Thirteen hosts suit NameSilo; Enom takes at most twelve, so the same saved set has the invalid-default recovery there.
$thirteen=array_map(fn($n)=>'ns'.$n.'.site.example.org',range(1,13));
$siteProfile->enabled=true;$siteProfile->nameservers=$thirteen;$siteProfile->saveQuietly();
$section=$owners->present($d->fresh())->management['nameservers'];
same($free?$thirteen:[],$section['defaultServers'],'A 13-host saved default follows the registrar limit');
same($free?null:'The site default nameservers can’t be used with Enom. Enter your own nameservers, or contact support.',$section['defaultError'],'Only Enom refuses the 13-host saved default');
expect(isset($section['actions']['nameservers']),'Custom nameserver editing stays available with a 13-host saved default');
$siteProfile->enabled=true;$siteProfile->nameservers=$siteServers;$siteProfile->saveQuietly();
Setting::updateOrCreate(['key'=>SiteDnsDefault::KEY,'settingable_type'=>null,'settingable_id'=>null],['type'=>'string','encrypted'=>false,'value'=>(string)$siteProfile->id]);
$session->forget(['errors','_old_input','domains_failed_form']);
