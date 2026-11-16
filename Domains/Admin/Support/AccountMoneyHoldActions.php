<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\{AccountMoneyHolds,StaffOperationReview};
final class AccountMoneyHoldActions
{
    public static function actions(Domain $d): array
    {
        $staff=Auth::user();
        try{StaffOperationReview::requirePermission($staff,'release',null);}catch(\DomainException){return [];}
        return DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','account_money_hold')->whereNull('resolved_at')->orderBy('id')->get()
            ->map(fn($alert)=>Action::make('release_money_hold_'.$alert->id)->label('Release hold')->requiresConfirmation()->modalHeading('Release hold')
                ->modalSubmitActionLabel('Release hold')->modalDescription('Releases the account hold. The dispute and any money still owed stay on the invoice.')
                ->action(function()use($alert):void {
                    try{AccountMoneyHolds::release(Auth::user(),(int)$alert->id);Notification::make()->success()->title('Hold released')->send();}
                    catch(\Throwable){Notification::make()->danger()->title("Couldn't release hold")->body('Reload the page and try again.')->send();}
                }))->all();
    }
}
