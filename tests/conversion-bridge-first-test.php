<?php
// Bridge-first conversion: this package's converter only verifies a finished target. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
const CONVERSION_DEFER_STAGE=true;
require __DIR__.'/Support/conversion.php';
require __DIR__.'/Support/retained-offer.php';
use DomainsConversion\Converter;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Catalogue\UpgradeGate;
use Paymenter\Extensions\Servers\Domains\Services\OfferRetirement;

$migrations=glob(dirname(__DIR__).'/Domains/database/migrations/*.php');
$domains=static fn():array=>[array_values(array_filter(Schema::getTableListing(null,false),fn($t)=>str_starts_with($t,'domains_'))),
    DB::table('migrations')->whereIn('migration',array_map(fn($file)=>basename($file,'.php'),$migrations))->count()];
same([[],0],$domains(),'Retained source starts without Domains schema');
conversionRefuses(fn()=>Converter::stageSchema(),'Direct staging names the pinned bridge','Domains bridge 0.1.0 at 891124421645');
same([[],0],$domains(),'Refused staging leaves no partial foundation');
conversionRefuses(fn()=>Converter::stageSchema(),'Direct staging gives the bridge-first steps',Converter::BRIDGE_FIRST);
conversionRefuses(fn()=>(new Converter($evidence,$maintenance))->run(),'Dry run gives the bridge-first steps',Converter::BRIDGE_FIRST);
same([[],0],$domains(),'Refused dry run leaves no Domains schema');

app(Illuminate\Database\Migrations\Migrator::class)->run([$migrations[0]],['force'=>true]);$partial=$domains();
conversionRefuses(fn()=>Converter::stageSchema(),'Partial foundation is never repaired','Target migration history incomplete');
same($partial,$domains(),'Refused partial foundation runs no further migrations');

$saved=retainedOfferDatabase();
conversionRefuses(fn()=>Converter::stageSchema(),'Checked bridge needs this package installed first','Target migration history incomplete');
expect(!OfferRetirement::started()&&Schema::hasTable('domains_sell_rows'),'Refused verification leaves the bridge stores to the package upgrade');
(new Paymenter\Extensions\Servers\Domains\Domains)->upgraded('0.1.0');
Converter::stageSchema();
expect(OfferRetirement::finished()&&!Schema::hasTable('domains_sell_rows'),'Checked bridge upgrade finishes offer retirement');
same($saved['receipt'],UpgradeGate::routeState(),'Verification keeps the checked bridge receipt');
$nativeSuccessful=true;echo "Bridge-first conversion staging verified.\n";
