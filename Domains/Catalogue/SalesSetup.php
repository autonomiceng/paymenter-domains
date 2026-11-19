<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Catalogue;

use Illuminate\Support\Facades\{Auth, DB, Log};
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{AccountBindings, StaffSettingsReview as Review};

/** Local staff preview and one confirmation for a specific set of target offers. */
final readonly class SalesSetup
{
    public static function routed(): ?self { return CallerRouting::resolve(self::class); }

    public function __construct(private Writer $writer, private Clock $clock, private AccountBindings $bindings,
        private \Closure $feature, private \Closure $requestFacts) {}

    private function draft(int $serverId, array $suffixes, array $options): SalesSetupDraft
    {
        if (DB::transactionLevel() !== 0) { throw new \LogicException('Sales setup requires the outer writer.'); }
        return new SalesSetupDraft($serverId, $suffixes, $options, $this->clock, $this->bindings, $this->feature);
    }

    public function preview(int $serverId, array $suffixes, array $options): array
    {
        $plan = $this->draft($serverId, $suffixes, $options)->plan();
        return ['rows' => $plan['rows'], 'review_key' => $plan['entries'] === [] ? null :
            Review::ticket((int) Auth::id(), $serverId, null, 'sales_setup', $plan['stamp'])];
    }

    public function save(int $serverId, array $suffixes, array $options, string $reviewKey, string $reason): array
    {
        $draft = $this->draft($serverId, $suffixes, $options);
        $review = new Review((int) Auth::id(), $serverId, null, 'sales_setup', $reviewKey, $reason);
        $result = $this->writer->reviewed($review, $draft);
        $binding = $result['binding'];
        unset($result['binding']);
        $result['facts_hint_failed'] = [];
        foreach ($result['rows'] as $name) {
            try { ($this->requestFacts)($binding, $name); }
            catch (\Throwable $error) {
                $result['facts_hint_failed'][] = $name;
                Log::warning('Domains sales setup facts request deferred.', ['server_id' => $serverId, 'tld' => $name, 'exception_class' => $error::class]);
            }
        }
        return $result;
    }
}
