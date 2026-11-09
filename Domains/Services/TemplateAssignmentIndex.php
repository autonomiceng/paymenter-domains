<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
/** Indexes the DNS template assignments, so a template's dependency check reads only that template's rows. */
final class TemplateAssignmentIndex
{
    public const MIGRATION='2026_11_09_000057_index_template_assignments';
    public const COLUMNS=[
        'domains_watch_names'=>['ns_profile_id','state'],
        'domains_managed_public_config'=>['profile_id'],
        'domains_watch_product_maps'=>['expected_ns_profile_id'],
    ];
    /** New tables carry the index. A retained table keeps its earlier index set until the additive migration runs. */
    public static function define(Blueprint $t,string $table): void
    {
        if(!Schema::hasTable($table) || Schema::hasIndex($table,self::COLUMNS[$table])){$t->index(self::COLUMNS[$table]);}
    }
    public static function install(): void
    {
        foreach(self::COLUMNS as $table=>$columns){
            if(Schema::hasTable($table) && !Schema::hasIndex($table,$columns)){Schema::table($table,static fn(Blueprint $t)=>$t->index($columns));}
        }
        self::verify();
    }
    public static function verify(): void
    {
        if(!Schema::hasTable('migrations') || !DB::table('migrations')->where('migration',self::MIGRATION)->exists()){return;}
        foreach(self::COLUMNS as $table=>$columns){
            if(Schema::hasTable($table) && !Schema::hasIndex($table,$columns)){throw new \RuntimeException('DNS template assignment index is missing.');}
        }
    }
}
