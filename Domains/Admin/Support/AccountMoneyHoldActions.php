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
    /** The Billing notice's id, so the request page's Payment row can link to it. */
    public const ANCHOR='notice-account-hold';
    /** The open hold as the Billing notice: what was lost, when the hold started, when to release it, with Release hold.
     * $release: the page's cached Release hold action for the first open hold, when this reader may release it. */
    public static function notice(Domain $d, ?callable $release=null): ?\Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Row
    {
        $alert=DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','account_money_hold')->whereNull('resolved_at')->orderBy('id')->first();
        if(!$alert){return null;}
        $op=\Paymenter\Extensions\Servers\Domains\Models\Operation::find($alert->operation_id);
        $lost=$op?AccountMoneyHolds::lost($op,(string)$alert->alert_key):null;
        $what=$lost!==null&&$op?->invoice_id?\Paymenter\Extensions\Servers\Domains\Services\Storefront::money(\Paymenter\Extensions\Servers\Domains\Services\PricingRule::decimal($lost)).' on '.Facts::invoiceName((int)$op->invoice_id).' was':'A payment was';
        $line='Account on hold: '.$what.' lost to a bank dispute. Release it once the customer has paid it back or you accept the loss.'
            .' Held since '.\Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at((int)$alert->first_seen).'. Domain payouts and new domain orders for this customer wait; renewals within paid terms continue.';
        $action=$release?$release('release_money_hold_'.$alert->id):null;
        return \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Row::notice('account-hold',\Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Value::state(
            \Paymenter\Extensions\Servers\Domains\Presentation\State::NeedsReview,\Paymenter\Extensions\Servers\Domains\Presentation\Reader::Staff,$line),$action)->target();
    }
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
