<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The nameserver editor checks a list the server would refuse before the warning, and the warning takes the editor's place.
require __DIR__.'/Support/management.php';
use Paymenter\Extensions\Servers\Domains\Services\{OwnerFormValidation,OwnerNameservers};
[$d,$driver]=$holding(placeholderComFixtureName('customer-precheck-placeholder.com'));
require __DIR__.'/Support/owner-pages.php';
$section=$owners->present($d->fresh(),true)->management['nameservers']; $max=$section['max'];
same([true,$d->registrar_key==='namesilo'],[is_int($max)&&$max>=12,$section['letterEnding']??null],'The editor knows the server limit and ending rule');
$blade=file_get_contents(dirname(__DIR__).'/Domains/resources/views/partials/owner-management.blade.php');
expect(str_contains($blade,'data-confirm data-nameservers-max="{{ $section[\'max\'] }}"'),'The nameserver editor form carries the limit for the browser check');
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
$styles=file_get_contents(dirname(__DIR__).'/Domains/resources/views/partials/styles.blade.php');
expect(str_contains($script,"editor?.classList.add('domains-edit--behind')")&&str_contains($styles,'.domains .domains-edit--behind { visibility: hidden; }')
    &&str_contains($styles,'.domains .domains-edit--behind [data-confirm-dialog] { visibility: visible; }'),'Only the warning shows while it is open');
// Reopening an open warning closes it first; that close event arrives after it is open again and must not show the editor.
expect(str_contains($script,"dialog.addEventListener('close', () => { if (!dialog.open) editor?.classList.remove('domains-edit--behind'); });"),'The editor shows again only once the warning is closed');
$nativeSuccessful=true; echo "Customer nameserver precheck passed.\n";
