<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
require __DIR__.'/native-app.php';
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\{ManagedPublicWorkSchema,ManagedPublicSchema};
$paths=array_filter(glob(dirname(__DIR__).'/Domains/database/migrations/*.php'),static fn($p)=>!str_contains($p,'000030'));
app(Illuminate\Database\Migrations\Migrator::class)->run(array_values($paths),['force'=>true]);
$entry=new Paymenter\Extensions\Servers\Domains\Domains([]);
DB::table(ManagedPublicWorkSchema::TABLE)->insert(['domain_id'=>7,'user_id'=>8,'generation'=>2,
    'account_fingerprint'=>str_repeat('a',64),'kind'=>'delegation','due_at'=>1800000000,
    'profile_id'=>9,'profile_revision'=>3,'origin_watch_id'=>10,'origin_observation_id'=>11,'target_stamp'=>str_repeat('b',64)]);
$before=(array)DB::table(ManagedPublicWorkSchema::TABLE)->sole();
$entry->installed(); $entry->installed();
$after=(array)DB::table(ManagedPublicWorkSchema::TABLE)->sole();
foreach($before as $key=>$value){same($value,$after[$key],'Original enrollment value preserved: '.$key);}
same('awaiting_acceptance',$after['safe_code'],'Migration never accepts dormant work');same(null,$after['domain_revision'],'No authority stamp backfill');
same(null,$after['claim'],'Migration claims no work');same(0,(int)$after['attempts'],'No DNS attempt fabricated');
same(0,DB::table('domains_managed_public_observations')->count(),'Migration copies no watch facts');
Schema::drop('domains_managed_public_config');
$refused=false;try{ManagedPublicSchema::install();}catch(RuntimeException){$refused=true;}
expect($refused,'Partial managed upgrade refuses repair');
$nativeSuccessful=true;echo "Original dormant enrollment survives additive upgrade without acceptance or fabricated facts.\n";
