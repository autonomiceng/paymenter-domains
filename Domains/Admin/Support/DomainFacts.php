<?php
// Adapted from Enom 444f31c Admin/Support/DomainFacts and DomainAdministration read paths.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource, OperationResource};
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\DomainStatus;
use Paymenter\Extensions\Servers\Domains\Services\{BillingCalendar, DomainStates, ObservationRecords, ObservationWork, RenewalReplacements, RenewalStop};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Request-local allowlisted projection. Never cache across actors, call providers or serialize models.
 * Each fact is ['value'=>?string,'sub'=>?string,'url'=>?string,'tone'=>?string,'help'=>?string,'demo'=>bool].
 * A null value is Missing; a row that does not apply is absent. */
final class DomainFacts
{
    public const REGISTRATION=['Expires','Registrar','In registrar account','Transfer lock','WHOIS privacy','Registrar auto-renew','Last sync'];
    public const BILLING=['Customer','Service','Auto-renew','Renewal price','Renewal invoices','Next invoice','Open invoice'];
    public const HELP=['Transfer lock'=>'Registrar lock that blocks transfers to another registrar.',
        'Registrar auto-renew'=>"The registrar's own auto-renew. Keep it off: Beco renews after the customer pays."];
    public const UNREADABLE="Can't read saved data. Check the app encryption key.";
    public const UNREADABLE_RENEWAL="Can't read renewal settings";
    /** Statuses where the registration exists, so its dates and registrar settings apply. */
    private const REGISTERED=['active','expired','grace','redemption'];
    public static function for(Domain $d): array
    {
        Staff::requireView();
        $pending=$d->adoptionPending(); $registered=$pending||in_array($d->status,self::REGISTERED,true);
        $account=self::account($d); $sync=self::sync($d,$account); $paused=$sync==='paused'; $f=[];
        try { $observation=$d->observation??[]; $policy=$d->renewal_policy??[]; } catch (\Throwable) { $observation=$policy=null; }
        if ($registered) {
            $expiry=$d->provider_expires_at?->format('Y-m-d');
            if ($pending) { $expiry??=is_string($policy['conversion_source_expiry']??null)?$policy['conversion_source_expiry']:null; }
            $f['Expires']=self::fact($expiry?gmdate('M j, Y',strtotime($expiry.' 00:00:00 UTC')):null,sub:$expiry?null:self::unread($d,$sync),
                help:$pending&&$expiry?'From the old record. Not synced yet.':null);
        }
        $registrar=['enom'=>'Enom','namesilo'=>'NameSilo'][$d->registrar_key]??null;
        $f['Registrar']=$account&&$registrar ? self::fact(\App\Models\Server::whereKey($d->server_id)->value('name')??$registrar,
            sub:implode(' · ',array_filter([$registrar,['test'=>'Test','live'=>'Live'][$account->environment]??null,
                $paused||!$account->processing_enabled||($policy['automatic_spend_paused']??false)?'orders paused':null])),
            url:self::url(fn()=>\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::canAccess()?\Paymenter\Extensions\Servers\Domains\Admin\Pages\RegistrarSetup::getUrl():null),
            demo:DemoData::is($account)) : self::fact(null,sub:$account?'Unknown registrar':'Registrar account not found');
        if ($registered && $observation!==null) {
            $facts=$observation['facts']??[];
            $unread=self::unread($d,$sync);
            $f['In registrar account']=self::fact(($observation['record_hold']['reason']??null)==='ownership_drift'?'No':self::flag($facts['owned']??null,'Yes','No'),sub:isset($facts['owned'])?null:$unread);
            $f['Transfer lock']=self::fact(self::flag($facts['locked']??null),sub:isset($facts['locked'])?null:$unread,help:self::HELP['Transfer lock']);
            $f['WHOIS privacy']=self::fact(self::flag($facts['privacy']??null),sub:isset($facts['privacy'])?null:$unread);
            $f['Registrar auto-renew']=self::fact(self::flag($facts['auto_renew']??null),sub:isset($facts['auto_renew'])?null:$unread,help:self::HELP['Registrar auto-renew']);
        }
        $job=!Schema::hasTable('domains_observation_work')||($id=ObservationWork::currentId($d))===null?null:DB::table('domains_observation_work')->find($id);
        if ($registered||$job||$d->last_synced_at) { $f['Last sync']=match(true) {
            $paused=>self::fact('Paused',sub:'Demo registrar is off while Enom Test mode is on',tone:'warning'),
            $sync==='failed'=>self::fact('Sync failed',sub:(int)$job->tries.' attempt'.((int)$job->tries===1?'':'s').'. Stopped until resumed.',tone:'danger'),
            default=>self::fact($d->last_synced_at?->utc()->format('M j, Y H:i').($d->last_synced_at?' UTC':''),
                sub:$job&&$job->claim!==null&&$job->lease_until>time()?'Syncing now':($d->last_synced_at?null:'Not synced yet')),
        }; }
        $user=$d->user;
        $f['Customer']=$user?self::fact(trim($user->first_name.' '.$user->last_name)?:$user->email,sub:$user->email,
            url:self::url(fn()=>\App\Admin\Resources\UserResource::getUrl('edit',['record'=>$user->id]))):self::fact(null,sub:'Customer account not found');
        $service=\App\Models\Service::find($d->service_id);
        $f['Service']=$service?self::fact($d->domain.' registration',sub:'Service #'.$service->id,
            url:self::url(fn()=>\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$service->id]))):self::fact(null,sub:'Service #'.$d->service_id.' not found');
        if ($policy!==null) {
            try { $renewal=RenewalStop::read($d); } catch (\Throwable) { $renewal=null; }
            $cancel=$renewal&&$renewal['cancel']->contains('service_id',$d->service_id);
            $autoOff=match(true) {$renewal===null=>null,RenewalStop::adopted($renewal)=>'Set at import',
                $cancel&&!RenewalStop::blocksExisting($renewal)=>'Cancels at period end',RenewalStop::ownerOff($renewal)=>'Turned off by customer',default=>null};
            $invoicesOff=match(true) {$pending=>'Until import',$renewal&&RenewalStop::blocksExisting($renewal)=>'Service cancellation pending',
                (bool)($policy['renewals_stopped']??false)=>'Stopped by staff',default=>null};
            $f['Auto-renew']=$renewal===null?self::fact(null,sub:self::UNREADABLE_RENEWAL):self::fact($autoOff?'Off':'On',sub:$autoOff);
            $f['Renewal price']=self::price($d,$service,$observation);
            $f['Renewal invoices']=self::fact($invoicesOff?'Off':'On',sub:$invoicesOff);
            if (!$pending&&$d->status==='active'&&$renewal&&!$autoOff&&!$invoicesOff) { $f['Next invoice']=self::nextInvoice($d); }
        }
        $invoice=DB::table('domains_operations as o')->join('invoices as i','i.id','=','o.invoice_id')->where('o.domain_id',$d->id)
            ->where('o.service_id',$d->service_id)->where('i.status','pending')->orderByDesc('i.id')->value('i.id');
        if ($invoice) { $f['Open invoice']=self::fact('Invoice #'.$invoice,url:self::invoiceUrl((int)$invoice)); }
        return $f;
    }
    /** The domain pill and reason. $renewalOff and $late are passed by the list, which batches them. */
    public static function status(Domain $d, ?bool $renewalOff=null, ?bool $late=null): array
    {
        $late??=AcquisitionPaymentReview::count($d)>0;
        if ($late) { return DomainStatus::for('needs_review','Paid after order closed'); }
        if ($renewalOff===null) { try { $renewalOff=RenewalStop::stopped(RenewalStop::read($d)); } catch (\Throwable) { $renewalOff=false; } }
        $op=$d->latestAcquisition;
        [$state,$reason]=DomainStates::managed($d,$op,gmdate('Y-m-d'),$renewalOff);
        if (in_array($state,['held','uncertain','needs_review'],true)&&in_array($op?->state,['held','uncertain'],true)&&$op->safe_code!==null) { $reason=Labels::reason($op->safe_code); }
        return DomainStatus::for($state,$reason);
    }
    /** The one page notice: the most urgent cause and its fix. */
    public static function notice(Domain $d): ?array
    {
        Staff::requireView();
        try { $d->observation; $d->renewal_policy; $d->snapshot; } catch (\Throwable) { return ['title'=>self::UNREADABLE,'tone'=>'danger']; }
        $request=DomainQueries::operations($d)->orderByDesc('id')->value('id');
        $requestLink=$request?['label'=>'Open request #'.$request,'url'=>self::url(fn()=>OperationResource::getUrl('view',['record'=>$request]))]:null;
        // Sync cannot run until a pending import is confirmed, so the import notice comes first.
        if ($d->adoptionPending()) {
            return ['title'=>'Imported without purchase records. Check open invoices, then import.','tone'=>'warning',
                'action'=>Staff::can(Staff::ADOPT_PREVIEW)?['label'=>'Import domain','url'=>self::url(fn()=>DomainResource::getUrl('adoption',['record'=>$d->id]))]:null];
        }
        if (($hold=self::recordHold($d))!==null) { return ['title'=>$hold,'tone'=>'warning','resume'=>true,'link'=>$requestLink]; }
        if (AcquisitionPaymentReview::count($d)>0) {
            $invoice=DB::table('domains_obligation_alerts as a')->join('domains_operations as o','o.id','=','a.operation_id')->where('a.domain_id',$d->id)
                ->where('a.code','acquisition_late_money')->whereNull('a.resolved_at')->orderByDesc('a.id')->value('o.invoice_id');
            return ['title'=>'Paid after the order closed.','body'=>'Check the invoice and payments before contacting the customer.','tone'=>'warning',
                'action'=>self::invoiceAction($invoice)];
        }
        if (RenewalReplacements::activity($d)) {
            $invoice=$d->operations()->get()->first(fn(Operation $op)=>array_key_exists('renewal_replacement',$op->policy??[]))?->invoice_id;
            return ['title'=>'Payment received after refund. Check the invoice.','tone'=>'warning','action'=>self::invoiceAction($invoice)];
        }
        if ($d->provenance==='adopted'&&($d->observation['adoption_review']['near_due']??false)===true&&!RenewalStop::stopped(RenewalStop::read($d))) {
            return ['title'=>'Imported after the invoice date. No renewal invoice was issued.','tone'=>'warning',
                'action'=>['label'=>'Open service','url'=>self::url(fn()=>\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$d->service_id]))]];
        }
        if (!self::account($d)) { return ['title'=>'Registrar account changed.','tone'=>'warning']; }
        $op=$d->latestAcquisition;
        if ($op&&in_array($op->state,['held','uncertain'],true)&&in_array($d->status,['registration_pending','transfer_pending','needs_review'],true)) {
            $title=Labels::action($op->action).($op->state==='held'?' not sent: '.lcfirst(Labels::reason($op->safe_code)).'.':' not confirmed by the registrar.');
            return ['title'=>$title,'tone'=>'warning','action'=>['label'=>'Open request #'.$op->id,'url'=>self::url(fn()=>OperationResource::getUrl('view',['record'=>$op->id]))]];
        }
        return null;
    }
    public static function recordHold(Domain $d): ?string
    {
        Staff::requireView();
        try {
            if (!ObservationRecords::stopped($d)) { return null; }
            if (!ObservationRecords::held($d)) { return 'Sync stopped after repeated failures.'; }
            return match($d->observation['record_hold']['reason']??null) {
                'history_limit'=>'Sync stopped: DNS record history is full.',
                'ownership_drift'=>'Sync stopped: domain is not in the registrar account.',
                'records_unavailable'=>"Sync stopped: couldn't read DNS records.",
                default=>'Sync stopped: DNS record check failed.' };
        } catch (\Throwable) { return self::UNREADABLE; }
    }
    /** 'paused' (demo registrar off under Enom Test mode), 'failed' (stopped after repeated failures) or null. Shared by the page and the list. */
    public static function sync(Domain $d, ?Account $account=null): ?string
    {
        $account??=self::account($d);
        return $account&&DemoData::paused($account)?'paused':(ObservationWork::stopped($d)?'failed':null);
    }
    /** Why a value read from the registrar is Missing. */
    public static function unread(Domain $d, ?string $sync): string
    {
        return $d->adoptionPending()?'Shows after import':match($sync) {'paused'=>'Sync paused','failed'=>'Sync failed',
            default=>$d->last_synced_at?'Registrar did not return it':'Not synced yet'};
    }
    private static function account(Domain $d): ?Account
    {
        return Account::where('server_id',$d->server_id)->where('registrar_key',$d->registrar_key)->where('account_fingerprint',$d->account_fingerprint)->first();
    }
    private static function price(Domain $d, ?\App\Models\Service $service, ?array $observation): array
    {
        if (!$service) { return self::fact(null,sub:'Needs the linked service'); }
        if (!in_array($d->price_mode,['standard','complimentary'],true)) { return self::fact(null,sub:'No renewal price set'); }
        $code=$observation['adoption_review']['price']??null;
        return self::fact($service->currency_code.' '.number_format((float)$service->price,2).' / year',
            sub:match(true) {$code==='below_cost'=>'Below cost',$code!==null=>'Cost missing',$d->price_mode==='complimentary'=>'Complimentary',default=>'Standard pricing'},
            tone:$code===null?null:'danger');
    }
    private static function nextInvoice(Domain $d): array
    {
        if (!$d->provider_expires_at) { return self::fact(null,sub:'Needs the expiry date'); }
        $server=\App\Models\Server::with('settings')->find($d->server_id);
        $timing=BillingCalendar::timing($server?->settings??collect());
        $issue=BillingCalendar::dates($d->provider_expires_at,$timing['issue'],$timing['due'])['issue'];
        return $issue>=gmdate('Y-m-d') ? self::fact(gmdate('M j, Y',strtotime($issue.' 00:00:00 UTC'))) : self::fact(null,sub:'Not issued');
    }
    private static function invoiceAction(mixed $invoice): ?array
    { return ($url=$invoice?self::invoiceUrl((int)$invoice):null)?['label'=>'Open invoice','url'=>$url]:null; }
    private static function invoiceUrl(int $id): ?string
    {
        $invoice=\App\Models\Invoice::find($id);
        return $invoice&&\App\Admin\Resources\InvoiceResource::canEdit($invoice)?self::url(fn()=>\App\Admin\Resources\InvoiceResource::getUrl('edit',['record'=>$id])):null;
    }
    /** Links to pages the current panel may not register resolve to no link. */
    private static function url(\Closure $make): ?string { try { return $make(); } catch (\Throwable) { return null; } }
    private static function flag(mixed $value, string $on='On', string $off='Off'): ?string { return is_bool($value)?($value?$on:$off):null; }
    private static function fact(?string $value, ?string $sub=null, ?string $url=null, ?string $tone=null, ?string $help=null, bool $demo=false): array
    { return ['value'=>$value===''?null:$value,'sub'=>$sub===''?null:$sub,'url'=>$url,'tone'=>$tone,'help'=>$help,'demo'=>$demo]; }
}
