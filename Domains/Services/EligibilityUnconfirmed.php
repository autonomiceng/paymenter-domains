<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Safe read refusal. Never retains provider text, request parameters or customer data. */
final class EligibilityUnconfirmed extends \DomainException
{
    public function __construct(public readonly ?string $registrarCode=null)
    {
        if($registrarCode!==null&&!preg_match('/^[0-9]{3,9}$/D',$registrarCode)){throw new \InvalidArgumentException('Invalid registrar diagnostic.');}
        parent::__construct('Domain eligibility could not be confirmed.');
    }
}
