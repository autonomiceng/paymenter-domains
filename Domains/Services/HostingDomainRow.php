<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\{Service,User};
use Illuminate\Support\Facades\{Auth,View};
use Illuminate\Support\HtmlString;
/** Exact native service view adapter. No global view hook or native component mutation. */
final class HostingDomainRow
{
    public static function boot(): void
    {
        if(!HookRegistration::claim(View::getDispatcher(),self::class)){return;}
        View::composer('services.show',static function($view):void {
            $data=$view->getData();$service=$data['service']??null;$owner=Auth::user();
            if(!$service instanceof Service || !$owner instanceof User){return;}
            try{$summary=(new DomainSummaries())->services($owner,[(int)$service->id])[$service->id]??null;}catch(\Throwable $e){\Illuminate\Support\Facades\Log::warning('Hosting domain summary unavailable.',['exception_type'=>get_class($e)]);return;}
            if(!$summary || !in_array((int)$service->id,array_column($summary->relatedServices,'id'),true)){return;}
            $link=new HtmlString('<a href="'.e($summary->urls['detail']).'" style="overflow-wrap:anywhere;text-decoration:underline;outline-offset:3px">'.e($summary->name).'</a>');
            $view->with('fields',[...($data['fields']??[]),['label'=>'Domain','text'=>$link]]);
        });
    }
}
