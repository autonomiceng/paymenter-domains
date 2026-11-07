<?php
// Adapted from Enom 444f31c Admin/Support/DomainFacts and DomainAdministration read paths.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Models\{Account, Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\{DomainStatus, Missing, RequestStatus};
use Paymenter\Extensions\Servers\Domains\Services\{BillingCalendar, DomainStates, ObligationAlerts, ObservationProjection, ObservationRecords, ObservationWork, OwnedDomains, OwnerRecords, RenewalCycles, RenewalReplacements, RenewalStop, StaffHoldingReview, StaffOperationReview};
use Paymenter\Extensions\Servers\Domains\Support\DemoData;
/** Request-local allowlisted projection. Never cache across actors, call providers or serialize models.
 * Each fact is ['value'=>string|list<string>|null,'sub'=>?string,'url'=>?string,'tone'=>?string,'help'=>?string,'demo'=>bool], and 'at' (a timestamp) on a time the page shows in the viewer's zone.
 * A null value is Missing; a row that does not apply is absent. */
final class DomainFacts
{
    /** The page's sections and their rows, in order. */
    public const SECTIONS=['Registration'=>['Expires','Registrar','In registrar account','Transfer lock','WHOIS privacy','Email auth code','Registrar auto-renew','Last sync','Open alerts'],
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
     * Payment activity after the order closed shows in the status and the page head instead. */
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
    private const OWNER_OFF='Turned off by customer';
    public const NO_ACCOUNT='Registrar account not found. Check Registrars.';
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
            $f['Expires']=self::fact($expiry?gmdate('M j, Y',strtotime($expiry.' 00:00:00 UTC')):null,sub:$expiry?null:($pending?self::unread($d,$sync):'No confirmed expiry saved. Check the domain at the registrar.'),
                help:$pending&&$expiry?'From the old record. Not synced yet.':null);
        }
        $registrar=['enom'=>'Enom','namesilo'=>'NameSilo'][$d->registrar_key]??null;
        $f['Registrar']=$account&&$registrar ? self::fact(\App\Models\Server::whereKey($d->server_id)->value('name')??$registrar,
            sub:implode(', ',array_filter([$registrar,['test'=>'Test','live'=>'Live'][$account->environment]??null,
                !self::ended($d)&&($paused||!$account->processing_enabled||($policy['automatic_spend_paused']??false))?'orders paused':null])),
            url:self::url(fn()=>RegistrarFacts::accountUrl((int)$d->server_id)),
            demo:DemoData::is($account)) : self::fact(null,sub:$account?'Unknown registrar. Check Registrars.':self::NO_ACCOUNT);
        if ($registered && $observation!==null) {
            $facts=$observation['facts']??null;
            $facts=is_array($facts)&&ObservationProjection::matches($d,$facts)?$facts:[];
            $unread=self::unread($d,$sync);
            $f['In registrar account']=self::fact(ObservationRecords::held($d)&&($observation['record_hold']['reason']??null)==='ownership_drift'?'No':self::flag($facts['owned']??null,'Yes','No'),sub:isset($facts['owned'])?null:$unread);
            try { $contact=OwnedDomains::owner(OwnedDomains::contacts($d)); $none=$pending?self::unread($d,$sync):(Staff::can('admin.domains.manage')?self::ADD_CONTACTS:'Ask the customer to add them on the domain page'); }
            catch (\Throwable) { $contact=null; $none=self::UNREADABLE; }
            $f['Contacts']=self::fact($contact['name']??null,sub:$contact['email']??$none);
            $nameservers=OwnedDomains::storedNameservers($d);
            $f['Nameservers']=self::fact($nameservers,sub:$nameservers?null:$unread,demo:$nameservers&&$account&&DemoData::is($account));
            try { $sections=ObservationProjection::sections($d); $records=OwnerRecords::forDomain($d,$sections); }
            catch (\Illuminate\Contracts\Encryption\DecryptException) { $sections=$records=[]; }
            if ($d->registrar_key==='enom'&&filter_var($d->server?->settings()->where('key','domains_dns_tools')->value('value'),FILTER_VALIDATE_BOOLEAN)) {
                $count=isset($records['dns'])?count($records['dns']):null;
                $f['DNS records']=self::fact($count===null?(ObservationRecords::limitNotice($d)===null?'Not read yet':null):$count.' '.($count===1?'record':'records'),sub:$count===null?(ObservationRecords::limitNotice($d)??(Staff::can('admin.domains.manage')?'Use Sync now to read them.':null)):(empty($records['srv'])?null:count($records['srv']).' SRV '.(count($records['srv'])===1?'record':'records').'.'));
                if ($count!==null) { $f['DNS records']['asOf']=$sections['dns']['at']??null; }
            }
            if ($d->registrar_key==='namesilo') {
                try { $off=app(\Paymenter\Extensions\Servers\Domains\Services\OwnerPorts::class)->forDomain($d)->capabilities()->get('dns')->support!==\Paymenter\Extensions\Servers\Domains\Data\Support::Supported; } catch (\Throwable) { $off=false; }
                $count=isset($records['dns'])?count($records['dns'])+count($records['dns_fixed']):null;
                // Closed DNS keeps any saved count and always says why and what to do, in the owner's words.
                if ($count!==null || $off) { $f['DNS records']=self::fact($count===null?'Not available':$count.' '.($count===1?'record':'records'),
                    sub:implode(' · ',array_filter([$count!==null?'As of '.($sections['dns']['at']??''):null,$off?OwnedDomains::DNS_OFF:null]))); }
            }
            $glue=$records['glue']??[];
            if ($glue) {
                $warnings=\Paymenter\Extensions\Servers\Domains\Services\GlueDrift::warnings($d,known:$sections['known_glue']??[]);
                $at=\Paymenter\Extensions\Servers\Domains\Services\GlueRecords::asOf($d,$sections);
                $f['Glue records']=[...self::fact(array_map(fn($row)=>$row['host'].' '.$row['ip'],$glue),sub:implode(' ',$warnings?[self::differs(count($warnings),count(array_unique(array_column($glue,'host')))),...$warnings]:[]),tone:$warnings?'warning':null,help:'Needed when nameservers are named under this domain.'),'asOf'=>$at?:null];
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
        // An ended domain won't sync again, so it keeps its last read and shows no row without one.
        if (($registered||$job||$d->last_synced_at)&&(!self::ended($d)||$d->last_synced_at)) { $f['Last sync']=match(true) {
            $paused=>self::fact('Paused',sub:'Demo registrar is off while Enom Test mode is on',tone:'warning'),
            $sync==='failed'=>self::fact('Read stopped',tone:'danger'),
            default=>['at'=>$d->last_synced_at?->getTimestamp(),...self::fact($d->last_synced_at?->utc()->format('M j, Y H:i').($d->last_synced_at?' UTC':''),
                sub:$job&&$job->claim!==null&&$job->lease_until>time()?($d->last_synced_at?'Syncing now':Missing::fills('when this sync finishes')):($d->last_synced_at?null:Missing::fills('within a day')))],
        }; }
        $user=$d->user;
        $f['Customer']=$user?self::fact(trim($user->first_name.' '.$user->last_name)?:$user->email,sub:$user->email,
            url:self::url(fn()=>\App\Admin\Resources\UserResource::getUrl('edit',['record'=>$user->id]))):self::fact(null,sub:'Customer account not found. Check the Users page.');
        $service=\App\Models\Service::find($d->service_id);
        $f['Service']=$service?self::fact($d->domain.' registration',sub:'Service #'.$service->id,
            url:self::url(fn()=>\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$service->id]))):self::fact(null,sub:'Service #'.$d->service_id.' not found. Check the Services page.');
        $invoices=DB::table('domains_operations as o')->join('invoices as i','i.id','=','o.invoice_id')->where('o.domain_id',$d->id)
            ->where('o.service_id',$d->service_id)->where('i.status','pending')->orderByDesc('i.id');
        // Reopened native debt does not restore a terminal or superseded renewal's authority.
        $renewalOperations=$d->operations()->where('action','renew')->whereNotIn('state',RequestStatus::FINISHED)->get();
        // Missing expiry cannot establish a current cycle, but open native debt still needs staff review.
        // This display fallback grants no renewal authority and retains the same binding and terminal exclusions.
        $renewals=($d->provider_expires_at!==null?RenewalCycles::current($d,$renewalOperations):$renewalOperations->filter(
            fn(Operation $op)=>$op->authority_generation===$d->authority_generation&&$op->account_fingerprint===$d->account_fingerprint
                &&(int)$op->server_id===(int)$d->server_id&&$op->registrar_key===$d->registrar_key))->pluck('id');
        $renewalInvoice=(clone $invoices)->whereIn('o.id',$renewals)->first(['i.id','i.due_at']);
        $invoice=$renewalInvoice??$invoices->where('o.action','!=','renew')->first(['i.id','i.due_at']);
        if ($policy!==null&&!self::ended($d)) {
            try { $renewal=RenewalStop::read($d); } catch (\Throwable) { $renewal=null; }
            $cancel=$renewal&&$renewal['cancel']->contains('service_id',$d->service_id);
            $off=match(true) {$renewal===null=>null,RenewalStop::adopted($renewal)=>'Set at import',$pending=>'Until import',
                RenewalStop::blocksExisting($renewal)=>'Service cancellation pending',$cancel=>'Cancels at period end',
                (bool)($policy['renewals_stopped']??false)=>'Stopped by staff',RenewalStop::ownerOff($renewal)=>self::ownerOff(),default=>null};
            $f['Renewal invoices']=$renewal===null?self::fact(null,sub:self::UNREADABLE_RENEWAL,help:self::help()['Renewal invoices']):self::fact($off?'Off':'On',sub:$off,help:self::help()['Renewal invoices']);
            $f['Renewal price']=self::price($d,$service,$observation);
            $expired=$d->provider_expires_at&&$d->provider_expires_at->format('Y-m-d')<=gmdate('Y-m-d');
            // An issued renewal is due even when future automatic renewals have been stopped.
            if ($renewalInvoice) { $f['Next invoice']=self::fact($renewalInvoice->due_at?'Due '.gmdate('M j, Y',strtotime(substr($renewalInvoice->due_at,0,10).' 00:00:00 UTC')):'Due'); }
            elseif (!$pending&&$d->status==='active'&&!$expired&&$renewal&&!$off) { $f['Next invoice']=self::nextInvoice($d); }
        }
        if ($invoice) { $f['Open invoice']=self::fact('Invoice #'.$invoice->id,sub:'Awaiting payment',url:self::invoiceUrl((int)$invoice->id)); }
        // A failed read leaves the unread rows red Missing without a sentence each. Why, and what to do, is said once:
        // a stopped read in the page notice, a read still retrying on Last sync. Contacts come from saved requests, not the read,
        // so their Missing keeps its own step.
        if($registered && ($problem=self::readProblem($d))!==null){
            foreach(['In registrar account','Nameservers','Transfer lock','WHOIS privacy','Registrar auto-renew','DNS records'] as $label){
                if(isset($f[$label]) && ($f[$label]['value']===null || $f[$label]['value']==='Not read yet')){$f[$label]['value']=null;$f[$label]['sub']=null;}
            }
            if(isset($f['Last sync'])){$f['Last sync']['sub']=!self::ended($d)&&self::recordHold($d)!==null?null:$problem;}
        }
        return self::alerts($d,$f);
    }
    /** Safe cause text shared by the domain page and the list. Unknown saved kinds still need review. */
    public static function alertCause(string $code): string
    { return $code==='observation_review'&&!self::canResume()?'Sync stopped. '.self::ASK_RESUME:ObligationAlerts::LABELS[$code]??'Domain alert needs review.'; }
    /** Puts each cause under its row, collecting causes without a row on the Open alerts row. */
    private static function alerts(Domain $d, array $f): array
    {
        // The head names a held order, or the top alert when no notice takes its place, so a review alert on a row would only repeat them.
        $inputs=StaffDomainInputs::for([$d])[$d->id]; $status=self::status($d,$inputs);
        $notice=self::notice($d);
        $op=$d->latestAcquisition; $named=$op&&in_array($op->state,['held','uncertain'],true)&&in_array($d->status,StaffDomainStatus::ACQUIRING,true)?(int)$op->id:(in_array($status['alert'],['operation_review','held_obligation'],true)&&($notice===null||($notice['resume']??false))?$status['request']:null);
        // One link at a time: another held request stays in the rail, and the head names it once this one clears.
        $rows=array_filter($inputs['alerts'],fn(array $a)=>!(in_array($a['code'],['operation_review','held_obligation'],true)&&$named!==null&&($a['request']===$named||$named===$status['request'])));
        foreach (array_unique(array_column($rows,'code')) as $code) {
            // Late payment already has a status reason and says so in the head.
            if ($code==='acquisition_late_money') { continue; }
            [$row,$tone]=self::ALERTS[$code]??[null,'warning']; $cause=self::alertCause($code);
            // The row sends staff to the same place the head would.
            $request=in_array($code,['operation_review','held_obligation'],true)?array_values(array_filter($rows,fn(array $a)=>$a['code']===$code&&$a['request']!==null))[0]['request']??null:null;
            if ($request!==null) { $cause=str_replace('Check the request','Check request #'.$request,$cause); }
            if (!isset($f[$row])) {
                $notice=$f['Open alerts']??self::fact([],tone:$tone);
                $notice['value']=array_values(array_unique([...$notice['value'],$cause]));
                $notice['tone']=$notice['tone']==='danger'?'danger':$tone;
                $f['Open alerts']=$notice;
                continue;
            }
            $fact=$f[$row];
            // The Last sync row already says it stopped, beside the button.
            if ($code==='observation_review'&&str_ends_with((string)$fact['sub'],'Stopped until resumed.')) { continue; }
            if ($fact['sub']!==null&&$fact['sub']!==$cause) { $fact['plain']??=StaffDomainStatus::sentence($fact['sub']); }
            $fact['sub']=$fact['sub']===null||$fact['sub']===$cause?$cause:StaffDomainStatus::sentence($fact['sub']).' '.$cause;
            $fact['tone']=$fact['tone']==='danger'?'danger':$tone; $fact['alert']=true;
            if ($request!==null) { $fact['request']=$request; }
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
        return StaffDomainStatus::for($shared,$state,$d,$op,$inputs['alerts'],$inputs['renewal'],$inputs['waiting']);
    }
    /** The list's status tone. A stopped sync or a missing registrar account is a job for staff, as in the domain's head. */
    public static function listTone(array $status, ?string $sync, bool $noAccount=false): string
    { return $status['tone']==='success'&&($sync==='failed'||$noAccount)?'warning':$status['tone']; }
    /** The status reason, then the staff step, as sentences. */
    public static function statusLine(array $status): ?string
    { return implode(' ',array_map(StaffDomainStatus::sentence(...),array_filter([$status['reason'],$status['step']])))?:null; }
    /** The Note in the Domains list: the status line, or what a sync problem needs. A stopped sync is amber (listTone), so the note always says it. $act: staff have a job here.
     * The Registrar column is hidden by default, so a missing registrar account leads the note.
     * @return array{text:?string,act:bool} */
    public static function note(array $status, ?string $sync, bool $noAccount=false): array
    {
        if ($noAccount) { $note=self::note($status,$sync); return ['text'=>trim(self::NO_ACCOUNT.' '.$note['text']),'act'=>true]; }
        $line=self::statusLine($status);
        if ($line!==null) { return $sync==='failed'&&!str_contains($line,'Sync ')?['text'=>'Sync stopped. '.$line,'act'=>true]:['text'=>$line,'act'=>$status['act']]; }
        return match($sync) { 'failed'=>['text'=>'Sync failed. '.(self::canResume()?'Use Resume sync on the domain.':self::ASK_RESUME),'act'=>true],'paused'=>['text'=>'Sync paused.','act'=>false],default=>['text'=>null,'act'=>false] };
    }
    /** The request a status step names, when this panel can open it. */
    public static function requestUrl(array $status): ?string
    { return $status['request']?self::url(fn()=>OperationResource::getUrl('view',['record'=>$status['request']])):null; }
    /** The status line, with its "Check request #N." as a link and a mark when staff have a job here. */
    public static function stepHtml(array $status, bool $mark=true): string|HtmlString|null
    {
        $line=self::statusLine($status); $label='Check request #'.$status['request']; $check=$label.'.'; $url=self::requestUrl($status);
        if ($line===null) { return null; }
        $linked=$url&&str_ends_with($line,$check);
        if (!$linked&&!($status['act']&&$mark)) { return $line; }
        $html=new HtmlString($linked?e(substr($line,0,-strlen($check))).self::requestLink($url,$label).'.':e($line));
        return $status['act']&&$mark?StatusMark::act($html):$html;
    }
    /** The head of a domain a request is holding: the link first, then what it holds, said once for the page. */
    public static function holdHtml(int $request, bool $mark=true): HtmlString
    {
        $url=self::requestUrl(['request'=>$request]); $label='Check request #'.$request;
        $html=new HtmlString(($url?self::requestLink($url,$label):e($label)).' Changes are on hold until it is checked.');
        return $mark?StatusMark::act($html):$html;
    }
    /** A row's words with its "Check request #N" as a link, when this panel can open it. */
    public static function linkRequest(string|HtmlString $words, int $request): string|HtmlString
    {
        $url=self::requestUrl(['request'=>$request]); $label='Check request #'.$request;
        if (!$url) { return $words; }
        return new HtmlString(str_replace($label,self::requestLink($url,$label),$words instanceof HtmlString?$words->toHtml():e($words)));
    }
    /** The page's one status line: the notice, else the hold, else the status step, under one mark.
     * Active can't stay green over a stopped sync or rows that need work. An amber or red state is already the mark, so its words get none of their own.
     * $held is the request holding changes. It comes first, then the request the status names, and no request names none.
     * @return array{tone:string,step:string|HtmlString|null} */
    public static function head(Domain $d, array $status, array $facts, ?int $held=null): array
    {
        $notice=self::notice($d); $request=self::headRequest($d,$status,$held,$notice);
        if (($notice['resume']??false)&&$request) { $notice['link']=['label'=>'Check request #'.$request,'url'=>self::requestUrl(['request'=>$request])]; }
        $missing=array_filter($facts,fn(array $fact)=>$fact['value']===null&&!Missing::needsNothing($fact['sub']));
        $tone=$status['tone']==='success'&&(($notice['resume']??false)||$missing!==[])?'warning':$status['tone'];
        $mark=!in_array($tone,['warning','danger'],true);
        $step=match(true) { $notice!==null=>self::noticeHtml($notice,$mark),$held!==null=>self::holdHtml($held,$mark),default=>self::stepHtml($status,$mark) };
        return ['tone'=>$tone,'step'=>$step??($missing!==[]?'Some details are Missing. Each row says what to do.':null)];
    }
    public const ADD_CONTACTS='Add them with Edit';
    /** While changes are on hold, Contacts' Missing says what comes before Edit, not just Edit. */
    public static function whileHeld(array $facts, ?string $first): array
    {
        if (($facts['Contacts']['sub']??null)===self::ADD_CONTACTS) { $facts['Contacts']['sub']=($first??'Wait until changes are back on').', then add them with Edit.'; }
        return $facts;
    }
    /** The one request the head names, or null. A notice names one only beside Resume sync. Rows name no other (withProgress). */
    public static function headRequest(Domain $d, array $status, ?int $held=null, ?array $notice=null): ?int
    {
        $notice??=self::notice($d);
        return $notice===null||($notice['resume']??false)?$held??$status['request']:null;
    }
    /** A notice in the head line: its words, then its links. */
    private static function noticeHtml(array $notice, bool $mark): HtmlString
    {
        $links=array_filter([$notice['action']??null,$notice['link']??null],fn(?array $link)=>$link!==null&&$link['url']!==null);
        $html=new HtmlString(e(implode(' ',array_filter([$notice['title'],$notice['body']??null]))).implode('',array_map(fn(array $link)=>' '.self::requestLink($link['url'],$link['label']).'.',$links)));
        return $mark?StatusMark::act($html):$html;
    }
    /** A link to a request, in the text around it. */
    public static function requestLink(string $url, string $label): string
    { return '<a class="fi-link domains-link" href="'.e($url).'">'.e($label).'</a>'; }
    /** A Note cell is already the link, since a table row can't hold another one. Its step leads, so a cut note still shows it,
     * and just has to look like a link. */
    public static function noteStep(string $note, array $status): string|HtmlString
    {
        $label='Check request #'.$status['request']; $check=$label.'.';
        if (!self::requestUrl($status)||!str_ends_with($note,$check)) { return $note; }
        return new HtmlString('<span class="domains-link">'.e($label).'</span>. '.e(rtrim(substr($note,0,-strlen($check)))));
    }
    /** The most urgent cause and its fix, said in the head line. */
    public static function notice(Domain $d): ?array
    {
        Staff::requireView();
        try { $d->observation; $d->renewal_policy; $d->snapshot; } catch (\Throwable) { return ['title'=>self::UNREADABLE,'tone'=>'danger']; }
        // Sync cannot run until a pending import is confirmed, so the import notice comes first.
        if ($d->adoptionPending()) {
            return ['title'=>'Imported without purchase records. Check open invoices.','tone'=>'warning'];
        }
        if (!self::ended($d)&&($hold=self::recordHold($d))!==null) { return ['title'=>$hold,'body'=>self::restart($d),'tone'=>'warning','resume'=>true]; }
        if (AcquisitionPaymentReview::count($d)>0) {
            $invoice=DB::table('domains_obligation_alerts as a')->join('domains_operations as o','o.id','=','a.operation_id')->where('a.domain_id',$d->id)
                ->where('a.code','acquisition_late_money')->whereNull('a.resolved_at')->orderByDesc('a.id')->value('o.invoice_id');
            return ['title'=>'Payment activity after the order closed.','body'=>'Check the invoice and payments before contacting the customer.','tone'=>'warning',
                'action'=>self::invoiceAction($invoice)];
        }
        try {
            if (RenewalReplacements::activity($d)) {
                $invoice=$d->operations()->where('action','renew')->get()->first(fn(Operation $op)=>array_key_exists('renewal_replacement',$op->policy??[]))?->invoice_id;
                return ['title'=>'Payment received after credit. Check the invoice.','tone'=>'warning','action'=>self::invoiceAction($invoice)];
            }
        } catch (\Throwable) { return ['title'=>self::UNREADABLE,'tone'=>'danger']; }
        if ($d->provenance==='adopted'&&($d->observation['adoption_review']['near_due']??false)===true&&!RenewalStop::stopped(RenewalStop::read($d))) {
            return ['title'=>'Imported after the invoice date. No renewal invoice was issued.','tone'=>'warning',
                'action'=>['label'=>'Open service','url'=>self::url(fn()=>\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$d->service_id]))]];
        }
        if (!self::account($d)) { return ['title'=>self::NO_ACCOUNT,'tone'=>'warning']; }
        $op=$d->latestAcquisition;
        if ($op&&in_array($op->state,['held','uncertain'],true)&&in_array($d->status,StaffDomainStatus::ACQUIRING,true)) {
            $cause=rtrim(preg_replace('/^Not sent: /','',(string)Labels::reason($op->safe_code,$op->funding_basis==='complimentary')),'.');
            $title=Labels::action($op->action).($op->state==='held'?' not sent'.($cause===''?'':': '.(preg_match('/\A(?:Enom|NameSilo)\b/',$cause)?$cause:lcfirst($cause))).'.':' not confirmed by the registrar.');
            try{$title=RequestFacts::readyMessage($op)??$title;}catch(\Throwable){}
            return ['title'=>$title,'tone'=>'warning','action'=>['label'=>'Check request #'.$op->id,'url'=>self::url(fn()=>OperationResource::getUrl('view',['record'=>$op->id]))]];
        }
        return null;
    }
    private static function readCause(Domain $d): string
    {
        $read=\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::status($d);
        // The records head shows the error code, and the notice body the one step.
        return $read['read_cause']??'Registrar read stopped.';
    }
    public static function recordHold(Domain $d): ?string
    {
        Staff::requireView();
        try {
            if (!ObservationRecords::stopped($d)) { return null; }
            if (!ObservationRecords::held($d)) { return self::readCause($d); }
            return ObservationRecords::limitNotice($d)??match($d->observation['record_hold']['reason']??null) {
                'ownership_drift'=>'Sync stopped: domain is not in the registrar account.',
                'glue_unavailable'=>"Couldn't read glue records. Other domain facts were saved. Use Sync now to try again.",
                'records_unavailable'=>self::unreadFamily($d->observation['record_hold']['family']??null),
                'caa_unsupported'=>"Sync stopped: this domain has CAA records, which can't be read from Enom here. Other domain facts were saved. Manage this domain's DNS at Enom.",
                default=>'Sync stopped: DNS record check failed.' };
        } catch (\Throwable) { return self::UNREADABLE; }
    }
    /** Staff copy for a record hold that names the family whose read failed; DNS (or an unnamed legacy hold) keeps its wording. */
    private static function unreadFamily(mixed $family): string
    {
        $name=match($family) { 'srv'=>'SRV records','ds'=>'DNSSEC records','glue'=>'glue records','nameservers'=>'nameservers',
            'contacts'=>'contacts','flags'=>'lock, auto-renew and privacy settings',default=>null };
        return $name===null?"Sync stopped: couldn't read DNS records.":"Sync stopped: couldn't read ".$name.'. Other domain facts were saved. Use Sync now to try again.';
    }
    /** 'paused' (demo registrar off under Enom Test mode), 'failed' (stopped after a failed check) or null. Shared by the page and the list. Nothing syncs on an ended domain, so it has neither.
     * The list passes the account and stopped state it loaded for the whole page. $accountLoaded includes a missing account. */
    public static function sync(Domain $d, ?Account $account=null, ?bool $stopped=null, bool $accountLoaded=false): ?string
    {
        if (self::ended($d)) { return null; }
        if(!$accountLoaded){$account??=self::account($d);}
        return $account&&DemoData::syncPaused($d,$account)?'paused':(($stopped??ObservationWork::stopped($d))?'failed':null);
    }
    /** Why a value read from the registrar is Missing. */
    public static function unread(Domain $d, ?string $sync): string
    {
        return $d->adoptionPending()?Missing::fills('after import'):match($sync) {'paused'=>Missing::fills('when sync resumes'),'failed'=>'Sync failed. '.(self::canResume()?'Use Resume sync.':self::ASK_RESUME),
            default=>$d->last_synced_at?'Registrar did not return it. Check the domain at the registrar.':Missing::fills('within a day')};
    }
    /** One safe failure cause and the actual automatic retry, or the stopped-read next step. */
    private static function readProblem(Domain $d): ?string
    {
        try{$read=\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::status($d);}
        catch(\Illuminate\Contracts\Encryption\DecryptException){return self::UNREADABLE;}
        if(!$read['read_code']){return null;}
        return $read['read_cause'].' '.($read['read_stopped']?self::restart($d):($read['next_read']!==null
            ?'Next automatic retry: '.gmdate('M j, Y, g:i A',$read['next_read']).' UTC.'
            :'No registrar read is queued. Check the background worker.'));
    }
    private const ASK_RESUME='Ask staff who can resume sync.';
    /** The control that clears a record hold. Resume sync needs a job row. Sync now can set a hold with no row, and its own complete read clears it. */
    private static function restart(Domain $d): string
    {
        if (ObservationWork::currentId($d)!==null) { return self::canResume()?'Use Resume sync below.':self::ASK_RESUME; }
        return Staff::can('admin.domains.manage')&&$d->status==='active'?'Use Sync now.':'Ask staff who can sync this domain.';
    }
    private static function canResume(): bool { return Staff::can(StaffOperationReview::permission('resume_observation')); }
    /** The customer's own Off still lets them renew by hand or turn it back on. A staff stop blocks both, so its red button says what is left to stop. */
    private static function ownerOff(): string
    {
        return self::OWNER_OFF.(Staff::can('admin.domains.manage')&&Staff::can(StaffHoldingReview::permission('stop'))?'. They can still renew by hand or turn it back on. Stop renewal blocks both.':'');
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
        if ($code==='retained_renewal') { $code=null; }
        $company=\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::company();
        return self::fact(($d->price_mode==='complimentary'?'Standard renewal price ':'').$service->currency_code.' '.number_format((float)$service->price,2).' / year'.($d->price_mode==='complimentary'?', complimentary':''),
            sub:match(true) {$code==='below_cost'=>'Below cost',$code!==null=>'Cost missing',$d->price_mode==='complimentary'=>\Paymenter\Extensions\Servers\Domains\Services\ComplimentaryAccounts::LABEL.'. '.($company!==''?$company.' covers':'We cover').' registrar costs.',self::purchasePrice($d,$service)=>'Price kept from purchase until the next renewal invoice.',default=>'Standard pricing'},
            tone:$code===null?null:'danger');
    }
    /** The native service keeps checkout's annual gross until a renewal invoice projects current pricing. */
    private static function purchasePrice(Domain $d,\App\Models\Service $service): bool
    {
        $checkout=$d->snapshot['checkout']??[];
        return isset($checkout['annual_price'])&&($checkout['currency']??null)===$service->currency_code
            &&\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor($checkout['annual_price'])===\Paymenter\Extensions\Servers\Domains\Services\PricingRule::minor((string)$service->price);
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
    /** Progress takes its own line under a row's words, so Contacts keeps its email; a Missing cause gives way to it. A settled or replaced result says nothing.
     * A stopped change reads its request's line, never the sentence written for the customer. It names its request only when that is $named, the one the head names (headRequest),
     * so the page sends staff to one request, and to none when none needs them. */
    public static function withProgress(array $facts, array $progress, ?int $named=null): array
    {
        foreach (['Contacts'=>'contacts','Nameservers'=>'nameservers','Transfer lock'=>'lock','DNS records'=>'dns','Email auth code'=>'auth_code_email'] as $label=>$setting) {
            $row=$progress[$setting]??null;
            if (!isset($facts[$label])||$row===null||($row['settled']??false)||($row['replaced']??false)) { continue; }
            $stopped=isset($row['outcome']); $request=$stopped&&$row['id']===$named?$row['id']:null;
            $text=$stopped?StaffDomainStatus::sentence($row['outcome']).($request===null?'':' Check request #'.$request.'.'):$row['text'];
            if ($facts[$label]['value']===null) { $facts[$label]['sub']=$text; continue; }
            $facts[$label]['progress']=['text'=>$text,'request'=>$request,'waiting'=>(bool)($row['pending']??false),'tone'=>$row['tone']??null];
        }
        return $facts;
    }
    /** A count above the glue detail: "1 of 2 differs." */
    public static function differs(int $count, int $of): string
    { return $count.' of '.max($count,$of).' '.($count===1?'differs':'differ').'.'; }
    private static function fact(string|array|null $value, ?string $sub=null, ?string $url=null, ?string $tone=null, ?string $help=null, bool $demo=false): array
    { return ['value'=>$value===''?null:$value,'sub'=>$sub===''?null:$sub,'url'=>$url,'tone'=>$tone,'help'=>$help,'demo'=>$demo]; }
}
