<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use Paymenter\Extensions\Servers\Domains\Data\{PslSnapshot, Registrable, Held};
final class RegistrableNameResolver
{
    public function resolve(string $hostname, PslSnapshot $current): Registrable|Held
    {
        $hold = fn(string $code) => new Held($code, $current->revision);
        // U-labels are held; A-labels remain ASCII and are never decoded or mapped.
        if (preg_match('/[^\x00-\x7f]/', $hostname)) { return $hold('idn_held'); }
        $hostname = strtolower($hostname);
        if (str_ends_with($hostname, '.')) { $hostname = substr($hostname, 0, -1); }
        if (strlen($hostname) > 253 || filter_var($hostname, FILTER_VALIDATE_IP) ||
            !preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\z/D', $hostname)) {
            return $hold('source_invalid');
        }
        $labels = explode('.', $hostname); $count = count($labels);
        $suffix = $this->suffix($labels, $current->rules['icann']);
        if ($suffix === null || $suffix < 1 || $count <= $suffix) { return $hold('suffix_unknown'); }
        $registrable = implode('.', array_slice($labels, -$suffix - 1));
        $private = $this->suffix($labels, $current->rules['private']);
        // The private suffix itself is also held: it is a hosting boundary, not ownership evidence.
        if (($private !== null && $private >= $suffix + 1) || isset($current->rules['private']['*.'.$hostname])) { return $hold('private_suffix'); }
        return new Registrable($hostname, $registrable, $current->revision);
    }
    public static function changed(Registrable|Held $old, Registrable|Held $new): bool
    { return $old::class !== $new::class || ($old instanceof Registrable ? $old->name !== $new->name : $old->safe_code !== $new->safe_code); }
    private function suffix(array $labels, array $rules): ?int
    {
        $best = null; $exception = null; $count = count($labels);
        for ($i = 0; $i < $count; $i++) {
            $tail = implode('.', array_slice($labels, $i)); $length = $count - $i;
            if (isset($rules['!'.$tail])) { $exception = max($exception ?? 0, $length - 1); }
            if (isset($rules[$tail])) { $best = max($best ?? 0, $length); }
            if ($i > 0 && isset($rules['*.'.$tail])) { $best = max($best ?? 0, $length + 1); }
        }
        return $exception ?? $best; // No implicit '*' fallback for an unknown TLD.
    }
}
