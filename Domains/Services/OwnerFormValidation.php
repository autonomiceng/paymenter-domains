<?php
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Safe field identifiers and reason codes only; never carry submitted values or provider errors. */
final class OwnerFormValidation extends \DomainException
{
    public function __construct(public readonly array $fields, private readonly array $nameservers=[]) { parent::__construct('Invalid domain form.'); }
    public function messages(string $domain, #[\SensitiveParameter] array $input=[], string $registrar='enom'): array
    {
        $out=[];
        foreach ($this->fields as $field) {
            $out[$field]=match($field) {
                'nameservers'=>OwnerNameservers::message($this->nameservers,$input['nameservers']??null,$domain),
                'ds.key_tag'=>'Enter a number from 0 to 65535.',
                'ds.algorithm','ds.digest_type'=>'Choose an option from the list.',
                'ds.digest'=>'Enter the digest your DNS provider gives you: 64 characters for SHA-256 or 40 for SHA-1.',
                'glue.host'=>'Enter a hostname under '.$domain.', such as ns1.'.$domain.'.',
                'glue.ip'=>'Enter a public IPv4 or IPv6 address.',
                default=>self::fieldMessage($field,$registrar)??(preg_match('/^(dns|srv)\.records\.count$/D',$field)?'Keep between 1 and 50 records.':
                    (preg_match('/^(dns|srv)\.records(?:\.preimage)?$/D',$field)?'We couldn’t save this change. Reload the page and try again.':
                    (preg_match('/^(dns|srv)\.records\.(\d+)$/D',$field,$m)?'Check record '.((int)$m[2]+1).'. These values don’t work together.':'Check this field.'))),
            };
        }
        return $out;
    }
    private static function fieldMessage(string $field,string $registrar): ?string
    {
        if (str_starts_with($field,'contact.')) {
            $key=substr($field,8);$limit=ContactDetails::limits($registrar)[$key]??255;
            return match($key) {
                'country'=>'Choose a country from the list.',
                'email_address'=>'Enter a valid email address.',
                'phone'=>'Enter a phone number with its country code, such as +1.2025550123.',
                'state_province'=>$registrar==='namesilo'?'For the US or Canada, enter the 2-letter state or province code. For other countries, enter its name.':'Enter your state or province for the US, Canada or Australia.',
                default=>'Enter '.match($key) {'first_name'=>'your first name','last_name'=>'your last name','address1'=>'your street address','address2'=>'the second address line','city'=>'your city','postal_code'=>'your postal code','organization_name'=>'your organization',default=>'this contact detail'}.' (up to '.$limit.' characters).',
            };
        }
        if (!preg_match('/^(dns|srv)\.records\.\d+\.([a-z_]+)$/D',$field,$m)) { return null; }
        return match($m[2]) {
            'name'=>'Enter @, *, or a hostname such as www.',
            'type'=>'Choose a record type from the list.',
            'address'=>'Enter an address for this record type: an IP for A or AAAA, a hostname ending in a dot for CNAME or MX, a web address for URL or FRAME, or text for TXT.',
            'mx_pref','priority','weight','port'=>'Enter a number from 0 to 65535.',
            'service'=>'Enter a service name of up to 14 letters, numbers or hyphens, such as sip.',
            'protocol'=>'Choose TCP or UDP.',
            'target'=>'Enter a hostname ending in a dot, such as sip.example.com.',
            default=>null,
        };
    }
}
