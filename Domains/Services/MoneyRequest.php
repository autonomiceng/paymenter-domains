<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
/** One money decision. Automatic invoice scope names queued work or explicitly declares a durable retry record
 * committed by its caller before returning Deferred. A staff review stamp covers only the full scope. */
final readonly class MoneyRequest
{
    public function __construct(public Domain $seed, public ?string $kind = null, public ?int $subject = null,
        public ?int $staffId = null, public bool $released = false, public MoneyScope $scope = MoneyScope::Full, public ?int $invoiceId = null, public ?string $retryRecord = null)
    {
        if (($scope === MoneyScope::Invoice) !== ($invoiceId !== null) || ($scope === MoneyScope::Invoice && ($staffId !== null || ($kind === null && ($retryRecord === null || trim($retryRecord) === ''))))) {
            throw new \LogicException('Invoice scope needs its invoice, its retry work and no staff review.');
        }
    }
}
