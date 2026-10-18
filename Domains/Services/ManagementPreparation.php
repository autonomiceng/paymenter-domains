<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
/** Preparation can enrich explicitly named provider facts, never replace desired state. */
final class ManagementPreparation
{
    public static function requireFrozen(Operation $op, #[\SensitiveParameter] array $prepared): void
    {
        $frozen = $op->policy['management']['input'] ?? null;
        if (!is_array($frozen)) { throw new \DomainException('Management input unavailable.'); }
        $allowed = $op->registrar_key === 'enom' ? match ($op->action) {
            'glue_update' => ['old_ip'], 'privacy_purchase', 'privacy_renew' => ['privacy_terms'], default => [],
        } : [];
        // Purchase target follows dispatch day; renewal target follows its frozen base.
        foreach ($allowed as $key) {
            if ($key === 'privacy_terms' && isset($frozen[$key])) {
                foreach ($op->action === 'privacy_purchase' ? ['base'] : ['base','expected'] as $field) {
                    if (($prepared[$key][$field] ?? null) !== ($frozen[$key][$field] ?? null)) { throw new \DomainException('Privacy obligation changed.'); }
                }
            }
            unset($frozen[$key], $prepared[$key]);
        }
        if ($prepared !== $frozen) { throw new \DomainException('Prepared desired state changed.'); }
    }
}
