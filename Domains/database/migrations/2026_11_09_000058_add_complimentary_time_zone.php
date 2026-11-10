<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\ComplimentaryTimeSchema;
return new class extends Migration {
    public function up(): void {ComplimentaryTimeSchema::install();}
    // Refuse rollback before Laravel deletes the migration record: new rows depend on their UTC marker.
    public function down(): void { throw new \LogicException; }
};
