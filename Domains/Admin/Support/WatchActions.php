<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
use Filament\Actions\Action;
use Filament\Forms\Components\{Textarea,TextInput};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\RoutingProfileResource;
use Paymenter\Extensions\Servers\Domains\Models\WatchProfile;
use Paymenter\Extensions\Servers\Domains\Services\{SiteDnsDefault,StaffWatchWrites as Writes};
/** Staff actions bind the DNS template revision loaded by the page. */
final class WatchActions
{
    /** The DNS template form says this once, for every box. */
    public const ONE_PER_LINE='Enter one value per line.';
    public static function action(int $id,int $revision,string $action,string $label,array $fields=[],?int $defaultId=null): Action
    {
        $a=Action::make($action)->label($label)->visible(fn()=>Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));
        if($fields){$a->schema($fields);}
        return $a->action(function(array $data,array $arguments,$livewire)use($id,$revision,$action,$defaultId):void {
                $reason=match($action){'save'=>'DNS template saved','enable'=>'DNS template turned on','disable'=>'DNS template turned off','delete'=>'DNS template deleted'};
                try{(new Writes())->run(Auth::user(),$id,$action,Writes::ticket((int)Auth::id(),$id,$revision,$action,$defaultId),$reason,$data+$arguments);}
                catch(\DomainException|\InvalidArgumentException|\Illuminate\Database\QueryException|\Illuminate\Database\Eloquent\ModelNotFoundException $e){
                    Notification::make()->warning()->title(self::error($e))->send();
                    // A refused template save keeps the dialog open with what was typed.
                    if($action==='save'){throw new \Filament\Support\Exceptions\Halt();}
                    return;
                }
                $done=match($action){'save'=>$id?'DNS template saved':'DNS template added','enable'=>'DNS template turned on','disable'=>'DNS template turned off','delete'=>'DNS template deleted'};
                Notification::make()->success()->title($done)->send();
                if($action!=='delete'){
                    if($id){$livewire->refreshTemplate();}
                    return;
                }
                $livewire->redirect(RoutingProfileResource::getUrl());
            });
    }
    /** Write errors in page words. */
    private static function error(\Throwable $e): string
    {
        $changed='This template changed. Reload the page and try again.';
        if(!$e instanceof \DomainException && !$e instanceof \InvalidArgumentException){return $changed;}
        return match($e->getMessage()){
            'The saved details changed. Reload and try again.','Reload and try again.'=>$changed,
            'Enter one DNS target per line.'=>'Enter one value per line.',
            Writes::UNREADABLE=>Writes::UNREADABLE,
            default=>$e->getMessage()};
    }
    public static function enable(WatchProfile $row,int $revision): Action
    {
        return self::templateGuard(self::action((int)$row->id,$revision,'enable','Turn on')->view('domains::admin.template-switch')
            ->arguments(['on'=>false,'blocked'=>$row->readProfile()===null])->requiresConfirmation()->modalHeading('Turn on DNS template?')
            ->modalSubmitActionLabel('Turn on')
            ->visible(fn()=>!$row->enabled && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE)),$row->readProfile()===null?Writes::UNREADABLE:null);
    }
    /** Blocked switches keep the same dimming and reason as the shared blocked button. */
    private static function templateGuard(Action $action,?string $reason): Action
    {
        return $reason?$action->tooltip($reason)->livewireClickHandlerEnabled(false)
            ->extraAttributes(['aria-disabled'=>'true','aria-label'=>'DNS template. '.$reason,'class'=>'domains-blocked']):$action;
    }
    public static function disable(WatchProfile $row,int $revision): Action
    {
        $reason=\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::usesRefusal((int)$row->id)??($row->readProfile()===null?Writes::UNREADABLE:null);
        return self::templateGuard(self::action((int)$row->id,$revision,'disable','Turn off')->view('domains::admin.template-switch')
            ->arguments(['on'=>true,'blocked'=>$reason!==null])->requiresConfirmation()->modalHeading('Turn off DNS template?')
            ->modalSubmitActionLabel('Turn off')->visible(fn()=>$row->enabled && Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE)),$reason);
    }
    public static function deleteTemplate(WatchProfile $row,int $revision): Action
    {
        $reason=\Paymenter\Extensions\Servers\Domains\Services\SiteDnsDefault::usesRefusal((int)$row->id);
        if($reason){return Sheet::blocked('delete','Delete','DNS template',$reason)->color('danger')
            ->action(fn()=>Notification::make()->warning()->title($reason)->send())
            ->visible(fn()=>Staff::can(Writes::VIEW)&&Staff::can(Writes::MANAGE));}
        return self::action((int)$row->id,$revision,'delete','Delete')->color('danger')->outlined()
            ->requiresConfirmation()->modalHeading('Delete DNS template?')->modalDescription('The saved nameservers and records will be deleted. This cannot be undone.')
            ->modalSubmitActionLabel('Delete');
    }
    public static function addTemplate(): Action
    {
        return self::action( 0, 0, 'save', 'Add DNS template', self::templateFields())
            ->modalDescription(self::ONE_PER_LINE)->modalWidth(Width::Medium)->modalSubmitActionLabel('Save');
    }

    public static function canEditTemplate(WatchProfile $row): bool
    {
        return Staff::can(Writes::VIEW) && Staff::can(Writes::MANAGE)
            && (!SiteDnsDefault::uses((int) $row->id) || Staff::canEditDefaultDns());
    }

    public static function templateFields(?WatchProfile $row = null): array
    {
        $fields = [
            TextInput::make('name')->label('Name')->default($row?->name)->required()->maxLength(128)
                ->rule(FieldRules::check(fn ($name) => Writes::requireUniqueName((string) $name, $row?->id))),
            Textarea::make('nameservers')->label('Nameservers')->default(implode("\n", $row?->nameservers ?? []))
                ->required()->maxLength(8192)->rows(max(4, count($row?->nameservers ?? [])))->rule(FieldRules::hostname(13))
                ->helperText('Enter one nameserver per line.'),
        ];
        return $fields;
    }
}
