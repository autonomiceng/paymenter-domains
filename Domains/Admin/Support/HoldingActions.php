<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Textarea};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks,NativeDomainGuard,RenewalStop,StaffHoldingReview};
/** Owned holding actions only; native service controls remain upstream. */
final class HoldingActions
{
    public static function make(Domain $d): array
    {
        if(!Staff::can('admin.domains.manage')){return [];}
        try{$l=HoldingLocks::take($d,advisory:true);$stamp=StaffHoldingReview::stamp($l);}catch(\Throwable){return [];}
        $stopped=RenewalStop::stopped($l);
        $out=[];
        foreach(StaffHoldingReview::ACTIONS as$name=>$label){
            if($name==='reprice'&&$d->price_mode!=='standard'){continue;}
            $description=self::description($name);
            if($name==='reprice'){
                try{$price=app(NativeDomainGuard::class)->quote($l);$description='Sets this domain’s renewal price to the current published price: '.$price['annual_gross'].' USD per year including tax. '.$description;}
                catch(\Throwable){$description='Current renewal price is unavailable. Reload this page and check the published price before repricing. '.$description;}
            }
            $out[]=Action::make($name)->label($label)->color($name==='stop'?'warning':'gray')
                ->visible(fn()=>Staff::can(StaffHoldingReview::permission($name))&&($name==='reprice'||($name==='stop'?!$stopped:$stopped)))
                ->requiresConfirmation()->modalHeading($label)->modalDescription($description)->modalSubmitActionLabel($label)->modalWidth('lg')
                ->schema([Hidden::make('review_key')->default(fn()=>StaffHoldingReview::ticket((int)Auth::id(),(int)$d->id,$name,$stamp,app(Clock::class))),
                    Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(3)->helperText('Use one line.')
                        ->rules(['regex:/\A[^\x00-\x1f\x7f]*\z/'])->validationMessages(['regex'=>'Use one line without control characters.'])])
                ->action(function(array $data,$livewire)use($d,$name):void {
                    try{
                        $actor=Auth::user();if(!$actor instanceof \App\Models\User){throw new \DomainException('Current staff permissions required.');}
                        app(StaffHoldingReview::class)->run($actor,(int)$d->id,$name,(string)($data['review_key']??''),trim((string)($data['reason']??'')));
                        Notification::make()->success()->title('Holding updated')->body('The reason and before/after values were recorded. Reload this page to review the current holding.')->send();
                    }catch(\Throwable $error){
                        $message=$error instanceof \DomainException?match($error->getMessage()){
                            'Current staff permissions required.','Current staff permissions required. Reload this page.'=>'Your role no longer allows this change. Ask an administrator, then reload this page.',
                            'Enter a one-line reason of 5–500 characters.'=>'Enter a one-line reason of 5–500 characters.',
                            'Invalid review key. Reload this page.','Holding changed. Reload this page and review it again.','Review key used.','Holding native authority changed.'=>'This domain changed after you opened this form. Nothing was changed. Reload the page, check the current values, and try again.',
                            'Holding audit full or unavailable.'=>'This domain’s holding history is full (maximum 100 changes) or unavailable. Nothing was changed. Contact the maintainer.',
                            'Review confirmed domain ownership and billing status before resuming or repricing this holding.'=>'Can’t resume or reprice: completed, paid registration, transfer or renewal proof is unavailable, the service is not active, or a cancellation needs review. Check Registrar requests and the service’s cancellations.',
                            default=>null}:null;
                        if($message===null){Log::warning('Domains holding change failed.',['domain_id'=>$d->id,'action'=>$name,'exception_class'=>$error::class]);
                            $message='Nothing was changed. Reload this page and review current permissions, billing status and Registrar requests. Existing invoices remain unchanged.';}
                        Notification::make()->danger()->title('Holding change refused')->body($message)->send();
                    }finally{$livewire->mountedActions=[];}
                });
        }
        return $out;
    }
    public static function description(string $action): string
    {
        return match($action){
            'reprice'=>'Each future renewal invoice still uses the published price on the day it is issued. The catalogue and existing invoices don’t change.',
            'stop'=>'Stops new renewal invoices for this domain. Invoices already issued stay payable. Renewals already issued, including complimentary ones, can still be sent to the registrar and charged to the registrar account. To prevent that, hold the unsent request on Registrar requests first. Privacy can still be bought and renewed. The registration and the registrar’s auto-renew setting don’t change: if registrar auto-renew is on, the registrar may still renew the domain and charge the account.',
            'resume'=>'Allows new renewal invoices again on this domain’s existing billing schedule. Also removes any end-of-period cancellation on the service, including one the customer requested. Issued invoices don’t change, and nothing is sent to the registrar.',
        };
    }
}
