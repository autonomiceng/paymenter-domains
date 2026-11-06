<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
// Real encrypted account casts and per-row staff HTML must agree; a pure model stub misses that boundary.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/staff-tlds.php';
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{Staff,TldFacts};
extract(commerceFixture(nameSiloLocal:false,identities:['namesilo'=>'demo-namesilo']));tldPanel();
$actor=staffUser([Staff::VIEW,Staff::COSTS]);Auth::setUser($actor);
$grants=Staff::requireView();$facts=TldFacts::page(collect($tlds),$grants);
same([false,true],array_map(static fn($t)=>$facts[$t->id]['demo'],$tlds),'Shared staff facts preserve each loaded account demo identity');
$html=TldFacts::accountsTable($tlds[0],$grants)->toHtml();
$dom=new DOMDocument();@$dom->loadHTML($html);$xpath=new DOMXPath($dom);
foreach($tlds as $i=>$t){
    $row=$xpath->query('//tbody/tr')->item($i);
    same($i===1?1:0,(int)$xpath->evaluate('count(td[1]//*[contains(@class,"domains-demo-tag")])',$row),'Only the seeded account receives the existing Demo tag');
}
$nativeSuccessful=true;echo "Staff price tables keep the correct per-account Demo tag.\n";
