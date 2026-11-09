<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http;
/** Whether the signed-in customer can open the domain list. Its routes also need a verified email when the site asks for one. */
final class DomainList
{
    public static function reachable(): bool
    {
        $user=auth()->user();
        return $user!==null&&(!config('settings.mail_must_verify')||$user->hasVerifiedEmail());
    }
}
