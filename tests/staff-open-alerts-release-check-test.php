<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// The alert count skips the release check. A passing check changes only how a held transfer's step reads, never whether staff
// must act, so the badge, the Open alerts filter and the list's own row rule still count the same domains.
require __DIR__.'/Support/staff-transfer.php';
use Illuminate\Support\Facades\{Cache, DB};
use Livewire\Livewire;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ListDomains;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{DomainFacts, DomainQueries, StaffDomainInputs};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{ObligationAlerts, OperationClaims};

Illuminate\Support\Facades\Auth::setUser($staff);
$ready=$newTransfer(placeholderComFixtureName('staff-alert-ready-placeholder.com'),1);
DB::table('domains_accounts')->where('server_id',$ready->server_id)->update(['processing_enabled'=>false]);
(new OperationClaims($bindings,$clock))->claim((int)$ready->id);
$ready=$ready->fresh(); same('processing',$ready->safe_code,'Orders off holds the paid saved-code transfer');
ObligationAlerts::record($ready->domain,'operation_review',time(),(int)$ready->id);
// With Orders off the release check passes, so the list's step reads Ready to send; without it the step names the held request.
$domain=$ready->domain()->with('latestAcquisition')->sole();
$full=DomainFacts::status($domain,StaffDomainInputs::for([$domain])[$domain->id]);
$count=DomainFacts::status($domain,StaffDomainInputs::for([$domain],null,true)[$domain->id]);
expect(str_starts_with((string)$full['step'],'Ready to send. Orders are off for this account.'),'The list step uses the passing release check: '.$full['step']);
expect($count['step']!==$full['step'],'Without the release check only the wording changes: '.$count['step']);
same([true,$ready->id],[$count['act'],$count['request']],'The count still sees a job for staff on the same request');
same([$full['act'],$full['request']],[$count['act'],$count['request']],'Skipping the release check never changes whether staff must act');
$listed=Domain::query()->with('latestAcquisition')->orderBy('id')->get()
    ->filter(function (Domain $d): bool { $in=StaffDomainInputs::for([$d])[$d->id]; return $in['alerts']!==[]&&DomainFacts::status($d,$in)['act']; })
    ->pluck('id')->values()->all();
config(['cache.default'=>'array']); Cache::flush();
// The fixture's writes change the signed-in user, so staff sign in again before the menu reads.
Illuminate\Support\Facades\Auth::setUser($staff); $badge=DomainResource::getNavigationBadge();
$page=Livewire::actingAs($staff)->test(ListDomains::class)->filterTable('alerts',true);
$page->set('tableDeferredFilters',$page->get('tableFilters'))->call('applyTableFilters')->assertSuccessful();
$filtered=$page->instance()->getTableRecords()->pluck('id')->sort()->values()->all();
same([[$domain->id],(string)count($listed),$listed],[$listed,$badge,$filtered],
    'Badge, Open alerts filter and the list rule count the same domains: '.json_encode([$listed,$badge,$filtered]));
same(count($listed),DomainQueries::openAlertCount(Domain::query()),'The account count agrees');
$nativeSuccessful=true; echo "The alert count skips only the release check's wording and agrees with the filter and the list.\n";
