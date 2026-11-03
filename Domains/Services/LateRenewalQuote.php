<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Frozen late-renewal price and precise cutoff, shared by billing, execution and retirement. */
final class LateRenewalQuote
{
    public static function price(array $l,array $quote,array $regular): array
    {
        $rate=$l['prices']->where('action','renew')->where('term',1)->where('currency','USD')->where('account_fingerprint',$l['domain']->account_fingerprint)->first();
        $cost = isset($l['offer']) ? $l['offer']['cost']?->total : ($rate?->wholesale === null ? null : PriceCatalogue::cost($rate->wholesale));
        if($cost===null){throw new \DomainException('Renewal cost Missing. Sync prices and try again.');}
        // Preserve the regular dollar margin, including fixed-price holders. Never turn a negative margin into an extra loss.
        $margin=PricingRule::units($regular['annual_net'])-PricingRule::units($cost);
        if($margin<0){throw new \DomainException('Renewal margin Missing. Fix renewal pricing and try again.');}
        $units=PricingRule::units($quote['cost']['amount'])+$margin;
        $net=PricingRule::decimal(intdiv($units+99,100));
        return [...$regular,'annual_net'=>$net,'annual_gross'=>NativeDomainGuard::gross($net,$l['tax']),'late'=>true,'margin'=>PricingRule::decimal($margin,4)];
    }
    public static function deadline(\Paymenter\Extensions\Servers\Domains\Models\Operation $op): ?\DateTimeImmutable
    {
        $late=$op->policy['renewal']['late']??null;
        if($late===null){return null;}
        try{$v=$late['deadline'];$at=is_string($v)?\DateTimeImmutable::createFromFormat(DATE_ATOM,$v):false;if(!$at||$at->format(DATE_ATOM)!==$v){throw new \DomainException;}return $at;}catch(\Throwable){throw new \DomainException('Late renewal deadline Missing. Contact support.');}
    }
}
