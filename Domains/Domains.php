<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains;
use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Models\Service;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Services\{CartAdmission,Installation,Runtime};
#[ExtensionMeta(name: 'Domains', description: 'Sell and manage domains through supported registrars.', version: '0.1.0', author: 'Autonomic Engineering')]
class Domains extends Server
{
    public function boot(): void { Runtime::register(); app(Runtime::class)->boot(); }
    public function installed(): void { Runtime::register(); app(Installation::class)->install(); }
    public function upgraded($oldVersion = null): void { $this->installed(); }
    public function uninstalled(): void {} // Retain all ownership, billing and operation evidence.
    public function getConfig($values = []): array
    {
        Runtime::register();app(Services\NativeServerGuard::class)->boot();
        return [['name'=>'domains_setup_note','type'=>'placeholder','label'=>'Configure credentials, acceptance and processing in Domains → Registrar setup. Treat staff with server-edit permission as able to access saved credentials.']];
    }
    public function getProductConfig($values = []): array { return []; }
    public function getCheckoutConfig($product, $values = [], $settings = []): array
    { Runtime::register(); return app(CartAdmission::class)->fields($product); }
    public function enabled($record): void { $this->installed(); }
    public function disabled($record): void { Runtime::register(); app(Services\NativeServerGuard::class)->pause((int)$record->id); }
    public function updated($record): void {} // The model guard owns relevant lifecycle pauses.
    public function createServer(Service $service, $settings = [], $properties = []): array
    {
        try{app(Services\RuntimeWork::class)->service((int)$service->id);}
        catch(\Throwable $e){logger()->warning('Domains native wake deferred to scheduler.',['service_id'=>(int)$service->id,'exception_class'=>$e::class]);}
        return ['status'=>'Processing. View progress on the Domains page.'];
    }
    public function unsuspendServer(Service $service, $settings = [], $properties = []): array { return $this->createServer($service,$settings,$properties); }
    public function suspendServer(Service $service, $settings = [], $properties = []): array { return []; }
    public function terminateServer(Service $service, $settings = [], $properties = []): array { return []; }
    public function getActions(Service $service, $settings = [], $properties = []): array
    {
        $dto=Auth::user()?app(Services\NativePresenter::class)->service(Auth::user(),(int)$service->id):null;
        return $dto ? [['type'=>'button','label'=>'Manage domain','url'=>$dto->actions['show']],['type'=>'text','label'=>'Domain status','text'=>$dto->status]] : [];
    }
}
