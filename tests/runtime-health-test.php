<?php
// MIT, Copyright (c) 2026 Autonomic Engineering. Synthetic local facts only.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/commerce.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\RequestOrigin;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,AccountReadBudget,CatalogueAcceptances};

(new Paymenter\Extensions\Servers\Domains\Domains)->installed();extract(commerceFixture(false,static fn()=>null));
app()->instance(AccountBindings::class,$bindings);
// Worker cadence is operator documentation, not a staff page fact.
$runtimeDoc=(string)file_get_contents(__DIR__.'/../docs/runtime.md');
expect(str_contains($runtimeDoc,'at most eight accounts, with 25 operations, 25 domains and 25 TLDs per account'),'Worker selection counts operations, domains and TLDs');
expect(str_contains($runtimeDoc,'share the same limiter'),'Request limits have a separate unit');
$server=$tlds[0]->server_id;
$binding=CatalogueAcceptances::binding(Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id',$server)->sole());
$clock=new class implements Clock {
    public DateTimeImmutable $instant;
    public function __construct(){$this->instant=new DateTimeImmutable('2026-10-22T12:00:00Z');}
    public function now():DateTimeImmutable{return $this->instant;}
};
$budget=new AccountReadBudget($clock,48);
foreach([RequestOrigin::Interactive,RequestOrigin::Background] as $index=>$origin){
    $lease=$budget->acquire($binding,$origin);$budget->release($binding,$origin,$lease);
    same($index+1,(int)DB::table('domains_read_budgets')->where('server_id',$server)->value('used'),'Real limiter shares usage across interactive and background requests');
}
// Broken retained identity must not stop other account turns or independent producers.
$badAccount = \Paymenter\Extensions\Servers\Domains\Models\Account::where('server_id', $tlds[0]->server_id)->sole();
$retainedIdentity=$badAccount->getRawOriginal('stable_identity');
$batch=[];
for($i=0;$i<24;$i++){
    $t=$tlds[0]->replicate();$t->tld='batch'.$i.'.test';$t->save();$batch[]=$t;
}
app()->instance(\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::class,$native);
DB::table('domains_accounts')->where('id', $badAccount->id)->update(['stable_identity' => 'broken ciphertext']);
$failures = [];
\Illuminate\Support\Facades\Event::listen(\Illuminate\Log\Events\MessageLogged::class,
    static function ($event) use (&$failures): void {
        if ($event->message === 'Domains runtime work failed.') { $failures[] = $event->context; }
    });
$ports = new \Paymenter\Extensions\Servers\Domains\Services\RuntimePorts;
$independent = 0;
$ports->registerInitialManagedRecordObservations(static function () use (&$independent): void { $independent++; });
$runtime = new \Paymenter\Extensions\Servers\Domains\Services\RuntimeWork($clock, $ports);
$runtime->tick();
foreach ($tlds as $tld) {
    same($clock->now()->getTimestamp(), (int) DB::table('domains_runtime_turns')->where('server_id', $tld->server_id)->value('finished_at'),
        'Each account turn finishes despite a broken binding');
}
same(1, $independent, 'Independent managed observation producer still runs');
foreach (['registrar_balance', 'publication'] as $producer) {
    expect((bool) array_filter($failures, static fn ($f) => $f['producer'] === $producer && $f['row_id'] === (int) $badAccount->server_id),
        'Broken binding is recorded at its gate: '.$producer);
}
same(0,(int)DB::table('domains_runtime_turns')->where('server_id',$server)->value('tld_cursor'),'Failed publication gate retains the entire 25-row batch');
DB::table('domains_accounts')->where('id',$badAccount->id)->update(['stable_identity'=>$retainedIdentity]);
$clock->instant=$clock->now()->modify('+60 seconds');$runtime->tick();
same((int)end($batch)->id,(int)DB::table('domains_runtime_turns')->where('server_id',$server)->value('tld_cursor'),'Repair advances the retained full batch');
foreach($batch as $t){expect($native->product($t,'register')!==null,'Repair publishes every retained TLD');}
same(2,$independent,'Independent producer advances through repair');
// A per-TLD publication failure must retry before moving past a full batch.
$cursor=(int)end($batch)->id;$next=[];
for($i=0;$i<25;$i++){$t=$tlds[0]->replicate();$t->tld='retry'.$i.'.test';$t->save();$next[]=$t;}
$failed=$next[12];$rejectPublication=true;$rejected=0;
\App\Models\Product::creating(static function($product)use($failed,&$rejectPublication,&$rejected):void{
    if($rejectPublication&&$product->slug===\Paymenter\Extensions\Servers\Domains\Services\NativeCatalogue::slug($failed,'register')){$rejected++;throw new RuntimeException('Synthetic publication failure');}
});
$clock->instant=$clock->now()->modify('+60 seconds');$runtime->tick();
same(1,$rejected,'The synthetic failure reaches one TLD publication');
same($cursor,(int)DB::table('domains_runtime_turns')->where('server_id',$server)->value('tld_cursor'),'One failed TLD retains the full batch cursor');
expect($native->product($next[24],'register')!==null,'A failed TLD does not stop later publications in the batch');
expect((bool)array_filter($failures,static fn($f)=>$f['producer']==='publication'&&$f['row_id']===(int)$failed->id),'Failed publication retains its TLD diagnostic');
$rejectPublication=false;$clock->instant=$clock->now()->modify('+60 seconds');$runtime->tick();
same((int)$next[24]->id,(int)DB::table('domains_runtime_turns')->where('server_id',$server)->value('tld_cursor'),'Successful retry advances the full batch');
foreach($next as $t){expect($native->product($t,'register')!==null,'Retry publishes every TLD in the retained batch');}
echo "Runtime shared interactive/background request budget passed.\n";$nativeSuccessful=true;
