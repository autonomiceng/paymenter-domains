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
use Paymenter\Extensions\Servers\Domains\Services\{AcquisitionRetirement,HoldingLocks,StaffHoldingReview};
final class AcquisitionActions
{
    public const DESCRIPTION='Closes an unpaid registration or transfer order and releases the name for a new order. No registrar request is sent. Any payment attempt (including failed or refunded), other request or uncertain registrar outcome prevents retirement. A pending invoice must contain only this order; cancel shared invoices through the native billing workflow first. The reason and before/after values are recorded.';
    public static function make(Domain $d): array
    {
        if(!Staff::can('admin.domains.manage')||!Staff::can(AcquisitionRetirement::PERMISSION)
            ||!in_array($d->status,['registration_pending','transfer_pending'],true)){return [];}
        try{$stamp=StaffHoldingReview::stamp(HoldingLocks::take($d,advisory:true));}catch(\Throwable){return [];}
        return [Action::make('retire_unpaid')->label('Retire unpaid order')->color('warning')->requiresConfirmation()
            ->modalHeading('Retire unpaid order')->modalDescription(self::DESCRIPTION)->modalSubmitActionLabel('Retire unpaid order')->modalWidth('lg')
            ->schema([Hidden::make('review_key')->default(fn()=>StaffHoldingReview::ticket((int)Auth::id(),(int)$d->id,'retire_unpaid',$stamp,app(Clock::class))),
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(3)->helperText('Use one line.')
                    ->rules(['regex:/\A[^\x00-\x1f\x7f]*\z/'])])
            ->action(function(array $data,$livewire)use($d):void {
                try{
                    $actor=Auth::user();if(!$actor instanceof \App\Models\User){throw new \DomainException('Current staff permissions required. Reload this page.');}
                    app(AcquisitionRetirement::class)->retire((int)$d->id,$actor,(string)($data['review_key']??''),trim((string)($data['reason']??'')));
                    Notification::make()->success()->title('Unpaid order retired')->body('The order is closed and its name reservation is released. No registrar request was sent.')->send();
                    $livewire->redirect(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('view',['record'=>$d->id]));
                }catch(\Throwable $e){
                    $message=$e instanceof \DomainException?match($e->getMessage()){
                        'Holding native authority changed.','Holding product markers changed.','Frozen checkout identity changed.','Frozen domain invoice binding changed.'=>'Order details changed. Nothing was changed. Reload the page and review the order again.',
                        default=>$e->getMessage(),
                    }:'Nothing was changed. Reload the page and review the order, invoice and registrar requests.';
                    Log::warning('Domains unpaid retirement refused.',['domain_id'=>$d->id,'exception_class'=>$e::class]);
                    Notification::make()->danger()->title('Order retirement refused')->body($message)->send();
                }finally{$livewire->mountedActions=[];}
            })];
    }
}
