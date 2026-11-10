<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Fixed diagnostics only. Never retain a provider exception, URL, body or cURL text. */
final class SendFailure extends \RuntimeException
{
    public function __construct(public readonly string $category, public readonly int $timeoutMs)
    {
        if (!in_array($category, ['timeout','connect','tls','parse','other'], true) && !preg_match('/^http [1-5][0-9]{2}$/D', $category)
            || $timeoutMs < 1 || $timeoutMs > 25000) { throw new \InvalidArgumentException('Invalid send failure.'); }
        parent::__construct('Registrar exchange failed.');
    }
    public static function curl(int $errno, int $status, int $timeoutMs): self
    {
        $category = match (true) {
            $errno === 28 => 'timeout',
            in_array($errno, [5,6,7], true) => 'connect',
            in_array($errno, [35,51,53,54,58,59,60,64,66,77,80,82,83,90,91], true) => 'tls',
            $errno === 0 && $status >= 100 && $status <= 599 && $status !== 200 => 'http '.$status,
            default => 'other',
        };
        return new self($category, $timeoutMs);
    }
    public function facts(): array { return ['category'=>$this->category,'timeout_ms'=>$this->timeoutMs]; }
    public static function words(?array $facts): ?string
    {
        try { $safe = new self($facts['category'] ?? '', $facts['timeout_ms'] ?? 0); }
        catch (\Throwable) { return null; }
        return $safe->category.'; time limit '.$safe->timeoutMs.' ms';
    }
}
