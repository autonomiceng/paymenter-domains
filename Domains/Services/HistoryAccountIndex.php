<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
/** Bound history locks and account cursor reads to the requested owner. */
final class HistoryAccountIndex
{
    public const MIGRATION='2026_11_11_000059_index_history_account_scans';
    public const COLUMNS=[
        'domains_history_links'=>['domain_id','id'],
        'domains_watch_audit'=>['subject_type','subject_id','event','id'],
        'domains_domains'=>['server_id','id'],
        'domains_operations'=>['server_id','account_fingerprint','id'],
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
            if(Schema::hasTable($table) && !Schema::hasIndex($table,$columns)){throw new \RuntimeException('History or account index is missing.');}
        }
    }
}
