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
