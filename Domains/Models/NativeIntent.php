<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
/** Owned retained protection, never a native cancellation or execution grant. */
final class NativeIntent extends \Illuminate\Database\Eloquent\Model
{
    protected $table='domains_native_intents';
    protected $primaryKey='service_id';
    protected $guarded=[];
    public $incrementing=false;
    public $timestamps=false;
}
