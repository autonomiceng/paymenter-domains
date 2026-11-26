<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use Filament\Actions\Action;
use Paymenter\Extensions\Servers\Domains\Models\Operation;
use Paymenter\Extensions\Servers\Domains\Renewal\Execution\RenewalAttempt;
use Paymenter\Extensions\Servers\Domains\Staff\Requests\RequestReads;
use Paymenter\Extensions\Servers\Domains\Services\RegistrarAnswers;
/** The facts registrar support needs to find one request, as plain text for a support ticket. It reads only; nothing is sent. */
final class SupportDetails
{
    public const LABEL='Copy details for support';
    public static function text(Operation $op): string
    {
        $account=RequestReads::account($op);
        // A renewal's send time is its sealed attempt row; other actions keep it in the retained record.
        $attempt=$op->action!=='renew'&&RegistrarAnswers::readable($op)?($op->attempt??[]):[];
        try { $at=$op->action==='renew'?RenewalAttempt::of($op)->sentAt():(isset($attempt['dispatch_attempted_at'])?new \DateTimeImmutable((string)$attempt['dispatch_attempted_at']):null);
            $sent=$at?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s').' UTC'; }
        catch (\Throwable) { $sent=null; }
        $order=$op->provider_order_id??($op->action==='transfer'?($attempt['evidence']['transferReference']??null):RegistrarAnswers::capturedReference($op));
        $ask=$op->action==='transfer'?'Please confirm the final status of this transfer and any charge or refund on our account.'
            :'Please confirm whether this request created an order or charged our account.';
        $lines=[$ask,'',
            'Account: '.($account['value']??'Not found').($account['sub']!==null&&$account['value']!==null?' ('.$account['sub'].')':''),
            'Domain: '.($op->domain?->domain??'Not found'),
            'Action: '.Labels::action($op->action),
            'Request time (UTC): '.($sent??'Not recorded'),
            'Our reference: Request #'.$op->id,
            ...(is_string($order)&&$order!==''?['Order number: '.$order]:[])];
        return implode("\n",$lines);
    }
    /** Copies in the browser. The text rides in a data attribute, so no request data enters the click script.
     * Filament prints extra attributes unescaped, so the text is escaped here and the browser reads it back unchanged. */
    public static function action(Operation $op): Action
    {
        return Sheet::button(Action::make('copy_support_details')->label(self::LABEL)
            ->extraAttributes(['data-copy'=>e(self::text($op))])
            ->alpineClickHandler("(navigator.clipboard?.writeText(\$el.dataset.copy) ?? Promise.reject()).then(() => new FilamentNotification().title('Details copied').success().send()).catch(() => new FilamentNotification().title('Could not copy details').body('Your browser blocked the clipboard.').danger().send())"));
    }
}
