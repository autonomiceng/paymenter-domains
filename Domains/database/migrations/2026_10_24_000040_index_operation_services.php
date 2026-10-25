<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasIndex('domains_operations', ['service_id'])) {
            Schema::table('domains_operations', static fn (Blueprint $table) => $table->index('service_id'));
        }
    }
    public function down(): void {}
};
