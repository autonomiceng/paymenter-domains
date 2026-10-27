<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Closed read diagnostics. Exception messages, account secrets and provider bodies are never stored. */
final class ReadFailure
{
    public const CODES=['read_failed','read_exception','read_deadline','read_no_evidence','read_identity_changed',
        'account_unavailable','binding_mismatch','configuration_unverified','encryption_unavailable','storage_unavailable',
        'catalogue_reader_unavailable','catalogue_acceptance_unavailable','catalogue_acceptance_changed',
        'catalogue_evidence_missing','catalogue_evidence_invalid','catalogue_import_failed','catalogue_interrupted'];
    public static function safe(mixed $code): string
    { return in_array($code,self::CODES,true)?$code:'read_exception'; }
    public static function code(\Throwable $error, string $fallback='read_exception'): string
    {
        if ($error instanceof CatalogueImportFailure) { return $error->failureCode; }
        if ($error instanceof \Illuminate\Contracts\Encryption\DecryptException) { return 'encryption_unavailable'; }
        if ($error instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) { return 'account_unavailable'; }
        if ($error instanceof \Illuminate\Database\QueryException) { return 'storage_unavailable'; }
        if ($error instanceof \Paymenter\Extensions\Servers\Domains\Data\AccountBindingMismatch) { return 'binding_mismatch'; }
        // Match only known local messages; arbitrary exception text cannot become a diagnostic code.
        return match ($error->getMessage()) {
            'Registrar read failed.', 'Catalogue batch read failed.', 'Catalogue read failed.', 'Enom price read failed.',
            'Management transport unavailable.', 'Management response unavailable.'=>'read_failed',
            'Registrar request deadline reached.', 'Observation deadline reached.'=>'read_deadline',
            'Observation unavailable.'=>'read_no_evidence', 'Observation name changed.'=>'read_identity_changed',
            'Domains server is unavailable.', 'Unavailable catalogue account.', 'Unavailable registrar server.'=>'account_unavailable',
            'Registrar binding mismatch.', 'Observation queue binding changed.', 'Catalogue binding mismatch.'=>'binding_mismatch',
            'Registrar configuration could not be verified.'=>'configuration_unverified',
            'Catalogue reader unavailable.', 'Registrar reader could not be created.',
            'Registrar reader factory returned an invalid handle.'=>'catalogue_reader_unavailable',
            'Catalogue acceptance is unavailable.'=>'catalogue_acceptance_unavailable',
            'Acceptance changed during refresh.'=>'catalogue_acceptance_changed',
            'No catalogue evidence.'=>'catalogue_evidence_missing',
            'Unaccepted catalogue evidence.', 'Unaccepted refresh subset.'=>'catalogue_evidence_invalid',
            default=>in_array($fallback,self::CODES,true)?$fallback:'read_exception',
        };
    }
}
