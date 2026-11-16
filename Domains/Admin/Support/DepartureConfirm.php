<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Placeholder,Radio,Textarea};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Facades\{Auth,Log};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row,Value};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader,State};
use Paymenter\Extensions\Servers\Domains\Services\{DepartureBlocked,DepartureChecks,HoldingDeparture,HoldingLocks,MoneyDecisions,MoneyOutcome,PricingRule,StaffHoldingReview,StaffReason,WatchDiscovery};
/** Confirm transferred away: the head action, its blocking line and its dialog. Facts are read-only previews; the
 * confirmation checks them all again. */
final class DepartureConfirm
{
    public const LABEL='Confirm transferred away';
    private const CHANGED='This domain changed. Reload the page and try again.';
    private const FAILURES=[
        'Current staff permissions required. Reload this page.'=>"You don't have permission to confirm a departure.",
        'Enter a one-line reason between 5 and 500 characters.'=>'Enter a staff note (5 to 500 characters).',
        'Choose a reason.'=>'Choose a reason.',
        'Invalid review key. Reload this page.'=>self::CHANGED,'Holding changed. Reload this page and review it again.'=>self::CHANGED,
        'Review key used.'=>self::CHANGED,'Holding native authority changed.'=>self::CHANGED,
        'Holding audit full or unavailable.'=>'History for this domain is full. Ask your site admin.',
    ];
    /** Staff who may confirm and see the action. */
    public static function allowed(): bool
    { return Staff::can('admin.domains.manage')&&Staff::can(HoldingDeparture::PERMISSION); }
    /** @return array{block:?array,close:list<int>,credit:list<int>,kept:list<int>,credit_minor:array<int,int>} */
    public static function preview(Domain $d): array
    {
        // One read per loaded record: a page render asks several times, a new request loads a new record.
        static $cache=null;$cache??=new \WeakMap;
        return $cache[$d]??=HoldingDeparture::preview($d,app(Clock::class)->now()->getTimestamp());
    }
    /** The blocking line: its state word, sentence and record links. @return array{0:State,1:string,2:list<array{0:string,1:string,2:?string}>} */
    public static function line(array $block,Domain $d): array
    {
        $invoice=$block['invoice']===null?'the renewal invoice':self::invoice($block['invoice']);
        $request=$block['operation']===null?null:'Request #'.$block['operation'];
        $hold=$block['hold_domain']===null?null:Domain::whereKey($block['hold_domain'])->value('domain');
        $owner=trim((string)($d->user?->name??''))?:'The customer';
        [$state,$text]=match($block['line']){
            HoldingDeparture::UNREADABLE=>[State::CouldntRead,"The payment records for {$invoice} couldn't be read. Reopen this page to read them again. This clears when they read."],
            HoldingDeparture::OWNER_HOLD=>[State::NeedsReview,$owner."'s account is on hold.".($hold?" Use Release hold on {$hold}.":'')],
            HoldingDeparture::DISPUTE=>[State::NeedsReview,"A payment on {$invoice} is in a bank dispute. This clears when the dispute closes."],
            HoldingDeparture::OVERDRAWN=>[State::NeedsReview,"{$invoice} has paid out more in refunds and disputes than it received. After a lost dispute, unused returned account credit is taken back automatically. Confirm stays blocked while money is owed".($hold?" or the account is on hold; the hold on {$hold} shows what is owed.":'.')],
            HoldingDeparture::RETURN_SENDING=>[State::Checking,"A refund on {$invoice} is still being sent. This clears when it finishes."],
            HoldingDeparture::PAYMENT_PROCESSING=>[State::Checking,"A payment on {$invoice} is still processing. This clears when it finishes."],
            HoldingDeparture::AT_REGISTRAR=>[State::Checking,"The renewal on {$invoice}".($request?", {$request},":'')." is still at the registrar. This clears when the registrar answers."],
            HoldingDeparture::RENEWAL_REVIEW=>[State::NeedsReview,"The records for the renewal on {$invoice} don't agree, so it can't be closed safely.".($request?" See {$request}.":'')." This clears when they agree."],
            default=>[State::NeedsReview,'Last registrar read is older than 24 hours. Use Check again on the Recheck row.'],
        };
        $links=[];
        if($block['invoice']!==null){$links[]=['departure_invoice','Open '.self::invoice($block['invoice']),Facts::invoiceUrl($block['invoice'])];}
        if($request){$links[]=['departure_request','Open '.$request,Facts::url(fn()=>OperationResource::getUrl('view',['record'=>$block['operation']]))];}
        if($block['hold_domain']!==null&&$block['hold_domain']!==(int)$d->id){$links[]=['departure_hold','Open '.$hold,Facts::url(fn()=>DomainResource::getUrl('view',['record'=>$block['hold_domain']]))];}
        return [$state,$text,$links];
    }
    /** The invoice name as the Next invoice row shows it. */
    public static function invoice(int $id): string
    { $name=Facts::invoiceName($id); return ctype_digit($name)?'Invoice #'.$name:$name; }
    public static function action(Domain $d): Action
    {
        $suspected=static function()use($d):bool{try{return DepartureChecks::suspected($d);}catch(\Throwable){return false;}};
        return Action::make('confirm_departure')->label(self::LABEL)->color('primary')
            ->visible(fn()=>self::allowed()&&$suspected())
            // Muted, focusable and described by the Departure check row while a line blocks it.
            ->disabled(fn()=>self::preview($d)['block']!==null)
            ->tooltip(fn()=>($b=self::preview($d)['block'])?self::line($b,$d)[1]:null)
            ->modalHeading(self::LABEL)->modalDescription($d->domain)->modalSubmitActionLabel(self::LABEL)->modalWidth('2xl')
            ->modalSubmitAction(fn(Action $action)=>$action->color('danger'))
            ->schema(fn()=>[
                Hidden::make('review_key')->default(fn()=>StaffHoldingReview::ticket((int)Auth::id(),(int)$d->id,HoldingDeparture::ACTION,
                    StaffHoldingReview::stamp(HoldingLocks::take($d,advisory:true)),app(Clock::class))),
                Radio::make('reason')->label('Reason')->required()->live()->options(array_map(fn($r)=>rtrim($r,'.'),HoldingDeparture::REASONS)),
                // The helper sits under the label, before the field, so it is read before typing (mock 06).
                Textarea::make('note')->label('Staff note')->required()->minLength(5)->maxLength(500)->rows(2)
                    ->belowLabel(Text::make('Only staff see it. Say how you know the domain moved.'))
                    ->rules(['regex:'.StaffReason::SINGLE_LINE])->validationMessages(['regex'=>'Use one line.']),
                Placeholder::make('outcome')->label('When you confirm')->content(fn($get)=>self::outcome($d,$get('reason'))),
            ])
            ->action(function(array $data,Action $action,$livewire)use($d):void{
                $actor=Auth::user();
                $result=$actor instanceof \App\Models\User?app(HoldingDeparture::class)->confirm($actor,(int)$d->id,(string)($data['review_key']??''),(string)($data['reason']??''),trim((string)($data['note']??''))):null;
                if($result?->outcome===MoneyOutcome::Applied){
                    Notification::make()->success()->title('Domain confirmed transferred away')->send();
                    $livewire->redirect(DomainResource::getUrl('view',['record'=>$d->id]));return;
                }
                $error=$result?->error;
                $message=match(true){
                    $result?->outcome===MoneyOutcome::Deferred=>MoneyDecisions::BUSY,
                    $error instanceof DepartureBlocked=>self::line($error->block,$d)[1],
                    $error instanceof \DomainException=>self::FAILURES[$error->getMessage()]??null,
                    default=>null};
                if($message===null){Log::warning('Domains departure confirmation failed.',['domain_id'=>$d->id,'exception_class'=>$error?$error::class:null]);$message='Reload the page and try again.';}
                // The dialog stays open with the line, and nothing was confirmed.
                Notification::make()->danger()->title("Couldn't confirm")->body($message)->send();
                $action->halt();
            });
    }
    /** What confirmation changes, from the read-only preview: each line is also on the page. */
    private static function outcome(Domain $d,?string $reason=null): HtmlString
    {
        $p=self::preview($d);$lines=[];$b=fn(string $s)=>'<strong>'.e($s).'</strong>';
        foreach($p['close'] as $id){
            $op=$d->operations()->whereKey($id)->first();if(!$op){continue;}
            $lines[]=$b(self::invoice((int)$op->invoice_id)).e(' is cancelled. It was an unpaid renewal of $'.number_format((float)$op->amount,2).'.');
        }
        foreach($p['credit_minor'] as $invoice=>$minor){$lines[]=$b(self::invoice((int)$invoice)).e(' is cancelled, with '.self::money($minor).' to account credit.');}
        $lines[]=$b('Service #'.$d->service_id).e(' is cancelled.');
        $lines[]=e('The name becomes available to order.');
        $lines[]=e(trim("Confirm doesn't change DNS. ".(DepartureChecks::staffDnsCase($d)[1]??'')));
        $owner=self::owner($d);
        $watch=match(WatchDiscovery::departurePreview($d)){'active'=>'as an external domain','paused'=>'paused','hosting','retired'=>'not monitored',default=>null};
        if($watch!==null){$lines[]=e($owner."'s monitored domain stays, {$watch}.");}
        if(($subject=self::subject($d,$reason))!==null){$lines[]=e($owner.' gets one email, “'.$subject.'”.');}
        $lines[]=$b("This can't be undone.").e(' If the domain comes back, import it as a new domain.');
        return new HtmlString(implode('',array_map(fn($l)=>'<p>'.$l.'</p>',$lines)));
    }
    /** The departure email's subject for the picked reason, from its saved template; null when it won't send. */
    public static function subject(Domain $d,?string $reason): ?string
    {
        try{
            $t=\App\Models\NotificationTemplate::where('key','domains_domain_departed')->first();
            if(!$t||!$t->enabled||$d->user===null||config('settings.mail_disable')){return null;}
            // The same mail checks the departure email reserves with: a customer who turned the email off gets none.
            if(!$t->isEnabledForPreference($d->user->notificationsPreferences()->where('notification_template_id',$t->id)->first(),'mail')){return null;}
            return trim(\Illuminate\View\Compilers\BladeCompiler::render((string)$t->subject,['domain'=>$d->domain,'deleted'=>$reason==='deleted','company'=>\Paymenter\Extensions\Servers\Domains\Dns\DnsSignalLabels::company()]));
        }catch(\Throwable){return null;}
    }
    /** The customer's name as the Billing Customer row shows it. */
    private static function owner(Domain $d): string
    { $u=$d->user; return $u?(trim($u->first_name.' '.$u->last_name)?:(string)$u->email):'The customer'; }
    public static function money(int $minor): string
    { return '$'.number_format($minor/100,2); }
    /** The page rows confirmation changes, each saying "on confirm" in its own helper (mock 05, 05b). Read-only. */
    public static function pageFacts(Domain $d,array $facts): array
    {
        $p=self::preview($d);
        $closed=array_map(fn($id)=>(int)$d->operations()->whereKey($id)->value('invoice_id'),$p['close']);
        $outcome=static function(int $invoice)use($closed,$p):?string{
            if(isset($p['credit_minor'][$invoice])){return 'Cancelled on confirm, with '.self::money($p['credit_minor'][$invoice]).' to account credit.';}
            return in_array($invoice,$closed,true)?'Cancelled on confirm.':null;
        };
        // The row names its invoice first (DomainDecisions); the outcome follows the due date, before the pause sentence.
        $next=$facts['Next invoice']['sub']??null;$shown=null;$html=$next instanceof Htmlable?$next->toHtml():e((string)$next);
        foreach([...$closed,...array_keys($p['credit_minor'])] as $invoice){
            if(str_starts_with($html,e(self::invoice($invoice).','))){$shown=$invoice;break;}
        }
        if($shown!==null&&($line=$outcome($shown))!==null){
            $facts['Next invoice']['sub']=new HtmlString(str_replace(e(DepartureChecks::RENEWAL_WAITS),e($line.' '.DepartureChecks::RENEWAL_WAITS),$html));
        }
        // A paid renewal never sent has no open invoice for the row, so the row names it with its outcome.
        foreach($p['credit_minor'] as $invoice=>$minor){
            if($invoice===$shown){continue;}
            $url=Facts::invoiceUrl((int)$invoice);$name=self::invoice((int)$invoice);
            $total=(string)\App\Models\Invoice::find($invoice)?->formattedTotal;
            $rest=($total!==''?', '.$total:'').', paid, never sent to the registrar. '.$outcome((int)$invoice);
            $facts['Next invoice']=[...($facts['Next invoice']??Facts::row('Paused')),'value'=>'Paused',
                'sub'=>new HtmlString(e($name.$rest.' '.DepartureChecks::RENEWAL_WAITS).($url?' <a class="fi-link domains-link" href="'.e($url).'">Open invoice</a>':''))];
            break;
        }
        if(isset($facts['Service'])&&($facts['Service']['value']??null)!==null){$facts['Service']['sub']='Service #'.$d->service_id.'. Cancelled on confirm.';}
        if(isset($facts['DNS records'])){$sub=$facts['DNS records']['sub']??null;$add="Confirm doesn't change DNS.";
            $facts['DNS records']['sub']=$sub instanceof Htmlable?new HtmlString($sub->toHtml().' '.e($add)):trim((string)$sub.' '.$add);}
        return $facts;
    }
    /** The job rows after Public registry, and the Monitored domain row, for staff who may confirm. @return array{name:Row,email:?Row,watch:?Row} */
    public static function rows(Domain $d): array
    {
        $fact=\Paymenter\Extensions\Servers\Domains\Presentation\Fact::class;$owner=self::owner($d)."'s monitored domain stays.";
        $watch=match(WatchDiscovery::departurePreview($d)){
            'active'=>Value::fact($fact::entered('Monitored as an external domain on confirm'),$owner),
            'paused'=>Value::fact($fact::entered('Stays paused on confirm'),$owner),
            'retired'=>Value::fact($fact::entered('Not monitored after confirm'),$owner.' Its sources are off.'),
            'hosting'=>Value::fact($fact::entered('Not monitored after confirm'),$owner),
            default=>null};
        // Email: only when the departure email will send (its saved template is on), as the dialog line says.
        $email=self::subject($d,null)===null?null:Row::make('Email',Value::fact($fact::entered('One email to '.self::owner($d).' on confirm'),
            'Its subject follows the reason you pick.'.(DepartureChecks::dnsCase($d)==='registrar'?' It includes the step to set up DNS at the new host.':'')));
        return ['name'=>Row::make('Name',Value::fact($fact::entered('Released on confirm'),'The name becomes available to order. If the domain comes back, import it as a new domain.')),
            'email'=>$email,'watch'=>$watch?Row::make('Monitored domain',$watch):null];
    }
}
