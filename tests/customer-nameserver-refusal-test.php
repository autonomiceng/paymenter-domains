<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Native management completion and customer Blade share durable operation evidence.
require __DIR__.'/Support/management.php';
use Paymenter\Extensions\Servers\Domains\Services\ManagementProgress;

[$d,$driver]=$holding(placeholderComFixtureName('customer-refused-placeholder.com'));
require __DIR__.'/Support/owner-pages.php';
app()->forgetInstance(Paymenter\Extensions\Servers\Domains\Services\Storefront::class);
$op=$submit($d,$driver,'nameservers',['nameservers'=>['ns1.example.net','ns2.example.net']]);
$state->fault='refused'; same('rejected',$management->run($op->id,$driver)->state,'Registrar refusal is terminal');
$expected='The registrar didn’t accept these nameservers. Check them or contact support.';
foreach ([true,false] as $forms) {
    $dto=$owners->present($d->fresh(),$forms);
    same($expected,$dto->progress[0]['note'],'History gives the nameserver refusal reason');
    if ($forms) { same($expected,$dto->management['nameservers']['progress']['text'],'Settings use the same next step'); }
}
$request=Illuminate\Http\Request::create('/domains/'.$d->id); $request->setUserResolver(fn()=>$user); $request->setLaravelSession(app('request')->session());
$html=(new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($request,$d->id)->render();
$dom=storefrontDom($html); $history=trim(preg_replace('/\s+/',' ',$dom->query('//*[@id="history"]')->item(0)->textContent));
expect(str_contains($history,$expected)&&!str_contains($history,'Try again')&&!str_contains($history,'to make this change'),'Rendered History retains one consistent next step');
$nativeSuccessful=true; echo "Customer nameserver refusal passed.\n";
