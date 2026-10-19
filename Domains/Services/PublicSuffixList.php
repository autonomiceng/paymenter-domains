<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Setting;
use Illuminate\Support\Facades\{DB, Log, Schema};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
/** A single retained row serializes scheduled/manual admission. No lock spans network I/O. */
final class PublicSuffixList
{
    private array $parseCache = [];
    public const SOURCE = 'https://publicsuffix.org/list/public_suffix_list.dat';
    public const SETTING = 'domains_psl_source_url';
    public function __construct(private Clock $clock, private PslTransport $transport, private PslValidator $validator) {}
    public function current(): ?object { return DB::table('domains_psl_snapshots')->where('state', 'current')->first(); }
    public function status(): object { return DB::table('domains_psl_status')->where('id', 1)->firstOrFail(); }
    public function revision(): ?string { return $this->current()?->revision; }
    public function parserUsable(?object $snapshot = null): bool
    {
        $s = $snapshot ?? $this->current(); if ($s === null) { return false; }
        $key = $s->revision.hash('sha256', $s->compressed_body);
        if (array_key_exists($key, $this->parseCache)) { return $this->parseCache[$key]; }
        try { $this->validator->baseline(self::body($s)); $ok = true; } catch (PslFailure) { $ok = false; }
        $this->parseCache = [$key => $ok]; return $ok;
    }
    public function discoveryAllowed(): bool
    { $s = $this->current(); return $s !== null && $this->parserUsable($s) && $this->clock->now()->getTimestamp() <= (int) $s->validated_at + 90 * 86400; }
    public function staleWarning(): bool
    { $s = $this->current(); return $s !== null && $this->clock->now()->getTimestamp() > (int) $s->validated_at + 30 * 86400; }
    public function nextAllowedAt(): ?int
    { $at = $this->status()->last_network_fetch_at; return $at === null ? null : (int) $at + 86400; }
    public function source(): string
    { return Setting::whereNull('settingable_type')->where('key', self::SETTING)->value('value') ?? self::SOURCE; }
    public function tick(): void
    {
        if (!Schema::hasTable('domains_psl_status')) { return; }
        $at = $this->status()->last_attempt_at;
        if ($at === null || $this->clock->now()->getTimestamp() >= (int) $at + 7 * 86400) { $this->update(true); }
    }
    public function update(bool $scheduled = false): string
    {
        OperationClaims::outside(); $now = $this->clock->now()->getTimestamp();
        $claim = DB::transaction(function () use ($now, $scheduled): ?array {
            $s = DB::table('domains_psl_status')->where('id', 1)->lockForUpdate()->firstOrFail();
            if ($s->last_network_fetch_at !== null && $now < (int) $s->last_network_fetch_at + 86400) { return null; }
            if ($scheduled && $s->last_attempt_at !== null && $now < (int) $s->last_attempt_at + 7 * 86400) { return null; }
            $url = $this->source(); $token = bin2hex(random_bytes(16));
            DB::table('domains_psl_status')->where('id', 1)->update(['last_attempt_at' => $now,
                'last_network_fetch_at' => PslTransport::validUrl($url) ? $now : $s->last_network_fetch_at, 'attempt_token' => $token, 'last_result' => 'checking']);
            return [$url, $this->current(), $token]; // Reserve before DNS/HTTP, including failed or crashed attempts.
        });
        if ($claim === null) { return 'rate_limited'; }
        [$url, $old, $token] = $claim; $r = null;
        try {
            $headers = [];
            if ($old && $old->source_url === $url && !$this->staleWarning() && $this->parserUsable($old)) {
                if (PslTransport::header($old->etag) !== null) { $headers[] = 'If-None-Match: '.$old->etag; }
                if (PslTransport::header($old->last_modified) !== null) { $headers[] = 'If-Modified-Since: '.$old->last_modified; }
            }
            $r = $this->transport->fetch($url, $headers);
            if ($r['status'] === 304 && (!$old || !$headers || $old->source_url !== $url)) { throw new PslFailure('unexpected_304'); }
            $rules = null;
            if ($r['status'] === 200) {
                $this->validator->parse($r['body']); // Report invalid downloads independently of saved-parser recovery.
                try { $baseline = $old ? $this->validator->baseline(self::body($old)) : null; }
                catch (PslFailure) { throw new PslFailure('stored_copy_invalid'); }
                $rules = $this->validator->parse($r['body'], $baseline);
            }
            $revision = $rules === null ? $old->revision : hash('sha256', $r['body']);
            return DB::transaction(function () use ($now, $url, $old, $r, $rules, $revision, $token): string {
                $s = DB::table('domains_psl_status')->where('id', 1)->lockForUpdate()->firstOrFail();
                if ($s->attempt_token !== $token) { return 'superseded'; }
                $code = $r['status'] === 304 ? 'not_modified' : ($old?->revision === $revision ? 'identical' : 'updated');
                if ($code === 'updated') {
                    if (Schema::hasTable('domains_psl_candidates')) { DB::table('domains_psl_candidates')->delete(); }
                    DB::table('domains_psl_snapshots')->where('state', 'previous')->delete();
                    DB::table('domains_psl_snapshots')->where('state', 'current')->update(['state' => 'previous']);
                    DB::table('domains_psl_snapshots')->insert(['state' => 'current', 'revision' => $revision, 'source_url' => $url,
                        'etag' => PslTransport::header($r['etag'] ?? null), 'last_modified' => PslTransport::header($r['last-modified'] ?? null),
                        'byte_size' => strlen($r['body']), 'icann_count' => count($rules['icann']), 'private_count' => count($rules['private']),
                        'fetched_at' => $now, 'validated_at' => $now, 'compressed_body' => base64_encode(gzencode($r['body'], 9))]);
                } elseif ($r['status'] === 200) {
                    $values = ['validated_at' => $now, 'source_url' => $url];
                    $values += ['fetched_at' => $now, 'etag' => PslTransport::header($r['etag'] ?? null), 'last_modified' => PslTransport::header($r['last-modified'] ?? null)];
                    DB::table('domains_psl_snapshots')->where('state', 'current')->update($values);
                }
                DB::table('domains_psl_status')->where('id', 1)->update(['checked_at' => $now, 'last_result' => $code, 'consecutive_failures' => 0]);
                return $code;
            });
        } catch (\Throwable $e) {
            $code = $e instanceof PslFailure ? $e->safeCode : 'internal_error';
            if (!$e instanceof PslFailure) { Log::warning('Public suffix update failed internally.', ['exception_class' => $e::class]); }
            return DB::transaction(function () use ($token, $code, $r, $old, $url, $now): string {
                $q = DB::table('domains_psl_status')->where('id', 1); $s = (clone $q)->lockForUpdate()->firstOrFail();
                if ($s->attempt_token === $token) {
                    if ($old && isset($r['body']) && in_array($code, ['rules_lost_icann', 'rules_lost_private', 'stored_copy_invalid'], true)) {
                        try {
                            $review = new PslRebaseline($this->clock, $this->validator);
                            $captured = $review->capture($r['body'], $old, $url, $now);
                            if ($code === 'stored_copy_invalid' && $captured === 'stored_rules_rejected') { $code = 'stored_rules_rejected'; }
                        } catch (\Throwable $e) { Log::warning('Public suffix candidate could not be retained.', ['exception_class' => $e::class]); }
                    }
                    $q->update(['last_result' => $code, 'consecutive_failures' => min(4294967295, (int) $s->consecutive_failures + 1)]);
                } else { return 'superseded'; }
                return $code;
            });
        }
    }
    public static function body(object $snapshot): string
    {
        $compressed = base64_decode($snapshot->compressed_body, true);
        $body = $compressed === false ? false : @gzdecode($compressed, PslValidator::MAX_BYTES + 1);
        if ($body === false || strlen($body) > PslValidator::MAX_BYTES || hash('sha256', $body) !== $snapshot->revision) { throw new PslFailure('stored_copy_invalid'); }
        return $body;
    }
}
