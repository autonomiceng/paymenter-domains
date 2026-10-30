<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,TextInput};
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\{Grid,Section};
use Filament\Schemas\Components\Utilities\Get;
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Services\NameSiloAccountReset;
final class NameSiloAccountAction
{
    public static function make(int $id): Action
    {
        $details=static function(Get $get)use($id):array {
            try{return app(NameSiloAccountReset::class)->details($id,(string)$get('review'));}catch(\DomainException){return [];}
        };
        return Action::make('accept_namesilo_account')->label(NameSiloAccountReset::LABEL)->color('gray')
            ->visible(fn()=>Staff::can('admin.domains.manage')&&Account::where('server_id',$id)->where('registrar_key','namesilo')->exists())
            ->modalWidth('3xl')->modalHeading(NameSiloAccountReset::LABEL)->modalSubmitActionLabel('Accept account')
            ->modalDescription('Held requests stay held. Review and release each request separately.')
            ->fillForm(static function()use($id):array {
                try{return ['review'=>app(NameSiloAccountReset::class)->preview($id),'error'=>null];}
                catch(\DomainException $e){return ['review'=>null,'error'=>$e->getMessage()];}
            })
            ->schema([
                Hidden::make('review')->required(),Hidden::make('error'),
                TextEntry::make('read_error')->hiddenLabel()->color('danger')->state(fn(Get $get)=>$get('error'))->visible(fn(Get $get)=>(bool)$get('error')),
                Grid::make(['default'=>1,'md'=>2])->visible(fn(Get $get)=>$details($get)!==[])->schema([
                    Section::make('Saved account')->schema([
                        TextEntry::make('pinned_id')->label('Contact ID')->state(fn(Get $get)=>$details($get)['pinned']['contact_id']??null),
                        TextEntry::make('pinned_email')->label('Email')->state(fn(Get $get)=>$details($get)['pinned']['email']??null),
                    ]),
                    Section::make('Current account')->schema([
                        TextEntry::make('live_id')->label('Contact ID')->state(fn(Get $get)=>$details($get)['live']['contact_id']??null),
                        TextEntry::make('live_email')->label('Email')->state(fn(Get $get)=>$details($get)['live']['email']??null),
                    ]),
                ]),
                TextEntry::make('expected')->label('Expected default contact email')->state(fn(Get $get)=>$details($get)['expected']??null)->visible(fn(Get $get)=>$details($get)!==[]),
                TextEntry::make('email_mismatch')->hiddenLabel()->state(function(Get $get)use($details):?string {$r=$details($get);return $r===[]?null:'The NameSilo contact email is '.$r['live']['email'].'. We expected '.$r['expected'].'. Fix it at NameSilo or in Servers.';})
                    ->color('danger')->visible(function(Get $get)use($details):bool {$r=$details($get);return $r!==[]&&$r['live']['email']!==$r['expected'];}),
                TextInput::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->regex(\Paymenter\Extensions\Servers\Domains\Services\StaffReason::SINGLE_LINE)->helperText('Why are you accepting this account change?'),
            ])
            ->action(static function(array $data,Action $action)use($id):void {
                try{app(NameSiloAccountReset::class)->accept($id,(string)$data['review'],(string)$data['reason']);}
                catch(\DomainException $e){Notification::make()->title('Account not accepted')->body($e->getMessage())->danger()->send();$action->halt();return;}
                Notification::make()->title('NameSilo account accepted')->body('Held requests still need review and release.')->success()->send();
            });
    }
}
