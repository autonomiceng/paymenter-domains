<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\LiveCatalogueSchema;
return new class extends Migration {
    public function up(): void {LiveCatalogueSchema::install();}
    // Refuse rollback before Laravel deletes the migration record. Retaining only the column
    // would make a legitimate rollback indistinguishable from an unverified partial restore.
    public function down(): void { throw new \LogicException; }
};
