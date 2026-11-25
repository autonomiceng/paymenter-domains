<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Staff\Release;
/** An allowed release: the unsent proof the writer must still find under its locks. */
enum ReleaseProof: string
{
    /** The balance retry check already proved the retained claim unsent. */
    case BalanceRetry='balance_retry';
    /** A retained claim or contact step that must be proven unsent. */
    case Unsent='unsent';
    /** A request that was never attempted. */
    case Unattempted='unattempted';
}
