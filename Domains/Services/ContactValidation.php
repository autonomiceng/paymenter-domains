<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;

/** Field paths only; never include submitted contact values in errors. */
final class ContactValidation extends \DomainException
{
    public function missingMessage(): string
    { return 'Missing: '.implode(', ',$this->labels()).'. Update the customer account details.'; }
    /** Plain field names, once each across contact roles. */
    public function labels(): array
    {
        $labels=['first_name'=>'first name','last_name'=>'last name','address1'=>'address','address2'=>'address line 2',
            'organization_name'=>'company name','city'=>'city','state_province'=>'state or province','postal_code'=>'postal code',
            'country'=>'country','email_address'=>'email','phone'=>'phone'];
        return array_values(array_unique(array_map(static fn($field)=>$labels[($dot=strrpos($field,'.'))===false?$field:substr($field,$dot+1)]??'contact details',$this->fields)));
    }
    /** Contact roles that have a missing field. */
    public function roles(): array
    { return array_values(array_unique(array_filter(array_map(static fn($field)=>preg_match('/^contacts\.([a-z]+)\./',$field,$m)?$m[1]:null,$this->fields)))); }
    public function __construct(public readonly array $fields) { parent::__construct('Check the contact details.'); }
}
