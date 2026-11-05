<?php
// Queue serialization, real observer and the native page cache require one isolated application.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/owner-records.php';
require_once __DIR__.'/Support/staff-panel.php';
use Illuminate\Support\Facades\{Artisan,Auth,Cache,DB,Queue};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource\Pages\ViewDomain;
use Paymenter\Extensions\Servers\Domains\Jobs\SyncDomainRecords;
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,DomainObservation,ObservationSchema};
ObservationSchema::install();[$d,$driver]=$holding(placeholderComFixtureName('staff-sync-placeholder.com'));
staffPanel();$actor=staffUser([Staff::VIEW,'admin.domains.manage']);Auth::setUser($actor);
App\Models\Server::findOrFail($d->server_id)->settings()->updateOrCreate(['key'=>'domains_dns_tools'],['value'=>'1']);
$observer=new DomainObservation($readBindings,$clock,new AccountReadBudget($clock),$ports);
app()->instance(DomainObservation::class,$observer);
Queue::swap(Queue::getFacadeRoot()->queue);
config(['queue.default'=>'database','queue.connections.database.retry_after'=>360,'cache.default'=>'database']);
Illuminate\Support\Facades\Schema::create('cache',function($t){$t->string('key')->primary();$t->mediumText('value');$t->integer('expiration');});
Illuminate\Support\Facades\Schema::create('cache_locks',function($t){$t->string('key')->primary();$t->string('owner');$t->integer('expiration');});
Cache::flush();
$page=Livewire\Livewire::actingAs($actor)->test(ViewDomain::class,['record'=>$d->id]);
$page->assertSee('Not read yet');
$clock->instant=$clock->now()->modify('+61 seconds');
same(true,SyncDomainRecords::enqueue($actor->id,$d),'Staff sync queues a real database job');
same(1,DB::table('jobs')->count(),'One queued job');
$page->call('$refresh');$queuedHtml=$page->html();
Artisan::call('queue:work',['--stop-when-empty'=>true,'--sleep'=>0,'--tries'=>1]);
same(0,DB::table('jobs')->count(),'Worker consumes the job');
same(0,DB::table('failed_jobs')->count(),'No failed queue job');
same('Domain synced',App\Models\Notification::where('user_id',$actor->id)->latest('id')->value('title'),'Worker completes a registrar read');
same($clock->now()->format(DATE_ATOM),$d->fresh()->last_synced_at->format(DATE_ATOM),'Worker advances saved domain facts');
$page->call('pollManagement')->assertSee('0 records')->assertDontSee('Not read yet');
expect(str_contains($queuedHtml,'wire:poll.2s="pollManagement"'),'An open staff page must poll while its sync is queued');
expect(!str_contains($page->html(),'wire:poll.'),'Completed sync stops polling');
$nativeSuccessful=true;echo "Native database worker refreshes saved facts and the open staff page.\n";
