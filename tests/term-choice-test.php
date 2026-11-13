<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. One wording for a domain term choice wherever a term is picked.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Presentation\TermChoice;
storefrontViews();
same('1 year · $10.99',TermChoice::label(1,'$10.99'),'One year names its total');
same('5 years · $54.95',TermChoice::label(5,'$54.95'),'Several years name their total');
same('5 years',TermChoice::label(5,null),'Without a known total, the term alone');
same('2 years',TermChoice::label(2,''),'An empty total reads as unknown');
$views=dirname(__DIR__).'/Domains/resources/views';
$guard=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views,FilesystemIterator::SKIP_DOTS)) as $file){
    $relative=substr($file->getPathname(),strlen($views)+1);
    if(str_starts_with($relative,'admin/live-activation')||str_starts_with($relative,'admin/term-prices')){continue;} // staff reports, not choices
    $text=(string)file_get_contents($file->getPathname());
    if(preg_match("/'year'\\s*:\\s*'years'|\\?\\s*'year'/",$text)===1){$guard[]=$relative;}
}
same([],$guard,'Term choices word their years through TermChoice, not by hand');
foreach(['partials/result.blade.php','cart-edit.blade.php','partials/owner-renew.blade.php'] as $picker){
    expect(str_contains((string)file_get_contents($views.'/'.$picker),'TermChoice::label('),"$picker labels its term options with TermChoice");
}
echo "Term choice: checks passed.\n";
