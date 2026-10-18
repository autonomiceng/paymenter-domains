<?php
// Enom444f31c operation action layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Textarea};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{StaffOperationReview,StaffOperations};
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
                    }catch(\Throwable){Notification::make()->danger()->title('Review refused')->body('Reload this page and review current permissions, funding and retained evidence.'
                            .(in_array($name,['refund','credit'],true)?' A credit decision may be retained; check the ledger before retrying.':''))->send();}
                });
        }
        return $out;
    }
    public static function relevant(string $action,Operation $op): bool
    {
        if(PaidUncertainty::summary($op)!==null&&!in_array($action,['recheck','resume_reads','resume_observation'],true)){return false;}
        return match($action){
            'hold'=>in_array($op->state,['queued','awaiting_payment','awaiting_code'],true),
            'release','refund'=>$op->state==='held',
            'credit'=>$op->state==='rejected'&&$op->funding_basis==='paid_invoice',
            'replacement'=>$op->action==='renew'&&$op->state==='rejected',
            'disposition'=>in_array($op->state,['succeeded','rejected'],true),
            'resume_reads'=>in_array($op->action,['register','transfer','renew'],true)&&in_array($op->state,['running','pending','uncertain','held'],true),
            'recheck'=>in_array($op->state,['running','pending','uncertain','held'],true),
            'resume_observation'=>true,default=>false};
    }
    public static function description(string $action): string
    {
        return match($action){
            'recheck'=>'Reads the original attempt through the reconciliation engine. It never sends the request again.',
            'resume_reads'=>'Resets the bounded reconciliation read budget. The original attempt and financial uncertainty stay retained.',
            'resume_observation'=>'Resumes existing scheduled reads within their original limits and pending window. It grants no purchase authority.',
            'hold'=>'Holds an unattempted request for staff review. It does not credit the account.',
            'release'=>'Queues only a proven unsent request. Normal engine admission still applies. Sent or unknown outcomes cannot resume.',
            'disposition'=>'Records review of existing terminal engine proof. It does not change the outcome, release a name or credit the account.',
            'refund'=>'Closes only a proven unsent paid request and credits its exact funded line. Invoices and payment history stay retained. No registrar refund is requested.',
            'credit'=>'Retries the engine account-credit path with current funding and unsent proof. A sent failure never qualifies.',
            'replacement'=>'Allows a separate renewal obligation only after an exact unsent account credit for this cycle. The original debt and evidence stay retained.',
        };
    }
}
