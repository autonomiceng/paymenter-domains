<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Checkbox,Hidden,Select,Textarea,TextInput};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt;
use Paymenter\Extensions\Servers\Domains\Services\{RegistrarAnswers as Answers,StaffSettingsReview as Review,StaffSettingsWrites};
final class EnomAnswerAction
{
    public const LABEL='Resolve with Enom support';
    public static function label(Operation $op): string {return $op->registrar_key==='namesilo'?'Resolve with NameSilo support':self::LABEL;}
    /** The button names what it does; its dialog keeps the support heading. A transfer's rejection keeps its own label. */
    public static function button(Operation $op): string {return $op->action==='transfer'?self::label($op):'Record answer';}
    /** Unreadable saved attempt data never offers Not processed, nor does a renewal a check already saw charged or extended. */
    private static function accepted(Operation $op,?RenewalAttempt $renewal): bool
    {return !Answers::readable($op)||Answers::capturedReference($op)!==null||Answers::acquisitionProof($op)
        ||($op->action==='renew'?($renewal??RenewalAttempt::of($op))->acceptance()!==null:isset($op->attempt['registration'])||isset($op->attempt['renewal']))&&!Answers::stoppedOrder($op)
        ||$op->action==='renew'&&Answers::stoppedOrder($op)&&Answers::renewalProof($op,$op->domain()->firstOrFail());}
    /** One request's answer. The stamp is the settings review the page opened on. Step 1 is what support said, step 2 the ticket.
     * $renewal: the renewal's attempt facts the page already read. */
    public static function make(Operation $op,string $stamp,?RenewalAttempt $renewal=null): Action
    {
        $server=(int)$op->server_id;$id=$op->id;$registrar=$op->registrar_key==='namesilo'?'NameSilo':'Enom';$transfer=$op->action==='transfer';
        $unproven=Answers::stoppedOrder($op);
        return Action::make('enom_answer')->label(self::button($op))->modalHeading(self::label($op))->color('gray')
            // While an automatic check is scheduled the system can still read the result itself, so staff wait for it before asking support.
            ->visible(fn()=>Staff::can(Staff::POLICY)&&Staff::can(Review::permission('enom_answer'))&&Answers::ready($op,app(Clock::class)->now())&&!\Paymenter\Extensions\Servers\Domains\Services\RegistrarCheck::scheduled($op))
            ->modalDescription('Record what '.$registrar.' support found for Request #'.$id.'. Saving sends nothing to '.$registrar.'.')
            ->modalSubmitActionLabel('Record answer')->schema([
                Hidden::make('review_key')->default(fn()=>Review::ticket((int)Auth::id(),$server,null,'enom_answer',$stamp)),
                Hidden::make('operation_id')->default($id)->required(),
                Hidden::make('attempt_hash')->default(fn()=>Answers::identity($op))->required(),
                Section::make('1. What '.$registrar.' support said')->contained(false)->schema([
                    Select::make('outcome')->label('Result')->required()->live()->options($transfer
                        ?['transfer_rejected'=>'Rejected: the transfer will not complete']
                        :(self::accepted($op,$renewal)
                            ? ['accepted'=>'Processed: support confirmed the order number']
                            : ['accepted'=>'Processed: '.$registrar.' made the order','not_processed'=>'Not processed: no order and no charge'])),
                    TextInput::make('order_reference')->default(fn()=>$transfer?($op->attempt['evidence']['transferReference']??null):(Answers::capturedReference($op)??($unproven?$op->provider_order_id:null)))->label(fn($get)=>$get('outcome')==='transfer_rejected'&&$op->registrar_key==='enom'?'Enom transfer number':'Order number')
                        ->helperText(fn($get)=>$get('outcome')==='not_processed'?'Leave blank when there was no order.':'As support gave it. Saving it sends no new order.')->maxLength(20)
                        // The saved order number is not sent with a Not processed answer; the answer keeps it from the request.
                        ->visible(fn($get)=>!($get('outcome')==='not_processed'&&$unproven)),
                    TextInput::make('processed_at')->label('Processing time')->required()
                        ->visible(fn($get)=>$get('outcome')==='accepted'&&$op->registrar_key==='namesilo')
                        ->helperText('As support gave it, with the UTC offset, for example 2026-10-18T05:00:01-07:00.')->maxLength(35),
                    TextInput::make('transfer_status')->label('Final transfer status')->required()->maxLength(128)
                        ->visible(fn($get)=>$get('outcome')==='transfer_rejected'&&$op->registrar_key==='namesilo')
                        ->helperText('As support gave it.'),
                    TextInput::make('charge')->label('Charge (USD)')->required()->visible(fn($get)=>$get('outcome')==='transfer_rejected')
                        ->helperText('The final charge for this transfer, including zero.'),
                    TextInput::make('registrar_refund')->label('Refund (USD)')->required()->visible(fn($get)=>$get('outcome')==='transfer_rejected')
                        ->helperText('The settled refund, including zero. The customer gets their full payment back; we cover one failed fee.'),
                ]),
                Section::make('2. The support ticket')->contained(false)->schema([
                    TextInput::make('case_reference')->label('Case number')->maxLength(128)->helperText('Optional.'),
                    Textarea::make('evidence')->label('Their answer')->required()->maxLength(2000)
                        ->helperText('Paste the reply from support. Leave out passwords and keys.'),
                    // One confirmation. On a transfer it also states the result and amounts are final, which the writer requires.
                    Checkbox::make('confirmed')->label(fn($get)=>$transfer?$registrar.' support confirmed this account and request, that the transfer cannot complete later, and that the charge and refund are final'
                            :$registrar.' support checked this account and request, including whether an order or charge was made')->accepted()->required(),
                ]),
            ])->action(function(array $data)use($server,$registrar,$transfer):void{
                try{
                    if($transfer){$data['final_confirmed']=($data['confirmed']??false)===true;}
                    $r=new Review((int)Auth::id(),$server,null,'enom_answer',(string)($data['review_key']??''),'Recorded '.$registrar.' support answer');
                    app(StaffSettingsWrites::class)->run($r,$data);
                    Notification::make()->success()->title($registrar.' answer recorded')->body('Processed orders are checked again. Failed orders use the existing payment return.')->send();
                }catch(\Throwable $e){
                    Notification::make()->danger()->title('Could not finish recording the answer')->body($e instanceof \DomainException?$e->getMessage():'Reload the page. If the answer was saved, payment recovery will continue automatically.')->send();
                }
            });
    }
}
