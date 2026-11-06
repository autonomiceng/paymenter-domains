<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Real installed checkout; synthetic provider evidence.
declare(strict_types=1);
require __DIR__.'/runtime-native.php';
$presenter=app(\Paymenter\Extensions\Servers\Domains\Services\NativePresenter::class);
$user=commerceUser();$user->update(['email'=>'native-owner@example.com']);
foreach(['address'=>'1 Synthetic Street','city'=>'Seattle','state'=>'WA','zip'=>'98101','country'=>'US','phone'=>'(206) 555-0100'] as $key=>$value){$user->properties()->create(['key'=>$key,'name'=>$key,'value'=>$value]);}
foreach($tlds as $i=>$t){
    if($i){$policy=\Illuminate\Support\Facades\DB::table('domains_sales_policies')->where('tld','com')->sole();activeFixtureSeller($sellers,'com',$policy->revision,$t->server_id,true,true,$now);}
    $cart=commerceCart($user);
    commerceAdd($cart,$native->product($t,'register'),placeholderComFixtureName(str_repeat('a',46).'x-placeholder-'.$i.'.com'));
    commerceCheckout($user,$cart)->assertHasNoErrors();
    $d=\Paymenter\Extensions\Servers\Domains\Models\Domain::where('domain',placeholderComFixtureName(str_repeat('a',46).'x-placeholder-'.$i.'.com'))->sole();
    $d->operations()->sole()->update(['state'=>'held','safe_code'=>'staff_review_required']);
}
$staff=staffUser([...array_keys(\Paymenter\Extensions\Servers\Domains\Admin\Support\Staff::permissions()),'admin.servers.viewAny','admin.services.viewAny','admin.servers.view','admin.servers.create','admin.servers.update','admin.services.view','admin.services.update']);
$server=\App\Models\Server::findOrFail($tlds[0]->server_id);
$server->settings()->create(['key'=>'private_synthetic_secret','value'=>'SECRET-NEVER-IN-SNAPSHOT','encrypted'=>true]);
$service=\App\Models\Service::findOrFail($d->service_id);
unset($cart);gc_collect_cycles();gc_mem_caches();
