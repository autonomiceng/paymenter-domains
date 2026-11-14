<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. One wording for a domain term choice wherever a term is picked.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/storefront.php';
use Paymenter\Extensions\Servers\Domains\Presentation\TermChoice;
storefrontViews();require dirname(__DIR__).'/Domains/routes/web.php';app('router')->getRoutes()->refreshNameLookups();
same('1 year for $10.99',TermChoice::label(1,'$10.99'),'One year names its total');
$five=TermChoice::label(5,'$54.95');expect(str_starts_with($five,TermChoice::years(5))&&str_ends_with($five,'$54.95'),'Several years name the term and its total');
same('5 years',TermChoice::label(5,null),'Without a known total, the term alone');
same('2 years',TermChoice::label(2,''),'An empty total reads as unknown');
// The picked term, its option value and its shown total are the choice the form submits.
foreach([1,3,10] as $term){
    $result=storefrontData()['results'][0];$result['term']=$term;$total=$result['terms'][$term];
    $picked=storefrontDom(view('domains::partials.result',['result'=>$result])->render())->query('//select[@name="term"]/option[@selected]');
    same(1,$picked->length,"One term is picked for $term years");
    same([(string)$term,$total],[$picked->item(0)->getAttribute('value'),$picked->item(0)->getAttribute('data-total')],"The picked option submits $term years at $total");
    expect(str_starts_with(trim($picked->item(0)->textContent),TermChoice::years($term))&&str_ends_with(trim($picked->item(0)->textContent),$total),"The picked option shows $term years and $total");
}
$nativeSuccessful=true;echo "Term choice: checks passed.\n";
