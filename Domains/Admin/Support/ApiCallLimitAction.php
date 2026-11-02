<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,Textarea,TextInput};
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\{Actions,Flex};
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\{ApiCallLimits,StaffReason,StaffSettingsReview as Review,StaffSettingsWrites};
/** The account page supplies the stamp it showed, including the shared login's limits. */
final class ApiCallLimitAction
{
    public static function row(TextEntry $entry,Account $account,string $stamp): Flex
    {
        if($account->environment==='live'){$entry->label('API calls, today (UTC)');}
        return Flex::make([$entry,Actions::make([self::make($account,$stamp)])->grow(false)])->from('md');
    }
    public static function make(Account $account,string $stamp): Action
    {
        $server=(int)$account->server_id;$limits=ApiCallLimits::forAccount($account);$test=$account->environment==='test';
        return Action::make('call_limits_'.$server)->label('Edit')->color('gray')
            ->visible(fn()=>Staff::can(Staff::POLICY)&&Staff::can(Review::permission('call_limits'))&&$account->registrar_key==='enom')
            ->modalHeading('Edit our call limits')->modalSubmitActionLabel('Save')
            ->modalDescription('These limits are shared by every connection using this login. Lowering a limit below current usage stops further calls until usage falls below it. Calls already sent keep their results.')
            ->schema([Hidden::make('review_key')->default(fn()=>Review::ticket((int)Auth::id(),$server,null,'call_limits',$stamp)),
                TextInput::make('day')->label('Daily call limit')->default($limits['day']??null)->required()->integer()->minValue(1)->maxValue(ApiCallLimits::MAX)
                    ->helperText('Our daily limit on registrar calls. It stops a runaway job. '.($test?'Counts the last 24 hours.':'Resets at midnight UTC.')),
                TextInput::make('minute')->label('Calls per minute')->default($limits['minute']??null)->visible($test)->required($test)->integer()->minValue(1)->maxValue(ApiCallLimits::MAX),
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(2)->rules(['regex:'.StaffReason::SINGLE_LINE])->helperText('Use one line. Do not include credentials.')])
            ->action(static function(array $data)use($server):void {
                try{
                    $r=new Review((int)Auth::id(),$server,null,'call_limits',(string)($data['review_key']??''),trim((string)($data['reason']??'')));
                    app(StaffSettingsWrites::class)->run($r,$data);
                    Notification::make()->success()->title('Call limits saved')->body('Further calls wait while usage is at or above a limit.')->send();
                }catch(\Throwable $e){Notification::make()->danger()->title("Couldn't save call limits")->body($e instanceof \DomainException?$e->getMessage():'Reload the account page, then try again.')->send();}
            });
    }
}
