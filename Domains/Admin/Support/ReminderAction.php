<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Checkbox,Hidden,TextInput,Textarea};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,DB};
use Paymenter\Extensions\Servers\Domains\Services\ReminderSchedules;
/** Optional reminders never toggle mandatory paid-order or recorded-credit notices. */
final class ReminderAction
{
    public static function make(int $server): Action
    {
        $row=static fn()=>DB::table('domains_reminder_schedules')->where('server_id',$server)->first();
        return Action::make('reminders_'.$server)->label('Edit customer reminders')->color('gray')
            ->visible(fn()=>Staff::can(Staff::POLICY))->disabled(fn()=>!$row())
            ->requiresConfirmation()->modalHeading('Edit customer reminders')
            ->modalDescription('Optional renewal and expiry reminders use UTC days. Paid transfer-code and recorded-credit notices remain independent. Native notification preferences and channel switches still apply.')
            ->schema([Hidden::make('revision')->default(fn()=>$row()?->revision),
                Checkbox::make('enabled')->label('Enable renewal and expiry reminders')->default(fn()=>(bool)$row()?->enabled),
                TextInput::make('before')->label('Days before expiry')->default(fn()=>implode(',',json_decode($row()?->before_days??'[]',true)))->required()->maxLength(80),
                TextInput::make('after')->label('Days after expiry')->default(fn()=>implode(',',json_decode($row()?->after_days??'[]',true)))->required()->maxLength(80),
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(2)->helperText('Use one line. Do not include credentials.')])
            ->action(function(array $data):void {
                try{ReminderSchedules::save(Auth::user(),$server,(int)$data['revision'],(bool)$data['enabled'],(string)$data['before'],(string)$data['after'],trim((string)$data['reason']));Notification::make()->success()->title('Reminder schedule saved')->body('Reload before making another change.')->send();}
                catch(\Throwable){Notification::make()->danger()->title('Reminder change refused')->body('Reload and check your permissions, schedule revision and day offsets.')->send();}
            });
    }
}
