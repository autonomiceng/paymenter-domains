<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void {\Paymenter\Extensions\Servers\Domains\Services\RuntimeSchema::install();}
    public function down(): void {} // Retain cursors, liveness and account identity.
};
