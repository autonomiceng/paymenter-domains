<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// While one registrar change runs, the other rows keep their values and say when they open.
require __DIR__.'/Support/management.php';
[$d,$driver]=$holding(placeholderComFixtureName('customer-waiting-placeholder.com'));
require __DIR__.'/Support/owner-pages.php';
app()->forgetInstance(Paymenter\Extensions\Servers\Domains\Services\Storefront::class);
$before=$owners->present($d->fresh(),true)->management;
expect(count($before)>1,'The fixture shows more than one setting');
$op=$submit($d,$driver,'nameservers',['nameservers'=>['ns1.example.net','ns2.example.net']]);
// An unclear registrar answer leaves the change unresolved, which blocks every other change.
$state->fault='empty_ack'; same('uncertain',$management->run($op->id,$driver)->state,'The registrar answer stays unclear');
$during=$owners->present($d->fresh(),true)->management;
expect($during['nameservers']['progress']['pending']??false,'The nameserver change is running');
same(array_keys($before),array_keys($during),'Every row stays while the nameserver change runs');
$waiting=array_filter($during,fn($section,$key)=>$key!=='nameservers'&&!$section['actions'],ARRAY_FILTER_USE_BOTH);
expect($waiting!==[],'Other rows wait for the change');
foreach ($waiting as $key=>$section) { same('Opens when the nameservers change finishes.',$section['waiting']??null,'Row says when it opens: '.$key); }
$request=Illuminate\Http\Request::create('/domains/'.$d->id); $request->setUserResolver(fn()=>$user); $request->setLaravelSession(app('request')->session());
$html=(new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($request,$d->id)->render();
$dom=storefrontDom($html);
foreach (array_keys($waiting) as $key) {
    $row=$dom->query('//*[@id="'.$key.'"]')->item(0);
    expect($row!==null&&str_contains($row->textContent,'Opens when the nameservers change finishes.'),'Rendered row stays with its reason: '.$key);
}
$nativeSuccessful=true; echo "Customer rows during a pending change passed.\n";
