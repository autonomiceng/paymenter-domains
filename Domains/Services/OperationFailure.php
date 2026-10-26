<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\ModelNotFoundException;
/** Closed staff diagnostics; raw exception messages never cross the UI or log boundary. */
enum OperationFailure: string
{
    case ProviderEvidence = 'acquisition_provider_evidence';
    case NoPaidLine = 'no_eligible_paid_line';
    case InvoiceMissing = 'original_invoice_missing';
    public function message(): string
    {
        return match($this){
            self::ProviderEvidence=>"The registrar may have received this request.",
            self::NoPaidLine=>"This request has no paid invoice.",
            self::InvoiceMissing=>"Invoice not found.",
        };
    }
    public static function known(\Throwable $error): ?self
    {
        if($error instanceof OperationRefused){return $error->reason;}
        return $error instanceof ModelNotFoundException && $error->getModel()===Invoice::class ? self::InvoiceMissing : null;
    }
    public static function code(\Throwable $error): string
    { return self::known($error)?->value ?? RuntimeReason::failure($error); }
}
