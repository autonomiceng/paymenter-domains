<?php
// Adapted from Enom d2c55e2 Models/Tld.php; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

namespace Paymenter\Extensions\Servers\Domains\Models;

use App\Models\Server;
use Illuminate\Database\Eloquent\Model;

/** One ending, its pricing rule and the last exact-term wholesale costs. */
class Tld extends Model
{
    use InvalidatesStorefront;

    public const MODES = ['fixed', 'markup_amount', 'markup_percent'];
    public const ACTIONS = ['register', 'renew', 'transfer'];

    use HasAccountBinding;

    protected $table = 'domains_tlds';
    protected $guarded = [];
    protected $hidden = ['account_fingerprint', 'cost_register_terms', 'cost_renew', 'cost_transfer', 'markup'];
    protected $casts = [
        'enabled' => 'boolean', 'register_enabled' => 'boolean', 'renew_enabled' => 'boolean', 'transfer_enabled' => 'boolean',
        'use_default_rule' => 'boolean', 'markup' => 'decimal:2', 'renew_price' => 'decimal:2',
        'fixed_register' => 'decimal:2', 'fixed_renew' => 'decimal:2', 'fixed_transfer' => 'decimal:2',
        'cost_register_terms' => 'array', 'sale_holds' => 'array', 'cost_renew' => 'decimal:4', 'cost_transfer' => 'decimal:4',
        'costs_synced_at' => 'immutable_datetime', 'sort_order' => 'integer',
        'min_years' => 'integer', 'max_years' => 'integer', 'registry_fields' => 'array',
    ];

    public function server() { return $this->belongsTo(Server::class); }
    public function getDisplayNameAttribute(): string { return '.'.$this->tld; }

}
