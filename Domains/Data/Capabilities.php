<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

final readonly class Capabilities
{
    private array $actions;
    /** Named management capabilities remain provider-specific; absent entries stay unknown. */
    public function __construct(public AccountBinding $binding, array $actions,
        public PrivacyMode $privacy = PrivacyMode::Unknown)
    {
        $copy = [];
        foreach ($actions as $name => $capability) {
            if (!in_array($name, ['register', 'transfer', 'renew', 'nameservers', 'contacts', 'lock', 'privacy',
                'auto_renew_off', 'auth_code_email', 'auth_code_display', 'dns', 'ds', 'glue'], true) || !$capability instanceof Capability) {
                throw new \InvalidArgumentException('Invalid driver capability.');
            }
            $copy[$name] = $capability;
        }
        $this->actions = $copy;
    }
    public function get(string $action): Capability { return $this->actions[$action] ?? new Capability; }
}
