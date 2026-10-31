<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Textarea};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{HoldingLocks,RenewalStop,StaffHoldingReview};
/** Owned holding actions only; native service controls remain upstream. */
final class HoldingActions
{
    public const LABELS=['stop'=>'Stop renewal','resume'=>'Resume renewal'];
    private const DONE=['stop'=>'Renewal stopped','resume'=>'Renewal resumed'];
    private const CHANGED='This domain changed. Reload the page and try again.';
    /** Service refusals => the dialog's failure line. Unknown refusals get the generic line. */
    private const FAILURES=[
        'Current staff permissions required.'=>"You don't have permission to change renewals.",
        'Current staff permissions required. Reload this page.'=>"You don't have permission to change renewals.",
        'Enter a one-line reason of 5–500 characters.'=>'Enter a reason (5 to 500 characters).',
        'Enter a one-line reason between 5 and 500 characters.'=>'Enter a reason (5 to 500 characters).',
        'Invalid review key. Reload this page.'=>self::CHANGED,'Holding changed. Reload this page and review it again.'=>self::CHANGED,
        'Review key used.'=>self::CHANGED,'Holding native authority changed.'=>self::CHANGED,
        'Holding audit full or unavailable.'=>'History for this domain is full. Ask your site admin.',
        'Review confirmed domain ownership and billing status before resuming this holding.'=>"The service isn't active, the registration isn't confirmed yet, or the service has an immediate cancellation. Check the service, then try again.",
    ];
    public static function make(Domain $d): array
    {
        if(!Staff::can('admin.domains.manage')){return [];}
        try{$l=HoldingLocks::take($d,advisory:true);$stamp=StaffHoldingReview::stamp($l);}catch(\Throwable){return [];}
        $stopped=RenewalStop::blocked($l);
        $out=[];
        foreach(StaffHoldingReview::ACTIONS as$name=>$unused){
            $label=self::LABELS[$name];
            $out[]=Action::make($name)->label($label)->color($name==='stop'?'danger':'gray')
                ->visible(fn()=>Staff::can(StaffHoldingReview::permission($name))&&($name==='stop'?!$stopped:$stopped))
                ->requiresConfirmation()->modalHeading($name==='stop'?'Stop renewal?':$label)->modalDescription(self::description($name))->modalSubmitActionLabel($label)->modalWidth('lg')
                ->schema([Hidden::make('review_key')->default(fn()=>StaffHoldingReview::ticket((int)Auth::id(),(int)$d->id,$name,$stamp,app(Clock::class))),
                    Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(3)
                        ->rules(['regex:'.StaffReason::SINGLE_LINE])->validationMessages(['regex'=>'Use one line.'])])
                ->action(function(array $data,$livewire)use($d,$name):void {
                    try{
                        $actor=Auth::user();if(!$actor instanceof \App\Models\User){throw new \DomainException('Current staff permissions required.');}
                        app(StaffHoldingReview::class)->run($actor,(int)$d->id,$name,(string)($data['review_key']??''),trim((string)($data['reason']??'')));
                        Notification::make()->success()->title(self::DONE[$name])->send();
                        $livewire->redirect(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('view',['record'=>$d->id]));
                    }catch(\Throwable $error){
                        $message=$error instanceof \DomainException?(self::FAILURES[$error->getMessage()]??null):null;
                        if($message===null){Log::warning('Domains holding change failed.',['domain_id'=>$d->id,'action'=>$name,'exception_class'=>$error::class]);
                            $message='Reload the page and try again.';}
                        Notification::make()->danger()->title("Couldn't update renewal")->body($message)->send();
                    }finally{$livewire->mountedActions=[];}
                });
        }
        return $out;
    }
    public static function description(string $action): string
    {
        return match($action){
            'stop'=>"Stops new renewal invoices. Invoices already sent stay payable, and renewal requests already created may still run. To stop all renewals, also turn off Registrar auto-renew.",
            'resume'=>'Restarts renewal invoices and removes any pending service cancellation.',
        };
    }
}
