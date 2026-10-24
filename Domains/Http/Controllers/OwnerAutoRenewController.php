<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{OwnedDomains,OwnerAutoRenew};
final readonly class OwnerAutoRenewController
{
    public function __construct(private OwnedDomains $owners,private OwnerAutoRenew $preference) {}
    public function save(Request $request,int $domain)
    {
        $this->owners->owned($request->user(),$domain);$context='owner:'.$domain.':auto-renew';
        FormKeys::verify($request,$context);FormKeys::consume($request,$context);
        $enabled=$request->request->all()['enabled']??null;
        $response=redirect()->route('domains.domains.show',$domain);
        if(!in_array($enabled,['0','1'],true)||($enabled==='0'&&$request->input('confirmed')!=='1')) {
            return $response->withErrors(['auto-renew'=>'Confirm your auto-renew choice and try again.']);
        }
        try{$this->preference->set($request->user(),$domain,$enabled==='1');}
        catch(\Throwable){return $response->withErrors(['auto-renew'=>'Auto-renew could not be changed. Reload this page and try again, or contact support.']);}
        return $response;
    }
}
