<?php
// Adapted from Enom 444f31c staff activity; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Services\{CorrectionProgress, ManagementEvidence};
final class RequestFacts
{
    public static function for(Operation $op, ?StaffGrants $grants = null, ?Domain $domain = null): array
    {
        $grants ??= Staff::requireView();
        $out = ['reason'=>$op->safe_code===null ? null : Labels::reason($op->safe_code), 'refund'=>'Customer credit: not assessed'];
        try {
            $p=$op->policy??[]; $a=$op->attempt??[]; $f=$op->funding??[];
            if ($op->state==='held' && $op->safe_code==='transfer_setup_review_required' && ($op->attempt??[])===[] && OperationActions::transferCutoff($op)) {
                try {
                    \Paymenter\Extensions\Servers\Domains\Services\RefundProof::requireUnsent($op,$op->domain);
                    $out['cutoff']='Code deadline passed. Release is unavailable; review account credit with funding and unsent proof.';
                } catch (\DomainException) { /* Sent or unknown outcomes retain their original evidence guidance. */ }
            }
            $out['refund']=array_key_exists('refund',$f) ? 'Credit marker retained; review ledger'
                : (RefundCandidates::matches($op,$f) || ($p['renewal_execution']['refundable_later']??false)===true
                    ? 'Customer credit review candidate; funding and unsent proof required'
                    : ($op->state==='rejected' && $op->funding_basis!=='paid_invoice'
                        ? 'Paid invoice basis not recorded; no credit inferred' : 'Customer credit: not assessed'));
            if ($op->registrar_key==='namesilo' && $op->action==='transfer'
                && (in_array($op->safe_code,['transfer_provider_rejected','transfer_needs_action'],true)
                    || in_array($p['reconciliation']['transfer_status']??null,['rejected','needs_action'],true))) {
                $rejected=$op->safe_code==='transfer_provider_rejected' || ($p['reconciliation']['transfer_status']??null)==='rejected';
                $out['registrar_refund']=$rejected ? 'Registrar refund is manual — request it from registrar support'
                    : 'If this transfer is abandoned, request any registrar refund manually via registrar support.';
            }
            if (isset($out['cutoff']) && $out['refund']==='Customer credit: not assessed') { $out['refund']='Customer credit: not yet assessed'; }
            $out['outcome']=match(true) {
                array_key_exists('execution',$a) && $a['execution']===null=>$op->state==='succeeded'
                    ? 'Original send response was unknown; completion later confirmed' : 'Original send response unknown; never resend',
                ($a['seal']??null)==='sealed'=>'Original attempt possibly sent; never resend or credit automatically',
                $op->state==='uncertain'=>'Unconfirmed. Do not resend or refund until the registrar result is known.',
                default=>null,
            };
            $corrections=$a['corrections']??[];
            if (is_array($corrections) && $corrections!==[]) { $out['correction']='Original corrections retained; separate review required'; }
            elseif (!is_array($corrections)) { $out['correction']='Correction evidence unavailable'; }
            if (isset($p['management']['correction_link'])) {
                $d=$domain??Domain::find($op->domain_id);
                $key=$p['management']['correction_link']['operation_key']??null;
                $original=$d && is_string($key) ? DomainQueries::operations($d)->where('operation_key',$key)->first() : null;
                $confirmed=$original && CorrectionProgress::matches($op,$original,$d)
                    && $op->state==='succeeded' && ManagementEvidence::resolved($op,$d);
                $out['correction']=$confirmed ? 'Readback confirmed; awaiting review' : 'Correction unresolved';
            }
            $counts=[self::count($p['contact_readmissions']??[]),self::count($p['reconciliation_resumptions']??[]),self::count($f['financial_adjustments']??[])];
            if ($counts!==['0','0','0']) { $out['audit']='Contact readmissions: '.$counts[0].' · Read resumes: '.$counts[1].' · Financial adjustments: '.$counts[2]; }
            if ($grants->costs) {
                $c=$op->cost??[];
                $out['cost']='Estimated '.Labels::money(($c['currency']??null)==='USD' ? ($c['estimated']??null) : null)
                    .' · Actual '.Labels::money(($c['actual']['currency']??null)==='USD' ? ($c['actual']['amount']??null) : null);
            }
            if ($grants->metadata) {
                $payload=$op->payload??[];
                $out['metadata']='Transfer code: '.(is_string($payload['epp']??null)&&$payload['epp']!==''?'Stored':'Not recorded')
                    .' · Contacts: '.(array_key_exists('contacts',$payload)?'Stored':'Not recorded')
                    .' · Execution: '.(array_key_exists('execution',$a)?'Recorded':'Not recorded');
            }
        } catch (\Throwable) { $out['outcome']='Encrypted evidence unavailable; no outcome inferred'; }
        return $out;
    }
    public static function review(Operation $op, ?StaffGrants $grants = null, ?Domain $domain = null, bool $detail = false): array
    {
        $grants ??= Staff::requireView();
        $facts=self::for($op,$grants,$domain);
        return [...array_values(array_filter(array_map(fn($key)=>$facts[$key]??null,
            ['cutoff','outcome','registrar_refund','refund','correction','cost','metadata','audit']),fn($line)=>$line!==null)),
            ...StaffReviewHistory::for($op,$grants,$detail)];
    }
    private static function count(mixed $items): string { return is_array($items) ? (string)count($items) : 'Unknown'; }
}
