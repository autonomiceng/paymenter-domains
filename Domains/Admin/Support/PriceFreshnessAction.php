<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Textarea,TextInput};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Data\CatalogueFreshness;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{StaffReason,StaffSettingsReview as Review,StaffSettingsWrites};

/** Uses the neighbouring call-limit row's permission, review ticket, audit and Filament form. */
final class PriceFreshnessAction
{
    public static function make(Account $account,string $stamp): Action
    {
        $server=(int)$account->server_id;
        return Action::make('price_freshness_'.$server)->label('Edit')->color('gray')
            ->visible(fn()=>Staff::can(Staff::POLICY)&&Staff::can(Review::permission('price_freshness')))
            ->modalHeading('Prices stay current for')->modalSubmitActionLabel('Save')
            ->schema([Hidden::make('review_key')->default(fn()=>Review::ticket((int)Auth::id(),$server,null,'price_freshness',$stamp)),
                TextInput::make('hours')->label('Hours')->default(CatalogueFreshness::forAccount($server)->hours)->required()->integer()->minValue(24)->maxValue(168)
                    ->dehydrateStateUsing(static fn($state):int=>(int)$state),
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(2)->rules(['regex:'.StaffReason::SINGLE_LINE])->helperText('Use one line. Do not include credentials.')])
            ->action(static function(array $data)use($server):void {
                try{
                    $r=new Review((int)Auth::id(),$server,null,'price_freshness',(string)($data['review_key']??''),trim((string)($data['reason']??'')));
                    app(StaffSettingsWrites::class)->run($r,$data);
                    Notification::make()->success()->title('Price freshness saved')->send();
                }catch(\Throwable $e){Notification::make()->danger()->title("Couldn't save price freshness")->body($e instanceof \DomainException?$e->getMessage():'Reload the account page, then try again.')->send();}
            });
    }
}
