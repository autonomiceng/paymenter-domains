<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\FoundationSchema;

return new class extends Migration {
    public function up(): void { FoundationSchema::install(); }
    // Uninstall and rollback retain financial evidence and global reservations.
    public function down(): void {}
};
