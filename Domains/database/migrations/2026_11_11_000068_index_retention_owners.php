<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\RetentionOwnerIndex;
return new class extends Migration {
    public function up(): void {RetentionOwnerIndex::install();}
    public function down(): void {throw new RuntimeException('Customer history owner indexes are retained.');}
};
