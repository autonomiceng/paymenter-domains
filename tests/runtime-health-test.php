<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic local facts only.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget,CatalogueAcceptances,RuntimeHealth};

(new Paymenter\Extensions\Servers\Domains\Domains)->installed();extract(commerceFixture(false,static fn()=>null));
app()->instance(AccountBindings::class,$bindings);
$server=$tlds[0]->server_id;
$binding=CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server)->sole());
$clock=new class implements Clock {
    public function now():DateTimeImmutable{return new DateTimeImmutable('2026-10-22T12:00:00Z');}
};
$budget=new AccountReadBudget($clock,48);
foreach([RequestOrigin::Interactive,RequestOrigin::Background] as $index=>$origin){
    $lease=$budget->acquire($binding,$origin);$budget->release($binding,$origin,$lease);
    $before=DB::table('domains_read_budgets')->where('server_id',$server)->first();
    $health=RuntimeHealth::for($server);
    same(($index+1).' registrar requests in the last recorded minute (limit 48 background, 60 total). Shared by interactive and background reads and changes; identity verification and the Enom TEST daily limit are separate.', $health['Read capacity'],'Real limiter shares usage across interactive and background requests');
    same((array)$before,(array)DB::table('domains_read_budgets')->where('server_id',$server)->first(),'Health view does not change the shared minute budget');
}
echo "Runtime health shared interactive/background request-budget copy passed.\n";$nativeSuccessful=true;
