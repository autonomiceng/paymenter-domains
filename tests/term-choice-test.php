<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. One wording for a domain term choice wherever a term is picked.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Presentation\TermChoice;
storefrontViews();
same('1 year for $10.99',TermChoice::label(1,'$10.99'),'One year names its total');
$five=TermChoice::label(5,'$54.95');expect(str_starts_with($five,TermChoice::years(5))&&str_ends_with($five,'$54.95'),'Several years name the term and its total');
same('5 years',TermChoice::label(5,null),'Without a known total, the term alone');
same('2 years',TermChoice::label(2,''),'An empty total reads as unknown');
$views=dirname(__DIR__).'/Domains/resources/views';
$guard=[];$dots=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($views,FilesystemIterator::SKIP_DOTS)) as $file){
    $relative=substr($file->getPathname(),strlen($views)+1);
    if(str_starts_with($relative,'admin/live-activation')||str_starts_with($relative,'admin/term-prices')){continue;} // staff reports, not choices
    $text=(string)file_get_contents($file->getPathname());
    if(preg_match("/'year'\\s*:\\s*'years'|\\?\\s*'year'/",$text)===1){$guard[]=$relative;}
    if(!str_starts_with($relative,'admin/')&&str_contains($text,'·')){$dots[]=$relative;}
}
same([],$dots,'Customer views join words with words, never a middle dot');
same([],$guard,'Term choices word their years through TermChoice, not by hand');
foreach(['partials/result.blade.php','cart-edit.blade.php','partials/owner-renew.blade.php'] as $picker){
    expect(str_contains((string)file_get_contents($views.'/'.$picker),'TermChoice::label('),"$picker labels its term options with TermChoice");
}
$line=trim(preg_replace('/\s+/',' ',strip_tags(view('domains::checkout-line',['domain'=>['name'=>'example.com','action'=>'Transfer','years'=>1,'renewalPrice'=>'$14.00','editUrl'=>'/cart/1']])->render())));
expect(str_contains($line,'Transfer for '.TermChoice::years(1)),'The checkout line names its action and term: '.$line);
$nativeSuccessful=true;echo "Term choice: checks passed.\n";
