<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The registry blocks transfers out for 60 days, so Transfer lock and Auth code say when transfers open.
require __DIR__.'/Support/management.php';
// A real registration completed at the fixture clock, with Transfer lock and Auth code both offered.
[$d,$driver,$registration]=$holding(placeholderComFixtureName('transfer-window-placeholder.com'));
require __DIR__.'/Support/owner-pages.php';
app('router')->getRoutes()->refreshNameLookups();
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\OwnedDomains;
$now=new DateTimeImmutable('2026-11-10T07:00:00+00:00');
$op=static fn(string $action,string $state,?string $at)=>(new Operation)->forceFill(['action'=>$action,'state'=>$state,'completed_at'=>$at]);
$line=OwnedDomains::transferOutFrom($op('register','succeeded','2026-11-10T06:52:00+00:00'),$now);
same('Transfers to another registrar open on Jan 9, 2027, 60 days after registration.',$line,'A new registration says when transfers out open');
same('Transfers to another registrar open on Dec 10, 2026, 60 days after the transfer.',OwnedDomains::transferOutFrom($op('transfer','succeeded','2026-10-11T00:00:00+00:00'),$now),'A transfer in counts from its completion');
same(null,OwnedDomains::transferOutFrom($op('register','succeeded','2026-09-01T00:00:00+00:00'),$now),'Past the 60 days there is no line');
same(null,OwnedDomains::transferOutFrom($op('register','queued',null),$now),'An unfinished registration has no date yet');
same(null,OwnedDomains::transferOutFrom(null,$now),'A domain we did not register or transfer has no line');
// The owner page puts the date beside both controls.
same('2026-10-18T12:00:00+00:00',$registration->fresh()->completed_at?->format(DATE_ATOM),'Fixture: the registration completed at the fixture clock');
$page=$owners->present($d->fresh());$expected='Transfers to another registrar open on Dec 17, 2026, 60 days after registration.';
same([$expected,$expected],[$page->management['lock']['transferFrom']??null,$page->management['auth_code_email']['transferFrom']??null],'The owner page gives Transfer lock and Auth code the date');
$keys=array_fill_keys(['setup','recovery','code','renew','auto-renew',...Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnerActionController::ACTIONS],'fixture-key');
$dom=storefrontDom(view('domains::show',['domain'=>$page,'summary'=>null,'keys'=>$keys])->render());
foreach (['lock','auth_code_email'] as $row) {
    same(1,$dom->query('//li[@id="'.$row.'"]//*[contains(@class,"domains-sub")][normalize-space(.)="'.$expected.'"]')->length,'The transfer date sits next to '.$row);
}
$nativeSuccessful=true;echo "Transfer-out window passed.\n";
