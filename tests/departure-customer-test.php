<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Registrar and payment transports are synthetic.
declare(strict_types=1);
require __DIR__.'/departure-departed.php';
use Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController;

$nativeSuccessful=false;
// The owner opens the page of the domain whose departure staff confirmed above.
app('view')->share('errors',new Illuminate\Support\ViewErrorBag);
$saved=$d->fresh()->renewal_policy['departure'];
$request=Illuminate\Http\Request::create('/domains/'.$d->id);$request->setUserResolver(static fn()=>$owner);
$request->setLaravelSession(app('session')->driver('array'));
$view=app(OwnedDomainController::class)->show($request,(int)$d->id);
$data=$view->getData();$html=$view->render();
$dom=Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.$html,LIBXML_NOERROR);
$text=static fn(?Dom\Element $e):string=>$e===null?'':preg_replace('/\s+/',' ',$e->textContent);
// The page keeps the saved departure reason and confirmation time.
$registration=$dom->getElementById('registration');
expect($registration!==null,'The departed page has a Registration section');
expect(str_contains($text($dom->querySelector('[data-piece="head-line"]')),rtrim(Paymenter\Extensions\Servers\Domains\Services\HoldingDeparture::REASONS[$saved['reason']],'.')),'The head gives the saved reason');
same((new DateTimeImmutable($saved['confirmed_at']))->getTimestamp(),(new DateTimeImmutable($data['departed']['history'][0]['date']))->getTimestamp(),'Departure history keeps the saved confirmation time');
// Nothing on the page changes the departed domain: no forms, no buttons, no renewal or settings controls.
same(0,$dom->querySelectorAll('form')->length,'The departed page has no forms');
same(0,$dom->querySelectorAll('.domains button, button[data-dialog-open], [data-dialog-open]')->length,'The departed page has no buttons or dialogs');
foreach($dom->querySelectorAll('a[href]') as $a){
    $href=(string)$a->getAttribute('href');
    expect(!preg_match('#/domains/'.$d->id.'/(manage|renew|auto-renew|setup|code|contacts|recovery)#',$href)&&!str_contains($href,'edit='),'No link changes the departed domain: '.$href);
}
expect($dom->getElementById('renewal')===null&&$dom->getElementById('settings')===null,'Renewal and settings sections are left out');
// The way back is the existing transfer page for this name.
$back=$dom->querySelector('.domains-head-actions a');
expect($back!==null&&str_contains((string)$back->getAttribute('href'),'/domains/transfer')&&str_contains(urldecode((string)$back->getAttribute('href')),'name='.$d->domain),'The head links to the transfer page for this domain');
echo "The customer departed page shows the saved departure and offers no changes to the domain.\n";
$nativeSuccessful=true;
