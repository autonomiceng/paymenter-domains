<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace DomainsConversion;
final class EvidenceFile
{
    public static function target(string $path,bool $replace): void
    {
        $parent=dirname($path);
        if(realpath($parent)!==$parent||!is_dir($parent)||basename($path)==='.'||basename($path)==='..'||is_link($path)
            ||(file_exists($path)&&(!$replace||!is_file($path)))){throw new \DomainException('Private canonical output path required; use --replace for an existing file.');}
    }
    public static function write(string $path,array $evidence,bool $replace,#[\SensitiveParameter] array $tokens): void
    {
        self::target($path,$replace);$json=json_encode($evidence,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
        // Check both unescaped field values and the bytes that will be written. Provider echoes are never retained.
        $check=static function($value)use(&$check,$tokens):void{
            if(is_array($value)){foreach($value as$v){$check($v);}return;}
            if(is_string($value)){foreach($tokens as$t){if($t!==''&&str_contains($value,$t)){throw new \RuntimeException('Collector secret in evidence refused.');}}}
        };
        $check($evidence);$check($json);
        if(strlen($json)>8_000_000){throw new \RuntimeException('Collector evidence exceeds converter file limit; narrow scope.');}
        $old=umask(0077);$temporary=null;$lock=null;
        try {
            // Serialize cooperating collectors without leaving a separate lock file.
            $lock=fopen(dirname($path),'r');if(!$lock||!flock($lock,LOCK_EX)){throw new \RuntimeException;}
            self::target($path,$replace);
            $temporary=tempnam(dirname($path),'.conversion-evidence-');
            if($temporary===false||!chmod($temporary,0600)||file_put_contents($temporary,$json)!==strlen($json)){throw new \RuntimeException;}
            // POSIX rename replaces an unexpected concurrent destination. Initial publication
            // uses an atomic no-clobber hard link; explicit replacement uses temp + rename.
            if($replace){if(!rename($temporary,$path)){throw new \RuntimeException;}$temporary=null;}
            elseif(!@link($temporary,$path)){throw new \RuntimeException('Concurrent evidence destination refused.');}
        } finally {
            if(is_string($temporary)&&is_file($temporary)){unlink($temporary);}if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}umask($old);
        }
    }
}
