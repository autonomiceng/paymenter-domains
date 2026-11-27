<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Domains code draws claim, review and audit keys from random_bytes(), and Laravel draws encryption IVs and random
// strings and bcrypt salts the same way. In a booted native fixture each of those namespaces resolves it to a seeded stream instead, so
// the same case writes the same keys, ciphertexts and the digests built from them in any process. A database snapshot
// records the stream position it was exported at, and a process that loads it continues from there, so its keys never
// repeat one the snapshot already holds.
namespace Illuminate\Encryption {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace Illuminate\Hashing {
    // A bcrypt salt is random too: seed it, so a stored password hash and digests over its row repeat.
    if (!function_exists(__NAMESPACE__.'\password_hash')) {
        function password_hash(string $password, string|int|null $algo, array $options = []): string
        {
            if ($algo !== PASSWORD_BCRYPT) { return \password_hash($password, $algo, $options); }
            $salt = substr(strtr(base64_encode(\NativeFixtureRandom::bytes(16)), '+', '.'), 0, 22);
            return crypt($password, sprintf('$2y$%02d$', $options['cost'] ?? 10).$salt);
        }
    }
}
namespace Illuminate\Support {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace Paymenter\Extensions\Servers\Domains\Admin\Support {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Feed {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Publication {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace Paymenter\Extensions\Servers\Domains\Holding\Adoption {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace Paymenter\Extensions\Servers\Domains\Http {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace Paymenter\Extensions\Servers\Domains\Installation {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace Paymenter\Extensions\Servers\Domains\Management\Contacts {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace Paymenter\Extensions\Servers\Domains\Management\Records {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace Paymenter\Extensions\Servers\Domains\Operations\Authority {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace Paymenter\Extensions\Servers\Domains\Renewal\Execution {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace Paymenter\Extensions\Servers\Domains\Services {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace Paymenter\Extensions\Servers\Domains\Storefront\Search {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length, 'domains'); } }
}
namespace {
    final class NativeFixtureRandom
    {
        private static string $seed = 'boot';
        /** Draws taken per stream. Domains keys have their own stream, so a framework draw that only some case orders
         * make, such as one a cold cache takes once per process, cannot shift the keys a later case writes. */
        private static array $draws = [];

        /** Start a new stream, such as one per independent case, so its keys do not depend on earlier cases. */
        public static function reseed(string $seed): void { self::$seed = $seed; self::$draws = []; }

        /** The seed and the draws taken from each stream. @return array{string,array<string,int>} */
        public static function position(): array { return [self::$seed, self::$draws]; }

        /** Continue the stream a loaded database snapshot was exported at, so new keys never repeat its stored ones. */
        public static function resumeSnapshot(string $sql): void
        {
            if (preg_match('/^-- random-stream: (.+)$/m', $sql, $position) === 1) {
                [self::$seed, self::$draws] = json_decode($position[1], true, 4, JSON_THROW_ON_ERROR);
            }
        }

        public static function bytes(int $length, string $stream = 'framework'): string
        {
            if ($length < 1) { throw new ValueError('random_bytes(): Argument #1 ($length) must be greater than 0'); }
            self::$draws[$stream] ??= 0;
            for ($bytes = ''; strlen($bytes) < $length;) { $bytes .= hash('sha256', self::$seed."\0".$stream."\0".++self::$draws[$stream], true); }
            return substr($bytes, 0, $length);
        }
    }
}
