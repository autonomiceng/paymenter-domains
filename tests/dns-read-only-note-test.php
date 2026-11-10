<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// A Read-only DNS record says why it can't be changed and what to do.
require __DIR__.'/native-app.php';
require __DIR__.'/Support/storefront.php';
storefrontViews();
$note='Read-only records are shown as the registrar reports them. You can’t change them on this page. Contact support if one needs to change.';
$records=[['name'=>'*','type'=>'A','address'=>'192.0.2.10','ttl'=>3600]];
$html=view('domains::partials.dns-inventory',['section'=>['inventory'=>$records,'actions'=>[]]])->render();
expect(str_contains($html,'Read-only')&&str_contains($html,$note),'A Read-only record explains itself');
$html=view('domains::partials.dns-inventory',['section'=>['inventory'=>$records,'types'=>['A'],'actions'=>['dns'=>'/domains/1/dns']]])->render();
expect(str_contains($html,'Edit above')&&!str_contains($html,$note),'Editable records need no note');
$nativeSuccessful=true;echo "DNS read-only note passed.\n";
