<?php
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Safe field identifiers only; never carry submitted values or provider errors. */
final class OwnerFormValidation extends \DomainException
{
    public function __construct(public readonly array $fields) { parent::__construct('Invalid domain form.'); }
    public function messages(string $domain): array
    {
        $out=[];
        foreach ($this->fields as $field) {
            $out[$field]=match($field) {
                'nameservers'=>'Check the nameservers.',
                'ds.key_tag'=>'Enter a number from 0 to 65535.',
                'ds.algorithm','ds.digest_type'=>'Choose an option from the list.',
                'ds.digest'=>'Enter the digest your DNS provider gives you: 64 characters for SHA-256 or 40 for SHA-1.',
                'glue.host'=>'Enter a hostname under '.$domain.', such as ns1.'.$domain.'.',
                'glue.ip'=>'Enter a public IPv4 or IPv6 address.',
                default=>preg_match('/^(dns|srv)\.records\.count$/D',$field)?'Keep between 1 and 50 records.':
                    (preg_match('/^(dns|srv)\.records(?:\.preimage)?$/D',$field)?'We couldn’t save this change. Reload the page and try again.':
                    (preg_match('/^(dns|srv)\.records\.(\d+)$/D',$field,$m)?'Check record '.((int)$m[2]+1).'. These values don’t work together.':'Check this field.')),
            };
        }
        return $out;
    }
}
