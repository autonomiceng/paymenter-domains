<?php
// The Domains list Note and the domain page head name the same job: a request to check leads, and an unconfirmed registration has no stopped sync.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
require __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Auth, DB, Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, Staff, StaffDomainInputs};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain};
staffPanel(); [, $seed]=staffData();
Auth::setUser(staffUser([Staff::VIEW]));
Schema::disableForeignKeyConstraints();
Account::where('server_id',$seed->server_id)->update(['processing_enabled'=>true]);
$n=0;
$row=static function(string $status, string $state) use ($seed, &$n): Domain {
    $n++; $label='note-source-'.$n;
    $d=$seed->replicate()->fill(['domain'=>$label.'.example.com','sld'=>$label,'service_id'=>3000+$n,'status'=>$status,'provider_expires_at'=>today()->addDays(400)->format('Y-m-d')]);
    $d->save();
    DB::table('services')->insert(['id'=>$d->service_id,'user_id'=>$d->user_id,'order_id'=>1,'product_id'=>1,'plan_id'=>1,'status'=>'active','quantity'=>1,'price'=>'5.00','currency_code'=>'USD']);
    $seed->operations()->sole()->replicate()->fill(['domain_id'=>$d->id,'service_id'=>$d->service_id,'authority_generation'=>$d->authority_generation,
        'operation_key'=>$label,'action'=>'register','state'=>$state,'safe_code'=>null,'attempt'=>[],'policy'=>[],'payload'=>[],'cost'=>[],'funding'=>[]])->save();
    return $d->fresh();
};
// Domain 1: a danger cost alert and an unconfirmed request. The list Note leads with the request, as the page head does.
$d=$row('active','succeeded'); $request=(int)$d->latestAcquisition->id;
$inputs=StaffDomainInputs::for([$d])[$d->id];
$inputs['alerts']=[['code'=>'unknown_cost','request'=>null],['code'=>'operation_review','request'=>$request]];
$status=DomainFacts::status($d,$inputs);
same($request,$status['request'],'The step names the request to check');
same('operation_review',$status['alert'],'The request alert leads, not the cost alert');
$note=DomainFacts::note($status,null,false,$d)['text'];
expect(str_contains((string)$note,'Check request #'.$request.'.'),'The list Note says Check request #N: '.$note);
// With no request to check, the danger alert still leads.
$inputs['alerts']=[['code'=>'operation_review','request'=>null],['code'=>'unknown_cost','request'=>null]];
same('unknown_cost',DomainFacts::status($d,$inputs)['alert'],'Without a request the danger alert still leads');
// A stopped sync still shows on a registered domain.
same('failed',DomainFacts::sync($d,null,true,accountLoaded:true),'A stopped sync on a registered domain says so');
// Domain 50: the registration is unconfirmed, so its request owns recovery. The page has no Resume sync, and the list does not say Sync stopped.
$pending=$row('registration_pending','uncertain');
same(null,DomainFacts::sync($pending,null,true,accountLoaded:true),'An unconfirmed registration has no stopped sync in the list or the page');
$note=(string)DomainFacts::note(DomainFacts::status($pending),DomainFacts::sync($pending,null,true,accountLoaded:true),false,$pending)['text'];
expect(!str_contains($note,'Sync stopped'),'The list Note does not say Sync stopped: '.$note);
echo "List note source passed.\n";
$nativeSuccessful=true;
