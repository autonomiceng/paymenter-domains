<?php
// Synthetic cURL only. MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Registrars\NameSilo\Api;
function hrtime(bool $asNumber=false): int { return $GLOBALS['nsGuardNanoseconds']??0; }
function curl_init(): object { return new \stdClass; }
function curl_setopt_array($handle,array $options): bool { $GLOBALS['nsGuardOptions']=array_replace($GLOBALS['nsGuardOptions']??[],$options);return true; }
function curl_exec($handle): bool { ++$GLOBALS['nsGuardSends'];return !($GLOBALS['nsGuardExecFail']??false); }
function curl_getinfo($handle,int $option): int { return 200; }
function curl_close($handle): void {}
