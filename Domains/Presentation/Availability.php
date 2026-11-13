<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The one place that picks an availability state, from evidence. It replaces Missing: a page never decides between
 * Not read yet, Checking, Couldn't read, Not set, Not for sale and Not supported itself, and never from text.
 * Null means there is a value to show. */
final class Availability
{
    /** A value we read from elsewhere. Can't be done wins, then not sold, then how the read went. A good read that came
     * back empty is None, a value, not a missing one. */
    public static function value(Read $read, bool $hasValue, bool $supported = true, bool $sold = true): State|Plain|null
    {
        if (!$supported) { return State::NotSupported; }
        if (!$sold) { return State::NotForSale; }
        if ($read->checking && $read->lastGoodAt !== null && $hasValue) { return null; }
        if ($read->checking || $read->lastGoodAt === null || self::failedLast($read)) { return self::unread($read); }
        return $hasValue ? null : Plain::None;
    }
    /** A value with no current good read: waiting on the registrar now, the last read failed, or never read. A failed read
     * is red even when an older value exists, so it is never muted. */
    public static function unread(Read $read): State
    { return $read->checking ? State::Checking : (self::failedLast($read) ? State::CouldntRead : State::NotReadYet); }
    /** A setting. Empty and required is Not set, which staff fix; empty and optional is None. */
    public static function setting(bool $set, bool $required): State|Plain|null
    { return $set ? null : ($required ? State::NotSet : Plain::None); }
    private static function failedLast(Read $read): bool
    { return $read->lastFailedAt !== null && ($read->lastGoodAt === null || $read->lastFailedAt >= $read->lastGoodAt); }
}
