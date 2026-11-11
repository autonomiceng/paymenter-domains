<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/nameserver-pages.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault;
[$d]=$holding(placeholderComFixtureName('default-review-placeholder.com'));
require __DIR__.'/Support/owner-default-confirm.php';
$freshFill();
// An unexpected database failure is not an unconfigured default and must not disable custom editing.
$failDefault=true;DB::connection()->beforeExecuting(static function($sql,$bindings)use(&$failDefault):void{
 if($failDefault&&str_starts_with(strtolower($sql),'select')&&str_contains($sql,'domains_business_settings')){throw new RuntimeException('Synthetic private lookup diagnostic');}
});
$dto=$owners->present($d->fresh());$section=$dto->management['nameservers'];
expect(($section['defaultError']??null)!==null,'Unexpected default lookup has distinct safe presentation');
expect(isset($section['actions']['nameservers'])&&$section['defaultServers']===[],'Custom nameserver editing survives failed default lookup without fallback');
expect(!str_contains(json_encode($section),'Synthetic private'),'Lookup diagnostic never enters customer facts');
$failDefault=false;
// Render that same DTO through the customer page: the dialog explains the lookup, not the missing-default hint.
$show=static function($dto=null)use($d,$user,$session,$owners){
 $request=\Illuminate\Http\Request::create('/domains/'.$d->id);$request->setUserResolver(fn()=>$user);$request->setLaravelSession($session);
 $view=(new \Paymenter\Extensions\Servers\Domains\Http\Controllers\OwnedDomainController($owners))->show($request,$d->id);
 return storefrontDom(($dto?$view->with(['domain'=>$dto]):$view)->render());
};
$dialogText=static fn($dom)=>trim(preg_replace('/\s+/u',' ',$dom->query('//dialog[@id="edit-nameservers"]')->item(0)?->textContent??''));
$editor=$show($dto);$text=$dialogText($editor);
expect(str_contains($text,'We could not read the site default nameservers. Enter your own nameservers, or try again later.'),'The nameserver dialog explains the lookup failure');
expect(!str_contains($text,\Paymenter\Extensions\Servers\Domains\Services\ManagementNameservers::MISSING),'A lookup failure is not shown as a missing default');
same(1,$editor->query('//dialog[@id="edit-nameservers"]//textarea[@name="nameservers"]')->length,'Custom nameserver editing stays in the dialog');
same(0,$editor->query('//dialog[@id="edit-nameservers"]//button[@name="fill_site_default"]')->length,'No site-default fill is offered without readable defaults');
// A saved default the registrar refuses cannot be fixed by trying again; custom editing remains.
$profile->nameservers=array_map(static fn($n)=>'ns'.$n.'.example.net',range(1,13));$profile->saveQuietly();
$section=$owners->present($d->fresh())->management['nameservers'];
same('The site default nameservers can’t be used with Enom. Enter your own nameservers, or contact support.',$section['defaultError'],'A 13-host default for Enom has its own recovery');
expect(isset($section['actions']['nameservers'])&&$section['defaultServers']===[],'An invalid default leaves custom editing without a fallback');
$text=$dialogText($editor=$show());
expect(str_contains($text,'can’t be used with Enom')&&!str_contains($text,'try again later'),'The dialog shows the invalid-default recovery');
same(1,$editor->query('//dialog[@id="edit-nameservers"]//textarea[@name="nameservers"]')->length,'Invalid default keeps the custom editor');
$changedDefault();
$nativeSuccessful=true;echo "Current site default requires a fresh explicit confirmation.\n";
