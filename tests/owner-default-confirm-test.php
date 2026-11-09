<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/nameserver-pages.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,OwnerSetup,TransferInputs,Refunds};
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController;
[$d]=$holding(placeholderComFixtureName('default-review-placeholder.com'));storefrontViews();
$profile=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::create(['name'=>'Current default','enabled'=>true,'nameservers'=>['ns3.example.net','ns4.example.net'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$viewer->id,'reason'=>'Synthetic DNS default']));
\App\Models\Setting::updateOrCreate(['key'=>SiteDnsDefault::KEY,'settingable_type'=>null,'settingable_id'=>null],['value'=>(string)$profile->id,'type'=>'string','encrypted'=>false]);
$controller=new OwnerActionController($owners,new OwnerSetup($clock,$ports),new TransferInputs($clock,app(Refunds::class)),$management,$ports);
$session=app('session')->driver();app('request')->setLaravelSession($session);$before=$d->operations()->count();
$data=['fill_site_default'=>'1','confirmed'=>'1','nameservers'=>"ns1.old.example.net\nns2.old.example.net"];
$r=\Illuminate\Http\Request::create('/domains/'.$d->id.'/manage/nameservers','POST',$data);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
$r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':nameservers'));
$response=$controller->manage($r,$d->id,'nameservers');
same($before,$d->operations()->count(),'Filling a fresh site default cannot accept an old confirmation');
same("ns3.example.net\nns4.example.net",$r->input('nameservers'),'The resolved current hosts are shown for review');
expect(!$r->request->has('confirmed'),'Fresh fill clears the incoming confirmation');
same(422,$response->getStatusCode(),'A fresh fill returns the actual confirmation page');
expect(str_contains($response->getContent(),'ns3.example.net')&&str_contains($response->getContent(),'confirm-nameservers'),'The native response renders the reviewed hosts');
// An unexpected database failure is not an unconfigured default and must not disable custom editing.
$failDefault=true;DB::connection()->beforeExecuting(static function($sql,$bindings)use(&$failDefault):void{
 if($failDefault&&str_starts_with(strtolower($sql),'select')&&in_array(SiteDnsDefault::KEY,$bindings,true)){throw new RuntimeException('Synthetic private lookup diagnostic');}
});
$dto=$owners->present($d->fresh());$section=$dto->management['nameservers'];
expect(($section['defaultError']??null)!==null,'Unexpected default lookup has distinct safe presentation');
expect(isset($section['actions']['nameservers'])&&$section['defaultServers']===[],'Custom nameserver editing survives failed default lookup without fallback');
expect(!str_contains(json_encode($section),'Synthetic private'),'Lookup diagnostic never enters customer facts');
$failDefault=false;
// Render that same DTO through the customer page: the dialog explains the lookup, not the missing-default hint.
$show=static function($dto=null)use($d,$user,$session,$owners){
 $request=\Illuminate\Http\Request::create('/domains/'.$d->id);$request->setUserResolver(fn()=>$user);$request->setLaravelSession($session);
 $view=(new \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($request,$d->id);
 return storefrontDom(($dto?$view->with(['domain'=>$dto]):$view)->render());
};
$dialogText=static fn($dom)=>trim(preg_replace('/\s+/u',' ',$dom->query('//dialog[@id="edit-nameservers"]')->item(0)?->textContent??''));
$editor=$show($dto);$text=$dialogText($editor);
expect(str_contains($text,'We could not read the site default nameservers. Enter your own nameservers, or try again later.'),'The nameserver dialog explains the lookup failure');
expect(!str_contains($text,\Paymenter\Extensions\Servers\Domains\Services\ManagementNameservers::MISSING),'A lookup failure is not shown as a missing default');
same(1,$editor->query('//dialog[@id="edit-nameservers"]//textarea[@name="nameservers"]')->length,'Custom nameserver editing stays in the dialog');
same(0,$editor->query('//dialog[@id="edit-nameservers"]//button[@name="fill_site_default"]')->length,'No site-default fill is offered without readable defaults');
// A saved default the registrar refuses cannot be fixed by trying again; custom editing remains.
$profile->nameservers=array_map(static fn($n)=>'ns'.$n.'.example.net',range(1,13));$profile->saveQuietly();
$section=$owners->present($d->fresh())->management['nameservers'];
same('The site default nameservers can’t be used with Enom. Enter your own nameservers, or contact support.',$section['defaultError'],'A 13-host default for Enom has its own recovery');
expect(isset($section['actions']['nameservers'])&&$section['defaultServers']===[],'An invalid default leaves custom editing without a fallback');
$text=$dialogText($editor=$show());
expect(str_contains($text,'can’t be used with Enom')&&!str_contains($text,'try again later'),'The dialog shows the invalid-default recovery');
same(1,$editor->query('//dialog[@id="edit-nameservers"]//textarea[@name="nameservers"]')->length,'Invalid default keeps the custom editor');
// The default changes after the customer reviewed it: a new fill shows the new hosts and needs a new confirmation.
$post=static function(array $data)use($d,$user,$session){
 $r=\Illuminate\Http\Request::create('/domains/'.$d->id.'/manage/nameservers','POST',$data);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
 $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':nameservers'));return $r;
};
$profile->nameservers=['ns5.example.net','ns6.example.net'];$profile->saveQuietly();
$r=$post(['fill_site_default'=>'1','confirmed'=>'1','nameservers'=>"ns3.example.net\nns4.example.net"]);$refill=$controller->manage($r,$d->id,'nameservers');
same(422,$refill->getStatusCode(),'An intervening default change returns to confirmation');
same("ns5.example.net\nns6.example.net",$r->input('nameservers'),'The new default hosts replace the reviewed ones');
same($before,$d->operations()->count(),'The old confirmation cannot accept the changed default');
// The second explicit confirmation sends exactly the hosts shown.
$controller->manage($post(['confirmed'=>'1','nameservers'=>"ns5.example.net\nns6.example.net"]),$d->id,'nameservers');
same($before+1,$d->operations()->count(),'The second explicit confirmation queues one change');
same(['nameservers'=>['ns5.example.net','ns6.example.net']],$d->operations()->where('action','nameservers')->latest('id')->firstOrFail()->payload,'The confirmed hosts are sent as ordinary nameservers');
$nativeSuccessful=true;echo "Current site default requires a fresh explicit confirmation.\n";
