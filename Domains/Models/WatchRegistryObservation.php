<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
final class WatchRegistryObservation extends Model
{
    protected $table='domains_watch_registry'; public $timestamps=false; protected $guarded=[];
    protected $hidden=['facts','stamp'];
    protected $casts=['facts'=>'encrypted:array','started_at'=>'integer','completed_at'=>'integer','valid_until'=>'integer'];
    protected static function booted(): void
    {
        static::creating(static function(self $r):void {
            if (DB::transactionLevel()<1 || !in_array($r->result,['facts','unknown','not_registered'],true) || !is_array($r->facts)
                || $r->completed_at<$r->started_at || $r->completed_at>=$r->started_at+45
                || ($r->result!=='facts' && $r->valid_until!==null)) { throw new \DomainException('Invalid registry observation.'); }
        });
        static::updating(static function():never { throw new \DomainException('Registry facts are immutable.'); });
        static::deleting(static function():never { throw new \DomainException('Registry history is retained.'); });
    }
    protected function incrementOrDecrement($column,$amount,$extra,$method):never { throw new \DomainException('Registry facts are immutable.'); }
}
