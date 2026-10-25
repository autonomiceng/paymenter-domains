<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** The status set for a registrar account. Tones are Filament colours. Mode (Live or Test) is plain text, never a pill. */
final class AccountStatus
{
    public const DEMO_PAUSED = 'Demo registrar off while Enom Test mode is on';
    /** A NameSilo test account while test enrollment is off on this site. */
    public const TEST_MODE_OFF = 'Test mode turned off on this site';
    /** key => [label, tone] */
    public const SET = [
        'active' => ['Active', 'success'], 'orders_paused' => ['Orders paused', 'warning'],
        'not_connected' => ['Not connected', 'gray'], 'connection_failed' => ['Connection failed', 'danger'],
    ];
    /** AccountBindings::configurationStatus() => [status key, default reason line] */
    public const STATES = [
        'matched' => ['active', null], 'not_configured' => ['not_connected', null], 'server_disabled' => ['not_connected', 'Off in Paymenter'],
        'server_unavailable' => ['connection_failed', 'Server missing in Paymenter'], 'site_invalid' => ['connection_failed', 'Site address must use HTTPS'],
        'site_unbound' => ['connection_failed', 'Site address not approved for live mode'], 'site_mismatch' => ['connection_failed', 'Site address changed'],
        'configuration_unavailable' => ['connection_failed', 'Could not load account settings'],
    ];
    /** A connected account with processing off has its orders paused; $reason names why (default: paused by staff).
     * @return array{label:string,tone:string,reason:?string} */
    public static function for(?string $configuration, bool $processing = true, ?string $reason = null): array
    {
        [$key, $default] = self::STATES[$configuration] ?? ['connection_failed', 'Status missing'];
        if ($key === 'active' && !$processing) { [$key, $default] = ['orders_paused', 'Paused by staff']; }
        [$label, $tone] = self::SET[$key];
        return ['label' => $label, 'tone' => $tone, 'reason' => $reason ?? $default];
    }
}
