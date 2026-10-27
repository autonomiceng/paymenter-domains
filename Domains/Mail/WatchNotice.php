<?php
// Native shell/text seam adapted from Enom 444f31c; MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Mail;
use Illuminate\Mail\Mailables\Content;
use Illuminate\View\Compilers\BladeCompiler;
final class WatchNotice extends \App\Mail\Mail
{
    public function content(): Content
    {
        $html=(string)\Illuminate\Support\Str::markdown(BladeCompiler::render($this->data['body'],$this->data));
        $text=preg_replace_callback('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/si',
            static fn($m)=>strip_tags($m[2]).' ('.html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5,'UTF-8').')',$html);
        $text=html_entity_decode(strip_tags(preg_replace('/<\/(?:p|div|h[1-6]|td|tr)>|<br\s*\/?\s*>/i',"\n\n",$text)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        return new Content(html:'components.mail.base',text:'domains::mail-text',with:[...$this->data,'notice_text'=>trim(preg_replace('/\n[ \t]*\n(?:[ \t]*\n)+/',"\n\n",$text))]);
    }
}
