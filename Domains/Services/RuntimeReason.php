<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Closed vocabulary only: never derive a diagnostic from an exception message. */
enum RuntimeReason: string
{
    case ProcessingOff = 'processing_off';
    case SpendingPaused = 'automatic_spend_paused';
    case BillingOff = 'billing_off';
    case MissingAnchor = 'missing_anchor';
    case OfflineProvenance = 'offline_provenance';
    case NoInvoiceAuthority = 'no_invoice_authority';
    case OutsideCalendar = 'outside_billing_calendar';
    case LifecycleNotReady = 'lifecycle_not_ready';
    case DatabaseFailed = 'database_failure';
    case NativeRecordMissing = 'native_record_missing';
    case GuardRefused = 'guard_refused';
    case InvalidInput = 'invalid_input';
    case InvariantFailed = 'invariant_failed';
    case ProducerFailed = 'producer_failed';
    /** Existing write holds and renewal enums also have closed, constructor-validated vocabularies. */
    public static function failure(\Throwable $e): string
    {
        return match(true){
            $e instanceof WriteHeld=>$e->reason,
            $e instanceof RenewalRefused=>$e->reason->value,
            $e instanceof \Illuminate\Database\QueryException,$e instanceof \PDOException=>self::DatabaseFailed->value,
            $e instanceof \Illuminate\Database\Eloquent\ModelNotFoundException=>self::NativeRecordMissing->value,
            $e instanceof \DomainException=>self::GuardRefused->value,
            $e instanceof \InvalidArgumentException=>self::InvalidInput->value,
            $e instanceof \LogicException=>self::InvariantFailed->value,
            default=>self::ProducerFailed->value,
        };
    }
}
