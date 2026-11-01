<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Mail;
use App\Models\EmailLog;
/** Native mail logging matches the exact class App\Mail\Mail, so its subclasses close their own Email Logs row. */
trait ClosesEmailLog
{
    public function send($mailer)
    {
        try { $sent=parent::send($mailer); }
        catch (\Throwable $e) { EmailLog::whereKey($this->email_log_id)->update(['status'=>'failed','error'=>$e->getMessage()]); throw $e; }
        EmailLog::whereKey($this->email_log_id)->update(['status'=>'sent','sent_at'=>now(),'error'=>null]);
        return $sent;
    }
}
