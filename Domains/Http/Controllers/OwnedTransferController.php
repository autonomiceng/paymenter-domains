<?php
// Enom 444f31c DomainController::transfer; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Services\Storefront;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
/** Authenticated entry only. Checks and native cart admission retain the storefront's independent proof. */
final readonly class OwnedTransferController
{
    public function __construct(private Storefront $storefront) {}
    public function show(Request $request)
    {
        $endings=array_values(array_filter($this->storefront->endings(),fn($row)=>$row['transfer']!==null));
        $names=array_column($endings,'tld'); $raw=$request->query('name');
        $raw=is_string($raw)?substr($raw,0,300):''; $parsed=$raw===''?['sld'=>null,'ending'=>null,'error'=>null]:StorefrontController::parseName($raw);
        $chosen=$request->query('tld'); $preferred=in_array($chosen,$names,true)?$chosen:($names[0]??null);
        $error=$parsed['error'];
        if ($parsed['ending']!==null) {
            if (in_array($parsed['ending'],$names,true)) { $preferred=$parsed['ending']; }
            else { $error='Choose an ending from the list.'; }
        }
        $sld=$parsed['sld']; $results=$sld!==null&&$error===null&&$preferred!==null
            ? [['domain'=>$sld.'.'.$preferred,'sld'=>$sld,'tld'=>$preferred,'status'=>'idle','testEnvironment'=>collect($endings)->firstWhere('tld',$preferred)['testEnvironment']]] : [];
        return view('domains::transfer',compact('endings','preferred','sld','error','results')+['name'=>$raw,
            'testEnvironment'=>$this->storefront->testEnvironment($endings),'checkKey'=>FormKeys::issue($request,'search')]);
    }
}
