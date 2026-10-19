<?php
// Adapted from Enom 444f31c Admin/Support/DomainFacts and DomainAdministration read paths.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain};
/** Request-local allowlisted projection. Never cache across actors, call providers or serialize models. */
final class DomainFacts
{
    public static function for(Domain $d): array
    {
        Staff::requireView();
        $account = Account::where('server_id',$d->server_id)->where('registrar_key',$d->registrar_key)
            ->where('account_fingerprint',$d->account_fingerprint)->first();
        $result = ['Assigned registrar'=>match($d->registrar_key) {'enom'=>'Enom','namesilo'=>'NameSilo',default=>'Unknown'},
            'Account'=>$account ? '#'.$account->id.' · Server #'.$account->server_id : 'Binding needs review',
            'Environment'=>$account ? match($account->environment) {'test'=>'Test','live'=>'Live',default=>'Unknown'} : 'Unknown',
            'Processing'=>$account ? Labels::flag($account->processing_enabled) : 'Unknown',
            'Owner'=>'Customer #'.$d->user_id, 'Service'=>'#'.$d->service_id,
            'Status'=>Labels::status($d->status), 'Confirmed expiry'=>$d->provider_expires_at?->format('Y-m-d') ?? 'Unknown',
            'Renewal billing'=>Labels::flag($d->billing_managed),
            'Who pays'=>match($d->price_mode) {'standard'=>'Standard','complimentary'=>'Complimentary',default=>'Review policy'},
            'Assignment generation'=>(string)$d->authority_generation];
        if ($d->adoptionPending()) {
            $result['Status']='Registration setup pending'; $result['Confirmed expiry']='Not verified';
            $result['Renewal billing']='Paused during setup';
            $result['Processing']='Held for this domain';
            $result['Last recorded expiry (unverified)']=$d->provider_expires_at?->format('Y-m-d') ?? 'Unknown';
            try { $result['Last recorded expiry (unverified)']=$d->provider_expires_at?->format('Y-m-d') ?? ($d->renewal_policy['conversion_source_expiry']??'Unknown'); } catch (\Throwable) {}
            $result['Open domain invoices']=DB::table('domains_operations as o')->join('invoices as i','i.id','=','o.invoice_id')->where('o.domain_id',$d->id)->where('i.status','pending')->distinct()->pluck('i.id')->map(fn($id)=>'#'.$id.' — payable; review before collecting')->all() ?: ['None'];
            $result['Setup reason']='Converted as pending: purchase evidence was not verified, or renewal billing was not managed. Renewals stay paused until adoption is available. Retained requests and invoices need review.';
        }
        try {
            $ob = $d->observation ?? []; $facts = $ob['facts'] ?? [];
            $result += ['Observed ownership'=>Labels::flag($facts['owned']??null),
                'Registrar auto-renew'=>Labels::flag($facts['auto_renew']??null),
                'Transfer lock'=>Labels::flag($facts['locked']??null), 'Privacy'=>Labels::flag($facts['privacy']??null),
                'Observation drift'=>Labels::flag($ob['drift']??null),
                'Follow-up'=>$d->adoptionPending() ? 'Paused during setup' : match($ob['followup']['state']??null) {'observed'=>'Observed','pending'=>'Pending',default=>'Unknown'}];
            $result['Automatic spending paused']=Labels::flag(($d->renewal_policy??[])['automatic_spend_paused']??null);
        } catch (\Throwable) { $result['Encrypted facts']='Unavailable; review encryption access'; }
        $jobs = DB::table('domains_observation_work')->where('domain_id',$d->id)->where('server_id',$d->server_id)
            ->where('account_fingerprint',$d->account_fingerprint)->where('generation',$d->authority_generation)->orderByDesc('id')->limit(5)->get();
        $result['Observation work'] = $jobs->map(fn($j)=>self::work($j))->all() ?: ['Not scheduled'];
        $holds=PaidUncertainty::for($d); if($holds){$result['Account spending holds']=$holds;}
        $reviews=PaidUncertainty::reviews($d); if($reviews){$result['Paid outcome review']=$reviews;}
        $activity=\Paymenter\Extensions\Servers\Domains\Services\RenewalReplacements::activity($d);if($activity){$result['Refunded debt review']=$activity;}
        $result += ReminderFacts::for($d);
        $result['Data retention']='Encrypted contact and operation evidence is retained. Erasure requires a separately reviewed policy.';
        return $result;
    }
    public static function recordHold(Domain $d): ?string
    {
        Staff::requireView();
        try {
            if (!\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::stopped($d)) { return null; }
            if (!\Paymenter\Extensions\Servers\Domains\Services\ObservationRecords::held($d)) { return 'Scheduled reads need staff review before they can resume.'; }
            return match($d->observation['record_hold']['reason']??null) {
                'history_limit'=>'Record history has reached its safety limit.',
                'ownership_drift'=>'The registrar account no longer lists this domain.',
                'records_unavailable'=>'Current records could not be confirmed.',
                default=>'The record check needs review.' };
        } catch (\Throwable) { return 'Record check details are unavailable; review encryption access.'; }
    }
    private static function work(object $job): string
    {
        $tries=(int)$job->tries; $resumes=(int)$job->resumes;
        // Attempts include healthy pending checks; a matching refresh resets this counter.
        return ($job->safe_code===null ? ($job->claim===null?'Waiting for next check':($job->lease_until<=time()?'Check interrupted; review before resuming':'Check in progress')) : Labels::reason($job->safe_code))
            .($job->safe_code==='refreshed'?'':' · '.$tries.' check'.($tries===1?'':'s'))
            .($resumes>0?' · '.$resumes.' resume'.($resumes===1?'':'s'):'');
    }
}
