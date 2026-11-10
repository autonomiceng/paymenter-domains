<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The nameserver editor checks a list the server would refuse before the warning, and the warning takes the editor's place.
require __DIR__.'/Support/management.php';
use Paymenter\Extensions\Servers\Domains\Services\{OwnerFormValidation,OwnerNameservers};
[$d,$driver]=$holding(placeholderComFixtureName('customer-precheck-placeholder.com'));
require __DIR__.'/Support/owner-pages.php';
app()->forgetInstance(Paymenter\Extensions\Servers\Domains\Services\Storefront::class);
$max=$owners->present($d->fresh(),true)->management['nameservers']['max'];
$request=Illuminate\Http\Request::create('/domains/'.$d->id); $request->setUserResolver(fn()=>$user); $request->setLaravelSession(app('request')->session());
$html=(new Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($request,$d->id)->render();
$form=storefrontDom($html)->query('//dialog[@id="edit-nameservers"]//form[@data-confirm]')->item(0);
expect($form!==null,'The nameserver editor renders');
same((string)$max,$form->getAttribute('data-nameservers-max'),'The editor carries the server limit');
// Every refusal the browser can give uses the server's words.
$script=file_get_contents(dirname(__DIR__).'/Domains/resources/views/partials/client-script.blade.php');
foreach (['few'=>"ns1.example.com",'hostname'=>"ns1.example.com\nnot a host!!",'duplicate'=>"ns1.example.com\nns1.example.com"] as $kind=>$text) {
    try { OwnerNameservers::parse($text,false,$d->registrar_key); throw new LogicException('Expected a refusal: '.$kind); }
    catch (OwnerFormValidation $e) { $message=OwnerNameservers::message((new ReflectionProperty($e,'nameservers'))->getValue($e),$text,$d->domain); }
    $fixed=match($kind){'hostname'=>" isn't a valid hostname. Use a name like ns1.example.com.",'duplicate'=>' is listed more than once.',default=>$message};
    expect(str_contains($message,$fixed)&&str_contains($script,$fixed),'Browser check uses the server words: '.$kind);
}
expect(str_contains($script,"'Enter no more than ' + max + ' nameservers.'")&&OwnerNameservers::message(['kind'=>'many','max'=>$max],'',$d->domain)==='Enter no more than '.$max.' nameservers.','Browser check uses the server words: many');
expect(str_contains($script,'const problem = nameserverProblem();')&&strpos($script,'const problem = nameserverProblem();')<strpos($script,"editor?.classList.add('domains-edit--behind');"),'The list is checked before the warning opens');
// The warning replaces the editor instead of stacking on it.
expect(str_contains($script,"editor?.classList.add('domains-edit--behind')")&&str_contains($html,'.domains .domains-edit--behind { visibility: hidden; }')
    &&str_contains($html,'.domains .domains-edit--behind [data-confirm-dialog] { visibility: visible; }'),'Only the warning shows while it is open');
$nativeSuccessful=true; echo "Customer nameserver precheck passed.\n";
