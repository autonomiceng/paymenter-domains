<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** Match character-based forms while refusing malformed UTF-8 and control characters. */
final class StaffReason
{
    public const SINGLE_LINE = '/\A[^\x00-\x1f\x7f\x{2028}\x{2029}]*\z/u';
    public static function singleLine(string $reason): bool
    {
        return preg_match(self::SINGLE_LINE,$reason)===1;
    }
    public static function require(string $reason,int $min=5,int $max=500): void
    {
        if(!self::valid($reason,$min,$max)||$reason!==trim($reason)||!self::singleLine($reason)){
            throw new StaffReviewRefused('Enter a one-line reason between '.$min.' and '.$max.' characters.');
        }
    }
    public static function valid(string $reason,int $min,int $max): bool
    {
        return preg_match('//u',$reason)===1 && mb_strlen($reason,'UTF-8')>=$min && mb_strlen($reason,'UTF-8')<=$max;
    }
}
