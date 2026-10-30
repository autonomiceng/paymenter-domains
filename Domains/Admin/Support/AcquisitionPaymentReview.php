<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
use App\Models\{Invoice,InvoiceTransaction,Service};
use Filament\Schemas\Components\Section;
use Filament\Actions\Action;
use Filament\Forms\Components\{Checkbox,Hidden,Textarea};
use Filament\Notifications\Notification;
use Filament\Infolists\Components\TextEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\{Auth,DB,Log,Schema};
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\AcquisitionPaymentReviews;
use Paymenter\Extensions\Servers\Domains\Admin\Resources\OperationResource;
/** Retained payment discrepancy and audited acknowledgement, with no financial disposition. */
final class AcquisitionPaymentReview
{
    public const DESCRIPTION='A payment, or a reactivated Paymenter service, was recorded after this domain order closed. Check the invoice, its payments and any account credit already given before contacting the customer. Nothing is sent to the registrar, a released name is not reserved again, and no credit is added automatically.';
    public static function query(Builder $query): Builder
    {
        return $query->whereExists(fn($q)=>$q->selectRaw('1')->from('domains_obligation_alerts as a')
            ->whereColumn('a.domain_id','domains_domains.id')->where('a.code','acquisition_late_money')->whereNull('a.resolved_at'));
    }
    public static function count(Domain $d): int
    {if(!Schema::hasTable('domains_obligation_alerts')){return 0;}return DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','acquisition_late_money')->whereNull('resolved_at')->count();}
    public static function actions(Domain $d): array
    {
        $actor=Auth::user();$staff=$actor instanceof \App\Models\User?\App\Models\User::with('role')->find($actor->id):null;
        if(!AcquisitionPaymentReviews::allowed($staff)||self::count($d)===0){return [];}
        return [Action::make('review_late_payment')->label('Record payment review')->color('warning')->modalWidth('lg')
            ->modalDescription('Confirm that you checked the invoice, its payments and account credit. This records your review and clears the reviewed payment alerts.')
            ->schema([Hidden::make('review_key')->default(function()use($staff,$d):string{
                    try{return app(AcquisitionPaymentReviews::class)->ticket($staff,(int)$d->id);}
                    catch(\DomainException){return '';}
                }),
                Checkbox::make('confirmed')->label('I reconciled the invoice, payments and account credit')->accepted()->required(),
                Textarea::make('reason')->label('Reason')->required()->minLength(5)->maxLength(500)->rows(3)
                    ->rules(['regex:'.StaffReason::SINGLE_LINE])->validationMessages(['regex'=>'Use one line.'])])
            ->action(function(array $data,$livewire)use($d):void {
                try{
                    $actor=Auth::user();if(!$actor instanceof \App\Models\User){throw new \DomainException('Current staff permissions required. Reload this page.');}
                    app(AcquisitionPaymentReviews::class)->review($actor,(int)$d->id,(string)($data['review_key']??''),trim((string)($data['reason']??'')),($data['confirmed']??false)===true);
                    Notification::make()->success()->title('Payment review recorded')->send();
                    $livewire->redirect(\Paymenter\Extensions\Servers\Domains\Admin\Resources\DomainResource::getUrl('view',['record'=>$d->id]));
                }catch(\Throwable $e){
                    Log::warning('Domains payment review refused.',['domain_id'=>$d->id,'exception_class'=>$e::class]);
                    Notification::make()->danger()->title("Couldn't record payment review")->body('Reload the page, check the current invoice, payments and account credit, then review again.')->send();
                }finally{$livewire->mountedActions=[];}
            })];
    }
    public static function section(Domain $d): array
    {
        Staff::requireView();if(!Schema::hasTable('domains_obligation_alerts')){return [];}
        $rows=DB::table('domains_obligation_alerts as a')->leftJoin('domains_operations as o','o.id','=','a.operation_id')
            ->where('a.domain_id',$d->id)->where('a.code','acquisition_late_money')->whereNull('a.resolved_at')->orderByDesc('a.id')->limit(10)
            ->get(['a.id','a.first_seen','a.last_seen','o.id as request_id','o.invoice_id']);
        if($rows->isEmpty()){return [];}$entries=[];$activities=[];$seen=[];
        $service=Service::whereKey($d->service_id)->value('status');
        foreach($rows as$r){
            $links=[];
            if(!isset($seen[$r->invoice_id])){
            if($r->invoice_id){$label='Invoice #'.$r->invoice_id;$invoice=Invoice::find($r->invoice_id);$links[]=$invoice&&\App\Admin\Resources\InvoiceResource::canEdit($invoice)
                ?self::link(\App\Admin\Resources\InvoiceResource::getUrl('edit',['record'=>$r->invoice_id]),$label):e($label).' · invoice edit access required';}
            $links[]='Current service: '.e(ucfirst($service??'missing'));
            $links[]='Payment records: '.InvoiceTransaction::where('invoice_id',$r->invoice_id)->count();
            $entries[]=TextEntry::make('payment_current_'.$r->id)->label('Current billing state')->state(new HtmlString(implode(' · ',$links)));
            $seen[$r->invoice_id]=true;
            }
            $activities[]=TextEntry::make('payment_review_'.$r->id)->label('Recorded '.gmdate('M j, Y H:i:s \U\T\C',(int)$r->first_seen))
                ->state(new HtmlString($r->request_id?self::link(OperationResource::getUrl('view',['record'=>$r->request_id]),'Registrar request #'.$r->request_id):'Registrar request unavailable'));
        }
        $count=DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','acquisition_late_money')->whereNull('resolved_at')->count();
        return [Section::make('Payment activity after the order closed')->icon('heroicon-o-exclamation-triangle')->iconColor('warning')
            ->description(self::DESCRIPTION.($count>10?' Showing the latest 10 of '.$count.' recorded activities.':''))->columnSpanFull()->schema([...$entries,...$activities])];
    }
    private static function link(string $url,string $label): string
    {return '<a class="fi-link" style="text-decoration:underline" href="'.e($url).'">'.e($label).'</a>';}
}
