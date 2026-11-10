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
    if ($forms) {
        // The row names what the registrar refused, in red, with a support link.
        $row=$dto->management['nameservers']['progress'];
        same('Not changed: the registrar did not take the change to ns1.example.net, ns2.example.net. Check that each one is a working nameserver, then try again.',$row['text'],'Settings name the refused change and the next step');
        same(['bad',true],[$row['tone']??null,$row['support']??null],'The refused row is red and offers support');
    }
}
$request=Illuminate\Http\Request::create('/domains/'.$d->id); $request->setUserResolver(fn()=>$user); $request->setLaravelSession(app('request')->session());
$html=(new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($request,$d->id)->render();
$dom=storefrontDom($html); $history=trim(preg_replace('/\s+/',' ',$dom->query('//*[@id="history"]')->item(0)->textContent));
expect(str_contains($history,$expected)&&!str_contains($history,'Try again')&&!str_contains($history,'to make this change'),'Rendered History retains one consistent next step');
$row=storefrontDom($html)->query('//*[@id="nameservers"]')->item(0);
expect(str_contains($row->C14N(),'domains-tone-text-bad')&&str_contains($row->textContent,'Contact support'),'Rendered row shows the refusal in red with Contact support');
// Older and equal observations do not clear a refused request.
$saved=$d->fresh()->observation??[];
foreach(['-1 minute','+0 seconds'] as $offset){
    $at=$op->fresh()->updated_at->modify($offset)->format(DATE_ATOM);
    $d->forceFill(['observation'=>array_replace_recursive($saved,['sections'=>['nameservers'=>Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::section($d,
        ['operation_key'=>'observation','at'=>$at,'facts'=>['nameservers'=>['dns1.registrar.example','dns2.registrar.example']]])]])])->save();
    $dto=$owners->present($d->fresh(),true);$row=$dto->management['nameservers']['progress'];
    same(['bad',true,false],[$row['tone'],$row['support'],$row['settled']],'Only a strictly newer read clears refusal: '.$offset);
    same($expected,$dto->progress[0]['note'],'History keeps refusal: '.$offset);
    $dom=storefrontDom((new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($request,$d->id)->render());
    $row=$dom->query('//*[@id="nameservers"]')->item(0);
    expect(str_contains($row->C14N(),'domains-tone-text-bad')&&str_contains($row->textContent,'Contact support'),'Older/equal read keeps red refusal and support');
}
// A projection exception also keeps the refusal. The same stored operation supplies the History above.
$unreadable=new class extends Paymenter\Extensions\Servers\Domains\Models\Domain {
    public function getForeignKey(){return 'domain_id';}
    public function getAttribute($key){if($key==='observation'){throw new RuntimeException('Synthetic projection failure');}return parent::getAttribute($key);}
};
$unreadable->setRawAttributes($d->fresh()->getAttributes(),true);
$row=ManagementProgress::for($unreadable)['nameservers'];
same(['bad',true,false],[$row['tone'],$row['support'],$row['settled']],'Projection failure retains red refusal and support');
expect(str_contains($row['text'],'Not changed:')&&str_contains($row['text'],'try again.'),'Projection failure retains retry guidance');

// A later registrar read of the nameservers ends the row's notice. History keeps the refusal.
$at=$op->fresh()->updated_at->modify('+1 minute')->format(DATE_ATOM);
$d->forceFill(['observation'=>array_replace_recursive($d->fresh()->observation??[],['sections'=>['nameservers'=>Paymenter\Extensions\Servers\Domains\Services\ObservationProjection::section($d->fresh(),
    ['operation_key'=>'observation','at'=>$at,'facts'=>['nameservers'=>['dns1.registrar.example','dns2.registrar.example']]])]])])->save();
$dto=$owners->present($d->fresh(),true);
same(null,$dto->management['nameservers']['progress']??null,'A read after the refusal clears the row');
same($expected,$dto->progress[0]['note'],'History still gives the refusal');
$nativeSuccessful=true; echo "Customer nameserver refusal passed.\n";
