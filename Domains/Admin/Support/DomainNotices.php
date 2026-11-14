<?php
// Adapted from Enom 444f31c Admin/Support/DomainFacts and DomainAdministration read paths.
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
use Paymenter\Extensions\Servers\Domains\Models\{Domain, Operation};
use Paymenter\Extensions\Servers\Domains\Presentation\Missing;
use Paymenter\Extensions\Servers\Domains\Services\{ObservationWork, RenewalReplacements, RenewalStop};
/** The staff domain page head: the most urgent notice, its links, and request links in status text. Request-local; never cache across actors. */
final class DomainNotices
{
    /** The request a status step names, when this panel can open it. */
    public static function requestUrl(array $status): ?string
    { return $status['request']?Facts::url(fn()=>OperationResource::getUrl('view',['record'=>$status['request']])):null; }
    /** The status line, with its "Check request #N." as a link and a mark when staff have a job here. */
    public static function stepHtml(array $status, bool $mark=true): string|HtmlString|null
    {
        $line=DomainDecisions::statusLine($status); $label='Check request #'.$status['request']; $check=$label.'.'; $url=self::requestUrl($status);
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
    public static function head(Domain $d, array $status, array $facts, ?int $held=null, array|false|null $notice=false): array
    {
        if ($notice===false) { $notice=self::notice($d); } $request=self::headRequest($d,$status,$held,$notice);
        // A stopped read names its request beside any invoice link late money added: staff need both.
        if (($notice['resume']??false)&&$request) { $notice['action']=['label'=>'Check request #'.$request,'url'=>self::requestUrl(['request'=>$request])]; }
        $missing=array_filter($facts,fn(array $fact)=>$fact['value']===null&&!Missing::needsNothing($fact['sub']));
        // A danger notice, such as unreadable saved data, is red whatever the status says. Otherwise the mark keeps its
        // word's tone: Missing rows say so in their own rows, so they don't turn a green Active amber.
        $tone=match(true) { ($notice['tone']??null)==='danger'=>'danger',$status['tone']==='success'&&($notice['resume']??false)=>'warning',default=>$status['tone'] };
        $mark=!in_array($tone,['warning','danger'],true);
        $step=match(true) { $notice!==null=>self::noticeHtml($notice,$mark),$held!==null=>self::holdHtml($held,$mark),default=>self::stepHtml($status,$mark) };
        return ['tone'=>$tone,'step'=>$step??($missing!==[]?'Some details are Missing. Each row says what to do.':null)];
    }
    /** The one request the head names, or null. A notice names one only beside Resume sync. Rows name no other (withProgress). */
    public static function headRequest(Domain $d, array $status, ?int $held=null, array|false|null $notice=false): ?int
    {
        if ($notice===false) { $notice=self::notice($d); }
        return $notice===null||($notice['resume']??false)?$held??$status['request']??(ObservationWork::resumeBlocked($d)!==null?$d->latestAcquisition?->id:null):null;
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
        try { $d->observation; $d->renewal_policy; $d->snapshot; } catch (\Throwable) { return ['title'=>DomainDecisions::UNREADABLE,'tone'=>'danger']; }
        $op=$d->latestAcquisition;
        if(DomainDecisions::unconfirmedRegistration($d)&&$op){
            // Sent and still inside the registrar's normal wait: the request reads In progress, so the head keeps the status step (Registration in progress).
            if($op->state==='pending'){return null;}
            // Unreadable request data still names the request, so staff can open it.
            try { $facts=RequestFacts::for($op); } catch (\Throwable) { $facts=['unreadable'=>true]; }
            $unreadable=$facts['unreadable']??false;
            return ['title'=>'Registration not confirmed by the registrar.','body'=>$unreadable?DomainDecisions::UNREADABLE:($facts['registrar_reply']??null),'tone'=>$unreadable?'danger':'warning',
                'action'=>['label'=>'Check request #'.$op->id,'url'=>Facts::url(fn()=>OperationResource::getUrl('view',['record'=>$op->id]))]];
        }
        $read=\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::status($d);
        // A read problem leads the head, but late money and its invoice stay beside it: staff resolve both.
        $payment=self::paymentNotice($d);
        $withPayment=static fn(array $notice):array=>$payment===null?$notice:[...$notice,
            'body'=>trim(($notice['body']??'').' '.implode(' ',array_filter([$payment['title'],$payment['body']??null]))),
            'tone'=>$payment['tone']==='danger'?'danger':$notice['tone'],'link'=>$payment['action']??null,'invoice'=>$payment['invoice']??null];
        if (!DomainDecisions::ended($d)&&$read['read_code']==='domain_not_in_account') {
            $name=\App\Models\Server::whereKey($d->server_id)->value('name');
            return $withPayment(['title'=>'Domain is not in the registrar account.','tone'=>'warning',
                'body'=>(is_string($name)&&trim($name)!==''?'Assigned account: '.trim($name).'. Register or transfer the domain there. ':'Register or transfer the domain into the assigned registrar account. ')
                    .(DomainDecisions::syncNow($d)?'Then use Sync now.':'Ask staff who can sync this domain.'),
                'action'=>['label'=>'Open registrar account','url'=>Facts::url(fn()=>RegistrarFacts::accountUrl((int)$d->server_id))]]);
        }
        if (!DomainDecisions::ended($d)&&$read['read_review']&&!\Paymenter\Extensions\Servers\Domains\Services\HoldingReadRetry::retryableHold($d)) {
            $name=trim((string)\App\Models\Server::whereKey($d->server_id)->value('name'));
            // A failed read is not ownership proof. Name the possible cause without stopping recovery.
            $account='The domain may not be in '.($name!==''?'the assigned account: '.$name.'.':'its assigned registrar account.').' Check the account.';
            if (!$read['read_stopped']&&$read['next_read']!==null) {
                return $withPayment(['title'=>'Registrar reads keep failing. Automatic retries continue.','tone'=>'warning',
                    'body'=>$account.(DomainDecisions::syncNow($d)?' Use Sync now to retry sooner.':'')]);
            }
            return $withPayment(['title'=>'Registrar reads keep failing.','tone'=>'warning',
                'body'=>$account.(DomainDecisions::syncNow($d)?' Then use Sync now.':' Ask staff who can sync this domain.'),'action'=>['label'=>'Open registrar account','url'=>Facts::url(fn()=>RegistrarFacts::accountUrl((int)$d->server_id))]]);
        }
        // Sync cannot run until a pending import is confirmed, so the import notice comes first.
        if ($d->adoptionPending()) {
            return ['title'=>'Imported without purchase records. Check open invoices.','tone'=>'warning'];
        }
        if (!DomainDecisions::ended($d)&&($hold=DomainDecisions::hold($d))!==null) {
            $hasAction=preg_match('/(?:use Sync now|use Resume sync|ask staff|Manage this domain)/i',$hold['text']);
            return $withPayment(['title'=>$hold['text'],'body'=>$hold['stopped']&&!$hasAction?DomainDecisions::restart($d):null,'tone'=>'warning','resume'=>true]);
        }
        if ($payment!==null) { return $payment; }
        if ($d->provenance==='adopted'&&($d->observation['adoption_review']['near_due']??false)===true&&!RenewalStop::stopped(RenewalStop::read($d))) {
            return ['title'=>'Imported after the invoice date. No renewal invoice was issued.','tone'=>'warning',
                'action'=>['label'=>'Open service','url'=>Facts::url(fn()=>\App\Admin\Resources\ServiceResource::getUrl('edit',['record'=>$d->service_id]))]];
        }
        if (!DomainDecisions::account($d)) { return ['title'=>DomainDecisions::NO_ACCOUNT,'tone'=>'warning']; }
        $op=$d->latestAcquisition;
        if ($op&&in_array($op->state,['held','uncertain'],true)&&in_array($d->status,StaffDomainStatus::ACQUIRING,true)) {
            $cause=rtrim(preg_replace('/^Not sent: /','',(string)Labels::reason($op->safe_code,$op->funding_basis==='complimentary')),'.');
            $title=Labels::action($op->action).($op->state==='held'?' not sent'.($cause===''?'':': '.(preg_match('/\A(?:Enom|NameSilo)\b/',$cause)?$cause:lcfirst($cause))).'.':' not confirmed by the registrar.');
            try{$title=RequestFacts::readyMessage($op)??$title;}catch(\Throwable){}
            // An order waiting only for Orders names the one fix, the registrar's Orders switch.
            try{$orders=\Paymenter\Extensions\Servers\Domains\Services\ContactStepRetries::waitsForOrders($op);}catch(\Throwable){$orders=false;}
            return ['title'=>$title,'tone'=>'warning','action'=>$orders
                ?['label'=>'Open registrar','url'=>Facts::url(fn()=>RegistrarFacts::accountUrl((int)$op->server_id,'orders'))]
                :['label'=>'Check request #'.$op->id,'url'=>Facts::url(fn()=>OperationResource::getUrl('view',['record'=>$op->id]))]];
        }
        return null;
    }
    /** Money that reached closed or refunded debt, with its invoice. */
    private static function paymentNotice(Domain $d): ?array
    {
        if (AcquisitionPaymentReview::count($d)>0) {
            $invoice=DB::table('domains_obligation_alerts as a')->join('domains_operations as o','o.id','=','a.operation_id')->where('a.domain_id',$d->id)
                ->where('a.code','acquisition_late_money')->whereNull('a.resolved_at')->orderByDesc('a.id')->value('o.invoice_id');
            return ['title'=>'Payment activity after the order closed.','body'=>'Check the invoice and payments before contacting the customer.','tone'=>'warning',
                'action'=>self::invoiceAction($invoice),'invoice'=>$invoice?(int)$invoice:null];
        }
        try {
            if (RenewalReplacements::activity($d)) {
                $invoice=$d->operations()->where('action','renew')->get()->first(fn(Operation $op)=>array_key_exists('renewal_replacement',$op->policy??[]))?->invoice_id;
                return ['title'=>'Payment received after credit. Check the invoice.','tone'=>'warning','action'=>self::invoiceAction($invoice),'invoice'=>$invoice?(int)$invoice:null];
            }
        } catch (\Throwable) { return ['title'=>DomainDecisions::UNREADABLE,'tone'=>'danger']; }
        return null;
    }
    private static function invoiceAction(mixed $invoice): ?array
    { return ($url=$invoice?Facts::invoiceUrl((int)$invoice):null)?['label'=>'Open invoice','url'=>$url]:null; }
}
