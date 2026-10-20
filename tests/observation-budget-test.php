<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php'; require __DIR__.'/Support/commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Data\{RequestBudget,RequestContext,RequestOrigin};
use Paymenter\Extensions\Servers\Domains\Services\{AccountReadBudget,CatalogueAcceptances,ObservationSchema,ReadDeferred};
use Paymenter\Extensions\Servers\Domains\Tests\FakeClock;
extract(commerceFixture()); ObservationSchema::install(); ObservationSchema::verify();
$clock=new FakeClock($now); $budget=new AccountReadBudget($clock); $other=new AccountReadBudget($clock);
$b=CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[1]->server_id)->sole());
$e=CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$tlds[0]->server_id)->sole());
$held=static function(callable $fn):void {try{$fn();expect(false,'Expected durable budget refusal');}catch(ReadDeferred){}};
$t=$budget->acquire($b,RequestOrigin::Interactive); $held(fn()=>$other->acquire($b,RequestOrigin::Interactive));
$budget->release($b,RequestOrigin::Interactive,$t); $held(fn()=>$other->acquire($b,RequestOrigin::Interactive));
$clock->instant=$now->modify('+999999 microseconds'); $held(fn()=>$other->acquire($b,RequestOrigin::Interactive));
$clock->instant=$now->modify('+1 second'); $t=$other->acquire($b,RequestOrigin::Interactive); $other->release($b,RequestOrigin::Interactive,$t);
$x=$budget->acquire($b,RequestOrigin::Background); $y=$other->acquire($b,RequestOrigin::Background); $held(fn()=>$budget->acquire($b,RequestOrigin::Background));
$z=$budget->acquire($e,RequestOrigin::Background); $budget->release($e,RequestOrigin::Background,$z);
$budget->release($b,RequestOrigin::Background,$x); $z=$other->acquire($b,RequestOrigin::Background);
$other->release($b,RequestOrigin::Background,$z); $other->release($b,RequestOrigin::Background,$y);
// A crashed worker retains admission until beyond the maximum25s wire timeout.
$x=$budget->acquire($b,RequestOrigin::Background); $y=$budget->acquire($b,RequestOrigin::Background);
$clock->instant=$now->modify('+30 seconds'); $held(fn()=>$other->acquire($b,RequestOrigin::Background));
$clock->instant=$now->modify('+31 seconds'); $z=$other->acquire($b,RequestOrigin::Background); $other->release($b,RequestOrigin::Background,$z);
// Real client scope always releases on failures, but never refunds the minute quota.
$clock->instant=$now->modify('+2 minutes'); $ctx=new RequestContext($b,RequestOrigin::Background,$clock->now()->modify('+1 minute'),new RequestBudget(1),$clock,$budget);
try{$ctx->withinRead($b,fn()=>throw new RuntimeException('Synthetic lost read'));}catch(RuntimeException){}
same([],json_decode(DB::table('domains_read_budgets')->where('server_id',$b->serverId)->value('leases'),true),'Failure releases wire lease');
same(1,(int)DB::table('domains_read_budgets')->where('server_id',$b->serverId)->value('used'),'Failure retains durable usage');
for($i=1;$i<60;$i++){$t=$other->acquire($b,RequestOrigin::Background);$other->release($b,RequestOrigin::Background,$t);}
$held(fn()=>$budget->acquire($b,RequestOrigin::Interactive)); $held(fn()=>$budget->acquire($b,RequestOrigin::Background));
$clock->instant=$clock->now()->modify('+60 seconds');$t=$budget->acquire($b,RequestOrigin::Background);$budget->release($b,RequestOrigin::Background,$t);
try { DB::transaction(fn()=>$budget->acquire($b,RequestOrigin::Background)); expect(false,'Budget must start outside locks'); } catch (LogicException) {}
// Binding drift is a different refusal and must escape the budget-only assertion.
DB::table('domains_read_budgets')->where('server_id',$b->serverId)->update(['account_fingerprint'=>str_repeat('0',64)]);
try {
    $held(fn()=>$budget->acquire($b,RequestOrigin::Background));
    expect(false,'Budget assertion swallowed a binding-change refusal');
} catch (DomainException $error) {
    same(DomainException::class,$error::class,'Binding drift retains its exact exception type');
    same('Read budget binding changed.',$error->getMessage(),'Binding failure is not quota exhaustion');
} finally {
    DB::table('domains_read_budgets')->where('server_id',$b->serverId)->update(['account_fingerprint'=>$b->fingerprint]);
}
$nativeSuccessful=true; echo "Durable per-account/wire budgets passed\n";
