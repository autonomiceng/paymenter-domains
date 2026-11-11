<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Database\Eloquent\Model;
/** One row owns extension-wide choices. Each named column retains the source type and bytes on import. */
final class BusinessSettings extends Model
{
    protected $table='domains_business_settings';
    public $timestamps=false;
    public $incrementing=false;
    protected $guarded=[];
    protected $casts=['pricing_rule'=>'array','card_fee'=>'array','dns_template'=>'array','imported'=>'boolean'];
    protected static function booted(): void
    {
        static::saving(static function(self $row): void {
            if((int)$row->id!==1){throw new \LogicException('Global domain choices require singleton 1.');}
        });
        static::deleting(static function(): never {throw new \LogicException('Global domain choices are retained.');});
    }
}
