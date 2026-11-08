<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Data;

/** Diagnostic only, never proof of acceptance, rejection, charge or safe replay. */
final readonly class RegistrarReply
{
    // Keep only fixed error wording. Free text can echo credentials, contacts or account data.
    private const TEXTS=['Error 545 - Name server does not exist','Invalid nameserver information',
        'Parameter value policy error','Bad User name or Password','Insufficient funds',
        'Domain name not found','Domain name already exists','Invalid domain name',
        'Validation error. Failed to create order.','Validation error; failed to add; order(s)'];
    private function __construct(public ?string $code, public ?string $text) {}
    public static function capture(?string $code, #[\SensitiveParameter] mixed $message): ?self
    {
        $code=is_string($code)&&preg_match('/^[0-9]{1,6}$/D',$code)?$code:null;
        $text=null;
        if(is_string($message)&&strlen($message)<=4096){
            $message=trim(preg_replace('/\s+/u',' ',$message)??'');
            foreach(self::TEXTS as $known){
                if($message===$known||str_starts_with($message,$known.'.')||str_starts_with($message,$known.';')||str_starts_with($message,$known.':')){$text=$known;break;}
            }
        }
        return $code===null&&$text===null?null:new self($code,$text);
    }
    /** Saved facts are revalidated before staff display, including older or malformed data. */
    public static function fromArray(mixed $facts): ?self
    {
        if(!is_array($facts)||array_diff(array_keys($facts),['code','text'])
            ||(!is_null($facts['code']??null)&&!is_string($facts['code']))
            ||(!is_null($facts['text']??null)&&!is_string($facts['text']))){return null;}
        $reply=self::capture($facts['code']??null,$facts['text']??null);
        return $reply&&$reply->code===($facts['code']??null)&&$reply->text===($facts['text']??null)?$reply:null;
    }
    public function words(): string
    {
        return ($this->code!==null?'Code '.$this->code.($this->text!==null?': ':'. '):'')
            .($this->text??'Error text omitted because it may contain private data.');
    }
}
