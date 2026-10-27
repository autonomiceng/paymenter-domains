<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real Blade with synthetic DTOs only.
declare(strict_types=1);
require_once __DIR__.'/reserved-fixtures.php';
require dirname(__DIR__).'/native-app.php';
require __DIR__.'/storefront.php';
require dirname(__DIR__,2).'/Domains/routes/web.php';
require dirname(__DIR__,2).'/Domains/routes/owned.php';
use Illuminate\Pagination\LengthAwarePaginator;
use Paymenter\Extensions\Servers\Domains\Presentation\OwnedDomain;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController;
storefrontViews();$shell=file_get_contents(getenv('PAYMENTER_TEST_FIXTURE').'/ui33-shell.html');
$keys=array_fill_keys(['setup','recovery','code',...OwnerActionController::ACTIONS],str_repeat('a',64));
app('view')->share('keys',$keys);
$make=static function(bool $free=false,bool $setup=false,bool $held=false):OwnedDomain {
    $actions=['show'=>'/domains/1','setupSave'=>'/domains/1/setup','contactsSave'=>'/domains/1/account-contacts','codeSave'=>'/domains/1/transfer-code'];
    if($setup){$actions+=['setup'=>'/domains/1/setup','code'=>'/domains/1/transfer-code'];}
    $management=[];
    foreach(['nameservers'=>'Nameservers','contacts'=>'Contacts','lock'=>'Transfer lock','privacy'=>'Domain privacy','auto_renew_off'=>'Renewal settings','auth_code_email'=>'Transfer code']as$cap=>$label){
        $management[$cap]=['label'=>$label,'url'=>'/domains/1/manage/'.$cap,'actions'=>[]];
    }
    $management['nameservers']['max']=$free?13:12; $management['nameservers']['defaults']=!$free; $management['contacts']['staged']=$free;$management['contacts']['limits']=\Paymenter\Extensions\Servers\Domains\Services\ContactDetails::limits($free?'namesilo':'enom');
    $management['privacy']+=['free'=>$free,'price'=>$free?null:'$8.00'];
    $management['auth_code_email']['recipient']=$free?'domain’s administrative contact':'domain owner';
    foreach($free?['contact_profile','contacts']:['contacts']as$a){$management['contacts']['actions'][$a]='/domains/1/manage/'.$a;}
    foreach(['privacy_enable','privacy_disable',...($free?[]:['privacy_purchase','privacy_renew'])]as$a){$management['privacy']['actions'][$a]='/domains/1/manage/'.$a;}
    return new OwnedDomain(placeholderComFixtureName((str_repeat('a',51).'-placeholder').'.com'),$setup?'Action required':'Active',$setup?'warn':'ok','2027-10-18','On','$14.00',$actions,
        [['label'=>'Contacts','state'=>'Under review'],['label'=>'Nameservers','state'=>'Under review']],
        ['contacts'=>$held,'required'=>$setup,'nameservers'=>true,'privacy'=>$free,'defaults'=>!$free,'maxNameservers'=>$free?13:12],
        ['open'=>!$held,'received'=>false,'paid'=>true,'deadline'=>'Nov 17, 2026, 12:00 UTC','held'=>$held,'unchecked'=>false,'phase'=>$held?'held':'editing'],$setup?[]:$management,false,statusReason:$setup?'Finish setup':null);
};
$enom=$make();$ns=$make(true);$setup=$make(true,true);$held=$make(false,true,true);
$summary=static fn(OwnedDomain $d)=>new \Paymenter\Extensions\Servers\Domains\Presentation\DomainSummary(name:$d->name,displayName:null,kind:'managed',registrationKey:'domain.registration.platform',dnsKeys:[],website:null,email:null,stateKeys:[],labels:\Paymenter\Extensions\Servers\Domains\Services\DomainSummaryStrings::all(),expiry:$d->expiry,expiryPrecision:'date',expirySource:'Confirmed registration',freshness:[],renewalDueAt:null,renewalPrice:$d->renewalPrice,relatedServices:[],hostnames:[],urls:['detail'=>$d->actions['show'],'preferences'=>null]);
$entry=storefrontData();$entry['results']=[[...$entry['results'][1],'sld'=>$entry['sld'],'domain'=>placeholderComFixtureName($entry['sld'].'.com'),'price'=>'$11.00']];
$pages=['transfer-entry'=>view('domains::transfer',$entry)->render(),'owned-index'=>view('domains::index',['domains'=>new LengthAwarePaginator(array_map($summary,[$enom,$ns,$held]),3,25),'filterCounts'=>[''=>3,'active'=>2,'attention'=>1]])->render(),
    'show-enom'=>view('domains::show',['domain'=>$enom])->render(),'show-namesilo'=>view('domains::show',['domain'=>$ns])->render(),
    'setup'=>view('domains::setup',['domain'=>$setup])->render(),'transfer-code'=>view('domains::code',['domain'=>$setup])->render()];
