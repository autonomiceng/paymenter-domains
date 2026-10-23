<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Database\Eloquent\Model;
final class WatchNotice extends Model
{
    public $timestamps=false;
    protected $table='domains_watch_notices';
    protected $guarded=[];
    protected $hidden=['data'];
    protected $casts=['data'=>'encrypted:array','user_id'=>'integer','watch_id'=>'integer','managed_domain_id'=>'integer','observation_id'=>'integer','threshold'=>'integer'];
}
