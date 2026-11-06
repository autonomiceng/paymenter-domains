<?php
// Synthetic native harness probe only; no Domains entry point is installed.
declare(strict_types=1);
require __DIR__.'/native-app.php';
if (in_array('--fail-after-boot', $argv, true)) { throw new RuntimeException('synthetic native failure'); }

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

foreach (['users', 'services', 'invoices', 'invoice_items', 'invoice_transactions', 'settings'] as $table) {
    expect(Schema::hasTable($table), 'Native migration missing: '.$table);
}
$user = App\Models\User::create(['email' => 'fixture@example.invalid', 'password' => bcrypt('synthetic')]);
same('fixture@example.invalid', App\Models\User::findOrFail($user->id)->email, 'Native model readback');
try {
    DB::transaction(static function (): void {
        App\Models\User::create(['email' => 'rollback@example.invalid', 'password' => bcrypt('synthetic')]);
        throw new RuntimeException('synthetic rollback');
    });
} catch (RuntimeException $error) {
    same('synthetic rollback', $error->getMessage(), 'Transaction refusal');
}
same(1, App\Models\User::count(), 'Native transaction rolled back');
expect(!class_exists('Paymenter\\Extensions\\Servers\\Domains\\Domains', false), 'Foundation has no entry point');
try { Illuminate\Support\Facades\Http::setHandler(static function (): never { throw new RuntimeException('Synthetic terminal handler should not run'); })->get('https://example.invalid'); expect(false, 'Stray HTTP escaped fixture'); }
catch (Illuminate\Http\Client\StrayRequestException) { /* expected */ }
echo "Native fixture: pinned upstream migrations, model, rollback and HTTP guard passed; no Domains install.\n";

// Real model casts are required to prove that reads preserve old/new encrypted payloads.
use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding,Environment};
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings,ObservationRecords,RegistrarRegistry,Rdap,RenewalAcceptance,RuntimeComposition,RuntimePorts,TransferPrecheck,WriteAcceptance,WriteFeatures};
$codecClock=new class implements \Paymenter\Extensions\Servers\Domains\Contracts\Clock {
    public function now():DateTimeImmutable{return new DateTimeImmutable('2026-11-04T12:00:00+00:00');}
};
app()->instance(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,$codecClock);
foreach (['enom','namesilo'] as $registrar) {
    $codecDomain=new Domain(['id'=>1,'domain'=>'example.com','registrar_key'=>$registrar,'server_id'=>1,
        'account_fingerprint'=>str_repeat('a',64),'authority_generation'=>1,'provider_domain_id'=>'1']);
    $stored=['identity'=>ObservationRecords::identity($codecDomain),'at'=>'2026-11-04T12:00:00+00:00',
        'sequence'=>7,'families'=>array_fill_keys(['dns','srv','ds','glue'],['state'=>'complete','records'=>[]])];
    foreach ([false,true] as $versioned) {
        $payload=$versioned?$stored+['format'=>1,'capability_revision'=>$registrar.'-records-1',
            'expected_families'=>['dns'=>'supported','srv'=>'unsupported','ds'=>'unsupported','glue'=>'supported']]:$stored;
        $codecDomain->observation=['management_sequence'=>7,'records'=>$payload];$cipher=$codecDomain->getAttributes()['observation'];
        expect(!str_contains($cipher,'families'),'Observation payload uses real encrypted model cast');
        $decoded=ObservationRecords::current($codecDomain);
        same($versioned||$registrar==='enom'?'complete':'unknown',$decoded['families']['dns']['state'],'Encrypted old/new provider inventory is readable');
        if ($versioned) {same('unknown',$decoded['families']['ds']['state'],'Encrypted unsupported DS never becomes complete empty');}
        same($cipher,$codecDomain->getAttributes()['observation'],'Reading never backfills encrypted payload');
    }
    $future=$stored;$future['at']=$codecClock->now()->modify('+1 second')->format(DATE_ATOM);
    $codecDomain->observation=['management_sequence'=>7,'records'=>$future];
    same(null,ObservationRecords::current($codecDomain),'Future encrypted inventory grants no record authority');
    $ahead=$stored;$ahead['sequence']=8;$codecDomain->observation=['management_sequence'=>7,'records'=>$ahead];
    same(null,ObservationRecords::current($codecDomain),'Inventory beyond the management watermark grants no record authority');
    $payload['format']=99;$codecDomain->observation=['management_sequence'=>7,'records'=>$payload];
    same(null,ObservationRecords::current($codecDomain),'Unknown encrypted record version refuses expansion');
    $payload['format']=1;$payload['identity']['generation']=99;$codecDomain->observation=['management_sequence'=>7,'records'=>$payload];
    same(null,ObservationRecords::current($codecDomain),'New encrypted format preserves the identity guard');
}
$codecBinding=new AccountBinding('enom',1,Environment::Test,'synthetic-uid');
$codecBindings=new AccountBindings(new RegistrarRegistry);
$codecCalls=0;$noWire=static function()use(&$codecCalls):never{$codecCalls++;throw new RuntimeException('No provider access');};
$codecManagement=new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Management($codecBinding,$noWire);
$codecForeign=new \Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Management(new AccountBinding('namesilo',2,Environment::Test,'other'),$noWire);
$codecMake=static fn($reader=null)=>RuntimeComposition::make($codecBinding,$codecBindings,$codecClock,new WriteFeatures,
    new WriteAcceptance($codecBinding),new RenewalAcceptance($codecBinding),new TransferPrecheck(new Rdap($noWire)),
    new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Registration($codecBinding,$noWire),
    new \Paymenter\Extensions\Servers\Domains\Registrars\Enom\Transfer($codecBinding,$noWire),$noWire,$codecManagement,observationReader:$reader);
$codecComposition=$codecMake();
same(array_keys(RuntimePorts::TYPES),array_keys($codecComposition),'Default runtime composition retains seven roles');
same($codecManagement,$codecMake($codecManagement)['observation_reader'],'Reader injection is explicit and account bound');
try{$codecMake($codecForeign);expect(false,'Foreign observation reader must refuse factory composition');}catch(DomainException){}
$codecAccount=new Account(['registrar_key'=>'enom','server_id'=>1,'environment'=>'test','stable_identity'=>'synthetic-uid',
    'account_fingerprint'=>$codecBinding->fingerprint]);
$codecPorts=new RuntimePorts;$codecPorts->register($codecBinding->fingerprint,static fn()=>$codecComposition);
expect($codecPorts->accepted($codecAccount),'Legacy seven-role deployment factories retain management discovery');
$codecComposition['observation_reader']=$codecForeign;
$codecPorts=new RuntimePorts;$codecPorts->register($codecBinding->fingerprint,static fn()=>$codecComposition);
expect(!$codecPorts->accepted($codecAccount),'Foreign reader binding closes runtime acceptance');
same(0,$codecCalls,'Local reader contracts never reach a provider');
echo "Demo C encrypted readers and runtime compatibility passed.\n";

$nativeSuccessful = true;
