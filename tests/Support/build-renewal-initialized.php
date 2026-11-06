<?php
// Generator for the empty native component fixture; run through the pinned native harness.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require_once __DIR__.'/renewal-initialized.php';
function buildRenewalInitializedFixture(): array
{
    (new Paymenter\Extensions\Servers\Domains\Services\Installation)->install();
    $db=Illuminate\Support\Facades\DB::connection();$schema=[];$rows=[];
    foreach($db->select("SELECT name,sql FROM sqlite_master WHERE type='table' AND name LIKE 'domains_%' ORDER BY name") as $table){
        $schema[]=$table->sql;$rows[$table->name]=array_map(static fn($r)=>(array)$r,$db->table($table->name)->get()->all());
    }
    foreach($db->select("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name LIKE 'domains_%' AND sql IS NOT NULL ORDER BY name") as $index){$schema[]=$index->sql;}
    $rows['settings']=array_map(static fn($r)=>(array)$r,$db->table('settings')->where('key','like','domains%')->get()->all());
    $rows['notification_templates']=array_map(static fn($r)=>(array)$r,$db->table('notification_templates')->where('key','like','domains%')->get()->all());
    $rows['migrations']=array_map(static fn($r)=>(array)$r,$db->table('migrations')->whereIn('migration',array_keys(app(Illuminate\Database\Migrations\Migrator::class)->getMigrationFiles([dirname(__DIR__,2).'/Domains/database/migrations'])))->get()->all());
    return ['runtime_sha256'=>renewalRuntimeDigest(),'paymenter_commit'=>DOMAINS_TEST_NATIVE_COMMIT,'schema'=>$schema,'rows'=>$rows];
}

// Including the generator in a native witness exposes the same real-installer payload without file output.
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    require dirname(__DIR__).'/native-app.php';
    file_put_contents($argv[1].'/renewal-initialized.json',json_encode(buildRenewalInitializedFixture(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
    $nativeSuccessful=true;
}
