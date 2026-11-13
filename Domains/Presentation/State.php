<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Presentation;
use Filament\Support\Contracts\HasLabel;
/** The closed list of status words on Domains pages. A page passes a case and never types the word, colour or mark.
 * Staff and customers read the same word; only the tone can differ, and only where tone() says so, because the tone
 * follows who must act. Every case carries an Entry naming its nearest words and why none fits; only the design system
 * owner adds a case, and a new tone or mark needs Ilya. Words not here keep stock styling until added.
 * HasLabel only: a State is never a Filament colour. */
enum State: string implements HasLabel
{
    #[Entry([State::Completed, State::Active], 'A check or result that passed. Completed is a finished job and Active is a record in good standing.', 'Checks passed, Matches template and Complete merged into it. DESIGN.md Panels, Nov 12, 2026.')]
    case Ok = 'ok';
    #[Entry([State::Ok, State::Monitored], 'A record that is live and in good standing. OK is a check result, not a record.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case Active = 'active';
    #[Entry([State::Active, State::Ok], 'A domain we watch but do not manage. Active means we manage it.', 'Monitoring merged into it. DESIGN.md Panels, Nov 12, 2026.')]
    case Monitored = 'monitored';
    #[Entry([State::Ok, State::Paid], 'A job or request that finished. OK is a check that passed.', 'Done merged into it. DESIGN.md Panels, Nov 12, 2026.')]
    case Completed = 'completed';
    #[Entry([State::Completed, State::Unpaid], 'An invoice the customer has paid. Completed is a job, not money.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case Paid = 'paid';
    #[Entry([State::Active, State::NotForSale], 'A TLD or extra we sell now. Active is a record in good standing.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case ForSale = 'for_sale';

    #[Entry([State::Checking, State::CouldntRead], 'A value never read, or waiting its turn, with nothing for anyone to do. Checking is a read happening now and Couldn\'t read is a read that failed.', 'Replaces calm Missing, Not read, Unknown and Not checked. Ilya, Nov 12, 2026, decision 4.')]
    case NotReadYet = 'not_read_yet';
    #[Entry([State::NotReadYet, State::Delayed], 'A renewal invoice we have not issued yet. Not read yet is a value read from elsewhere and Delayed is late work that needs someone.', 'Replaces Couldn\'t read on a renewal invoice not issued yet, Nov 13, 2026, DS-panel-system pages r1.')]
    case NotIssuedYet = 'not_issued_yet';
    #[Entry([State::NotSupported, State::Paused], 'We don\'t sell it, by choice or because the registrar doesn\'t offer it. Not supported means it can\'t be done at all.', 'Not offered and Not sold merged into it. Ilya, Nov 12, 2026, decision 2.')]
    case NotForSale = 'not_for_sale';
    #[Entry([State::NotForSale], 'The registrar or registry can\'t do this. Not for sale would be false for something like DNSSEC.', 'Unsupported merged into it. Ilya, Nov 12, 2026, decisions 2 and 3.')]
    case NotSupported = 'not_supported';
    #[Entry([State::ConnectionFailed, State::Paused], 'A registrar account that is off or not set up. Connection failed means a connection broke.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case NotConnected = 'not_connected';
    #[Entry([State::Stopped, State::OrdersPaused], 'Stopped for now on purpose, and it can resume. Orders paused is the account state staff must act on.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case Paused = 'paused';
    #[Entry([State::Paused, State::Cancelled], 'Ended on purpose and won\'t resume by itself. Paused resumes and Cancelled ends a record or request.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case Stopped = 'stopped';
    #[Entry([State::Stopped, State::Failed], 'A record or request ended on purpose before it finished. Failed means it broke.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case Cancelled = 'cancelled';
    #[Entry([State::NotReadYet, State::CouldntRead], 'A provider sent a code we have not mapped, and the code shows under it. The value was read, so neither Not read yet nor Couldn\'t read fits.', 'Backups\' existing word, shared by every repo. DESIGN.md Panels, Nov 12, 2026.')]
    case NotRecognized = 'not_recognized';
    #[Entry([State::AwaitingPayment, State::Paid], 'An invoice past its due date and not paid. Awaiting payment is not due yet. Grey for staff and amber for customers, because only the customer can pay.', 'Ilya, Nov 12, 2026, M0 round 3.')]
    case Unpaid = 'unpaid';