app('view')->share('errors',(new Illuminate\Support\ViewErrorBag)->put('default',new Illuminate\Support\MessageBag(['owner_action'=>'We couldn’t save this change. Check the details and try again. If it keeps happening, contact support.'])));
$pages['held-pending-errors']=view('domains::show',['domain'=>$held])->render();
app('view')->share('errors',(new Illuminate\Support\ViewErrorBag)->put('default',new Illuminate\Support\MessageBag(['transfer_code'=>'Enter a valid auth code.'])));
$pages['transfer-code-errors']=view('domains::code',['domain'=>$setup])->render();
app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$pages['held-setup']=view('domains::setup',['domain'=>$held])->render();
$pages['held-transfer']=view('domains::code',['domain'=>$held])->render();
app('view')->share('errors',(new Illuminate\Support\ViewErrorBag)->put('default',new Illuminate\Support\MessageBag(['contact.phone'=>'Check this contact detail.'])));
session()->flash('domains_failed_form','/domains/1/manage/contact_profile');
session()->flashInput(['role'=>'tech','contact'=>['first_name'=>'Example','last_name'=>'Customer','phone'=>'bad phone']]);
$pages['show-contact-errors']=view('domains::show',['domain'=>$ns])->render();
session()->forget(['domains_failed_form','_old_input']);
app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$pages['setup-checked']=view('domains::setup',['domain'=>$setup])->render();
$pages['setup-enom']=view('domains::setup',['domain'=>$make(false,true)])->render();
$pages['owned-empty']=view('domains::index',['domains'=>new LengthAwarePaginator([],0,25)])->render();
$pages['service']=view('domains::service',['domain'=>$enom])->render();
foreach (['saved'=>'editing','pending'=>'pending','review'=>'review','cutoff'=>'cutoff','payment'=>'payment'] as $name=>$phase) {
    $copy=new OwnedDomain(...[...get_object_vars($setup),'transfer'=>[...$setup->transfer,'received'=>true,'open'=>$phase==='editing','phase'=>$phase,'unchecked'=>in_array($phase,['pending','review'],true)]]);
    $pages['transfer-'.$name]=view('domains::code',['domain'=>$copy])->render();
}
$copy=new OwnedDomain(...[...get_object_vars($enom),'management'=>[],'changesUnavailable'=>true]);$pages['changes-unavailable']=view('domains::show',['domain'=>$copy])->render();
app('view')->share('errors',(new Illuminate\Support\ViewErrorBag)->put('default',new Illuminate\Support\MessageBag(['nameservers'=>'Check the nameservers.','privacy'=>'WHOIS privacy isn’t available for this domain.'])));
$pages['setup-errors']=view('domains::setup',['domain'=>$setup])->render();
$copy=new OwnedDomain(...[...get_object_vars($setup),'testEnvironment'=>true]);$pages['test-mode']=view('domains::setup',['domain'=>$copy])->render();
if (getenv('OWNER_RECORDS')==='1') { require __DIR__.'/owner-record-render.php'; }
foreach($pages as$name=>$html){preg_match('#<body>(.*)</body>#s',$html,$body);$content=$body[1]??$html;if(getenv('OWNER_RECORDS')!=='1'){$content=preg_replace('#<script\b[^>]*>.*?</script>#s','',$content);}writeRenderArtifact(getenv('OWNER_OUTPUT').'/'.$name.'.html',str_replace('@@SLOT@@',$content,$shell));}
$nativeSuccessful=true;echo "Real Blade owner pages rendered.\n";
