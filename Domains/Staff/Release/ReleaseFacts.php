<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Staff\Release;
/** What a release decision reads about one held request. HeldRelease fills it from the request and its holding facts. */
final readonly class ReleaseFacts
{
    /**
     * @param ?string $environment The registrar account environment, or null without an account.
     * @param bool $deployment This acquisition type is enabled for Enom on this site.
     * @param bool $renewalCap Enom renewals are enabled for this account, which a renewal call cap hold needs.
     * @param ?string $balanceReview Why a changed balance retry still needs review; null when its proof still matches.
     * @param ?string $contactBlocked Why the retained contact step cannot go again; null when it can.
     */
    public function __construct(
        public string $state,
        public string $action,
        public ?string $code,
        public ?string $registrar=null,
        public ?string $environment=null,
        public bool $deployment=false,
        public bool $renewalCap=false,
        public bool $balancePending=false,
        public ?string $balanceReview=null,
        public bool $balanceRetry=false,
        public bool $contactRetry=false,
        public ?string $contactBlocked=null,
        public bool $testCallRetry=false,
        public bool $accountGuardRetry=false,
        public bool $purchaseRefused=false,
        public bool $pausedRelease=false,
    ) {}
}
