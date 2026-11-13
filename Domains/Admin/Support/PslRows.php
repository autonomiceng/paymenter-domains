<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row, Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\Section;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact, Reader, State};
use Paymenter\Extensions\Servers\Domains\Services\{PslRebaseline, PublicSuffixList};
/** The public suffix list's status as the Detail page's sections: the saved list, then its downloads. Same facts and
 * causes as PslActions::row(), which System checks still reads. */
final class PslRows
{
    /** @return list<Section> */
    public static function sections(PublicSuffixList $list): array
    {
        $saved = $list->current(); $status = $list->status(); $review = app(PslRebaseline::class)->preview() !== null; $staff = Reader::Staff;
        $state = match (true) {
            $review => State::NeedsReview,
            $saved === null => State::NotReadYet,
            in_array(PslFacts::state($list), ['Saved list damaged', 'Saved list needs review'], true) => State::CouldntRead,
            !$list->discoveryAllowed() => State::OutOfDate,
            default => State::Ok,
        };
        $healthy = $state === State::Ok;
        $why = match (true) {
            $healthy => null,
            $review => 'Automatic update stopped because many rules disappeared. Review the removals before replacing the saved list.',
            default => 'Updates retry automatically each week. Check the scheduler and site logs if downloads keep failing.',
        };
        $rows = [Row::make('Status', Value::state($state, $staff, $why, 'psl'))->help(PslActions::HELP)];
        if ($saved !== null) {
            $rows[] = Row::make('Last updated', Value::fact(Fact::date((int) $saved->validated_at, $staff)));
            $rows[] = Row::make('Out of date after', Value::fact(Fact::date($list->expiresAt(), $staff)));
        }
        $sections = [Section::make('Saved list', $rows)];
        $downloads = [];
        if ((int) $status->consecutive_failures > 0 && $status->last_attempt_at !== null && !$review) {
            $result = in_array($status->last_result, [null, 'checking'], true) ? null : PslActions::result($status->last_result);
            $downloads[] = Row::make('Last download', Value::state(State::Failed, $staff, $result, 'psl-download')->when((int) $status->last_attempt_at));
        }
        if (!$review && ($at = PslActions::limitedUntil($list)) !== null) {
            $downloads[] = Row::make('Next download allowed', Value::fact(Fact::date($at, $staff)));
        }
        if ($downloads !== []) { $sections[] = Section::make('Downloads', $downloads); }
        return $sections;
    }
}
