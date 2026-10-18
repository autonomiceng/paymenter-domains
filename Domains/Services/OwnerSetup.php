<?php
// Enom 444f31c RegistrationSetup desired inputs; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Data\PrivacyMode;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
/** Local, explicit, immutable acquisition setup. Never sends or changes contacts/funding/outcomes. */
final class OwnerSetup
{
    private static bool $writing = false;
    public function __construct(private readonly Clock $clock, private readonly OwnerPorts $ports) {}
    public static function guard(Operation $op): void
    {
        $before=$op->getOriginal('policy')['owner_setup']??null; $after=$op->policy['owner_setup']??null;
        if (!self::$writing && ($before!==$after || $before!==null &&
            array_intersect_key($op->payload??[],array_flip(['nameservers','privacy']))!==array_intersect_key($op->getOriginal('payload')??[],array_flip(['nameservers','privacy'])))) {
            throw new \DomainException('Owner setup is frozen.');
        }
    }
    public function save(User $actor, int $id, array $input): void
    {
        OperationClaims::outside(); $seed=Domain::findOrFail($id);
        $port=$this->ports->forDomain($seed); $caps=$port->capabilities();
        DB::transaction(function() use($actor,$seed,$input,$port,$caps):void {
            $l=HoldingLocks::take($seed); $d=$l['domain'];
            $op=OwnedDomains::acquisition($l['operations'],$d);
            if ((int)$actor->id!==(int)$l['user']->id || !$op || !$port->bindsTo(CatalogueAcceptances::binding($l['account']))
                || !in_array($op->state,['awaiting_payment','awaiting_code','queued','held'],true) || ($op->attempt??[])!==[]
                || $op->completed_at!==null || $l['cancel']->contains('service_id',$d->service_id) || !in_array($l['service']->status,['pending','active'],true)
                || $op->state==='held'&&!in_array($op->safe_code,['contact_details_required','transfer_setup_review_required','nameservers_required'],true)) {
                throw new \DomainException('Setup unavailable.');
            }
            RefundProof::requireUnsent($op,$d); RefundPayment::requireLine($op,$l);
            $invoice=$l['invoices']->firstWhere('id',$op->invoice_id);
            if ($invoice->status!=='pending') { RefundPayment::requireCurrent($op,$l); }
            if ($op->action==='transfer') {
                if ($invoice->status==='paid' && !TransferInputs::deadline($op,$this->clock->now())) { throw new \DomainException('Unverified payment date.'); }
                TransferInputs::requireEditable($op,$this->clock->now());
            }
            $servers=$input['nameservers']??[]; $privacy=$input['privacy']??false;
            if (!is_array($servers)||!is_bool($privacy)) { throw new \DomainException('Setup unavailable.'); }
            if ($privacy&&$caps->privacy!==PrivacyMode::Free) { throw new SetupValidation(['privacy']); }
            $desired=AcquisitionSetup::validate($d,$op,$servers,$privacy); $old=$op->policy['owner_setup']??null;
            if ($old!==null) { if (($old['desired']??null)!==$desired) { throw new \DomainException('Setup already saved.'); } return; }
            $oldWriting=self::$writing; self::$writing=true;
            try { $op->update(['payload'=>[...($op->payload??[]),...$desired], 'policy'=>[...($op->policy??[]),
                'owner_setup'=>['actor'=>(int)$actor->id,'at'=>$this->clock->now()->format(DATE_ATOM),'desired'=>$desired]]]); }
            finally { self::$writing=$oldWriting; }
        });
    }
}
