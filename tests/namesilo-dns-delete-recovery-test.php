<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic shared DNS editor coverage.
declare(strict_types=1);
require __DIR__.'/Support/namesilo-dns-form.php';
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Auth,Cache};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Http\Controllers\{OwnedDomainController,OwnerActionController};
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\DnsManagement;
use Paymenter\Extensions\Servers\Domains\Data\NameserverEvidence;
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,ObservationProjection,OwnedDomains,OwnerPorts,OwnerSetup,Refunds,TransferInputs};
// Removing the last record is done only once an explicit empty inventory proves it.
$setup('dns-form-last',[]);$seed=$submit($d,$driver,'dns',['records'=>[array_diff_key($a,['record_id'=>true])]]);Cache::flush();$management->run($seed->id,$driver);$wire->writes=[];
$rows=$form();same(1,count($rows),'One saved record');$rows[0]['_delete']='1';$post($rows);$op=$latest();
$wire->fault='lost';Cache::flush();same('uncertain',$management->run($op->id,$driver)->state,'A lost delete is not done');
$unknown='Request #'.$op->id.': 0 of 1 change verified. The result is not confirmed. Contact support before making more changes.';$progress($unknown,true);
$ms->route='apibatch';$wire->fault='missing';Cache::flush();same('uncertain',$management->reconcile($op->id,$driver)->state,'A missing list does not prove the delete');$progress($unknown,true);
$wire->fault='';Cache::flush();same('succeeded',$management->reconcile($op->id,$driver)->state,'An explicit empty list proves the delete');
$progress('Request #'.$op->id.': 1 change verified.');same([],$section()['records'],'The editor opens empty');same(['dnsDeleteRecord'],$wire->writes,'The delete is sent once');
expect(str_contains($text($page()->query('//li[@id="dns"]/span[contains(@class,"domains-row__v")]')->item(0)),'0 records'),'The row shows no records');
$nativeSuccessful=true;echo "namesilo-dns-delete-recovery-test.php passed\n";
