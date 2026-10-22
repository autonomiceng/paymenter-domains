<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http\Controllers;
use Illuminate\Http\Request;
use Paymenter\Extensions\Servers\Domains\Http\FormKeys;
use Paymenter\Extensions\Servers\Domains\Services\{DomainBilling, OwnedDomains, RuntimeIneligible, RuntimeReason};
/** Owner renewal request: creates or returns this cycle's renewal invoice; never sends a registrar request. */
final readonly class OwnerRenewalController
{
    public const UNAVAILABLE='We couldn’t create a renewal invoice right now. Try again later. If it keeps happening, contact support.';
    public function __construct(private OwnedDomains $owners, private DomainBilling $billing) {}
    public function renew(Request $request,int $domain)
    {
        $d=$this->owners->owned($request->user(),$domain); $context='owner:'.$domain.':renew';
        FormKeys::verify($request,$context); FormKeys::consume($request,$context);
        $years=$request->request->all()['years']??null;
        if (!is_string($years)||!preg_match('/^[1-9][0-9]?$/',$years)) { return $this->failed($domain,'Choose how many years to renew for.'); }
        try { $invoice=$this->billing->invoice((int)$d->id,$request->user(),(int)$years); }
        catch (\Throwable $e) { return $this->failed($domain,self::refusal($e)); }
        if (!$invoice) { return $this->failed($domain,self::UNAVAILABLE); }
        return redirect()->route('invoices.show',$invoice);
    }
    /** Owner copy for billing refusals; internal reasons and messages never reach the page. */
    public static function refusal(\Throwable $e): string
    {
        if ($e instanceof RuntimeIneligible) { return match($e->reason) {
            RuntimeReason::BillingOff=>'Online renewal is not available right now. Contact support to renew this domain.',
            RuntimeReason::OutsideCalendar=>'This domain is too close to its expiry date to renew online. Contact support to renew it.',
            default=>self::UNAVAILABLE}; }
        return $e instanceof \DomainException ? match($e->getMessage()) {
            'Renewal is off for this adopted registration.'=>'Renewal is turned off for this domain. Contact support if you want to renew it.',
            'Renewal term exceeds the registry horizon.'=>'Domains can be registered for at most 10 years ahead. Choose a shorter term.',
            'Existing renewal obligation requires review.'=>'This domain already has a renewal invoice for a different term. Pay that invoice, or contact support to change it.',
            default=>self::UNAVAILABLE} : self::UNAVAILABLE;
    }
    private function failed(int $domain,string $message)
    { return redirect()->route('domains.domains.show',$domain)->withErrors(['renew'=>$message]); }
}
