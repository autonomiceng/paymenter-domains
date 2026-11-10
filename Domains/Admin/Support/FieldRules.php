<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Illuminate\Contracts\Validation\ValidationRule;
/** Dialog field checks that run the same rule the save runs. A refusal shows under the field, the dialog stays open
 * and what staff typed is kept. The save still checks again. Rules are objects: Filament evaluates a closure itself. */
final class FieldRules
{
    /** $validate(value, fail) calls fail with the message to show. */
    public static function rule(\Closure $validate): ValidationRule
    {
        return new class($validate) implements ValidationRule {
            public function __construct(private readonly \Closure $validate){}
            public function validate(string $attribute,mixed $value,\Closure $fail): void{($this->validate)($value,$fail);}
        };
    }
    /** Runs $check on the value and shows its refusal under the field. */
    public static function check(\Closure $check): ValidationRule
    {
        return self::rule(static function(mixed $value,\Closure $fail)use($check):void {
            try{$check($value);}catch(\InvalidArgumentException|\DomainException $e){$fail($e->getMessage());}
        });
    }
    /** One value per line: names the first line $valid refuses. */
    public static function lines(\Closure $valid,string $what): ValidationRule
    {
        return self::rule(static function(mixed $value,\Closure $fail)use($valid,$what):void {
            foreach(preg_split('/\R/',(string)$value)?:[] as $i=>$line){
                $line=trim($line);if($line===''){continue;}
                try{$ok=$valid($line);}catch(\InvalidArgumentException|\TypeError){$ok=false;}
                if(!$ok){$fail('Line '.($i+1).': "'.mb_strimwidth($line,0,60,'...').'" is not '.$what.'.');return;}
            }
        });
    }
    public static function hostname(): ValidationRule
    { return self::lines(static fn(string $line)=>is_string(\Paymenter\Extensions\Servers\Domains\Dns\DnsQuery::hostname($line)),'a hostname'); }
    public static function ip(): ValidationRule
    { return self::lines(static fn(string $line)=>filter_var($line,FILTER_VALIDATE_IP)!==false,'an IP address'); }
}
