<?php
// Enom 444f31c TLD pricing layout; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource\Pages;
use Filament\Pages\Enums\SubNavigationPosition;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\TldResource;
use Paymenter\Extensions\Servers\Domains\Admin\Support\{RegistrarFacts,Sheet,Staff,TldFacts,TldRows};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\{Row,Value};
use Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Page\{RecordDetailPage,Section};
use Paymenter\Extensions\Servers\Domains\Models\Account;
use Paymenter\Extensions\Servers\Domains\Presentation\{Fact,Reader,State,StaffTime};
/** A TLD on one registrar account: what it sells and for how much, with the Activity rail. The head actions, sales
 * words and tab bar are shared with the History tab (Concerns\TldSalesPage). */
class ViewTld extends RecordDetailPage
{
    use Concerns\TldSalesPage { head as salesHead; }
    protected static string $resource=TldResource::class;
    protected static ?SubNavigationPosition $subNavigationPosition=SubNavigationPosition::Top;
    public const CHOOSE_SELLER='Use More → Change registrar.';
    public const CHOOSE_SELLER_HEAD='Use Choose registrar above.';
    protected function actions(): array {return $this->tldActions();}
    /** Prices and costs on this tab are all USD, so the head says it once. */
    protected function head(): \Paymenter\Extensions\Servers\Domains\Admin\Support\Panel\Head {return $this->salesHead()->note(Fact::IN_USD);}
    /** The sales state and its reason, then price sync; the rail holds the newest changes. */
    protected function sections(): array
    {
        $grants=Staff::requireView();$t=$this->getRecord();$staff=Reader::Staff;$snapshot=$this->snap();
        $sellerId=TldFacts::sellerId($t->tld);$seller=$sellerId===null?null:Account::where('server_id',$sellerId)->first();
        $reason=$this->saleReason();
        $choose=$this->settings()['seller']->isVisible()?($this->headFix()==='seller'?self::CHOOSE_SELLER_HEAD:self::CHOOSE_SELLER):'Ask staff who can change the registrar.';
        $sales=[...($reason===null?[]:[Row::notice('sales',Value::state(State::NotForSale,$staff,$reason))]),
            Row::make('Registrar for new orders',$seller?Value::fact(Fact::entered((string)\App\Models\Server::whereKey($sellerId)->value('name'))??Fact::count((int)$sellerId),'Existing domains stay with their registrar.'):Value::state(State::NotSet,$staff,$choose)),
            Row::make('Terms',$snapshot['unreadable']?Value::state(State::CouldntRead,$staff,'Saved TLD terms unreadable. Use Edit prices to repair them.'):Value::fact(Fact::entered(TldFacts::terms($t))))];
        $refresh=$snapshot['refresh']??[];
        $next=isset($refresh['due_at'])?'Next update '.StaffTime::at((int)$refresh['due_at']).'.':'No update scheduled. Check Site setup on the Registrar account page, and the background worker.';
        $sync=[Row::make('Last updated',isset($refresh['succeeded_at'])?Value::fact(Fact::date((int)$refresh['succeeded_at'],$staff),$next):Value::state(State::NotReadYet,$staff,$next))
            ->actions(Sheet::button($this->syncPricesAction()->label('Sync prices')))];
        if($failure=TldFacts::syncFailure((int)$t->server_id)){
            $failed=Value::state(State::Failed,$staff,(string)$failure['cause']);
            $sync[]=Row::make('Last refresh failure',isset($failure['timestamp'])?$failed->when((int)$failure['timestamp']):$failed);
        }
        return [Section::make('Sales',$sales),Section::make('Price sync',$sync),...TldRows::accounts(TldFacts::accounts($t,$grants),(bool)$grants->costs)];
    }
    protected function rail(): array {return [TldRows::activity($this->getRecord())];}
}
