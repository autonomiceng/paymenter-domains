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

    public const MODES = ['fixed', 'markup_amount', 'markup_percent', 'markup_fee_percent'];
    public const ACTIONS = ['register', 'renew', 'transfer'];

    use HasAccountBinding;

    protected $table = 'domains_tlds';
    protected $guarded = [];
    protected $hidden = ['catalogue_intent', 'catalogue_facts', 'catalogue_issues', 'account_fingerprint', 'markup', 'catalogue_revision', 'catalogue_published_revision', 'catalogue_retained_annual_retail', 'catalogue_converted', 'catalogue_created'];
    protected $casts = [
        'use_default_rule' => 'boolean', 'markup' => 'decimal:2', 'fixed_register' => 'decimal:2', 'fixed_renew' => 'decimal:2', 'fixed_transfer' => 'decimal:2',
        'sort_order' => 'integer',
        'catalogue_retained_annual_retail' => 'decimal:2',
        'registry_fields' => 'array',
    ];

    public function server() { return $this->belongsTo(Server::class); }
    public function getDisplayNameAttribute(): string { return '.'.$this->tld; }

}
