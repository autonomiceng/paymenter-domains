<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. The site default fill needs a fresh explicit confirmation for either registrar.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,OwnerSetup,TransferInputs,Refunds};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController;
storefrontViews();
$profile=DB::transaction(fn()=>\Paymenter\Extensions\Servers\Domains\Models\WatchProfile::create(['name'=>'Current default','enabled'=>true,'nameservers'=>['ns3.example.net','ns4.example.net'],'website_endpoints'=>[],'email_endpoints'=>[],'edited_by'=>$user->id,'reason'=>'Synthetic DNS default']));
\App\Models\Setting::updateOrCreate(['key'=>SiteDnsDefault::KEY,'settingable_type'=>null,'settingable_id'=>null],['value'=>(string)$profile->id,'type'=>'string','encrypted'=>false]);
$controller=new OwnerActionController($owners,new OwnerSetup($clock,$ports),new TransferInputs($clock,app(Refunds::class)),$management,$ports);
$session=app('session')->driver();app('request')->setLaravelSession($session);
$post=static function(array $data)use($d,$user,$session){
 $r=\Illuminate\Http\Request::create('/domains/'.$d->id.'/manage/nameservers','POST',$data);$r->setUserResolver(fn()=>$user);$r->setLaravelSession($session);
 $r->request->set('_request_key',FormKeys::issue($r,'owner:'.$d->id.':nameservers'));return $r;
};
$freshFill=static function()use($d,$post,$controller):void {
 $before=$d->operations()->count();
 $r=$post(['fill_site_default'=>'1','confirmed'=>'1','nameservers'=>"ns1.old.example.net\nns2.old.example.net"]);
 $response=$controller->manage($r,$d->id,'nameservers');
 same($before,$d->operations()->count(),'Filling a fresh site default cannot accept an old confirmation');
 same("ns3.example.net\nns4.example.net",$r->input('nameservers'),'The resolved current hosts are shown for review');
 expect(!$r->request->has('confirmed'),'Fresh fill clears the incoming confirmation');
 same(422,$response->getStatusCode(),'A fresh fill returns the actual confirmation page');
 expect(str_contains($response->getContent(),'ns3.example.net')&&str_contains($response->getContent(),'confirm-nameservers'),'The native response renders the reviewed hosts');
};
// The default changes after the customer reviewed it: a new fill shows the new hosts and needs a new confirmation.
$changedDefault=static function()use($d,$post,$controller,$profile):void {
 $before=$d->operations()->count();
 $profile->nameservers=['ns5.example.net','ns6.example.net'];$profile->saveQuietly();
 $r=$post(['fill_site_default'=>'1','confirmed'=>'1','nameservers'=>"ns3.example.net\nns4.example.net"]);$refill=$controller->manage($r,$d->id,'nameservers');
 same(422,$refill->getStatusCode(),'An intervening default change returns to confirmation');
 same("ns5.example.net\nns6.example.net",$r->input('nameservers'),'The new default hosts replace the reviewed ones');
 same($before,$d->operations()->count(),'The old confirmation cannot accept the changed default');
 // The second explicit confirmation sends exactly the hosts shown.
 $controller->manage($post(['confirmed'=>'1','nameservers'=>"ns5.example.net\nns6.example.net"]),$d->id,'nameservers');
 same($before+1,$d->operations()->count(),'The second explicit confirmation queues one change');
 same(['nameservers'=>['ns5.example.net','ns6.example.net']],$d->operations()->where('action','nameservers')->latest('id')->firstOrFail()->payload,'The confirmed hosts are sent as ordinary nameservers');
};
