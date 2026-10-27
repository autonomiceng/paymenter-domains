<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
use Filament\Actions\Action;
use Filament\Forms\Components\{Checkbox,Hidden,TextInput,Textarea};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,DB,Schema};
use Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules;
/** Optional reminders never toggle mandatory paid-order or recorded-credit notices. */
final class ReminderAction
{
    public static function make(int $server): Action
    {
        $row=Schema::hasTable('domains_reminder_schedules')?DB::table('domains_reminder_schedules')->where('server_id',$server)->first():null;
        $before=ReminderSchedules::storedDays($row?->before_days);$after=ReminderSchedules::storedDays($row?->after_days);
        return Action::make('reminders_'.$server)->label('Edit reminders')->color('gray')
            ->visible(fn()=>Staff::can(Staff::POLICY)&&$row!==null)
            ->requiresConfirmation()->modalHeading('Edit reminders?')->modalSubmitActionLabel('Save')
            ->modalDescription('Optional renewal and expiry reminders use UTC days. Paid transfer-code and recorded-credit notices remain independent. Native notification preferences and channel switches still apply.')
            ->schema([Hidden::make('revision')->default($before!==null&&$after!==null?$row?->revision:null),
                Checkbox::make('enabled')->label('Enable renewal and expiry reminders')->default((bool)$row?->enabled),
                TextInput::make('before')->label('Days before expiry')->default($before===null?null:implode(',',$before))->placeholder($before===null?'Unavailable':null)->required()->maxLength(80),
                TextInput::make('after')->label('Days after expiry')->default($after===null?null:implode(',',$after))->placeholder($after===null?'Unavailable':null)->required()->maxLength(80),
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(2)->rules(['regex:'.StaffReason::SINGLE_LINE])->helperText('Use one line. Do not include credentials.')])
            ->action(function(array $data)use($server):void {
                try{if(!isset($data['revision'])){throw new \DomainException('Reviewed reminder revision required.');}ReminderSchedules::save(Auth::user(),$server,(int)$data['revision'],(bool)$data['enabled'],(string)$data['before'],(string)$data['after'],trim((string)$data['reason']));Notification::make()->success()->title('Reminders saved')->send();}
                catch(\Throwable){Notification::make()->danger()->title("Couldn't save reminders")->body('Reload the account page, then check the reminder days and your permissions before saving again.')->send();}
            });
    }
}
