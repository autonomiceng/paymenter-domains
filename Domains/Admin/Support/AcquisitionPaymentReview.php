<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Paymenter\Extensions\Servers\Domains\Services\StaffReason;
use Filament\Actions\Action;
use Filament\Forms\Components\{Checkbox,Hidden,Textarea};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{Auth,DB,Log,Schema};
use Paymenter\Extensions\Servers\Domains\Models\Domain;
use Paymenter\Extensions\Servers\Domains\Services\AcquisitionPaymentReviews;
/** Retained payment discrepancy and audited acknowledgement, with no financial disposition. */
final class AcquisitionPaymentReview
{
    public static function count(Domain $d): int
    {if(!Schema::hasTable('domains_obligation_alerts')){return 0;}return DB::table('domains_obligation_alerts')->where('domain_id',$d->id)->where('code','acquisition_late_money')->whereNull('resolved_at')->count();}
    public static function actions(Domain $d): array
    {
        $actor=Auth::user();$staff=$actor instanceof \App\Models\User?\App\Models\User::with('role')->find($actor->id):null;
        if(!AcquisitionPaymentReviews::allowed($staff)||self::count($d)===0){return [];}
        return [Action::make('review_late_payment')->label('Record payment review')->color('warning')->modalWidth('lg')
            ->modalDescription('Saves your review and clears these payment alerts.')
            ->schema([Hidden::make('review_key')->default(function()use($staff,$d):string{
                    try{return app(AcquisitionPaymentReviews::class)->ticket($staff,(int)$d->id);}
                    catch(\DomainException){return '';}
                }),
                Checkbox::make('confirmed')->label('I checked the invoice, payments and account credit')->accepted()->required(),
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
                    Notification::make()->danger()->title("Couldn't record payment review")->body('Reload the page and try again.')->send();
                }finally{$livewire->mountedActions=[];}
            })];
    }
}
