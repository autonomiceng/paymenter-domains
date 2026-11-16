<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Filament\Forms\Components\{Checkbox,Hidden,Select,Textarea,TextInput};
use Filament\Notifications\Notification;
use Filament\Schemas\Components\{Section,Text};
use Illuminate\Support\Facades\Auth;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Services\{RegistrarAnswers as Answers,StaffSettingsReview as Review,StaffSettingsWrites};
final class EnomAnswerAction
{
    public const LABEL='Resolve with Enom support';
    public static function label(Operation $op): string {return $op->registrar_key==='namesilo'?'Resolve with NameSilo support':self::LABEL;}
    /** The button names what it does; its dialog keeps the support heading. A transfer's rejection keeps its own label. */
    public static function button(Operation $op): string {return $op->action==='transfer'?self::label($op):'Record answer';}
    /** Unreadable saved attempt data never offers the no-order answer. */
    private static function accepted(Operation $op): bool
    {return !Answers::readable($op)||Answers::capturedReference($op)!==null||isset($op->attempt['registration'])||isset($op->attempt['renewal']);}
    /** The evidence line: what the scheduled read found and when. */
    private static function absenceLine(Operation $op,?array $absence): string
    {
        if($absence===null){return '';}
        $at=\Paymenter\Extensions\Servers\Domains\Presentation\StaffTime::at((new \DateTimeImmutable($absence['read_at']))->getTimestamp());
        $domain=$op->action==='renew'?'its expiry is still '.(new \DateTimeImmutable($absence['domain']['expiry']))->format('M j, Y'):'the domain is not in our Enom account';
        return 'Enom has no order and the domain is unchanged. Checked '.$at.': Enom’s order history has no order for this request, and '.$domain.'.';
    }
    /** One request's answer. The stamp is the settings review the page opened on. Step 1 is what support said, step 2 the ticket. */
    public static function make(Operation $op,string $stamp): Action
    {
        $server=(int)$op->server_id;$id=$op->id;$registrar=$op->registrar_key==='namesilo'?'NameSilo':'Enom';$transfer=$op->action==='transfer';
        // The scheduled read's proof that Enom has no order: the case number becomes optional for the no-order answer only.
        $absence=static function()use($op):?array{static $read=false,$value=null;if(!$read){$read=true;$value=Answers::absence($op,app(Clock::class)->now());}return $value;};
        $relaxed=static fn($get):bool=>$get('outcome')==='not_processed'&&$absence()!==null;
        return Action::make('enom_answer')->label(self::button($op))->modalHeading(self::label($op))->color('gray')
            // While an automatic check is scheduled the system can still read the result itself, so staff wait for it before asking support.
            ->visible(fn()=>Staff::can(Staff::POLICY)&&Staff::can(Review::permission('enom_answer'))&&Answers::ready($op,app(Clock::class)->now())&&!\Paymenter\Extensions\Servers\Domains\Services\RegistrarCheck::scheduled($op))
            ->modalDescription('Record what '.$registrar.' support found for Request #'.$id.'. Saving sends nothing to '.$registrar.'.')
            ->modalSubmitActionLabel('Record answer')->schema([
                Hidden::make('review_key')->default(fn()=>Review::ticket((int)Auth::id(),$server,null,'enom_answer',$stamp)),
                Hidden::make('operation_id')->default($id)->required(),
                Hidden::make('attempt_hash')->default(fn()=>Answers::identity($op))->required(),
                Section::make(fn()=>$absence()!==null?'1. The result':'1. What '.$registrar.' support said')->contained(false)->schema([
                    Text::make(fn()=>self::absenceLine($op,$absence()))->key('absence')->visible(fn()=>$absence()!==null),
                    Select::make('outcome')->label('Result')->required()->live()->options($transfer
                        ?['transfer_rejected'=>'Rejected: the transfer will not complete']
                        :(self::accepted($op)
                            ? ['accepted'=>'Processed: support confirmed the order number']
                            : ['accepted'=>'Processed: '.$registrar.' made the order','not_processed'=>'Not processed: no order and no charge'])),
                    TextInput::make('order_reference')->default(fn()=>$transfer?($op->attempt['evidence']['transferReference']??null):Answers::capturedReference($op))->label(fn($get)=>$get('outcome')==='transfer_rejected'&&$op->registrar_key==='enom'?'Enom transfer number':'Order number')
                        ->helperText(fn($get)=>$get('outcome')==='not_processed'?'Leave blank when there was no order.':'As support gave it. Saving it sends no new order.')->maxLength(20),
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
                    TextInput::make('case_reference')->label('Case number')->required(fn($get)=>!$relaxed($get))->maxLength(128)
                        ->helperText(fn($get)=>$relaxed($get)?'Optional. The check above shows Enom has no order.':null),
                    Textarea::make('evidence')->label(fn($get)=>$relaxed($get)?'What you checked':'Their answer')->required()->maxLength(2000)
                        ->helperText(fn($get)=>$relaxed($get)?'Say what you checked. Leave out passwords and keys.':'Paste the reply from support. Leave out passwords and keys.'),
                    // One confirmation. On a transfer it also states the result and amounts are final, which the writer requires.
                    Checkbox::make('confirmed')->label(fn($get)=>$transfer?$registrar.' support confirmed this account and request, that the transfer cannot complete later, and that the charge and refund are final'
                            :($relaxed($get)?'I checked that Enom has no order for this request and the domain is unchanged':$registrar.' support checked this account and request, including whether an order or charge was made'))->accepted()->required(),
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
