<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Http;
use Illuminate\Http\Request;
/** Only owned handlers decode byte-sensitive fields; middleware input bags may already be normalized. */
final class RawPost
{
    public static function body(#[\SensitiveParameter] Request $request): array
    {
        $length=trim((string)$request->header('Content-Length'));
        if (!$request->isMethod('POST') || (ctype_digit($length) && (int)$length>1048576)) { throw new \DomainException('Invalid form body.'); }
        // Content-Length is untrusted; cached JSON also supports bounded resource reads.
        $raw=stream_get_contents($request->getContent(true),1048577);
        if (!is_string($raw) || strlen($raw)>1048576) { throw new \DomainException('Invalid form body.'); }
        $type=strtolower(trim(explode(';',(string)$request->header('Content-Type'))[0]));
        if ($request->isJson()) {
            try { $body=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new \DomainException('Invalid form body.'); }
            if (!is_array($body) || !str_starts_with(ltrim($raw),'{')) { throw new \DomainException('Invalid form body.'); }
            return $body;
        }
        if ($type!=='application/x-www-form-urlencoded') { throw new \DomainException('Invalid form body.'); }
        // Refuse truncation: a partial DNS/SRV replacement could silently remove records.
        $limit=(int)ini_get('max_input_vars');$fields=substr_count($raw,'&')+1;
        if ($fields>1000 || ($limit>0 && $fields>$limit)) { throw new \DomainException('Invalid form body.'); }
        parse_str($raw,$body);return $body;
    }
}
