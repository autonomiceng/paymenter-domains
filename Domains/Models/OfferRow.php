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
    protected $casts=['longest'=>'integer','transfer'=>'boolean','prices'=>'array','register_cost'=>'decimal:4','renew_cost'=>'decimal:4','transfer_cost'=>'decimal:4','high_cost'=>'decimal:4'];
}
