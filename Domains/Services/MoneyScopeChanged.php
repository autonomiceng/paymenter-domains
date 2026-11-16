<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
/** An invoice-scoped decision needed rows outside its invoice, or its invoice moved. The runner rolls back and runs it
 * again on the full scope. Not a business refusal: never catch it inside a money decision. */
final class MoneyScopeChanged extends \LogicException {}
