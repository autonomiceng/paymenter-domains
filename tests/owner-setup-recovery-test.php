<?php
// Native owner POSTs need real checkout, encrypted payloads, native sessions and Blade.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owner-actions.php';
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Cache};
use Paymenter\Extensions\Servers\Domains\Http\{FormKeys};
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController, OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{OwnerSetup, DomainManagement};
$legacy=$newTransfer('owner-legacy-privacy-placeholder.com',1,true,true);
$legacy->update(['payload'=>[...$legacy->payload,'privacy'=>true]]);
$setup->save($user,$legacy->domain_id,['nameservers'=>['ns1.example.com','ns2.example.com']]);
same(true,$legacy->fresh()->payload['privacy'],'Hidden setup preserves a previously stored privacy choice');
$incomplete=$newTransfer(placeholderComFixtureName('owner-before-setup-placeholder.com'),1,true,true);$incomplete->update(['payload'=>[...$incomplete->payload,'nameservers'=>[]]]);$makeTransfer(1);
$priorCalls=count($state->calls);$engine->execute($incomplete->id);same([],$incomplete->fresh()->attempt??[],'Incomplete setup consumes no acquisition attempt');same($priorCalls,count($state->calls),'Incomplete setup performs no transport');
$setup->save($user,$incomplete->domain_id,['nameservers'=>['ns1.example.com','ns2.example.com'],'privacy'=>false]);
expect($claims->claim($incomplete->id)!==null,'Valid owner setup permits a virgin funded claim');
$op=$newTransfer(placeholderComFixtureName('owner-unverified-paid-placeholder.com'),0,false,false);DB::table('invoices')->where('id',$op->invoice_id)->update(['status'=>'paid']);
expect(!$owners->present($op->domain)->transfer['open'],'Paid status alone cannot offer an unverified deadline');
$r=$req($op->domain,'setup',['confirmed'=>'1']);$action->setup($r,$op->domain_id);expect(!isset($op->fresh()->policy['owner_setup']),'Unfunded setup refused');
$cutoff=$newTransfer(placeholderComFixtureName('owner-cutoff-setup-placeholder.com'),1,true,false);$clock->instant=$now->modify('+30 days');
expect(!isset($owners->present($cutoff->domain)->actions['setup']),'Virgin setup is absent at the exact paid cutoff');$clock->instant=$now;
$nativeSuccessful=true;echo "Native owner action matrix passed.\n";
