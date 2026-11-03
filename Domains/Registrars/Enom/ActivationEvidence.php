<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\Enom;
use Paymenter\Extensions\Servers\Domains\Contracts\LiveActivationEvidence;
use Paymenter\Extensions\Servers\Domains\Data\AccountBinding;
use Paymenter\Extensions\Servers\Domains\Services\EnomLive;
/** Preserve Enom deployment, evidence and pilot policy without changing stored receipts. */
final class ActivationEvidence implements LiveActivationEvidence
{
    public function deployment(AccountBinding $binding): bool {return EnomLive::deployment($binding);}
    public function site(): string {return EnomLive::site();}
    public function account(AccountBinding $binding): array {return EnomLive::account($binding);}
    public function enabled(AccountBinding $binding,string $feature): bool {return EnomLive::enabled($binding,$feature);}
    public function label(): string {return 'Enom';}
    public function configuration(): array {return [config('domains.enom_live'),config('domains.enom_identity.environment')];}
    public function requireControls(array $evidence): void {}
    public function transferProofRequired(): bool {return true;}
    public function receiptContext(AccountBinding $binding): array {return [];}
}
