<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\WatchAuditTimeSchema;
return new class extends Migration {
    public function up(): void { WatchAuditTimeSchema::install(); }
    public function down(): void { throw new LogicException('Watch audit time zones are retained.'); }
};
