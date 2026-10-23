<?php
// Adapted from Enom 444f31c Admin/Support/DomainFacts and DomainAdministration read paths.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain};
use Paymenter\Extensions\Servers\Domains\Services\{RenewalStop,StaffHoldingReview};
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
        if ($d->adoption_id !== null) {
            try {
                $a = \Paymenter\Extensions\Servers\Domains\Models\Adoption::find($d->adoption_id);
                $readable = is_array($a?->evidence) && is_array($d->snapshot['holding'] ?? null);
                $result['Adoption evidence'] = $readable ? 'Retained; checked before renewal' : 'Unavailable; staff review required';
            } catch (\Throwable) { $result['Adoption evidence'] = 'Unavailable; staff review required'; }
        }
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
        try{$renewal=RenewalStop::read($d);$result['Future renewals']=match(true){
            RenewalStop::adopted($renewal)=>'Off: original adoption requested no renewal.',
            RenewalStop::blocksExisting($renewal)=>'Stopped: service cancellation pending review.',
            (bool)($d->renewal_policy['renewals_stopped']??false)=>'Stopped by staff. Issued invoices stay payable.',
            RenewalStop::stopped($renewal)=>'Stops at end of current period (service cancellation).',default=>'On'};
        $history=$d->renewal_policy['staff_holdings']??[];
        $result['Change history']=array_map(static fn($r)=>(isset($r['at'])?(new \DateTimeImmutable($r['at']))->setTimezone(new \DateTimeZone('UTC'))->format('M j, Y H:i').' UTC':'Unknown time').' · Staff #'.($r['actor_id']??'?').' · '.(StaffHoldingReview::ACTIONS[$r['action']??'']??'Review').' · '.($r['reason']??''),array_slice($history,-5))?:['None'];}catch(\Throwable){$result['Future renewals']='Unavailable; review encryption access';$result['Change history']='Unavailable; review encryption access';}
        $result += ReminderFacts::for($d);
        $result['Data retention']='Encrypted contact and operation evidence is retained. Erasure requires a separately reviewed policy.';
        return $result;
    }
    public static function adoptionReview(Domain $d): array
    {
        Staff::requireView();
        if ($d->provenance !== 'adopted') { return []; }
        try {
            $review = $d->observation['adoption_review'] ?? []; $notes = [];
            $renewal = RenewalStop::read($d);
            if (RenewalStop::adopted($renewal)) { $notes[] = 'Renewal is off. No renewal invoices or renewal reminders are scheduled.'; }
            elseif (RenewalStop::stopped($renewal)) { $notes[] = 'Future renewal billing is stopped. Existing issued invoices and their reminders retain their own rules.'; }
            elseif (($review['near_due'] ?? false) === true) { $notes[] = 'The renewal billing cutoff was reached at adoption. Review this registration; no new automatic invoice can be promised.'; }
            elseif (($review['inside_issue_window'] ?? false) === true) { $notes[] = 'This registration was inside its billing window at adoption. Ordinary billing may issue an invoice and apply available credit immediately when its gates pass.'; }
            if (($review['price'] ?? null) !== null) { $notes[] = \Paymenter\Extensions\Servers\Domains\Data\Adoption\Review::priceNotice($review['price']); }
            return $notes;
        } catch (\Throwable) { return ['Adoption review details are unavailable. Ask an administrator to review encryption access.']; }
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
