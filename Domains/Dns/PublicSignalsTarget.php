<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Dns;
/** Public observation identity only; never a holding, billing or management grant. */
final readonly class PublicSignalsTarget
{
    private function __construct(public string $kind, public int $id, public int $owner,
        public int $revision, public ?string $account = null, public ?int $generation = null)
    {
        if ($id < 1 || $owner < 1 || $revision < 0 || ($kind === 'managed' &&
            (!preg_match('/^[a-f0-9]{64}$/D', $account ?? '') || $generation < 1))) {
            throw new \InvalidArgumentException('Invalid public target identity.');
        }
    }
    public static function watch(int $watchId, int $owner, int $revision): self
    { return new self('watch', $watchId, $owner, $revision); }
    public static function managed(int $domainId, int $owner, string $account, int $generation, int $domainRevision): self
    { return new self('managed', $domainId, $owner, $domainRevision, $account, $generation); }
}
