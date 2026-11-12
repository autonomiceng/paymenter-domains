<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** Reuse installation work only while the native fixture is still completely untouched. */
function restoreCommerceDatabase(): bool
{
    $path=(string)getenv('PAYMENTER_TEST_COMMERCE_DATABASE');
    $baseline=$GLOBALS['nativeFixtureDatabase']??null;
    $connection=Illuminate\Support\Facades\DB::connection();$pdo=$connection->getPdo();
    if($path===''||$baseline===null||$baseline['pdo']!==$pdo||$pdo->inTransaction()
        ||$baseline['changes']!==(int)$pdo->query('SELECT total_changes()')->fetchColumn()
        ||$baseline['schema']!==(int)$pdo->query('PRAGMA schema_version')->fetchColumn()
        ||config('audit.enabled')!==false||Carbon\Carbon::getTestNow()!==null
        ||app(Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->format(DATE_ATOM)!=='2026-10-18T12:00:00+00:00'){
        return false;
    }
    $sql=file_get_contents($path);
    same((string)getenv('PAYMENTER_TEST_COMMERCE_SHA256'),hash('sha256',$sql),'Installed fixture snapshot retains admitted bytes');
    $objects=$pdo->query("SELECT type,name FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%' ORDER BY type='view' DESC")->fetchAll(PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys=OFF');
    foreach($objects as $object){$pdo->exec('DROP '.strtoupper($object['type']).' "'.str_replace('"','""',$object['name']).'"');}
    $pdo->exec($sql);$connection->useDefaultSchemaGrammar();
    expect(preg_match('/^-- booted-models: (.+)$/m',$sql,$models)===1,'Installed snapshot records migration model boot order');
    foreach(json_decode($models[1],true,512,JSON_THROW_ON_ERROR) as $model){
        expect(is_subclass_of($model,Illuminate\Database\Eloquent\Model::class),'Installed snapshot model is an Eloquent model');new $model;
    }
    Paymenter\Extensions\Servers\Domains\Services\Runtime::register();
    Paymenter\Extensions\Servers\Domains\Catalogue\CallerRouting::reset();
    return true;
}
