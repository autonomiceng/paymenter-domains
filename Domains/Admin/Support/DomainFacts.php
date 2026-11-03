<?php
// Adapted from Enom 444f31c Admin/Support/DomainFacts and DomainAdministration read paths.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainStatus, Missing};
use Paymenter\Extensions\Servers\Domains\Services\{BillingCalendar, DomainStates, ObligationAlerts, ObservationProjection, ObservationRecords, ObservationWork, OwnedDomains, OwnerRecords, RenewalReplacements, RenewalStop};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Request-local allowlisted projection. Never cache across actors, call providers or serialize models.
 * Each fact is ['value'=>string|list<string>|null,'sub'=>?string,'url'=>?string,'tone'=>?string,'help'=>?string,'demo'=>bool], and 'at' (a timestamp) on a time the page shows in the viewer's zone.
 * A null value is Missing; a row that does not apply is absent. */
final class DomainFacts
{
    /** The page's sections and their rows, in order. */
    public const SECTIONS=['Registration'=>['Expires','Registrar','In registrar account','Transfer lock','Auth code','WHOIS privacy','Registrar auto-renew','Last sync'],
        'Renewal'=>['Renewal invoices','Renewal price','Next invoice','Open invoice'],
        'Contacts and DNS'=>['Contacts','Nameservers','Glue records','DNS records'],
        'Billing'=>['Customer','Service']];
    /** Rows that open another page, and what their link says. */
    public const LINKS=['Registrar'=>'Open registrar','Open invoice'=>'Open invoice','Customer'=>'Open customer','Service'=>'Open service'];
    public static function help(): array
    {
        $company = \Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::company();
        return ['Transfer lock'=>'Registrar lock that blocks transfers to another registrar.',
            'Registrar auto-renew'=>"The registrar's own auto-renew. Keep it off: ".($company !== '' ? $company.' renews' : 'we renew').' after the customer pays.',
            'Renewal invoices'=>($company !== '' ? $company.' sends' : 'We send').' a renewal invoice before this domain expires. Auto Pay is a separate setting.'];
    }
    /** Why the registrar's own auto-renew reads Off. */
    private static function registrarRenewOff(): string
    {
        $company = \Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::company();
        return 'Off on purpose. '.($company !== '' ? $company.' renews' : 'We renew').' the domain after the invoice is paid, so you are never charged by the registrar directly.';
    }
    /** Open alerts by kind: the row each concerns and its tone. ObligationAlerts::LABELS has the words. The row keeps its value; with no value it is Missing.
     * Payment activity after the order closed shows in the status and the page notice instead. */
    public const ALERTS=[
        'unknown_expiry'=>['Expires','danger'],
        'transfer_watch_relink'=>['Registrar','warning'],
        'operation_review'=>['Registrar','warning'],
        'funds_wait_review'=>['Registrar','warning'],
        'renewal_funds'=>['Registrar','warning'],
        'unknown_payment_time'=>['Registrar','danger'],
        'observation_review'=>['Last sync','warning'],
        'native_identity_missing'=>['Service','danger'],
        'acquisition_retirement_review'=>['Service','warning'],
        'refund_repair'=>['Service','warning'],
        'unknown_price'=>['Renewal price','danger'],
        'unknown_cost'=>['Renewal price','danger'],
        'below_cost'=>['Renewal price','danger'],
        'renewal_unpaid'=>['Renewal invoices','warning'],
        'held_obligation'=>['Renewal invoices','warning'],
        'invalid_invoice'=>['Renewal invoices','warning'],
        'credit_notice_overdue'=>['Renewal invoices','warning'],
        'schedule_binding'=>['Renewal invoices','warning'],
        'delivery_uncertain'=>['Renewal invoices','warning'],
        'template_failed'=>['Renewal invoices','warning'],
        'reminder_failed'=>['Renewal invoices','warning'],
        'missing_obligation'=>['Next invoice','danger'],
        'missing_execution'=>['Next invoice','danger'],
    ];
    public const UNREADABLE="Can't read saved data. Ask your site admin to check the encryption key.";
    public const UNREADABLE_RENEWAL="Can't read renewal settings. Ask your site admin to check the encryption key.";
    /** Statuses where the registration exists, so its dates and registrar settings apply. */
    private const REGISTERED=['active','expired','grace','redemption'];
    public static function registered(Domain $d): bool { return $d->adoptionPending()||in_array($d->status,self::REGISTERED,true); }
    /** Nothing renews on these, so renewal facts do not apply. A cancellation's saved intent stays as a guard and is not shown. */
    public static function ended(Domain $d): bool { return in_array($d->status,['cancelled','registration_rejected','transfer_rejected'],true); }
    public static function for(Domain $d): array
    {
        Staff::requireView();
        $pending=$d->adoptionPending(); $registered=self::registered($d);
        $account=self::account($d); $sync=self::sync($d,$account,accountLoaded:true); $paused=$sync==='paused'; $f=[];
        try { $observation=$d->observation??[]; $policy=$d->renewal_policy??[]; } catch (\Throwable) { $observation=$policy=null; }
        if ($registered) {
            $expiry=$d->provider_expires_at?->format('Y-m-d');
            if ($pending) { $expiry??=is_string($policy['conversion_source_expiry']??null)?$policy['conversion_source_expiry']:null; }
            $f['Expires']=self::fact($expiry?gmdate('M j, Y',strtotime($expiry.' 00:00:00 UTC')):null,sub:$expiry?null:self::unread($d,$sync),
                help:$pending&&$expiry?'From the old record. Not synced yet.':null);
        }
        $registrar=['enom'=>'Enom','namesilo'=>'NameSilo'][$d->registrar_key]??null;
        $f['Registrar']=$account&&$registrar ? self::fact(\App\Models\Server::whereKey($d->server_id)->value('name')??$registrar,
            sub:implode(', ',array_filter([$registrar,['test'=>'Test','live'=>'Live'][$account->environment]??null,
                $paused||!$account->processing_enabled||($policy['automatic_spend_paused']??false)?'orders paused':null])),
            url:self::url(fn()=>RegistrarFacts::accountUrl((int)$d->server_id)),
            demo:DemoData::is($account)) : self::fact(null,sub:$account?'Unknown registrar. Check Registrars.':'Registrar account not found. Check Registrars.');
        if ($registered && $observation!==null) {
            $facts=$observation['facts']??null;
            $facts=is_array($facts)&&ObservationProjection::matches($d,$facts)?$facts:[];
            $unread=self::unread($d,$sync);
            $f['In registrar account']=self::fact(ObservationRecords::held($d)&&($observation['record_hold']['reason']??null)==='ownership_drift'?'No':self::flag($facts['owned']??null,'Yes','No'),sub:isset($facts['owned'])?null:$unread);
            try { $contact=OwnedDomains::owner(OwnedDomains::contacts($d)); $none=$pending?self::unread($d,$sync):(Staff::can('admin.domains.manage')?'Add them with Edit':'Ask the customer to add them on the domain page'); }
            catch (\Throwable) { $contact=null; $none=self::UNREADABLE; }
            $f['Contacts']=self::fact($contact['name']??null,sub:$contact['email']??$none);
            $nameservers=OwnedDomains::storedNameservers($d);
            $f['Nameservers']=self::fact($nameservers,sub:$nameservers?null:$unread,demo:$nameservers&&$account&&DemoData::is($account));
            try { $sections=ObservationProjection::sections($d); $records=OwnerRecords::forDomain($d,$sections); }
            catch (\Illuminate\Contracts\Encryption\DecryptException) { $sections=$records=[]; }
            if ($d->registrar_key==='enom'&&filter_var($d->server?->settings()->where('key','domains_dns_tools')->value('value'),FILTER_VALIDATE_BOOLEAN)) {
                $count=isset($records['dns'])?count($records['dns']):null;
                $f['DNS records']=self::fact($count===null?null:$count.' '.($count===1?'record':'records'),sub:$count===null?(ObservationRecords::limitNotice($d)??'Use Sync now to read DNS records.'):implode(' · ',array_filter([isset($records['srv'])?count($records['srv']).' SRV':null,isset($records['dns'])?'As of '.($sections['dns']['at']??''):null])));
            }
            $glue=$records['glue']??[];
            if ($glue) {
                $warnings=\Paymenter\Extensions\Servers\Domains\Services\GlueDrift::warnings($d,known:$sections['known_glue']??[]);
                $at=\Paymenter\Extensions\Servers\Domains\Services\GlueRecords::asOf($d,$sections);
                $f['Glue records']=self::fact(array_map(fn($row)=>$row['host'].' '.$row['ip'],$glue),sub:implode(' ',array_filter([$at?'As of '.$at:null,...$warnings])),tone:$warnings?'warning':null,help:'Needed when nameservers are named under this domain.');
            }
            $locked=OwnedDomains::storedFlag($d,'lock','locked');
            $f['Transfer lock']=self::fact(self::flag($locked),sub:$locked!==null?null:$unread,help:self::help()['Transfer lock']);
            // Enom's privacy is not sold, so its row shows only on a domain that already has it. Other registrars keep theirs.
            $privacy=OwnedDomains::storedFlag($d,'privacy','privacy');
            if ($d->registrar_key!=='enom'||$privacy===true) { $f['WHOIS privacy']=self::fact(self::flag($privacy),sub:$privacy!==null?null:$unread); }
            $renews=$facts['auto_renew']??null;
            $f['Registrar auto-renew']=self::fact(self::flag($renews),sub:match($renews) {null=>$unread,false=>self::registrarRenewOff(),default=>null},help:$renews?self::help()['Registrar auto-renew']:null);
        }
        $job=!Schema::hasTable('domains_observation_work')||($id=ObservationWork::currentId($d))===null?null:DB::table('domains_observation_work')->find($id);
        if ($registered||$job||$d->last_synced_at) { $f['Last sync']=match(true) {
            $paused=>self::fact('Paused',sub:'Demo registrar is off while Enom Test mode is on',tone:'warning'),
            $sync==='failed'=>self::fact('Sync failed',sub:(int)$job->tries.' attempt'.((int)$job->tries===1?'':'s').'. Stopped until resumed.',tone:'danger'),
            default=>['at'=>$d->last_synced_at?->getTimestamp(),...self::fact($d->last_synced_at?->utc()->format('M j, Y H:i').($d->last_synced_at?' UTC':''),
                sub:$job&&$job->claim!==null&&$job->lease_until>time()?($d->last_synced_at?'Syncing now':Missing::fills('when this sync finishes')):($d->last_synced_at?null:Missing::fills('within a day')))],
        }; }
        $user=$d->user;
        $f['Customer']=$user?self::fact(trim($user->first_name.' '.$user->last_name)?:$user->email,sub:$user->email,
            url:self::url(fn()=>\App\Admin\Resources\UserResource::getUrl('edit',['record'=>$user->id]))):self::fact(null,sub:'Customer account not found. Check the Users page.');
        $service=\App\Models\Service::find($d->service_id);
        $f['Service']=$service?self::fact($d->domain.' registration',sub:'Service #'.$service->id,
            url:self::url(fn()=>\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$service->id]))):self::fact(null,sub:'Service #'.$d->service_id.' not found. Check the Services page.');
        if ($policy!==null&&!self::ended($d)) {
            try { $renewal=RenewalStop::read($d); } catch (\Throwable) { $renewal=null; }
            $cancel=$renewal&&$renewal['cancel']->contains('service_id',$d->service_id);
            $off=match(true) {$renewal===null=>null,RenewalStop::adopted($renewal)=>'Set at import',$pending=>'Until import',
                RenewalStop::blocksExisting($renewal)=>'Service cancellation pending',$cancel=>'Cancels at period end',
                (bool)($policy['renewals_stopped']??false)=>'Stopped by staff',RenewalStop::ownerOff($renewal)=>'Turned off by customer',default=>null};
            $f['Renewal invoices']=$renewal===null?self::fact(null,sub:self::UNREADABLE_RENEWAL):self::fact($off?'Off':'On',sub:$off,help:self::help()['Renewal invoices']);
            $f['Renewal price']=self::price($d,$service,$observation);
            $expired=$d->provider_expires_at&&$d->provider_expires_at->format('Y-m-d')<=gmdate('Y-m-d');
            if (!$pending&&$d->status==='active'&&!$expired&&$renewal&&!$off) { $f['Next invoice']=self::nextInvoice($d); }
        }
        $invoice=DB::table('domains_operations as o')->join('invoices as i','i.id','=','o.invoice_id')->where('o.domain_id',$d->id)
            ->where('o.service_id',$d->service_id)->where('i.status','pending')->orderByDesc('i.id')->value('i.id');
        if ($invoice) { $f['Open invoice']=self::fact('Invoice #'.$invoice,sub:'Awaiting payment',url:self::invoiceUrl((int)$invoice)); }
        return self::alerts($d,$f);
    }
    /** Safe cause text shared by the domain page and the list. Unknown saved kinds still need review. */
    public static function alertCause(string $code): string
    { return ObligationAlerts::LABELS[$code]??'Domain alert needs review.'; }
    /** Puts each cause under its row, collecting causes without a row for the page's top notice. */
    private static function alerts(Domain $d, array $f): array
    {
        // The page notice names a held order and the head names the top alert, so a review alert on a row would only repeat them.
        $inputs=StaffDomainInputs::for([$d])[$d->id]; $status=self::status($d,$inputs);
        $op=$d->latestAcquisition; $named=$op&&in_array($op->state,['held','uncertain'],true)&&in_array($d->status,StaffDomainStatus::ACQUIRING,true)?(int)$op->id:($status['alert']==='operation_review'?$status['request']:null);
        $rows=array_filter($inputs['alerts'],fn(array $a)=>!($a['code']==='operation_review'&&$a['request']===$named&&$named!==null));
        foreach (array_unique(array_column($rows,'code')) as $code) {
            // Late payment already has a status reason and its own notice.
            if ($code==='acquisition_late_money') { continue; }
            [$row,$tone]=self::ALERTS[$code]??[null,'warning']; $cause=self::alertCause($code);
            if (!isset($f[$row])) {
                $notice=$f['Open alerts']??self::fact([],tone:$tone);
                $notice['value']=array_values(array_unique([...$notice['value'],$cause]));
                $notice['tone']=$notice['tone']==='danger'?'danger':$tone;
                $f['Open alerts']=$notice;
                continue;
            }
            $fact=$f[$row];
            $fact['sub']=$fact['sub']===null||$fact['sub']===$cause?$cause:StaffDomainStatus::sentence($fact['sub']).' '.$cause;
            $fact['tone']=$fact['tone']==='danger'?'danger':$tone; $fact['alert']=true;
            $f[$row]=$fact;
        }
        // A failed customer monitoring alert: why, and what to do. The staff alert links here.
        if ($failed=NoticeHealth::open('managed',(int)$d->id,(string)$d->domain)) {
            $notice=$f['Open alerts']??self::fact([],tone:$failed['tone']);$notice['value']=[...$notice['value'],$failed['text']];
            $notice['tone']=in_array('danger',[$notice['tone'],$failed['tone']],true)?'danger':($notice['tone']==='warning'?'warning':$failed['tone']);
            $f['Open alerts']=$notice;
        }
        return $f;
    }
    /** The customer's status words and the staff next step (StaffDomainStatus). $inputs (StaffDomainInputs) are passed by the list and the filter, which batch them. */
    public static function status(Domain $d, ?array $inputs=null): array
    {
        $inputs??=StaffDomainInputs::for([$d])[$d->id]; $op=$d->latestAcquisition;
        // Saved request data that can't be read must not break the list.
        try { $order=OwnedDomains::order($d,$op,$inputs['invoice'],$inputs['closed']); } catch (\Throwable) { $order=[]; }
        [$state,$reason]=OwnedDomains::orderStatus(DomainStates::managed($d,$op,gmdate('Y-m-d'),(bool)$inputs['off']),$d,$order);
        $shared=['key'=>DomainStatus::key($state),...DomainStatus::for($state,$reason)];
        if ($inputs['late']) { return StaffDomainStatus::late($shared); }
        return StaffDomainStatus::for($shared,$state,$d,$op,$inputs['alerts'],$inputs['renewal']);
    }
    /** The status reason, then the staff step, as sentences. */
    public static function statusLine(array $status): ?string
    { return implode(' ',array_map(StaffDomainStatus::sentence(...),array_filter([$status['reason'],$status['step']])))?:null; }
    /** The Note in the Domains list: the status line, or what a sync problem needs. $act: staff have a job here.
     * @return array{text:?string,act:bool} */
    public static function note(array $status, ?string $sync): array
    {
        $line=self::statusLine($status);
        if ($line!==null) { return ['text'=>$line,'act'=>$status['act']]; }
        return match($sync) { 'failed'=>['text'=>'Sync failed. Sync again from the domain.','act'=>true],'paused'=>['text'=>'Sync paused.','act'=>false],default=>['text'=>null,'act'=>false] };
    }
    /** The request a status step names, when this panel can open it. */
    public static function requestUrl(array $status): ?string
    { return $status['request']?self::url(fn()=>OperationResource::getUrl('view',['record'=>$status['request']])):null; }
    /** The status line, with its "Check request #N." as a link and a mark when staff have a job here. */
    public static function stepHtml(array $status): string|HtmlString|null
    {
        $line=self::statusLine($status); $label='Check request #'.$status['request']; $check=$label.'.'; $url=self::requestUrl($status);
        if ($line===null) { return null; }
        $linked=$url&&str_ends_with($line,$check);
        if (!$linked&&!$status['act']) { return $line; }
        $html=new HtmlString($linked?e(substr($line,0,-strlen($check))).self::requestLink($url,$label).'.':e($line));
        return $status['act']?StatusMark::act($html):$html;
    }
    /** A link to a request, in the text around it. */
    public static function requestLink(string $url, string $label): string
    { return '<a class="fi-link domains-link" href="'.e($url).'">'.e($label).'</a>'; }
    /** A Note cell is already the link, since a table row can't hold another one. Its step just has to look like one. */
    public static function noteStep(string $note, array $status): string|HtmlString
    {
        $label='Check request #'.$status['request']; $check=$label.'.';
        if (!self::requestUrl($status)||!str_ends_with($note,$check)) { return $note; }
        return new HtmlString(e(substr($note,0,-strlen($check))).'<span class="domains-link">'.e($label).'</span>.');
    }
    /** The one page notice: the most urgent cause and its fix. */
    public static function notice(Domain $d): ?array
    {
        Staff::requireView();
        try { $d->observation; $d->renewal_policy; $d->snapshot; } catch (\Throwable) { return ['title'=>self::UNREADABLE,'tone'=>'danger']; }
        $request=DomainQueries::operations($d)->orderByDesc('id')->value('id');
        $requestLink=$request?['label'=>'Check request #'.$request,'url'=>self::url(fn()=>OperationResource::getUrl('view',['record'=>$request]))]:null;
        // Sync cannot run until a pending import is confirmed, so the import notice comes first.
        if ($d->adoptionPending()) {
            return ['title'=>'Imported without purchase records. Check open invoices.','tone'=>'warning'];
        }
        if (($hold=self::recordHold($d))!==null) { return ['title'=>$hold,'tone'=>'warning','resume'=>true,'link'=>$requestLink]; }
        if (AcquisitionPaymentReview::count($d)>0) {
            $invoice=DB::table('domains_obligation_alerts as a')->join('domains_operations as o','o.id','=','a.operation_id')->where('a.domain_id',$d->id)
                ->where('a.code','acquisition_late_money')->whereNull('a.resolved_at')->orderByDesc('a.id')->value('o.invoice_id');
            return ['title'=>'Payment activity after the order closed.','body'=>'Check the invoice and payments before contacting the customer.','tone'=>'warning',
                'action'=>self::invoiceAction($invoice)];
        }
        if (RenewalReplacements::activity($d)) {
            $invoice=$d->operations()->where('action','renew')->get()->first(fn(Operation $op)=>array_key_exists('renewal_replacement',$op->policy??[]))?->invoice_id;
            return ['title'=>'Payment received after credit. Check the invoice.','tone'=>'warning','action'=>self::invoiceAction($invoice)];
        }
        if ($d->provenance==='adopted'&&($d->observation['adoption_review']['near_due']??false)===true&&!RenewalStop::stopped(RenewalStop::read($d))) {
            return ['title'=>'Imported after the invoice date. No renewal invoice was issued.','tone'=>'warning',
                'action'=>['label'=>'Open service','url'=>self::url(fn()=>\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$d->service_id]))]];
        }
        if (!self::account($d)) { return ['title'=>'Registrar account not found. Check Registrars.','tone'=>'warning']; }
        $op=$d->latestAcquisition;
        if ($op&&in_array($op->state,['held','uncertain'],true)&&in_array($d->status,StaffDomainStatus::ACQUIRING,true)) {
            $cause=rtrim(preg_replace('/^Not sent: /','',(string)Labels::reason($op->safe_code)),'.');
            $title=Labels::action($op->action).($op->state==='held'?' not sent'.($cause===''?'':': '.(preg_match('/\A(?:Enom|NameSilo)\b/',$cause)?$cause:lcfirst($cause))).'.':' not confirmed by the registrar.');
            try{$title=RequestFacts::readyMessage($op)??$title;}catch(\Throwable){}
            return ['title'=>$title,'tone'=>'warning','action'=>['label'=>'Check request #'.$op->id,'url'=>self::url(fn()=>OperationResource::getUrl('view',['record'=>$op->id]))]];
        }
        return null;
    }
    public static function recordHold(Domain $d): ?string
    {
        Staff::requireView();
        try {
            if (!ObservationRecords::stopped($d)) { return null; }
            if (!ObservationRecords::held($d)) { return 'Sync stopped after a failed check.'; }
            return ObservationRecords::limitNotice($d)??match($d->observation['record_hold']['reason']??null) {
                'ownership_drift'=>'Sync stopped: domain is not in the registrar account.',
                'glue_unavailable'=>"Couldn't read glue records. Other domain facts were saved. Use Sync now to try again.",
                'records_unavailable'=>"Sync stopped: couldn't read DNS records.",
                default=>'Sync stopped: DNS record check failed.' };
        } catch (\Throwable) { return self::UNREADABLE; }
    }
    /** 'paused' (demo registrar off under Enom Test mode), 'failed' (stopped after a failed check) or null. Shared by the page and the list.
     * The list passes the account and stopped state it loaded for the whole page. $accountLoaded includes a missing account. */
    public static function sync(Domain $d, ?Account $account=null, ?bool $stopped=null, bool $accountLoaded=false): ?string
    {
        if(!$accountLoaded){$account??=self::account($d);}
        return $account&&DemoData::syncPaused($d,$account)?'paused':(($stopped??ObservationWork::stopped($d))?'failed':null);
    }
    /** Why a value read from the registrar is Missing. */
    public static function unread(Domain $d, ?string $sync): string
    {
        return $d->adoptionPending()?Missing::fills('after import'):match($sync) {'paused'=>Missing::fills('when sync resumes'),'failed'=>'Sync failed. Use Resume sync.',
            default=>$d->last_synced_at?'Registrar did not return it. Check the domain at the registrar.':Missing::fills('within a day')};
    }
    /** The domain sits on a registrar's test account. */
    public static function test(Domain $d): bool { return self::account($d)?->environment==='test'; }
    private static function account(Domain $d): ?Account
    {
        return Account::where('server_id',$d->server_id)->where('registrar_key',$d->registrar_key)->where('account_fingerprint',$d->account_fingerprint)->first();
    }
    private static function price(Domain $d, ?\App\Models\Service $service, ?array $observation): array
    {
        if (!$service) { return self::fact(null,sub:'Needs the linked service. Check the Services page.'); }
        if (!in_array($d->price_mode,['standard','complimentary'],true)) { return self::fact(null,sub:'No renewal price set. Check the linked service.'); }
        $code=$observation['adoption_review']['price']??null;
        return self::fact($service->currency_code.' '.number_format((float)$service->price,2).' / year',
            sub:match(true) {$code==='below_cost'=>'Below cost',$code!==null=>'Cost missing',$d->price_mode==='complimentary'=>'Complimentary',default=>'Standard pricing'},
            tone:$code===null?null:'danger');
    }
    private static function nextInvoice(Domain $d): array
    {
        if (!$d->provider_expires_at) { return self::fact(null,sub:Missing::fills('after the expiry date syncs')); }
        $server=\App\Models\Server::with('settings')->find($d->server_id);
        $timing=BillingCalendar::timing($server?->settings??collect());
        $issue=BillingCalendar::dates($d->provider_expires_at,$timing['issue'],$timing['due'])['issue'];
        return $issue>=gmdate('Y-m-d') ? self::fact(gmdate('M j, Y',strtotime($issue.' 00:00:00 UTC'))) : self::fact(null,sub:'Not issued. Check Renewals on the registrar account.');
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
    /** Progress sits under a row's own words, so Contacts keeps its email; a Missing cause gives way to it. A settled result is over and says nothing. */
    public static function withProgress(array $facts, array $progress): array
    {
        foreach (['Contacts'=>'contacts','Nameservers'=>'nameservers','Transfer lock'=>'lock','DNS records'=>'dns','Auth code'=>'auth_code_email'] as $label=>$setting) {
            $row=$progress[$setting]??null;
            if (!isset($facts[$label])||$row===null||($row['settled']??false)) { continue; }
            $facts[$label]['sub']=$facts[$label]['value']===null?$row['text']:trim(($facts[$label]['sub']??'').' '.$row['text']);
        }
        return $facts;
    }
    private static function fact(string|array|null $value, ?string $sub=null, ?string $url=null, ?string $tone=null, ?string $help=null, bool $demo=false): array
    { return ['value'=>$value===''?null:$value,'sub'=>$sub===''?null:$sub,'url'=>$url,'tone'=>$tone,'help'=>$help,'demo'=>$demo]; }
}