    #[Entry([State::Running, State::Queued], 'Anything waiting on the registrar, a read or a change. Running is work done entirely on our side.', 'Ilya, Nov 12, 2026, M0 rounds 4 and 5.')]
    case Checking = 'checking';
    #[Entry([State::Checking, State::Running], 'Waiting to start. The reason says what it waits for.', 'Waiting and Starting merged into it. DESIGN.md Panels, Nov 12, 2026.')]
    case Queued = 'queued';
    #[Entry([State::Checking, State::Queued], 'Work done entirely on our side, like updating prices from the last read. In progress names the tone and is never a word.', 'Ilya, Nov 12, 2026, M0 round 5.')]
    case Running = 'running';
    #[Entry([State::PendingTransfer, State::Checking], 'A paid registration the registrar has not finished. Checking is one read or change, not the domain\'s lifecycle.', 'Registration pending merged into it. DESIGN.md Panels, Nov 12, 2026.')]
    case PendingRegistration = 'pending_registration';
    #[Entry([State::PendingRegistration, State::Checking], 'A transfer the registries have not finished. Checking is one read or change, not the domain\'s lifecycle.', 'Transfer pending merged into it. DESIGN.md Panels, Nov 12, 2026.')]
    case PendingTransfer = 'pending_transfer';
    #[Entry([State::Unpaid, State::Queued], 'Waiting on an invoice that is not due yet. Cyan for staff and amber for customers, who must pay.', 'Ilya, Nov 12, 2026, plan r2 decision 4.')]
    case AwaitingPayment = 'awaiting_payment';
    #[Entry([State::NeedsReview, State::Checking], 'A paid change or held registration waiting for staff. Amber for staff, who must act, and cyan for customers, who can\'t. Needs review is a check that found a problem.', 'Replaces staff On hold. Ilya, Nov 12, 2026, M0 round 2.')]
    case UnderReview = 'under_review';

    #[Entry([State::NotReadYet, State::NeedsReview], 'A setting staff must choose is empty. Not read yet is a value we read from elsewhere.', 'Replaces Missing on settings, Not configured and No registrar selected. Ilya, Nov 12, 2026, decision 4.')]
    case NotSet = 'not_set';
    #[Entry([State::UnderReview, State::ActionRequired], 'A check found something staff must look at. Under review is a paid change waiting for staff.', 'Needs attention and Problem found merged into it. DESIGN.md Panels, Nov 12, 2026.')]
    case NeedsReview = 'needs_review';
    #[Entry([State::NeedsReview, State::NotSet], 'The customer must do something for a domain, like send an auth code. The reason says what.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case ActionRequired = 'action_required';
    #[Entry([State::Paused, State::NotConnected], 'A registrar account that sends no changes until staff turn orders back on.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case OrdersPaused = 'orders_paused';
    #[Entry([State::Expired, State::ActionRequired], 'The domain expires on today\'s date. Expired is past it.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case ExpiresToday = 'expires_today';
    #[Entry([State::NeedsReview], 'Registrar funds are below what renewals need, so staff add funds.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case LowBalance = 'low_balance';
    #[Entry([State::LowBalance, State::NeedsReview], 'A price under its registrar cost, so each sale loses money. Low balance is account funds and Needs review is a check that found a problem.', 'Parent ruling for pass 2, Nov 13, 2026: the value carries the amber mark, not a plain note.')]
    case BelowCost = 'below_cost';
    #[Entry([State::Queued, State::OutOfDate], 'Work that should have run by now and hasn\'t. Queued is still on time.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case Delayed = 'delayed';
    #[Entry([State::NotReadYet, State::CouldntRead], 'Data that was read but is older than it should be. Not read yet was never read.', 'Stale and Cost out of date merged into it. DESIGN.md Panels, Nov 12, 2026.')]
    case OutOfDate = 'out_of_date';

