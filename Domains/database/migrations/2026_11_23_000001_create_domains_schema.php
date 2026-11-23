<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Paymenter\Extensions\Servers\Domains\Services\{FreshInstallation, Installation};

return new class extends Migration {
    public function up(): void
    {
        if (!FreshInstallation::recovering()) {
            throw new \DomainException('Domains schema creation requires the owning installation intent.');
        }
        foreach (Installation::SCHEMAS as $schema) {
            $schema::install();
        }
    }

    public function down(): void
    {
        throw new \DomainException('The Domains schema cannot be rolled back because it retains ownership, operations and billing history.');
    }
};
