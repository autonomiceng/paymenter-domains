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
use Paymenter\Extensions\Servers\Domains\Services\{ObservationRecords,OperationFailure,StaffOperationReview,StaffOperations,StaffOperationWrites,StaffReviewRefused,TransferInputs};
final class OperationActions
{
    /** Toast titles, named for the action. */
    public const DONE=['recheck'=>'Status checked','resume_reads'=>'Status checked','resume_observation'=>'Sync resumed','hold'=>'Request on hold',
        'release'=>'Hold released','disposition'=>'Request closed','refund'=>'Cancelled and refunded','credit'=>'Refund retried','replacement'=>'Renewal invoice reissued'];
    /** Money actions ask for a reason. Check status, Resume sync, Put on hold and Release hold run on click with the action name as the
     * recorded reason; every action still uses a one-use ticket bound to the page's review stamp, so a stale second click is refused. */
    private const REASON=['refund','credit','replacement'];
    public static function make(Operation $op,string $stamp): array
    {
        $out=[];
        foreach(StaffOperationReview::ACTIONS as $name=>$label){
            $contactRecovery=$name==='release'&&$op->action==='transfer'&&$op->safe_code==='contact_details_required';
            if($contactRecovery){$label='Use updated account details';}
            $ticket=fn()=>StaffOperationReview::ticket((int)Auth::id(),(int)$op->domain_id,(int)$op->id,$name,$stamp,app(Clock::class));
            $action=Action::make($name)->label($label)->color($name==='refund'?'danger':'gray')
                ->visible(fn()=>Staff::can(StaffOperationReview::permission($name))&&self::relevant($name,$op));
            if(in_array($name,[...self::REASON,'disposition'],true)){
                $action->requiresConfirmation()->modalHeading($name==='disposition'?$label:$label.'?')->modalSubmitActionLabel($label)->modalWidth('lg')
                    ->modalDescription(self::description($name));
            }
            if(in_array($name,self::REASON,true)){
                $action->schema([Hidden::make('review_key')->default($ticket),
                    Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(3)
                        ->rules(['regex:'.StaffReason::SINGLE_LINE])->validationMessages(['regex'=>'Use one line.'])]);
            }
            $out[]=$action->action(function(array $data)use($op,$name,$label,$ticket,$contactRecovery){
                try { $actor=Auth::user(); if(!$actor instanceof \App\Models\User){throw new \DomainException('Staff required.');}
                    $reason=in_array($name,self::REASON,true)?trim((string)($data['reason']??'')):$label;
                    app(StaffOperations::class)->run($actor,(int)$op->domain_id,(int)$op->id,$name,(string)($data['review_key']??$ticket()),$reason);
                    Notification::make()->success()->title($contactRecovery?'Contact details updated':self::DONE[$name])->send();
                }catch(StaffReviewRefused $error){Notification::make()->danger()->title(self::FAILED)->body($error->getMessage())->send();
                }catch(\Throwable $error){
                    $code=OperationFailure::code($error);
                    Log::warning('Domains staff operation refused.',['reason'=>$code,'action'=>$name,'operation_id'=>(int)$op->id]);
                    Notification::make()->danger()->title(self::FAILED)->body(OperationFailure::known($error)?->message() ?? 'Reload the page and try again. Ref '.$code.'.')->send();}
            });
        }
        return $out;
    }
    public const FAILED="Couldn't update request";
    /** Domain recovery reuses the current request's bound, audited review ticket. */
    public static function scheduledReads(Domain $d): array
    {
        $op=DomainQueries::operations($d)->orderByDesc('id')->first(); if(!$op){return [];}
        foreach(self::make($op,StaffOperationReview::stamp($op,$d)) as $action){
            if($action->getName()==='resume_observation'){
                return [$action->visible(fn()=>Staff::can(StaffOperationReview::permission('resume_observation'))
                    &&!StaffOperationReview::liveLease($op,app(Clock::class))&&ObservationRecords::stopped($d))];
            }
        }
        return [];
    }
    public static function relevant(string $action,Operation $op): bool
    {
        if(StaffOperationReview::liveLease($op,app(Clock::class))){return false;}
        if($op->state==='succeeded'){return false;}
        if($action==='resume_observation'){return ObservationRecords::stopped($op->domain);}
        if(PaidUncertainty::summary($op)!==null&&!in_array($action,['recheck','resume_reads','resume_observation'],true)){return false;}
        return match($action){
            'hold'=>in_array($op->state,['queued','awaiting_payment','awaiting_code'],true),
            'release'=>StaffOperationWrites::canRelease($op,app(Clock::class)->now()),
            'refund'=>$op->state==='held',
            'credit'=>$op->state==='rejected'&&$op->funding_basis==='paid_invoice',
            'replacement'=>$op->action==='renew'&&$op->state==='rejected',
            'disposition'=>$op->state==='rejected',
            'resume_reads'=>in_array($op->action,['register','transfer','renew'],true)&&in_array($op->state,['running','pending','uncertain','held'],true)&&self::readLimit($op),
            'recheck'=>in_array($op->state,['running','pending','uncertain','held'],true)&&!(in_array($op->action,['register','transfer','renew'],true)&&self::readLimit($op)),
            default=>false};
    }
    public static function transferCutoff(Operation $op): bool
    {
        $now=app(Clock::class)->now();
        $deadline=$op->action==='transfer'?TransferInputs::deadline($op,$now):null;
        return $deadline!==null && $now>=$deadline;
    }
    /** Status checks stopped at the read limit; Check status then restarts them. */
    public static function readLimit(Operation $op): bool
    {
        try { $p=$op->policy??[]; } catch (\Throwable) { return false; }
        return ($p['renewal_polls']??0)>=\Paymenter\Extensions\Servers\Domains\Services\OperationReconciliation::POLL_LIMIT||($p['reconciliation']['polls']??0)>=\Paymenter\Extensions\Servers\Domains\Services\OperationReconciliation::POLL_LIMIT;
    }
    public static function description(string $action): ?string
    {
        return match($action){
            'disposition'=>'Records the final result. Nothing is sent or refunded.',
            'refund'=>"Cancels this unsent request and credits the customer's account.",
            'credit'=>'Tries the account credit again.',
            'replacement'=>'Creates a new renewal invoice for this cycle.',
            default=>null,
        };
    }
}
