<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{User,Service};
use Paymenter\Extensions\Servers\Domains\Admin\Support\RequestFacts;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Domain};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainStatus,DomainSummary,Missing};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Batched local projection. No transport, writes, locks, lazy model relations or actor cache. */
final class DomainSummaries
{
    public function one(User $owner,int $id,?int $now=null): ?DomainSummary
    { return $this->batch($owner,[$id],$now)['managed:'.$id]??null; }
    public function services(User $owner,array $ids,?int $now=null): array
    {
        if (count($ids)>250) { throw new \InvalidArgumentException('At most 250 services per batch.'); }
        $native=Service::whereIn('id',$ids)->where('user_id',$owner->id)->whereHas('order',fn($q)=>$q->where('user_id',$owner->id))->pluck('id')->all();
        $domains=Domain::where('user_id',$owner->id)->whereIn('service_id',$native)->pluck('id','service_id')->all();
        $dtos=$this->batch($owner,array_values($domains),$now);$out=[];
        foreach ($domains as $service=>$id) { if (isset($dtos['managed:'.$id])) { $out[$service]=$dtos['managed:'.$id]; } }
        return $out;
    }
    public function listed(User $owner,int $now): array
    {
        $base=Domain::where('user_id',$owner->id)->whereHas('service',fn($q)=>$q->where('user_id',$owner->id)->whereHas('order',fn($q)=>$q->where('user_id',$owner->id)));$rows=[];
        foreach ((clone $base)->pluck('id')->chunk(250) as $ids) { $rows+=$this->batch($owner,$ids->all(),$now); }
        return array_filter($rows,fn($row)=>!$row->abandoned && !$row->neverPaidOrder());
    }
    public function batch(User $owner,array $domainIds,?int $now=null): array
    {
        $domainIds=array_unique($domainIds);
        if (count($domainIds)>250) { throw new \InvalidArgumentException('At most 250 domains per batch.'); }
        if (!$domainIds) { return []; }
        $now??=app(Clock::class)->now()->getTimestamp();
        $owned=fn($q)=>$q->where('user_id',$owner->id)->whereHas('order',fn($q)=>$q->where('user_id',$owner->id));
        $domains=Domain::where('user_id',$owner->id)->whereIn('id',$domainIds)->whereHas('service',$owned)->get()->keyBy('id')->all();
        $services=Service::whereIn('id',array_map(fn($d)=>$d->service_id,$domains))->where('user_id',$owner->id)->get()->keyBy('id')->all();
        $renewals=[];
        // Status, order and renewal: the same projection the domain page reads, loaded once for the batch.
        // Billing issues renewals on the clock's own date, so the projection and the billing dates below use the clock's time zone, as the domain page does.
        $inputs=DomainStateInputs::load($domains,$services);$at=(new \DateTimeImmutable('@'.$now))->setTimezone(app(\Paymenter\Extensions\Servers\Domains\Contracts\Clock::class)->now()->getTimezone());$billingDay=$at->format('Y-m-d');
        $testServers=Account::whereIn('server_id',array_map(fn($d)=>$d->server_id,$domains))->where('environment','test')->pluck('server_id')->all();
        $out=[];
        foreach ($domains as $id=>$t) {
            $states=[];$invoiceCancelled=false;
            $expiry=null;$precision=null;$source=null;$order=[];$due=null;$price=null;$off=false;$reminderOff=false;$projected=false;$invoiceMissing=false;$invoiceOverdue=false;$invoicePaid=false;$invoiceReview=false;$renewalClosed=false;$goes=null;$issue=null;$waits=null;
            $pending=$t->adoptionPending();$registration='domain.registration.'.($pending?'unknown':(in_array($t->status,['active','expired','grace','redemption'],true)?'platform':'unknown'));
            $states[]=$pending?'adopt.pending':match($t->status){'active'=>$t->provider_expires_at===null?'domain.review':(DomainStatus::expiry($t->provider_expires_at->format('Y-m-d'),gmdate('Y-m-d',$now))==='expired'?'domain.expired':'domain.active'),'expired','grace'=>'domain.expired','redemption'=>'domain.redemption','registration_pending'=>'domain.pending','transfer_pending'=>'domain.transfer','cancelled'=>'domain.cancelled',default=>'domain.review'};
            if (!$pending) { $expiry=$t->provider_expires_at?->format('Y-m-d');$precision=$expiry?'date':null;$source=$expiry?'Confirmed registration':null; }
            $state=DomainStates::project($t,$inputs,$at);$op=$state->acquisition;
            if(!$pending && $op && (int)$op->service_id===(int)$t->service_id && (int)$op->server_id===(int)$t->server_id && $op->registrar_key===$t->registrar_key){if($op->state==='awaiting_payment'){$states=['domain.awaiting_payment'];}elseif(in_array($op->state,['held','uncertain'],true)){$states=[$op->action==='register' && !in_array($op->safe_code,['contact_details_required','transfer_setup_review_required','nameservers_required'],true)?'domain.processing':'domain.review'];}}
            $s=$services[$t->service_id];$a=$state->stops['adoption'];$off=$state->renewalOff;
            $reminderOff=$t->provenance==='adopted' && $a && $a->do_not_renew;
            if ($t->provenance==='adopted') { $states[]='adopt.provenance'; }
            if ($state->stops['policy']===null) { $states[]='domain.review'; } elseif ($off && !$pending) { $states[]='adopt.renewal_off'; }
            if (!$pending) { try { if ($s->currency_code==='USD') { $price=Storefront::money(HolderPricing::shown($t,$s,(new \DateTimeImmutable)->setTimestamp($now),$renewals)); } } catch (\Throwable) {} }
            if($t->price_mode==='complimentary'){$price=ComplimentaryAccounts::LABEL;}
            // The renewal invoice the page shows, in the same words; with none, the saved calendar's next one.
            if ($invoice=$state->invoice()) {
                $issued=$invoice['status']??'pending';$due=$state->due;
                $invoiceReview=$issued==='review';$renewalClosed=$issued==='closed';$invoiceCancelled=$issued==='cancelled';
                $invoiceMissing=$due===null && in_array($issued,['pending','paid'],true);
                $invoicePaid=$issued==='paid' && !$invoiceMissing;
                $goes=$invoicePaid?($invoice['goes']??null):null;
            } elseif ($expiry && !$off) { $projected=true;
                if ($state->calendar) { $due=$state->calendar['due'];$issue=$state->calendar['issue'];$invoiceMissing=$issue<$billingDay; } }
            $order=$state->order;$managedStatus=$state->status;
            $url=route('domains.domains.show',$id);
            if ($due && $due<=$billingDay) { $due=null; }
            // The renewal invoice is due before expiry; once expiry has passed the list shows Expired instead. The detail page still says it was never issued.
            $invoiceOverdue=$invoiceMissing;
            if (!$expiry || $expiry<=gmdate('Y-m-d',$now)) { $invoiceMissing=false; }
            if (!$expiry) { $waits=$pending?'import':(['registration_pending'=>'registration','transfer_pending'=>'transfer'][$t->status]??null); }
            [$status,$reason]=$managedStatus??[null,null];
            $out['managed:'.$id]=new DomainSummary($t->domain,null,'managed',$registration,[],null,null,array_values(array_unique($states)),DomainSummaryStrings::all(),
                $expiry,$precision,$source,[],$due,$price,[],[],['detail'=>$url,'invoiceHistory'=>$pending?route('invoices'):null,'support'=>Missing::supportUrl()],
                renewalOff:$off,renewalReminderOff:$reminderOff,renewalDueProjected:$projected,testEnvironment:in_array($t->server_id,$testServers),
                nameservers:ObservationProjection::nameservers($t)?->toArray(),statusState:$status,statusReason:$reason,demo:DemoData::is($t),
                daysLeft:$expiry?intdiv(strtotime($expiry.' 00:00:00 UTC')-strtotime(gmdate('Y-m-d',$now).' 00:00:00 UTC'),86400):null,
                renewalInvoiceMissing:$invoiceMissing,expiryWaits:$waits,renewalIssueAt:$issue,order:$order,abandoned:$op && UnpaidAcquisitionProof::terminal($op,$t) && RequestFacts::neverPaid($op),
                renewalInvoicePaid:$invoicePaid,renewalInvoiceReview:$invoiceReview,renewalGoesAt:$goes,renewalClosed:$renewalClosed,
                renewalInvoiceCancelled:$invoiceCancelled,renewalInvoiceOverdue:$invoiceOverdue);
        }
        return $out;
    }
}
