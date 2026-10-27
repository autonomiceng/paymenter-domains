<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
/** Internal revisioned configuration; enrollment is not feature acceptance. */
final class ManagedPublicConfig extends WatchRecord
{
    protected $table = 'domains_managed_public_config';
    protected $primaryKey = 'domain_id';
    public $incrementing = false;
    protected array $identity = ['domain_id', 'user_id'];
    protected $casts = ['revision' => 'integer', 'notice_preferences' => 'array', 'dns_episode' => 'array'];
    protected function validateRecord(): void
    {
        $domain=Domain::whereKey($this->domain_id)->first();
        if (!$domain || $domain->adoptionPending() || (int)$domain->user_id!==(int)$this->user_id
            || (int)$domain->service?->user_id!==(int)$this->user_id) { throw new \DomainException('Managed configuration owner changed.'); }
        if ((int) $this->domain_id < 1 || (int) $this->user_id < 1 || !is_array($this->notice_preferences)
            || !in_array($this->expected_dns, [null, 'platform', 'external'], true)
            || ($this->profile_id !== null && (int) $this->profile_id < 1)) {
            throw new \DomainException('Invalid managed public configuration.');
        }
    }
}
