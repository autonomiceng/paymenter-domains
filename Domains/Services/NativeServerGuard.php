<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Extension,Server,Setting};
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\Account;
final class NativeServerGuard
{
    public function pause(int $id): void
    {
        if(!Schema::hasTable('domains_accounts')){return;}
        DB::transaction(function()use($id):void {
            Server::whereKey($id)->lockForUpdate()->first();$a=Account::where('server_id',$id)->lockForUpdate()->first();
            if($a){$a->processing_enabled=false;Account::withVerifiedBindingWrite(fn()=>$a->save());}
        });
    }
    /** A native action handles Halt; other writers retain the ordinary exception. */
    public static function notifyDeletionRefusal(\Illuminate\Database\Eloquent\Model $record): void
    {
        if(self::deletionNoticeMissing()===null&&($page=\Livewire\Livewire::current()) instanceof \App\Admin\Resources\ServerResource\Pages\EditServer
            &&$page->getRecord()->is($record)&&$page->getMountedAction()?->getName()==='delete'){
            logger()->warning('Managed Domains server deletion refused.');
            $message='Domains still needs this server, so it cannot be deleted.';
            if(Schema::hasTable('domains_accounts')&&Account::where('server_id',$record->getRawOriginal('id'))->where('processing_enabled',false)->exists()){$message.=' Processing is paused.';}
            $message.=' Review processing in Registrar setup.';
            \Filament\Notifications\Notification::make()->danger()->title('Server could not be deleted')->body($message)->send();
            throw new \Filament\Support\Exceptions\Halt;
        }
    }
    public static function deletionNoticeMissing(): ?string
    {
        foreach([\Livewire\LivewireManager::class=>'current',\App\Admin\Resources\ServerResource\Pages\EditServer::class=>'getMountedAction',
            \Filament\Notifications\Notification::class=>'make'] as $class=>$method){if(!class_exists($class)||!method_exists($class,$method)){return $class.'::'.$method;}}
        return class_exists(\Filament\Support\Exceptions\Halt::class)?null:\Filament\Support\Exceptions\Halt::class;
    }
    public function boot(): void
    {
        if(!HookRegistration::claim(Server::getEventDispatcher(),self::class)){return;}
        foreach([Server::class,Extension::class] as $model){
            $model::creating(function($s):void {if($s->extension==='Domains'){
                try{app(Installation::class)->install();}catch(\DomainException|\RuntimeException|\Illuminate\Contracts\Cache\LockTimeoutException $e){
                    throw \Illuminate\Validation\ValidationException::withMessages(['data.extension'=>$e->getMessage()]);
                }
            }});
            $model::updating(function($s):void {
                if($s->getOriginal('type')!=='server'){return;}
                if($s->getOriginal('extension')==='Domains'&&$s->isDirty(['enabled','extension'])){$this->pause((int)$s->getOriginal('id'));}
                if($s->getOriginal('extension')==='Domains'&&$s->isDirty(['id','type','extension'])&&Schema::hasTable('domains_accounts')&&Account::where('server_id',$s->getOriginal('id'))->exists()){
                    throw new \DomainException('A retained Domains account cannot change extension.');}
            });
            $model::deleting(function($s):void {if($s->getOriginal('type')==='server'&&$s->getOriginal('extension')==='Domains'){$this->pause((int)$s->getOriginal('id'));
                if(Schema::hasTable('domains_accounts')&&Account::where('server_id',$s->getOriginal('id'))->exists()){self::notifyDeletionRefusal($s);logger()->warning('Managed Domains server deletion refused.');throw new \DomainException('Retained registrar accounts cannot be deleted. Disable processing instead.');}}});
        }
        Setting::saving(function(Setting $s):void {
            if($s->key!=='operations_enabled'||$s->settingable_type!==(new Server)->getMorphClass()||!filter_var($s->value,FILTER_VALIDATE_BOOLEAN)){return;}
            if(Server::whereKey($s->settingable_id)->where('extension','Enom')->exists()){
                $message='Legacy Enom processing cannot be enabled while the Domains runtime is installed.';
                if(!app()->runningInConsole()||request()->is('livewire/*')){throw \Illuminate\Validation\ValidationException::withMessages(['data.settings.operations_enabled'=>$message]);}throw new \DomainException($message);}
        });
    }
}
