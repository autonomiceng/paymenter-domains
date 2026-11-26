<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Money\Allocations;

use Illuminate\Database\Schema\Blueprint;
use Paymenter\Extensions\Servers\Domains\Installation\TableSchema;

final class RenewalAllocationSchema extends TableSchema
{
    // Retained paid renewals already use this key. Keep their exact postings readable across installation.
    public const TABLE = 'domains_renewal_funding';

    protected static function definitions(): array
    {
        return [self::TABLE => static function (Blueprint $t): void {
            $t->unsignedBigInteger('invoice_item_id');
            $t->unsignedTinyInteger('retry_number');
            $t->enum('source', array_column(PostingSource::cases(), 'value'));
            $t->unsignedBigInteger('source_id');
            $t->bigInteger('amount_minor');
            $t->primary(['invoice_item_id', 'retry_number', 'source', 'source_id']);
        }];
    }
}
