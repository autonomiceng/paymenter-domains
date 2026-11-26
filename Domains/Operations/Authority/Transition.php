<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority;

/** Why a writer transition did not return new authority. */
enum Transition: string
{
    case Applied = 'applied';
    /** Another live claim or read owns the step. */
    case Busy = 'busy';
    /** The caller's authority is no longer current; nothing changed. */
    case Stale = 'stale';
}