    #[Entry([State::Failed, State::NotReadYet], 'The last read of a value failed. Failed is a job or change that broke.', 'Replaces red Missing, Couldn\'t check and Unreadable. Ilya, Nov 12, 2026, decision 4.')]
    case CouldntRead = 'couldnt_read';
    #[Entry([State::CouldntRead, State::ConnectionFailed], 'A job, change or check that broke. The reason says what.', 'Check failed, Interrupted and Didn\'t start merged into it. DESIGN.md Panels, Nov 12, 2026.')]
    case Failed = 'failed';
    #[Entry([State::NotConnected, State::CouldntRead], 'A registrar account whose connection broke. Not connected is on purpose.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case ConnectionFailed = 'connection_failed';
    #[Entry([State::ExpiresToday, State::Redemption], 'Past the expiry date, red for every reader.', 'Ilya, Nov 12, 2026, plan r2 decision 4.')]
    case Expired = 'expired';
    #[Entry([State::Expired], 'Past grace and in the registry\'s redemption period, where a restore costs more than a renewal.', 'DESIGN.md Panels seed list, Nov 12, 2026.')]
    case Redemption = 'redemption';

    /** The block every repo shares (copied to Backups and AE Stripe later): results and the six availability states. */
    public const SHARED = [self::Ok, self::Failed, self::NotReadYet, self::Checking, self::CouldntRead, self::NotSet, self::NotForSale, self::NotSupported, self::NotRecognized];

    public function label(): string
    {
        return match ($this) {
            self::Ok => 'OK', self::Active => 'Active', self::Monitored => 'Monitored', self::Completed => 'Completed', self::Paid => 'Paid', self::ForSale => 'For sale',
            self::NotReadYet => 'Not read yet', self::NotIssuedYet => 'Not issued yet', self::NotForSale => 'Not for sale', self::NotSupported => 'Not supported', self::NotConnected => 'Not connected',
            self::Paused => 'Paused', self::Stopped => 'Stopped', self::Cancelled => 'Cancelled', self::NotRecognized => 'Not recognized', self::Unpaid => 'Unpaid',
            self::Checking => 'Checking', self::Queued => 'Queued', self::Running => 'Running', self::PendingRegistration => 'Pending registration',
            self::PendingTransfer => 'Pending transfer', self::AwaitingPayment => 'Awaiting payment', self::UnderReview => 'Under review',
            self::NotSet => 'Not set', self::NeedsReview => 'Needs review', self::ActionRequired => 'Action required', self::OrdersPaused => 'Orders paused',
            self::ExpiresToday => 'Expires today', self::LowBalance => 'Low balance', self::BelowCost => 'Below cost', self::Delayed => 'Delayed', self::OutOfDate => 'Out of date',
            self::CouldntRead => 'Couldn\'t read', self::Failed => 'Failed', self::ConnectionFailed => 'Connection failed', self::Expired => 'Expired', self::Redemption => 'Redemption',
        };
    }
    public function getLabel(): string { return $this->label(); }
    /** Amber means this reader must act and cyan means someone else is on it, so the three states one reader must act on
     * and the other can't differ by reader. Every other state has one tone. */
    public function tone(Reader $reader): Tone
    {
        return match ($this) {
            self::UnderReview => $reader === Reader::Staff ? Tone::Attention : Tone::Progress,
            self::AwaitingPayment => $reader === Reader::Staff ? Tone::Progress : Tone::Attention,
            self::Unpaid => $reader === Reader::Staff ? Tone::Off : Tone::Attention,
            self::Ok, self::Active, self::Monitored, self::Completed, self::Paid, self::ForSale => Tone::Ok,
            self::NotReadYet, self::NotIssuedYet, self::NotForSale, self::NotSupported, self::NotConnected, self::Paused, self::Stopped, self::Cancelled, self::NotRecognized => Tone::Off,
            self::Checking, self::Queued, self::Running, self::PendingRegistration, self::PendingTransfer => Tone::Progress,
            self::NotSet, self::NeedsReview, self::ActionRequired, self::OrdersPaused, self::ExpiresToday, self::LowBalance, self::BelowCost, self::Delayed, self::OutOfDate => Tone::Attention,
            self::CouldntRead, self::Failed, self::ConnectionFailed, self::Expired, self::Redemption => Tone::Failed,
        };
    }
}
