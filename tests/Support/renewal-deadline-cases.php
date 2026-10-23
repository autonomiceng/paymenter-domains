<?php
// Synthetic shared checkout/adoption deadlines; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
use Illuminate\Support\Facades\{Cache, DB};
use Paymenter\Extensions\Servers\Domains\Registrars\Enom\Api\ReadCommand;
return static function ($op, $domain, $binding, Closure $compose, $clock, $state, Closure $setNow, Closure $nativeMoney): array {
    $original = $op->getRawOriginal(); $entry = new DateTimeImmutable($op->base_expires_at->format('Y-m-d').'T11:55:00Z');
    $waiting = false; $wait = 0;
    DB::listen(static function ($query) use (&$waiting, &$wait, $clock, $setNow): void {
        if ($waiting && str_contains($query->sql, '"users"')) { $waiting = false; $setNow($clock->now()->modify('+'.$wait.' seconds')); }
    });
    $reset = static function () use ($op, $original, $state, $setNow, $entry): void {
        DB::table('domains_operations')->where('id', $op->id)->update($original);
        $setNow($entry); Cache::flush(); $state->calls = []; $state->writes = 0;
        $state->during = null; $state->duringCommand = null; $state->afterWrite = null;
    };
    $noSend = static function ($result, array $money) use ($nativeMoney, $state): void {
        expect(in_array($result->state, ['held','queued'], true), 'Deadline refusal keeps unsent renewal');
        same([], $result->attempt ?? [], 'Deadline refusal creates no execution/seal proof');
        same(null, $result->provider_order_id, 'Deadline refusal creates no order proof');
        expect(!isset($result->funding['seal']), 'Deadline refusal never seals funding');
        same(0, $state->writes, 'Deadline refusal sends no renewal');
        same($money, $nativeMoney(), 'Native finance unchanged on no-send');
    };
    // Initial capture waits and both full assessments share the entry cap, never a post-capture reset.
    foreach ([[245,0,true],[246,0,false],[169,75,true],[171,75,false]] as [$wait,$slow,$send]) {
        $reset(); [$engine,$writer] = $compose($binding); $money = $nativeMoney(); $waiting = true;
        if ($slow) { $state->during = static fn () => $setNow($clock->now()->modify('+'.$slow.' seconds')); }
        $result = $engine->run($op->id, $writer); $waiting = false;
        if ($send) {
            same('pending', $result->state, 'Full request fits after capture wait and preflight');
            same(1, $state->writes, 'Boundary admits one renewal'); same($state->order, $result->provider_order_id, 'Boundary response ID retained');
            same($money, $nativeMoney(), 'Boundary send never rewrites native finance');
        } else { $noSend($result, $money); }
    }
    // Both admission passes must stop their second physical read after the SAME absolute cap.
    foreach (['first','refresh','stricter_250','stricter_250_refresh'] as $pass) {
        $reset(); [$engine,$writer] = $compose($binding); $money = $nativeMoney();
        $stricter = str_starts_with($pass, 'stricter_250'); $refresh = $pass === 'refresh' || $pass === 'stricter_250_refresh';
        $wait = $stricter ? 0 : 100; $waiting = true;
        $delay = static fn () => $setNow($entry->modify('+'.($stricter ? 251 : 271).' seconds'));
        if ($refresh) {
            $state->duringCommand = ReadCommand::Balance;
            $state->during = static function () use ($state, $delay, $stricter, $setNow, $entry): void {
                if ($stricter) { $setNow($entry->modify('+30 seconds')); }
                $state->duringCommand = ReadCommand::Info; $state->during = $delay;
            };
        } else { $state->during = $delay; }
        $result = $engine->run($op->id, $writer); $waiting = false; $noSend($result, $money);
        same($refresh ? 9 : 1, count($state->calls), 'Late second physical read stopped: '.$pass);
    }
    // A 24-second final admitted wire returns its ID before entry+270, leaving persistence headroom.
    $reset(); [$engine,$writer,$completion] = $compose($binding); $wait = 245; $waiting = true; $money = $nativeMoney();
    $state->afterWrite = static fn () => $setNow($clock->now()->modify('+24 seconds'));
    $result = $engine->run($op->id, $writer); $waiting = false;
    same('pending', $result->state, 'Timely admitted last request remains pending');
    same($state->order, $result->provider_order_id, 'Timely last response ID retained');
    same($state->order, $result->attempt['renewal']['order_reference'], 'Original response reference retained in execution');
    same(1, $state->writes, 'One final admitted wire'); same($money, $nativeMoney(), 'Response persistence retains finance');
    $engine->run($op->id, $writer); same(1, $state->writes, 'Saved response never grants replay');
    // Read-only completion also charges capture waits to its stricter 250-second budget.
    $state->calls = []; $wait = 251; $waiting = true;
    same('pending', $completion->run($op->id)->state, 'Expired completion remains pending'); $waiting = false;
    same([], $state->calls, 'Completion capture wait prevents every physical read');
    $state->afterWrite = null; $setNow($entry->modify('+269 seconds')); Cache::flush();
    return [$engine,$writer,$completion];
};
