<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';require __DIR__.'/Support/FakeRegistrar.php';
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Admin\Support\SettingsActions;
use Paymenter\Extensions\Servers\Domains\Data\Environment;
use Paymenter\Extensions\Servers\Domains\Services\{CatalogueImportFailure,EnomTestCalls,EnomTestCallLimit,EnomTestCallSchema,ReadFailure};
app()->instance(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class,new Paymenter\Extensions\Servers\Domains\Tests\FakeClock(new DateTimeImmutable('2026-10-24T12:00:00Z')));
EnomTestCallSchema::install();config(['domains.enom_test_daily_call_cap'=>10]);
$problems=[];
foreach([new DomainException('Catalogue import unavailable.'),...array_map(fn($code)=>new CatalogueImportFailure($code),ReadFailure::CODES)] as $failure){
    $copy=SettingsActions::refusal($failure,'import');
    if(str_contains($copy,'No prices changed')||!str_contains($copy,'Check saved prices')){$problems[]='Incomplete import must direct staff to saved prices without an unchanged-price guarantee';}
}
// A readable counter does not prove its reservation writes are available.
DB::table(EnomTestCallSchema::TABLE)->insert(['day'=>EnomTestCalls::day(),'used'=>2]);
$fault=true;DB::listen(static function($query)use(&$fault):void{if($fault&&str_starts_with(strtolower($query->sql),'insert')&&str_contains($query->sql,EnomTestCallSchema::TABLE)){throw new RuntimeException('Synthetic counter write failure');}});
try{EnomTestCalls::reserve(Environment::Test);throw new LogicException('Reservation must refuse');}catch(EnomTestCallLimit){}
$copy=EnomTestCalls::refusedFacts();
if(str_contains($copy,'you can retry')||!str_contains($copy,'repair')||!str_contains($copy,'UTC day')){$problems[]='Readable remaining calls must explain repair or UTC day rollover without promising a retry';}
$fault=false;same(2,(int)DB::table(EnomTestCallSchema::TABLE)->value('used'),'Refused write rolls back the counter');
DB::table(EnomTestCallSchema::TABLE)->update(['used'=>10]);
expect(str_contains(EnomTestCalls::refusedFacts(),'Limit reached'),'Exhaustion retains its daily limit recovery');
DB::statement('DROP TABLE '.EnomTestCallSchema::TABLE);
$copy=EnomTestCalls::refusedFacts();
if(!str_contains($copy,'repair')||!str_contains($copy,'read and updated')){$problems[]='Unreadable counter requires repair of both reads and writes';}
expect($problems===[],implode('; ',array_unique($problems)));
echo "Staff import and counter recovery copy passed.\n";$nativeSuccessful=true;
