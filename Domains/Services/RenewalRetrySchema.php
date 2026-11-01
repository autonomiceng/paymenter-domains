<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
/** One retained operation per paid debt and retry number; ordinary operations keep zero. */
final class RenewalRetrySchema
{
    public const MIGRATION='2026_10_31_000046_add_renewal_retry_number';
    public static function install(): void
    {
        if(Schema::hasColumn('domains_operations','retry_number')){self::verify();return;}
        FoundationSchema::verify();
        $indexes=array_values(array_filter(Schema::getIndexes('domains_operations'),fn($i)=>$i['unique']&&$i['columns']===['invoice_item_id','action']));
        if(count($indexes)!==1){throw new \RuntimeException('Retained renewal debt uniqueness changed.');}
        $old=$indexes[0]['name'];$c=DB::connection();
        if(in_array($c->getDriverName(),['mysql','mariadb'],true)){
            // One atomic DDL statement: no crash window without either debt uniqueness key.
            $g=$c->getQueryGrammar();
            $c->statement('ALTER TABLE '.$g->wrapTable('domains_operations').' ADD COLUMN '.$g->wrap('retry_number').' TINYINT UNSIGNED NOT NULL DEFAULT 0, DROP INDEX '.$g->wrap($old).', ADD UNIQUE KEY '.$g->wrap('domains_operations_debt_retry_u').' ('.$g->columnize(['invoice_item_id','action','retry_number']).')');
        }elseif($c->getDriverName()==='sqlite'){
            DB::transaction(fn()=>Schema::table('domains_operations',function(Blueprint $t)use($old):void{
                $t->unsignedTinyInteger('retry_number')->default(0);$t->dropUnique($old);
                $t->unique(['invoice_item_id','action','retry_number'],$t->getTable().'_debt_retry_u');
            }));
        }else{throw new \RuntimeException('Renewal retry storage requires SQLite or MySQL/MariaDB.');}
        self::verify();
    }
    public static function verify(): void
    {
        if(!Schema::hasColumn('domains_operations','retry_number')){throw new \RuntimeException('Retained renewal retry storage is missing.');}
        FoundationSchema::verify();
    }
}
