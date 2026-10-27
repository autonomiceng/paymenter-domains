<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Field paths only; never include submitted contact values in errors. */
final class ContactValidation extends \DomainException
{
    public function missingMessage(): string
    {
        $labels=['first_name'=>'first name','last_name'=>'last name','address1'=>'address','address2'=>'address line 2',
            'organization_name'=>'company name','city'=>'city','state_province'=>'state or province','postal_code'=>'postal code',
            'country'=>'country','email_address'=>'email','phone'=>'phone'];
        $fields=array_unique(array_map(static fn($field)=>$labels[($dot=strrpos($field,'.'))===false?$field:substr($field,$dot+1)]??'contact details',$this->fields));
        return 'Missing: '.implode(', ',$fields).'. Update the customer account details.';
    }
    public function __construct(public readonly array $fields) { parent::__construct('Check the contact details.'); }
}
