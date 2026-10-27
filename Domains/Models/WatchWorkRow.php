<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
/** Read model only. Scheduler and staff writes retain their transaction boundaries. */
final class WatchWorkRow extends \Illuminate\Database\Eloquent\Model
{
    protected $table='domains_watch_work';
    public $timestamps=false;
    protected $guarded=['*'];
    protected $hidden=['claim'];
    protected static function booted(): void {static::saving(static fn()=>throw new \DomainException('Use the scheduled work boundary.'));static::deleting(static fn()=>throw new \DomainException('Use the scheduled work boundary.'));}
}
