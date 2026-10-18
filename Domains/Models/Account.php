<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;

use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $table = 'domains_accounts';
    protected $guarded = [];
    protected $hidden = ['account_fingerprint', 'stable_identity', 'identity_fingerprint', 'live_site'];
    protected $casts = ['stable_identity' => 'encrypted', 'processing_enabled' => 'boolean'];
}
