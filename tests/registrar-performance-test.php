<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount;
$registrarsPart='performance';require __DIR__.'/Support/registrar-pages.php';
$nativeSuccessful=false;
// The balance forecast reads the catalogue. The sheet and its funding link must share that work in one request.
DB::flushQueryLog();DB::enableQueryLog();
$page=Livewire::actingAs($staff)->test(RegistrarAccount::class,['account'=>$enom->server_id])->assertSuccessful();
$queries=DB::getQueryLog();DB::disableQueryLog();
$balanceReads=count(array_filter($queries,static fn(array $q):bool=>str_contains($q['query'],'from "domains_registrar_balances"')));
same(1,$balanceReads,'One balance and forecast per account page render');
$nativeSuccessful=true;
