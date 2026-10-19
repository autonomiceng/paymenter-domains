<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Services;
use App\Models\User;
use Illuminate\Support\Facades\{DB, Schema};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Staff;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\WatchAudit;
/** Pending bytes confer no discovery authority. Acceptance always revalidates them under the shared status lock. */
final readonly class PslRebaseline
{
    public function __construct(private Clock $clock, private PslValidator $validator) {}
    public function pending(): ?object
    { return Schema::hasTable('domains_psl_candidates') ? DB::table('domains_psl_candidates')->where('id', 1)->first() : null; }
    private function comparison(string $body, object $old): array
    {
        $rules = $this->validator->parse($body); // Every current validation except the removal comparison is mandatory.
        $oldBody = PublicSuffixList::body($old); // A damaged hash/compressed body is never recoverable here.
        try { $baseline = $this->validator->baseline($oldBody); $incompatible = false; }
        catch (PslFailure $e) {
            if ($e->safeCode !== 'rule_invalid') { throw $e; }
            $baseline = $this->validator->historicalIdentities($oldBody);
            $rulesForDiff = $this->validator->historicalIdentities($body); $incompatible = true;
        }
        $removed = []; $eligible = $incompatible;
        foreach ($baseline as $section => $set) {
            $missing = array_keys(array_diff_key($set, ($rulesForDiff ?? $rules)[$section]));
            $removed[$section] = ['count' => count($missing), 'samples' => array_slice($missing, 0, 20)];
            $eligible = $eligible || count($missing) * 100 > count($set) * 5;
        }
        if (!$eligible) { throw new PslFailure('rebaseline_not_required'); }
        return ['rules' => $rules, 'removed' => $removed, 'icu_incompatible' => $incompatible, 'rejected' => $incompatible ? $this->validator->incompatibleRules($oldBody) : []];
    }
    public function capture(string $body, object $old, string $url, int $fetchedAt): ?string
    {
        if (!Schema::hasTable('domains_psl_candidates')) { return null; }
        try { $diff = $this->comparison($body, $old); } catch (PslFailure) { return null; }
        $code = $diff['icu_incompatible'] ? 'stored_rules_rejected' : 'rules_lost';
        DB::table('domains_psl_candidates')->updateOrInsert(['id' => 1], ['revision' => hash('sha256', $body),
            'baseline_revision' => $old->revision, 'source_url' => $url, 'compressed_body' => base64_encode(gzencode($body, 9)),
            'safe_code' => $code, 'fetched_at' => $fetchedAt]);
        return $code;
    }
    public function preview(): ?array
    {
        $candidate = $this->pending(); $old = DB::table('domains_psl_snapshots')->where('state', 'current')->first();
        if (!$candidate || !$old || $old->revision !== $candidate->baseline_revision) { return null; }
        try { $diff = $this->comparison(PublicSuffixList::body($candidate), $old); } catch (PslFailure) { return null; }
        $counts = ['icann' => count($diff['rules']['icann']), 'private' => count($diff['rules']['private'])];
        unset($diff['rules']); return $diff + ['revision' => $candidate->revision, 'baseline_revision' => $old->revision,
            'source_url' => $candidate->source_url, 'fetched_at' => (int) $candidate->fetched_at,
            'before_counts' => ['icann' => (int) $old->icann_count, 'private' => (int) $old->private_count], 'after_counts' => $counts];
    }
    public function accept(User $actor, string $revision, string $confirmation, string $reason): void
    {
        if (!Staff::allows($actor, 'admin.domains.watch.view') || !Staff::allows($actor, 'admin.domains.watch.manage')) { abort(403); }
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 1000 || !preg_match('/\A[a-f0-9]{64}\z/', $revision) || $confirmation !== substr($revision, 0, 12)) { throw new PslFailure('confirmation_invalid'); }
        DB::transaction(function () use ($actor, $revision, $reason): void {
            DB::table('domains_psl_status')->where('id', 1)->lockForUpdate()->firstOrFail();
            if (!Staff::allows($actor, 'admin.domains.watch.view') || !Staff::allows($actor, 'admin.domains.watch.manage')) { abort(403); }
            $candidate = $this->pending(); $old = DB::table('domains_psl_snapshots')->where('state', 'current')->firstOrFail();
            if (!$candidate || !hash_equals($candidate->revision, $revision) || $old->revision !== $candidate->baseline_revision) { throw new PslFailure('candidate_changed'); }
            $body = PublicSuffixList::body($candidate); $diff = $this->comparison($body, $old); $now = $this->clock->now()->getTimestamp();
            DB::table('domains_psl_snapshots')->where('state', 'previous')->delete();
            DB::table('domains_psl_snapshots')->where('state', 'current')->update(['state' => 'previous']);
            DB::table('domains_psl_snapshots')->insert(['state' => 'current', 'revision' => $revision, 'source_url' => $candidate->source_url,
                'byte_size' => strlen($body), 'icann_count' => count($diff['rules']['icann']), 'private_count' => count($diff['rules']['private']),
                'fetched_at' => $candidate->fetched_at, 'validated_at' => $candidate->fetched_at, 'compressed_body' => $candidate->compressed_body]);
            WatchAudit::create(['subject_type' => 'psl', 'subject_id' => 1, 'actor_id' => $actor->id, 'event' => 'psl_rebaseline', 'reason' => $reason,
                'before_revision' => $old->revision, 'after_revision' => $revision, 'data' => ['removed' => $diff['removed'], 'icu_incompatible' => $diff['icu_incompatible'], 'rejected' => $diff['rejected']], 'created_at' => gmdate('Y-m-d H:i:s', $now)]);
            DB::table('domains_psl_candidates')->delete();
            DB::table('domains_psl_status')->where('id', 1)->update(['last_result' => 'rebaselined', 'attempt_token' => null, 'consecutive_failures' => 0]);
        });
    }
}
