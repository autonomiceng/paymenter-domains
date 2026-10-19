<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\WatchDnsSchema;
return new class extends Migration {
    public function up(): void { WatchDnsSchema::install(); }
    public function down(): void {} // Retain profile, public evidence and work history.
};
