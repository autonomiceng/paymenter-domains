<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Paymenter\Extensions\Servers\Domains\Data\{AccountBinding, CanonicalName};
use Paymenter\Extensions\Servers\Domains\Services\PricingRule;

/** Privileged local commands. Authorization belongs to the injected caller, never form input.
 * Defaults use an existing offer as their revision anchor; republication is bounded per offer. */
final readonly class Edit implements Command
{
    private function __construct(private AccountBinding $account, private string $ending,
        public string $kind, public ?Intent $intent = null, public ?array $rule = null,
        public ?string $retail = null, public array $actions = [], public bool $sellerTransfers = false)
    { CanonicalName::parse('example.'.$ending, [$ending]); }

    public static function defaults(AccountBinding $b, string $tld, Intent $intent): self
    {
        if ($intent->sell !== null || $intent->longest !== null || $intent->transfers !== null || $intent->stops !== []) {
            throw new \InvalidArgumentException('Defaults cannot contain offer overrides.');
        }
        return new self($b, $tld, 'defaults', $intent);
    }
    /** Null pricing rule removes the offer rule and inherits the business default. Staff Transfers On also
     * allows transfers on this account's own seller row ($sellerTransfers), so one switch means one thing. */
    public static function override(AccountBinding $b, string $tld, Intent $intent, ?array $rule = null, bool $sellerTransfers = false): self
    { return new self($b, $tld, 'override', $intent, $rule === null ? null : PricingRule::validate($rule), sellerTransfers: $sellerTransfers); }
    /** The binding is the new seller; an empty action list deselects the ending. */
    public static function seller(AccountBinding $b, string $tld, array $actions, ?Intent $intent = null): self
    {
        if (array_diff($actions, ['register', 'transfer']) !== [] || count(array_unique($actions)) !== count($actions)) {
            throw new \InvalidArgumentException('Invalid seller actions.');
        }
        return new self($b, $tld, 'seller', intent: $intent, actions: $actions);
    }
    public static function retainRenewal(AccountBinding $b, string $tld, string $retail): self
    {
        if (PricingRule::units($retail, 2) < 1) { throw new \InvalidArgumentException('Positive renewal retail required.'); }
        return new self($b, $tld, 'retail', retail: PricingRule::decimal(PricingRule::units($retail, 2)));
    }
    public static function requestSync(AccountBinding $b, string $tld): self { return new self($b, $tld, 'sync'); }
    public static function republish(AccountBinding $b, string $tld): self { return new self($b, $tld, 'republish'); }
    public function binding(): AccountBinding { return $this->account; }
    public function tld(): string { return $this->ending; }
}
