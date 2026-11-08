<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
$registrarsPart='dns';require __DIR__.'/Support/registrar-pages.php';

$nativeSuccessful=false;
\Livewire\Livewire::withQueryParams(['switch'=>'template'])->actingAs($admin)->test(\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarAccount::class,['account'=>$enom->server_id])->assertSet('switch',null);
$nativeSuccessful=true;
