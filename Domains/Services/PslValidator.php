<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Parses data only. No registrable-name lookup or discovery authority. */
final readonly class PslValidator
{
    public const MAX_BYTES = 1048576;
    public function __construct(private int $minBytes = 102400, private int $minIcann = 1000, private int $minPrivate = 100) {}
    /** @return array{icann: array<string, true>, private: array<string, true>} */
    public function parse(#[\SensitiveParameter] string $body, ?array $previous = null): array
    {
        $rules = $this->rules($body, true);
        foreach ($rules as $section => $set) {
            if ($previous !== null && count(array_diff_key($previous[$section], $set)) * 100 > count($previous[$section]) * 5) {
                throw new PslFailure('rules_lost_'.$section);
            }
        }
        return $rules;
    }
    /** Previously accepted data is not subject to today's size/count/sentinel floors. */
    public function baseline(#[\SensitiveParameter] string $body): array { return $this->rules($body, false); }
    private function rules(#[\SensitiveParameter] string $body, bool $current): array
    {
        $bytes = strlen($body);
        if ($current && $bytes < $this->minBytes) { throw new PslFailure('too_small'); }
        if ($bytes > self::MAX_BYTES) { throw new PslFailure('too_large'); }
        if (!preg_match('//u', $body) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $body)) { throw new PslFailure('invalid_utf8'); }
        $markers = ['// ===BEGIN ICANN DOMAINS===', '// ===END ICANN DOMAINS===',
            '// ===BEGIN PRIVATE DOMAINS===', '// ===END PRIVATE DOMAINS==='];
        $stage = 0; $rules = ['icann' => [], 'private' => []];
        foreach (preg_split('/\r\n|\n|\r/', $body) as $line) {
            $line = trim($line);
            $marker = array_search($line, $markers, true);
            if ($marker !== false) {
                if ($marker !== $stage) { throw new PslFailure('markers_order'); }
                $stage++; continue;
            }
            if ($line === '' || str_starts_with($line, '//')) { continue; }
            if (!in_array($stage, [1, 3], true)) { throw new PslFailure('rule_invalid'); }
            $prefix = str_starts_with($line, '*.') ? '*.' : (str_starts_with($line, '!') ? '!' : '');
            $name = substr($line, strlen($prefix));
            if (!function_exists('idn_to_ascii')) { throw new PslFailure('parser_unavailable'); }
            $ascii = idn_to_ascii($name, IDNA_NONTRANSITIONAL_TO_ASCII | IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ, INTL_IDNA_VARIANT_UTS46, $info);
            if ($ascii === false || $info['errors'] !== 0 || strlen($ascii) > 253 || str_ends_with($ascii, '.')) { throw new PslFailure('rule_invalid'); }
            $section = $stage === 1 ? 'icann' : 'private'; $rule = $prefix.$ascii;
            if (isset($rules[$section][$rule])) { throw new PslFailure('rule_invalid'); }
            $rules[$section][$rule] = true;
        }
        if ($stage !== 4) { throw new PslFailure('markers_missing'); }
        if ($current) {
            foreach (['com' => 'icann', 'co.uk' => 'icann', 'github.io' => 'private'] as $rule => $section) {
                if (!isset($rules[$section][$rule])) { throw new PslFailure('sentinel_missing'); }
            }
            if (count($rules['icann']) < $this->minIcann || count($rules['private']) < $this->minPrivate) { throw new PslFailure('too_few_rules'); }
        }
        return $rules;
    }
}
