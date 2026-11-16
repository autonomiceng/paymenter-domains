<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Placeholder,Radio,Textarea};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,Log};
use Paymenter\Extensions\Servers\Domains\Admin\Resources\{DomainResource,OperationResource};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Presentation\{Reader,State};
use Paymenter\Extensions\Servers\Domains\Services\{DepartureBlocked,DepartureChecks,HoldingDeparture,HoldingLocks,MoneyDecisions,MoneyOutcome,StaffHoldingReview,StaffReason};
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
    /** @return array{block:?array,close:list<int>,kept:list<int>} */
    public static function preview(Domain $d): array
    {
        // One read per loaded record: a page render asks several times, a new request loads a new record.
        static $cache=null;$cache??=new \WeakMap;
        return $cache[$d]??=HoldingDeparture::preview($d,app(Clock::class)->now()->getTimestamp());
    }
    /** The blocking line: its state word, sentence and record links. @return array{0:State,1:string,2:list<array{0:string,1:string,2:?string}>} */
    public static function line(array $block,Domain $d): array
    {
        $invoice=$block['invoice']===null?'the renewal invoice':Facts::invoiceName($block['invoice']);
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
        if($block['invoice']!==null){$links[]=['departure_invoice','Open '.Facts::invoiceName($block['invoice']),Facts::invoiceUrl($block['invoice'])];}
        if($request){$links[]=['departure_request','Open '.$request,Facts::url(fn()=>OperationResource::getUrl('view',['record'=>$block['operation']]))];}
        if($block['hold_domain']!==null&&$block['hold_domain']!==(int)$d->id){$links[]=['departure_hold','Open '.$hold,Facts::url(fn()=>DomainResource::getUrl('view',['record'=>$block['hold_domain']]))];}
        return [$state,$text,$links];
    }
    public static function action(Domain $d): Action
    {
        $suspected=static function()use($d):bool{try{return DepartureChecks::suspected($d);}catch(\Throwable){return false;}};
        return Action::make('confirm_departure')->label(self::LABEL)->color('primary')
            ->visible(fn()=>self::allowed()&&$suspected())
            // Muted, focusable and described by the Departure check row while a line blocks it.
            ->disabled(fn()=>self::preview($d)['block']!==null)
            ->tooltip(fn()=>($b=self::preview($d)['block'])?self::line($b,$d)[1]:null)
            ->modalHeading(self::LABEL.' '.$d->domain)->modalSubmitActionLabel(self::LABEL)->modalWidth('2xl')
            ->modalSubmitAction(fn(Action $action)=>$action->color('danger'))
            ->schema(fn()=>[
                Hidden::make('review_key')->default(fn()=>StaffHoldingReview::ticket((int)Auth::id(),(int)$d->id,HoldingDeparture::ACTION,
                    StaffHoldingReview::stamp(HoldingLocks::take($d,advisory:true)),app(Clock::class))),
                Radio::make('reason')->label('Reason')->required()->options(array_map(fn($r)=>rtrim($r,'.'),HoldingDeparture::REASONS)),
                Textarea::make('note')->label('Staff note')->required()->minLength(5)->maxLength(500)->rows(2)
                    ->helperText('Only staff see it. Say how you know the domain moved.')
                    ->rules(['regex:'.StaffReason::SINGLE_LINE])->validationMessages(['regex'=>'Use one line.']),
                Placeholder::make('outcome')->label('When you confirm')->content(self::outcome($d)),
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
    /** What confirmation changes, from the read-only preview. */
    private static function outcome(Domain $d): string
    {
        $lines=[];
        foreach(self::preview($d)['close'] as $id){
            $op=$d->operations()->whereKey($id)->first();if(!$op){continue;}
            $lines[]=Facts::invoiceName((int)$op->invoice_id).' is cancelled. It was an unpaid renewal of $'.number_format((float)$op->amount,2).'.';
        }
        $lines[]='Service #'.$d->service_id." is cancelled. Confirm doesn't change DNS.";
        $lines[]="This can't be undone. If the domain comes back, import it as a new domain.";
        return implode(' ',$lines);
    }
}
