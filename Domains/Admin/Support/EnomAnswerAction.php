<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Checkbox,Hidden,Select,Textarea,TextInput};
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\{Account,Operation};
use Paymenter\Extensions\Servers\Domains\Services\{RegistrarAnswers as Answers,StaffSettingsReview as Review,StaffSettingsWrites};
final class EnomAnswerAction
{
    private static function accepted(Operation $op): bool
    {return Answers::capturedReference($op)!==null||isset($op->attempt['registration'])||isset($op->attempt['renewal']);}
    public static function make(int $server,string $stamp): Action
    {
        $options=static function()use($server):array{
            return Operation::where('server_id',$server)->whereIn('registrar_key',['enom','namesilo'])->whereIn('state',['running','pending','uncertain','held','rejected'])->orderBy('id')->get()
                ->filter(fn($op)=>Answers::eligible($op,app(Clock::class)->now()))
                ->mapWithKeys(fn($op)=>[$op->id=>'Request #'.$op->id.' · '.$op->domain->domain.' · '.$op->action.' · '.($op->attempt['dispatch_attempted_at']??'')])->all();
        };
        return Action::make('enom_answer')->label('Record what Enom said')->color('gray')
            ->visible(fn()=>Staff::can(Staff::POLICY)&&Staff::can(Review::permission('enom_answer'))
                &&Account::where('server_id',$server)->whereIn('registrar_key',['enom','namesilo'])->exists()&&$options()!==[])
            ->modalDescription('The system cannot prove the final result or fee. Ask the registrar to check this account and exact attempt, then record its final answer.')
            ->modalSubmitActionLabel('Record answer')->schema([
                Hidden::make('review_key')->default(fn()=>Review::ticket((int)Auth::id(),$server,null,'enom_answer',$stamp)),
                Select::make('operation_id')->label('Request')->options($options)->required()->live()
                    ->afterStateUpdated(function($state,$set):void{
                        $op=Operation::find($state);$set('attempt_hash',$op?Answers::identity($op):null);$set('outcome',null);
                        $set('order_reference',$op?->action==='transfer'?($op->attempt['evidence']['transferReference']??null):($op?Answers::capturedReference($op):null));
                        foreach(['charge','registrar_refund','case_reference','evidence','processed_at','transfer_status'] as $field){$set($field,null);}
                        foreach(['final_confirmed','confirmed'] as $field){$set($field,false);}
                    }),
                Hidden::make('attempt_hash')->required(),
                Select::make('outcome')->label('Registrar’s final answer')->required()->live()->options(fn($get)=>Operation::find((int)$get('operation_id'))?->action==='transfer'
                    ?['transfer_rejected'=>'Registrar rejected the transfer']
                    :(self::accepted(Operation::find((int)$get('operation_id')) ?? new Operation)
                        ? ['accepted'=>'Processed: registrar confirmed the exact order number']
                        : ['accepted'=>'Processed: registrar gave the exact order number','not_processed'=>'Not processed: no order and no charge'])),
                TextInput::make('order_reference')->label(fn($get)=>$get('outcome')==='transfer_rejected'&&Operation::find((int)$get('operation_id'))?->registrar_key==='enom'?'Enom transfer number':'Registrar order number')
                    ->helperText(fn($get)=>$get('outcome')==='not_processed'?'Leave this blank if the registrar says there was no order and no charge.':'Use the exact number confirmed for this account and request. Recording it does not send another order.')->maxLength(20),
                TextInput::make('processed_at')->label('Order processing time')->required()
                    ->visible(fn($get)=>$get('outcome')==='accepted'&&Operation::find((int)$get('operation_id'))?->registrar_key==='namesilo')
                    ->helperText('Ask the registrar for the exact time and UTC offset, for example 2026-10-18T05:00:01-07:00.')->maxLength(35),
                TextInput::make('transfer_status')->label('Final transfer status')->required()->maxLength(128)
                    ->visible(fn($get)=>$get('outcome')==='transfer_rejected'&&Operation::find((int)$get('operation_id'))?->registrar_key==='namesilo')
                    ->helperText('Use the exact final status confirmed by the registrar.'),
                TextInput::make('charge')->label('Registrar charge (USD)')->required()->visible(fn($get)=>$get('outcome')==='transfer_rejected')
                    ->helperText('The final charge for this transfer, including zero.'),
                TextInput::make('registrar_refund')->label('Registrar refund (USD)')->required()->visible(fn($get)=>$get('outcome')==='transfer_rejected')
                    ->helperText('The settled refund, including zero. The customer gets their full payment back; we cover one failed fee.'),
                Checkbox::make('final_confirmed')->label('The registrar confirmed the transfer cannot complete later and these charge and refund amounts are final')
                    ->accepted()->required()->visible(fn($get)=>$get('outcome')==='transfer_rejected'),
                TextInput::make('case_reference')->label('Registrar support case')->required()->maxLength(128),
                Textarea::make('evidence')->label('Registrar’s answer')->required()->maxLength(2000)->helperText('Include the account, domain, request time and final result. Do not include credentials.'),
                Checkbox::make('confirmed')->label('The registrar confirmed this exact account and attempt, including whether it created an order or charged the account')->accepted()->required(),
            ])->action(function(array $data)use($server):void{
                try{
                    $r=new Review((int)Auth::id(),$server,null,'enom_answer',(string)($data['review_key']??''),'Recorded registrar support answer');
                    app(StaffSettingsWrites::class)->run($r,$data);
                    Notification::make()->success()->title('Registrar answer recorded')->body('Processed orders are checked again. Failed orders use the existing payment return.')->send();
                }catch(\Throwable $e){
                    Notification::make()->danger()->title('Could not finish recording the answer')->body($e instanceof \DomainException?$e->getMessage():'Reload the page. If the answer was saved, payment recovery will continue automatically.')->send();
                }
            });
    }
}
