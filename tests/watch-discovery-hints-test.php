<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/Support/registration-fixture.php';require __DIR__.'/Support/registration-refusal-reply.php';require __DIR__.'/Support/watch-discovery.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\WatchSource;
use Paymenter\Extensions\Servers\Domains\Services\DomainReservations;
$makeEngine($bindingFor(0),static fn()=>$purchaseRefusal);$refused=$runRegistration($new('release-placeholder.com'));
app()->offsetUnset(Paymenter\Extensions\Servers\Domains\Services\Runtime::class);app()->offsetUnset(Paymenter\Extensions\Servers\Domains\Services\RuntimePorts::class);
$f=discoveryFixture();extract($f);$property->update(['value'=>'release-placeholder.com']);$sweep();
$target=DB::table('domains_watch_discovery')->where('product_id',$product->id);$target->update(['hint'=>false,'due_at'=>$clock->at+86400]);
$before=$refused->fresh()->only(['attempt','funding','cost','state']);
DomainReservations::releaseTerminal('release-placeholder.com');
same(null,$refused->domain->fresh()->reservation_name,'Real terminal release clears the reservation under its existing guards');
expect((bool)(clone$target)->value('hint'),'Query-builder terminal release hints the formerly linked hosting name after commit');
same($before,$refused->fresh()->only(['attempt','funding','cost','state']),'Discovery hints never rewrite financial evidence');
$work->tick($clock->at);same('watch',WatchSource::where('service_id',$service->id)->value('state'),'Released refusal remains an external monitoring source');
$queries=[];DB::listen(static function($q)use(&$queries):void{if(str_contains($q->sql,'domains_watch_sources')){$queries[]=$q->sql;}});
$refused->domain->refresh()->update(['confirmed_revision'=>2]);
expect(!array_filter($queries,fn($q)=>str_contains($q,'"registrable_name" is null')),'Cleared reservations never look up unrelated null-name held sources');
$rows=DB::table('domains_watch_discovery')->count();$work->hint(0);same($rows,DB::table('domains_watch_discovery')->count(),'Nonpositive hints remain pre-SQL no-ops');
same([],$dns->calls,'Hints and release are local only');echo "Native terminal-release discovery hints passed.\n";$nativeSuccessful=true;
