<?php
// Enom444f31c operation action layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Textarea};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Domain,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{ObservationRecords,OperationFailure,StaffOperationReview,StaffOperations,StaffReviewRefused,TransferInputs};
final class OperationActions
{
    public static function make(Operation $op,string $stamp): array
    {
        $out=[];
        foreach(StaffOperationReview::ACTIONS as $name=>$label){
            $out[]=Action::make($name)->label($label)->color(in_array($name,['refund','credit','replacement'],true)?'warning':'gray')
                ->visible(fn()=>Staff::can(StaffOperationReview::permission($name))&&self::relevant($name,$op))
                ->requiresConfirmation()->modalHeading($label)->modalSubmitActionLabel($label)->modalWidth('lg')
                ->modalDescription(self::description($name))->schema([
                    Hidden::make('review_key')->default(fn()=>StaffOperationReview::ticket((int)Auth::id(),(int)$op->domain_id,(int)$op->id,$name,$stamp,app(Clock::class))),
                    Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(3)->helperText('Use one line.')
                        ->rules(['regex:/\A[^\x00-\x1f\x7f]*\z/'])->validationMessages(['regex'=>'Use one line without control characters.']),
                ])->action(function(array $data)use($op,$name){
                    try { $actor=Auth::user(); if(!$actor instanceof \App\Models\User){throw new \DomainException('Staff required.');}
                        app(StaffOperations::class)->run($actor,(int)$op->domain_id,(int)$op->id,$name,(string)($data['review_key']??''),trim((string)($data['reason']??'')));
                        Notification::make()->success()->title('Review recorded')->body('Reload this page to see the current request.')->send();
                    }catch(StaffReviewRefused $error){Notification::make()->danger()->title('Review refused')->body($error->getMessage())->send();
                    }catch(\Throwable $error){
                        $code=OperationFailure::code($error);
                        Log::warning('Domains staff operation refused.',['reason'=>$code,'action'=>$name,'operation_id'=>(int)$op->id]);
                        $message=(OperationFailure::known($error)?->message() ?? 'Reload this page and review current permissions, funding and retained evidence. Reference: '.$name.'/'.$op->id.'/'.$code.'.')
                            .(in_array($name,['refund','credit'],true)?' A credit decision may be retained; check the ledger before retrying.':'');
                        Notification::make()->danger()->title('Review refused')->body($message)->send();}
                });
        }
        return $out;
    }
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
            'release'=>$op->state==='held' && !self::transferCutoff($op),
            'refund'=>$op->state==='held',
            'credit'=>$op->state==='rejected'&&$op->funding_basis==='paid_invoice',
            'replacement'=>$op->action==='renew'&&$op->state==='rejected',
            'disposition'=>$op->state==='rejected',
            'resume_reads'=>in_array($op->action,['register','transfer','renew'],true)&&in_array($op->state,['running','pending','uncertain','held'],true),
            'recheck'=>in_array($op->state,['running','pending','uncertain','held'],true),
            default=>false};
    }
    public static function transferCutoff(Operation $op): bool
    {
        $now=app(Clock::class)->now();
        $deadline=$op->action==='transfer'?TransferInputs::deadline($op,$now):null;
        return $deadline!==null && $now>=$deadline;
    }
    public static function description(string $action): string
    {
        return match($action){
            'recheck'=>'Reads the original attempt through the reconciliation engine. It never sends the request again.',
            'resume_reads'=>'Resets the bounded reconciliation read budget. The original attempt and financial uncertainty stay retained.',
            'resume_observation'=>'Resumes this domain’s stopped scheduled reads within their original limits and pending window. It grants no purchase authority and does not reopen any request.',
            'hold'=>'Holds an unattempted request for staff review. It does not credit the account.',
            'release'=>'Queues only a proven unsent request. Normal engine admission still applies. Sent or unknown outcomes cannot resume.',
            'disposition'=>'Records review of existing terminal engine proof. It does not change the outcome, release a name or credit the account.',
            'refund'=>'Closes only a proven unsent paid request and credits its exact funded line. Invoices and payment history stay retained. No registrar refund is requested.',
            'credit'=>'Retries the engine account-credit path with current funding and unsent proof. A sent failure never qualifies.',
            'replacement'=>'Allows a separate renewal obligation only after an exact unsent account credit for this cycle. The original debt and evidence stay retained.',
        };
    }
}
