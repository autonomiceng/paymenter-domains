<?php
// Adapted from Enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.

namespace Paymenter\Extensions\Servers\Domains\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Session-bound intent keys with atomic replay claims across slow reads and stale sessions. */
final class FormKeys
{
    public static function issue(Request $request, string $context): string
    {
        $keys = $request->session()->get('domains.form_keys', []);
        $keys = array_filter($keys, fn ($entry) => is_array($entry) && ($entry['expires'] ?? 0) > time());
        if (!isset($keys[$context])) {
            // A domain page issues about 20 keys; keep room for many open pages before evicting the oldest.
            if (count($keys) >= 400) {
                uasort($keys, fn ($a, $b) => $a['expires'] <=> $b['expires']);
                array_shift($keys);
            }
            $keys[$context] = ['key' => bin2hex(random_bytes(32)), 'expires' => time() + 3600];
        }
        $request->session()->put('domains.form_keys', $keys);

        return $keys[$context]['key'];
    }

    public static function consume(Request $request, string $context): void
    {
        $key = self::verify($request, $context);
        // Shared atomic cache is an installation prerequisite39; never rely on the response-session save.
        abort_unless(Cache::add('domains.form.used:'.hash('sha256', $key), true, 3600), 419);
        $keys = $request->session()->get('domains.form_keys', []);
        unset($keys[$context]);
        $request->session()->put('domains.form_keys', $keys);
    }

    public static function verify(Request $request, string $context): string
    {
        $entry = $request->session()->get('domains.form_keys', [])[$context] ?? null;
        $input = $request->input('_request_key');
        abort_unless(is_array($entry) && ($entry['expires'] ?? 0) > time()
            && is_string($input) && hash_equals($entry['key'], $input), 419);

        return $input;
    }
}
