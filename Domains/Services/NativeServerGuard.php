<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Extension,Server,Setting};
use Illuminate\Support\Facades\{DB,Schema};
use Paymenter\Extensions\Servers\Domains\Models\Account;
final class NativeServerGuard
{
    private bool $booted=false;
    public function pause(int $id): void
    {
        if(!Schema::hasTable('domains_accounts')){return;}
        DB::transaction(function()use($id):void {
            Server::whereKey($id)->lockForUpdate()->first();$a=Account::where('server_id',$id)->lockForUpdate()->first();
            if($a){$a->processing_enabled=false;Account::withVerifiedBindingWrite(fn()=>$a->save());}
        });
    }
    /** Qualification seam only; production always retains interception until its removal slice. */
    public static function testingNativeEditors(): bool
    {return app()->runningUnitTests()&&config('domains.tests.native_editors')===true;}
    public function boot(): void
    {
        if($this->booted){return;}$this->booted=true;
        // Before native mount/hydration: stale route caches must never serialize encrypted settings.
        $guard=static function($component,array $params=[]):void {
            $class=get_class($component);$id=$params['record']??($component->record->id??null);
            $server=$class===\App\Admin\Resources\ServerResource\Pages\EditServer::class&&Server::whereKey($id)->where('extension','Domains')->exists();
            $service=$class===\App\Admin\Resources\ServiceResource\Pages\EditService::class&&Schema::hasTable('domains_domains')&&\Paymenter\Extensions\Servers\Domains\Models\Domain::where('service_id',$id)->exists();
            if($server||$service){abort(503,'Refresh the native route cache before editing managed domain records.');}
        };
        if(!self::testingNativeEditors()){app(\Livewire\EventBus::class)->before('mount',$guard);app(\Livewire\EventBus::class)->before('hydrate',$guard);}
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
                if(Schema::hasTable('domains_accounts')&&Account::where('server_id',$s->getOriginal('id'))->exists()){throw new \DomainException('Retained registrar accounts cannot be deleted. Disable processing instead.');}}});
        }
        Setting::saving(function(Setting $s):void {
            if($s->key!=='operations_enabled'||$s->settingable_type!==(new Server)->getMorphClass()||!filter_var($s->value,FILTER_VALIDATE_BOOLEAN)){return;}
            if(Server::whereKey($s->settingable_id)->where('extension','Enom')->exists()){
                $message='Legacy Enom processing cannot be enabled while the Domains runtime is installed.';
                if(!app()->runningInConsole()||request()->is('livewire/*')){throw \Illuminate\Validation\ValidationException::withMessages(['data.settings.operations_enabled'=>$message]);}throw new \DomainException($message);}
        });
    }
}
