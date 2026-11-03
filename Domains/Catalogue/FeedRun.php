<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\RequestContext;

/** Queue owns generation/state. Buffer outside locks; writer must recheck this guard under its locks. */
final class FeedRun
{
    /** $current revalidates the captured binding and last-started queue generation, or throws. */
    public static function prices(PricedFeed $feed, RequestContext $context, \Closure $current): array
    {
        $current(); $commands = [];
        foreach ($feed->prices($context) as $command) {
            if (!$command instanceof FeedObserved) { throw new \LogicException('Invalid feed command.'); }
            $context->binding->requireMatch($command->binding()); $commands[] = $command;
        }
        $current(); return $commands;
    }
}
