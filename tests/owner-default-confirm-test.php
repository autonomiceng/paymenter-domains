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
$nativeSuccessful=true;echo "Current site default requires a fresh explicit confirmation.\n";
