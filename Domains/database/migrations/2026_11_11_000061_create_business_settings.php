<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\BusinessSettingsSchema;
return new class extends Migration {
    public function up(): void { BusinessSettingsSchema::install(); }
    public function down(): void { throw new LogicException('Global domain choices and import history are retained.'); }
};
