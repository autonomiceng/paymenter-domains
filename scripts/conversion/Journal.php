<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\SchemaVerifier;
/** DDL is deliberately outside the atomic data cutover. Existing journal metadata is verified, never repaired. */
final class Journal
{
    private static function definitions(): array
    {
        return ['domains_conversion_runs'=>static function(Blueprint $t):void {$t->id();$t->string('source_digest',64);$t->string('plan_digest',64);$t->string('state',24);$t->text('summary');$t->longText('audit');$t->string('result_digest',64)->nullable();},
            'domains_conversion_map'=>static function(Blueprint $t):void {$t->id();$t->string('target_table',64);$t->unsignedBigInteger('source_id');$t->unsignedBigInteger('target_id');$t->longText('body');$t->string('digest',64);$t->unique(['target_table','source_id']);}];
    }
    public static function install(): void
    {
        foreach(self::definitions()as$name=>$definition){if(!Schema::hasTable($name)){Schema::create($name,$definition);}SchemaVerifier::verify($name,$definition);}self::verify();
    }
    public static function verify(): void
    {
        foreach(self::definitions()as$name=>$definition){if(!Schema::hasTable($name)){throw new \DomainException('Partial conversion journal DDL; resume schema staging.');}SchemaVerifier::verify($name,$definition);}self::ownership();
    }
    private static function ownership(): void
    {
        if(DB::table('domains_conversion_runs')->where('id','!=',1)->exists()||(DB::table('domains_conversion_map')->exists()&&!DB::table('domains_conversion_runs')->where('id',1)->exists())){throw new \DomainException('Invalid conversion journal ownership.');}
    }
    public static function stage(Planner $plan,array $summary,array $audit,?\Closure $checkpoint): void
    {
        self::install();$run=DB::table('domains_conversion_runs')->where('id',1)->first();
        if($run&&($run->source_digest!==$summary['source_digest']||$run->plan_digest!==$summary['plan_digest']||!in_array($run->state,['staging','staged'],true)||json_decode($run->summary,true,64,JSON_THROW_ON_ERROR)!==$summary||Inputs::decrypt($run->audit)!==$audit)){throw new \DomainException('Source or staged plan changed; restore/review before resuming.');}
        if(!$run){DB::table('domains_conversion_runs')->insert(['id'=>1,'source_digest'=>$summary['source_digest'],'plan_digest'=>$summary['plan_digest'],'state'=>'staging','summary'=>json_encode($summary,JSON_THROW_ON_ERROR),'audit'=>Inputs::encrypt($audit)]);}
        foreach($plan->rows as$table=>$rows){foreach($rows as$id=>$row){$digest=Inputs::digest($row);$q=DB::table('domains_conversion_map')->where('target_table',$table)->where('source_id',$id);$prior=$q->first();
            if($prior){if((int)$prior->target_id!==$id||$prior->digest!==$digest||Inputs::decrypt($prior->body)!==$row){throw new \DomainException('Staged row verification failed.');}}
            else{DB::table('domains_conversion_map')->insert(['target_table'=>$table,'source_id'=>$id,'target_id'=>$id,'body'=>Inputs::encrypt($row),'digest'=>$digest]);}$checkpoint?->__invoke('stage:'.$table.':'.$id);}}
        if(DB::table('domains_conversion_map')->count()!==array_sum(array_map('count',$plan->rows))){throw new \DomainException('Unexpected staged ID map.');}
        DB::table('domains_conversion_runs')->where('id',1)->update(['state'=>'staged']);
    }
}
