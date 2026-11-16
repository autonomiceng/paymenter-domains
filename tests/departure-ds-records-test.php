<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic retained DS inventory and unsupported change policy.
declare(strict_types=1);
require __DIR__.'/Support/observation-records-ready.php';
use Paymenter\Extensions\Servers\Domains\Presentation\{DsState,OwnedDomain,Plain};
use Paymenter\Extensions\Servers\Domains\Services\ObservationRecords;
// Present the retained inventory with NameSilo's default unsupported DS-change policy.
$record=$d->fresh();$record->registrar_key='namesilo';
$p=$record->observation;$p['records']=[...$p['records'],'identity'=>ObservationRecords::identity($record),
    'format'=>1,'capability_revision'=>'namesilo-records-1',
    'expected_families'=>['dns'=>'unsupported','srv'=>'unsupported','ds'=>'supported','glue'=>'supported']];
$record->observation=$p;
$domain=DsState::owner($owners->present($d->fresh()),$record,true);
$domain=new OwnedDomain(...[...get_object_vars($domain),'departureSuspected'=>true]);
same(true,$domain->management['ds']['off'],'DS changes remain unsupported');
same([],$domain->management['ds']['actions'],'Unsupported DS changes expose no actions');
$records=$domain->management['ds']['records'];expect(!empty($records),'The policy retains observed DS records');
$html=view('domains::partials.owner-management',['domain'=>$domain,'caps'=>['ds']])->render();
$dom=Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8">'.$html,LIBXML_NOERROR);
$row=$dom->getElementById('ds');
expect($row!==null&&str_contains($row->textContent,$records[0]['digest']),'Paused DNSSEC exposes the retained DS digest');
expect(str_contains($row->textContent,Plain::On->label()),'Nonempty retained DS inventory keeps DNSSEC On');
expect($row->querySelector('button[aria-disabled="true"][type="button"]')!==null,'Departure keeps DS changes paused');
$nativeSuccessful=true;echo "Paused DNSSEC retains its observed state and records.\n";
