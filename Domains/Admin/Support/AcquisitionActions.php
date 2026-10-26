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
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,HoldingLocks,StaffHoldingReview};
final class AcquisitionActions
{
    public const DESCRIPTION='Closes the unpaid order and releases the domain name.';
    /** Service refusals => the dialog's failure line. Unknown refusals get the generic line. */
    private const FAILURES=[
        'Current staff permissions required. Reload this page.'=>"You don't have permission to cancel orders.",
        'A review reason is required.'=>'Enter a reason.',
        'Invalid review key. Reload this page.'=>'Reload the page and try again.',
        'Order changed. Reload this page and review it again.'=>'This order changed. Reload the page and try again.',
        'Holding native authority changed.'=>'This order changed. Reload the page and try again.',
        'Holding product markers changed.'=>'This order changed. Reload the page and try again.',
        'Frozen checkout identity changed.'=>'This order changed. Reload the page and try again.',
        'Frozen domain invoice binding changed.'=>'This order changed. Reload the page and try again.',
        'The service is cancelled but its invoice is still open. Review the order and invoice; the name stays reserved.'=>"Can't cancel: the invoice is still open. Cancel the invoice first.",
        'This unpaid invoice also contains other items. Cancel it through the native billing workflow before retiring this order.'=>"Can't cancel: the invoice has other items. Cancel it in Paymenter.",
        'Only an unpaid order with no other requests or registrar evidence can be retired.'=>"Can't cancel: the order is no longer pending, or it has other requests or registrar details.",
        'This order has a payment or payment attempt, so it cannot be retired. The name stays reserved.'=>"Can't cancel: this order has a payment.",
        'A registrar request may have started. Review its outcome; the name stays reserved.'=>"Can't cancel: a registrar request may have started.",
        'Unpaid terminal proof is unavailable.'=>"Can't cancel: couldn't confirm the order is unpaid.",
    ];
    public static function make(Domain $d): array
    {
        if(!Staff::can('admin.domains.manage')||!Staff::can(AcquisitionRetirement::PERMISSION)
            ||!in_array($d->status,['registration_pending','transfer_pending'],true)){return [];}
        try{$stamp=StaffHoldingReview::stamp(HoldingLocks::take($d,advisory:true));}catch(\Throwable){return [];}
        return [Action::make('retire_unpaid')->label('Cancel order')->color('danger')->requiresConfirmation()
            ->modalHeading('Cancel order?')->modalDescription(self::DESCRIPTION)->modalSubmitActionLabel('Cancel order')->modalWidth('lg')
            ->schema([Hidden::make('review_key')->default(fn()=>StaffHoldingReview::ticket((int)Auth::id(),(int)$d->id,'retire_unpaid',$stamp,app(Clock::class))),
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(3)
                    ->rules(['regex:'.StaffReason::SINGLE_LINE])->validationMessages(['regex'=>'Use one line.'])])
            ->action(function(array $data,$livewire)use($d):void {
                try{
                    $actor=Auth::user();if(!$actor instanceof \App\Models\User){throw new \DomainException('Current staff permissions required. Reload this page.');}
                    app(AcquisitionRetirement::class)->retire((int)$d->id,$actor,(string)($data['review_key']??''),trim((string)($data['reason']??'')));
                    Notification::make()->success()->title('Order cancelled')->send();
                    $livewire->redirect(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('view',['record'=>$d->id]));
                }catch(\Throwable $e){
                    $message=$e instanceof \DomainException?(self::FAILURES[$e->getMessage()]??null):null;
                    Log::warning('Domains unpaid retirement refused.',['domain_id'=>$d->id,'exception_class'=>$e::class]);
                    Notification::make()->danger()->title("Couldn't cancel order")->body($message??'Reload the page and try again.')->send();
                }finally{$livewire->mountedActions=[];}
            })];
    }
}
