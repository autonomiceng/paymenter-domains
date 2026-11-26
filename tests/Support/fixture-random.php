<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
// Domains code draws claim, review and audit keys from random_bytes(), and Laravel draws encryption IVs and random
// strings and bcrypt salts the same way. In a booted native fixture each of those namespaces resolves it to a seeded stream instead, so
// the same case writes the same keys, ciphertexts and the digests built from them in any process. A native fixture is
// one process over an in-memory database, so seeded keys cannot collide.
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
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Feed {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace Paymenter\Extensions\Servers\Domains\Catalogue\Publication {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace Paymenter\Extensions\Servers\Domains\Holding\Adoption {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace Paymenter\Extensions\Servers\Domains\Http {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace Paymenter\Extensions\Servers\Domains\Installation {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace Paymenter\Extensions\Servers\Domains\Management\Contacts {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace Paymenter\Extensions\Servers\Domains\Renewal\Execution {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace Paymenter\Extensions\Servers\Domains\Services {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace Paymenter\Extensions\Servers\Domains\Storefront\Search {
    if (!function_exists(__NAMESPACE__.'\random_bytes')) { function random_bytes(int $length): string { return \NativeFixtureRandom::bytes($length); } }
}
namespace {
    final class NativeFixtureRandom
    {
        private static string $seed = 'boot';
        private static int $draws = 0;

        /** Start a new stream, such as one per independent case, so its keys do not depend on earlier cases. */
        public static function reseed(string $seed): void { self::$seed = $seed; self::$draws = 0; }

        public static function bytes(int $length): string
        {
            if ($length < 1) { throw new ValueError('random_bytes(): Argument #1 ($length) must be greater than 0'); }
            for ($bytes = ''; strlen($bytes) < $length;) { $bytes .= hash('sha256', self::$seed."\0".++self::$draws, true); }
            return substr($bytes, 0, $length);
        }
    }
}
