<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Contracts;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Models\Account;
/** Provider policy port for the local activation engine. Implementations perform no provider requests. */
interface LiveActivationEvidence
{
    public function deployment(AccountBinding $binding): bool;
    public function site(): string;
    public function account(AccountBinding $binding): array;
    public function enabled(AccountBinding $binding,string $feature): bool;
    public function label(): string;
    /** Local deployment inputs included in a displayed review's stamp. */
    public function configuration(Account $account): array;
    /** Provider-only portal controls, retained in the dated evidence hash. */
    public function requireControls(array $evidence): void;
    public function transferProofRequired(): bool;
    /** Additional current receipt binding, such as credential/runtime revisions. */
    public function receiptContext(AccountBinding $binding): array;
}
