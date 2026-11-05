<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Illuminate\Database\Eloquent\Model;
/** Read-only staff projection over offers. No catalogue row is stored in this model. */
final class OfferRow extends Model
{
    public $timestamps=false;
    protected $table='domains_offer_rows';
    protected $guarded=[];
    /** Filament's public table getters return these records whole, so what a role may not see stays out of the JSON. */
    protected $hidden=['account_fingerprint','register_cost','renew_cost','transfer_cost','high_cost','prices'];
    /** MariaDB projects JSON booleans as 'false'/'true'; SQLite returns 0/1. */
    protected function transfer(): \Illuminate\Database\Eloquent\Casts\Attribute
    { return \Illuminate\Database\Eloquent\Casts\Attribute::get(static fn($value)=>$value===null?null:filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE)); }
    protected $casts=['longest'=>'integer','prices'=>'array','register_cost'=>'decimal:4','renew_cost'=>'decimal:4','transfer_cost'=>'decimal:4','high_cost'=>'decimal:4'];
}
