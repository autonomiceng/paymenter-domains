<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
/** Bounded name samples for account summaries. The existing TLD list holds the full catalogue. */
final class TldNames
{
    public static function summary(array $names): string
    {
        $popular=['com'=>0,'net'=>1,'org'=>2];
        usort($names,static fn(string $a,string $b):int=>($popular[$a]??3)<=>($popular[$b]??3) ?: strcmp($a,$b));
        $text=implode(', ',array_map(static fn(string $name):string=>'.'.$name,array_slice($names,0,3)));
        return $text.(count($names)>3?' and '.(count($names)-3).' more':'');
    }
}
