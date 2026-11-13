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
same([false,true],array_column(TldFacts::accounts($tlds[0],$grants),'demo'),'Only the seeded account is marked demo in the price sections');
$sections=\Paymenter\Extensions\Servers\Domains\Admin\Support\TldRows::accounts(TldFacts::accounts($tlds[0],$grants),true);
same([false,true],array_map(static fn($s)=>str_contains($s->title,'(demo data)'),$sections),'Only the seeded account section says demo data');
$nativeSuccessful=true;echo "Staff price sections keep the correct per-account demo mark.\n";
