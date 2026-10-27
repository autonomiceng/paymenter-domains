<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);

/** Explicit opt-in for valid synthetic domain fixtures; never checks availability. */
function reservedFixtureName(string $name): string
{
    Paymenter\Extensions\Servers\Domains\Data\CanonicalName::requireCanonical($name);
    foreach (['example.com', 'example.net', 'example.org'] as $example) {
        if ($name === $example || str_ends_with($name, '.'.$example)) { return $name; }
    }
    if (str_ends_with($name, '.test')) { return $name; }
    throw new InvalidArgumentException('Synthetic fixture domain must use a reserved name: '.$name);
}

/** Explicit opt-in for .com-only fixtures that require a single-label SLD. */
function placeholderComFixtureName(string $name): string
{
    $parsed = Paymenter\Extensions\Servers\Domains\Data\CanonicalName::parse($name, ['com']);
    if (!str_contains($parsed['sld'], 'placeholder')) {
        throw new InvalidArgumentException('Synthetic .com fixture label must include placeholder.');
    }
    return $name;
}
