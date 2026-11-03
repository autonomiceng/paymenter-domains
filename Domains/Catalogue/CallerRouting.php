<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

/** C1 fixed legacy seam. There is deliberately no active target, flag, composition or activation.
 * Reads retain their existing pause behavior; existing write mutexes still own pause/generation.
 * C6 must switch the COMPLETE caller/writer/scheduler composition in one checked transition. */
final class CallerRouting
{
    public static function legacy(\Closure $body): mixed { return $body(); }
}
