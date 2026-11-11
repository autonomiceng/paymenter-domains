<?php
// Enom444f31c operation action layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Textarea};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{DomainManagement,EnomTestRegistration,ManagementEvidence,ObservationRecords,ObservationWork,OperationFailure,OperationTerminalProof,ReceiptReturns,StaffOperationReview,StaffOperations,StaffOperationWrites,StaffReviewRefused,StaffSyncReview,TransferInputs,UnpaidAcquisitionProof};
final class OperationActions
{
    /** Toast titles, named for the action. */
    public const DONE=['approve_charge'=>'Captured charge approved','recheck'=>'Request reviewed','resume_reads'=>'Status checks restarted','resume_observation'=>'Sync resumed','hold'=>'Request on hold',
        'release'=>'Hold released','disposition'=>'Request closed','refund'=>'Request cancelled','credit'=>'Refund checked','replacement'=>'Renewal invoice reissued'];
    /** Money actions ask for a reason. Check status, Resume sync, Put on hold and Release hold run on click with the action name as the
     * recorded reason; every action still uses a one-use ticket bound to the page's review stamp, so a stale second click is refused.
     * Release hold asks first when it sends a purchase right away. */
    private const REASON=['refund','credit','replacement','approve_charge'];
    public static function make(Operation $op,string $stamp): array
    {
        $out=[];
        foreach(StaffOperationReview::ACTIONS as $name=>$label){
            // Releasing a contact hold copies the account details in, so the button says that.
            $contactRecovery=$name==='release'&&$op->safe_code==='contact_details_required';
            if($contactRecovery){$label='Use updated account details';}
            $complimentaryClosure=$name==='credit'&&$op->funding_basis==='complimentary';
            if($complimentaryClosure){$label='Close order';}
            $ticket=fn()=>StaffOperationReview::ticket((int)Auth::id(),(int)$op->domain_id,(int)$op->id,$name,$stamp,app(Clock::class));
            $action=Action::make($name)->label($label)->color($name==='refund'?'danger':'gray')
                ->visible(fn()=>Staff::can(StaffOperationReview::permission($name))&&self::relevant($name,$op));
            if(in_array($name,[...self::REASON,'disposition'],true)){
                $action->requiresConfirmation()->modalHeading($name==='disposition'?$label:$label.'?')->modalSubmitActionLabel($label)->modalWidth('lg')
                    ->modalDescription($complimentaryClosure?'Closes this complimentary order and its service. Nothing is charged or credited.':($name==='refund'?self::refundWords($op):self::description($name)));
                // A destructive confirm is red wherever the action is shown, including as a notice button. Filament passes the
                // confirm button only as $action; any other name gets this action itself, whose button reopens the dialog.
                if($name==='refund'){$action->modalSubmitAction(fn(Action $action)=>$action->color('danger'));}
            }
            $account=$name==='release'&&!$contactRecovery&&$op->state==='held'?RequestFacts::account($op)['account']:null;
            // A held complimentary cost comes back on release, so release sends nothing until the cost is approved.
            $sends=EnomTestRegistration::releaseSends($op,$account)&&!\Paymenter\Extensions\Servers\Domains\Services\ComplimentaryCosts::awaitingApproval($op)?RequestFacts::sendWords($op,$account):null;
            // Every release starts paid work again, so each one asks first and says what it starts.
            if($name==='release'){
                [$heading,$submit,$description]=RequestFacts::releaseConfirmation($op,$account,$sends,$contactRecovery);
                $action->requiresConfirmation()->modalHeading($heading)->modalSubmitActionLabel($submit)->modalWidth('lg')->modalDescription($description);
            }
            if(in_array($name,self::REASON,true)){
                $action->schema([Hidden::make('review_key')->default($ticket),
                    Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(3)
                        ->rules(['regex:'.StaffReason::SINGLE_LINE])->validationMessages(['regex'=>'Use one line.'])]);
            }
            $out[]=$action->action(function(array $data)use($op,$name,$label,$ticket,$contactRecovery,$sends,$complimentaryClosure){
                try { $actor=Auth::user(); if(!$actor instanceof \App\Models\User){throw new \DomainException('Staff required.');}
                    $reason=in_array($name,self::REASON,true)?trim((string)($data['reason']??'')):$label;
                    app(StaffOperations::class)->run($actor,(int)$op->domain_id,(int)$op->id,$name,(string)($data['review_key']??$ticket()),$reason);
                    $check=$name!=='recheck'?null:(in_array($op->action,['register','transfer','renew'],true)?\Paymenter\Extensions\Servers\Domains\Services\RegistrarCheck::for($op->fresh()):ManagementCheck::words($op->fresh()));
                    $notification=Notification::make();
                    $check&&$check['title']!=='Registrar checked'?$notification->warning():$notification->success();
                    $notification->title($check['title']??($contactRecovery?'Contact details updated':($complimentaryClosure?'Order closed':self::DONE[$name])))
                        ->body($check['body']??($name==='resume_reads'?'The system will check again on its next turn.':($name==='recheck'?'Open this request for its current result and next step.':($sends===null?null:"The {$sends} goes to Enom within a few minutes."))))->send();
                }catch(StaffReviewRefused $error){
                    ($error->getMessage()===StaffOperationReview::CHANGED?self::stale($name,$op->fresh()):Notification::make()->danger()->persistent()->title(self::FAILED)->body($error->getMessage()))->send();
                }catch(\Throwable $error){
                    $code=OperationFailure::code($error);
                    Log::warning('Domains staff operation refused.',['reason'=>$code,'action'=>$name,'operation_id'=>(int)$op->id]);
                    $why=OperationFailure::known($error)?->message() ?? 'Reload the page and try again. Ref '.$code.'.';
                    $cancelled=null;
                    try{$cancelled=$name==='refund'?self::refundPending($op->fresh(),$code):null;}catch(\Throwable){}
                    // A failure stays on screen until staff close it.
                    ($cancelled??Notification::make()->danger()->title(self::FAILED)->body($why))->persistent()->send();}
            });
        }
        return $out;
    }
    public const FAILED="Couldn't update request";
    /** An old click: says what already happened. The page reloads after it. */
    public static function stale(string $action,Operation $op): Notification
    {
        return Notification::make()->warning()->title(match(true){
            $action==='release'&&$op->state!=='held'=>'Hold already released',$action==='hold'&&$op->state==='held'=>'Already on hold',default=>'This request changed'})
            ->body('Nothing new was done. This page is now up to date.');
    }
    /** What only the customer can give, whatever the state: 'payment', 'code' or null.
     * $statuses: invoice statuses by id that a list read for its page (null for a missing invoice). Without the request's invoice there, it is read now. */
    public static function customerOwes(Operation $op, ?array $statuses=null): ?string
    {
        if($op->invoice_id!==null&&($statuses!==null&&array_key_exists((int)$op->invoice_id,$statuses)?$statuses[(int)$op->invoice_id]:\App\Models\Invoice::whereKey($op->invoice_id)->value('status'))!=='paid'){return 'payment';}
        return $op->action==='transfer'&&!TransferInputs::validCode($op->payload['epp']??null)?'code':null;
    }
    /** Domain recovery reuses the current request's bound, audited review ticket. Any domain can stop syncing, so one with no request is reviewed on the domain. */
    public static function scheduledReads(Domain $d): array
    {
        $op=DomainQueries::operations($d)->orderByDesc('id')->first(); if(!$op){return [self::resumeOnDomain($d)];}
        foreach(self::make($op,StaffOperationReview::stamp($op,$d)) as $action){
            if($action->getName()==='resume_observation'){
                return [$action->disabled(fn()=>ObservationWork::resumeBlocked($d)!==null)->tooltip(fn()=>ObservationWork::resumeBlocked($d))->visible(fn()=>Staff::can(StaffOperationReview::permission('resume_observation'))
                    &&!StaffOperationReview::liveLease($op,app(Clock::class))&&!DomainFacts::ended($d)&&ObservationRecords::stopped($d)&&ObservationWork::currentId($d)!==null)];
            }
        }
        return [];
    }
    /** The same writer as a request's Resume sync, audited in the domain's own history. */
    private static function resumeOnDomain(Domain $d): Action
    {
        $name='resume_observation';
        return Action::make($name)->label(StaffOperationReview::ACTIONS[$name])->color('gray')
            ->disabled(fn()=>ObservationWork::resumeBlocked($d)!==null)->tooltip(fn()=>ObservationWork::resumeBlocked($d))
            ->visible(fn()=>Staff::can(StaffOperationReview::permission($name))&&!DomainFacts::ended($d)&&ObservationRecords::stopped($d)&&ObservationWork::currentId($d)!==null)
            ->action(function()use($d,$name):void {
                try { $actor=Auth::user(); $id=ObservationWork::currentId($d); if(!$actor instanceof \App\Models\User||$id===null){throw new \DomainException('Staff required.');}
                    app(ObservationWork::class)->resume($id,'staff_review',new StaffSyncReview((int)$actor->id,(int)$d->id,StaffSyncReview::stamp($d->fresh()),StaffOperationReview::ACTIONS[$name],app(Clock::class)));
                    Notification::make()->success()->title(self::DONE[$name])->send();
                }catch(\Throwable $error){
                    Log::warning('Domains sync resume refused.',['reason'=>OperationFailure::code($error),'domain_id'=>(int)$d->id]);
                    Notification::make()->danger()->title("Couldn't resume sync")->body($error instanceof StaffReviewRefused?$error->getMessage():'Reload the page and try again.')->send();}
            });
    }
    public static function relevant(string $action,Operation $op): bool
    {
        if(StaffOperationReview::liveLease($op,app(Clock::class))){return false;}
        if($op->state==='succeeded'){return false;}
        if($action==='resume_observation'){return !DomainFacts::ended($op->domain)&&ObservationRecords::stopped($op->domain);}
        if(PaidUncertainty::summary($op)!==null&&!in_array($action,['recheck','resume_reads','resume_observation','approve_charge'],true)){return false;}
        if($action==='approve_charge'){try{StaffOperationReview::requirePermission(\App\Models\User::with('role')->find(Auth::id()),$action,$op);return \Paymenter\Extensions\Servers\Domains\Services\CapturedChargeReview::approvable($op);}catch(\Throwable){return false;}}
        return match($action){
            // Release needs what the customer still owes, so a hold here would have no way back.
            'hold'=>StaffOperationWrites::canHold($op)&&self::customerOwes($op)===null,
            'release'=>StaffOperationWrites::canRelease($op,app(Clock::class)->now()),
            // A contact step hold offers it only while nothing went to NameSilo; the writer repeats that proof under locks.
            'refund'=>$op->state==='held'&&$op->funding_basis==='paid_invoice'
                &&($op->safe_code!==\Paymenter\Extensions\Servers\Domains\Services\ContactProfileHold::CODE||\Paymenter\Extensions\Servers\Domains\Services\ContactProfileHold::unsent($op)),
            // A closed unpaid order released its name, so Refunds can never credit it. Late money goes through the payment review.
            // Complimentary orders have no payment for the refund sweep; retry only their unfinished closure.
            'credit'=>$op->state==='rejected'&&(($op->funding_basis==='paid_invoice'&&$op->safe_code!==UnpaidAcquisitionProof::CODE)
                ||($op->funding_basis==='complimentary'&&in_array($op->action,['register','transfer'],true)&&($d=$op->domain)
                    &&!self::complimentaryCloseBlocked($op)&&($d->status!==($op->action==='register'?'registration_rejected':'transfer_rejected')||$d->service?->status!==\App\Models\Service::STATUS_CANCELLED))),
            'replacement'=>$op->action==='renew'&&$op->state==='rejected',
            'disposition'=>self::closable($op),
            'resume_reads'=>in_array($op->action,['register','transfer','renew'],true)&&in_array($op->state,['running','pending','uncertain','held'],true)&&self::readLimit($op),
            'recheck'=>in_array($op->action,['register','transfer','renew'],true)
                ?in_array($op->state,['running','pending','uncertain','held'],true)&&!self::readLimit($op)
                :DomainManagement::canReconcile($op,app(Clock::class)->now()),
            default=>false};
    }
    /** Presentation only: the writer repeats returnability under HoldingLocks. */
    public static function complimentaryCloseBlocked(Operation $op):bool
    {
        if($op->funding_basis!=='complimentary'||$op->state!=='rejected'||!in_array($op->action,['register','transfer'],true)){return false;}
        try{\Paymenter\Extensions\Servers\Domains\Services\RefundProof::requireReturnable($op,$op->domain);return false;}catch(\Throwable){return true;}
    }
    /** A failed request with proof it ended, no end date and no earlier close. Closing records the review and changes nothing else. */
    private static function closable(Operation $op): bool
    {
        if($op->state!=='rejected'||$op->completed_at!==null||\Paymenter\Extensions\Servers\Domains\Presentation\RequestStatus::closed($op->state,$op->safe_code)||!($d=$op->domain)){return false;}
        try {
            return (OperationTerminalProof::safe($op,$d)||ManagementEvidence::resolved($op,$d))
                &&!collect($op->policy['staff_actions']??[])->contains(fn($row)=>($row['action']??null)==='disposition'&&($row['phase']??null)==='completed');
        } catch (\Throwable) { return false; }
    }
    public static function transferCutoff(Operation $op): bool
    {
        $now=app(Clock::class)->now();
        $deadline=$op->action==='transfer'?TransferInputs::deadline($op,$now):null;
        return $deadline!==null && $now>=$deadline;
    }
    /** Status reads stopped by a limit or a captured financial hold; Check status uses the audited resume. */
    public static function readLimit(Operation $op): bool
    {
        try {
            $p=$op->policy??[];
            if($op->action==='renew'&&($op->attempt['seal']??null)==='sealed'
                &&\Paymenter\Extensions\Servers\Domains\Services\RenewalCompletion::capturedChargeHeld($op)){return true;}
        } catch (\Throwable) { return false; }
        if(($p['reconciliation']['reason']??null)==='transfer_deadline_passed'||($p['reconciliation']['final_check']??null)==='done'){return true;}
        if(\Paymenter\Extensions\Servers\Domains\Services\TransferPolling::expected($op)){return false;}
        return ($p['renewal_polls']??0)>=\Paymenter\Extensions\Servers\Domains\Services\OperationReconciliation::POLL_LIMIT||($p['reconciliation']['polls']??0)>=\Paymenter\Extensions\Servers\Domains\Services\OperationReconciliation::POLL_LIMIT;
    }
    /** Cancel and refund names the amount and where it goes before staff confirm. A new order and its service end first. */
    public static function refundWords(Operation $op): string
    {
        $amount=is_numeric($op->amount)&&(float)$op->amount>0?($op->currency?:'USD').' '.number_format((float)$op->amount,2,'.',''):'the payment';
        if(in_array($op->action,['register','transfer'],true)){
            $registrar=PaidUncertainty::registrar($op);
            return "Cancels this order and its service, then refunds {$amount} to the original payment. If that payment can't be refunded, {$amount} goes to the customer's account credit. Nothing was sent to {$registrar}, so there is no registrar cost.";
        }
        return "Cancels this unsent request and refunds {$amount} to the original payment. If that payment can't be refunded, {$amount} is added to the customer's account credit instead.";
    }
    /** Cancel and refund commits the cancel before any money moves. When the refund then fails, say both facts and the
     * retry, which reuses the same refund. Null when the cancel did not commit or the refund has finished. */
    public static function refundPending(?Operation $op,string $code): ?Notification
    {
        if($op?->state!=='rejected'||!isset($op->funding['refund_authorization'])){return null;}
        if(isset($op->funding['refund'])&&!ReceiptReturns::malformed($op)&&ReceiptReturns::outstanding($op)===0){return null;}
        $ended=in_array($op->action,['register','transfer'],true)?'The order and its service are cancelled.':'The request is cancelled.';
        $where=isset($op->funding['refund'])?" The payment provider hasn't confirmed the refund.":" The refund hasn't gone through (ref {$code}).";
        return Notification::make()->warning()->title('Cancelled, refund not finished')
            ->body($ended.$where.' Use Retry refund. It reuses this refund, so the customer is never refunded twice.');
    }
    /** The confirmation text for each action that opens one. */
    public static function description(string $action): ?string
    {
        return match($action){
            'approve_charge'=>'Approves the charge already incurred at the registrar. Completes this renewal from its retained proof and keeps future spending paused.',
            'disposition'=>'Records the final result. Nothing is sent or credited.',
            'refund'=>"Cancels this unsent request and returns the payment. Account credit is used only when the original payment cannot be refunded.",
            'credit'=>'Checks the payment return again using the same refund request.',
            'replacement'=>'Creates a new renewal invoice for this cycle.',
            default=>null,
        };
    }
}
