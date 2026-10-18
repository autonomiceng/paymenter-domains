<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;

use Illuminate\Database\Eloquent\Model;

class Price extends Model
{
    protected $table = 'domains_prices';
    protected $guarded = [];
    protected $hidden = ['account_fingerprint', 'wholesale', 'source', 'eligibility'];
    protected $casts = ['wholesale' => 'decimal:8', 'retail' => 'decimal:8', 'revision' => 'integer', 'term' => 'integer', 'eligibility' => 'array', 'observed_at' => 'immutable_datetime', 'fresh_until' => 'immutable_datetime'];
}
