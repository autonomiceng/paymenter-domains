<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Casts;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
/** New writes are encrypted; retained plain reasons remain readable without rewriting audit history. */
final class LegacyEncryptedString implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) { return null; }
        try { return $model::currentEncrypter()->decryptString($value); }
        catch (DecryptException $error) {
            $envelope = json_decode(base64_decode($value, true) ?: '', true);
            // A missing key or damaged ciphertext must not be mistaken for an old plain reason.
            if (is_array($envelope) && isset($envelope['iv'], $envelope['value'], $envelope['mac'])) { throw $error; }
            return $value;
        }
    }
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    { return $value === null ? null : $model::currentEncrypter()->encryptString($value); }
}
