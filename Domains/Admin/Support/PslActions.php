<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Admin\Support;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\{Hidden,TextInput};
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\{DB,Route};
use Paymenter\Extensions\Servers\Domains\Admin\Pages\PublicSuffixList as Page;
use Paymenter\Extensions\Servers\Domains\Contracts\Clock;
use Paymenter\Extensions\Servers\Domains\Services\{PublicSuffixList as Lists,PslFailure,PslRebaseline,PslTransport};
/** The System checks row "Public suffix list": Download now, Review new list and Change source, plus a link to the full list page. */
final class PslActions
{
    public const VIEW=Page::VIEW;
    public const MANAGE=Page::MANAGE;
    public const HELP="Mozilla's list of TLDs and public suffixes (publicsuffix.org). Used to find the registrable domain in a hostname. Downloads weekly, at most once a day.";
    /** Fixed audit reason: using a reviewed list is not a money or destructive action, so staff type none. */
    public const REASON='Used the new list from System checks.';
    private static function manage(): bool { return Staff::can(self::VIEW)&&Staff::can(self::MANAGE); }
    private static function requireManage(): void { abort_unless(self::manage(),403); }
    private static function date(int $at): string { return gmdate('M j, Y H:i \U\T\C',$at); }
    /** Seconds-since-epoch of the next allowed download, or null when one is allowed now. */
    private static function limitedUntil(Lists $list): ?int
    { $at=$list->nextAllowedAt(); return $at!==null&&app(Clock::class)->now()->getTimestamp()<$at?$at:null; }
    /** @return array{label:string,value:?string,tone:?string,cause:?string,action:null,help:string,buttons:list<Action>} */
    public static function row(callable $refresh): array
    {
        $list=app(Lists::class);$s=$list->current();$status=$list->status();$review=app(PslRebaseline::class)->preview();$tone=null;$subs=[];
        if ($review!==null) { $value='Needs review';$tone='warning'; }
        elseif ($s===null) { $value='Not downloaded';$tone='danger'; }
        elseif (in_array(PslFacts::state($list),['Saved list damaged','Saved list needs review'],true)) { $value="Can't read saved list";$tone='danger'; }
        elseif (!$list->discoveryAllowed()) { $value='Out of date';$tone='danger'; }
        else {
            $value='Updated '.self::date((int)$s->validated_at);
            $subs[]='Out of date after '.self::date($list->expiresAt());
        }
        if ((int)$status->consecutive_failures>0&&$status->last_attempt_at!==null&&$review===null) { $tone??='warning';$subs[]='Last download failed '.self::date((int)$status->last_attempt_at); }
        if ($review===null&&($at=self::limitedUntil($list))!==null) { $subs[]='Next download allowed '.self::date($at); }
        return ['label'=>'Public suffix list','value'=>$value,'tone'=>$tone??'success','cause'=>$subs?implode(' · ',$subs):null,'action'=>null,'help'=>self::HELP,
            'buttons'=>[self::download($refresh),self::accept($refresh),self::source($refresh),
                Action::make('psl_details')->label('View details')->link()->size('sm')->url(fn()=>Page::getUrl())->visible(fn()=>Route::has(Page::getRouteName()))]];
    }
    public static function download(callable $refresh): Action
    {
        return Action::make('psl_download')->label('Download now')->color('primary')->size('sm')
            ->visible(fn()=>self::manage()&&app(PslRebaseline::class)->preview()===null&&self::limitedUntil(app(Lists::class))===null)
            ->action(function()use($refresh): void {
                self::requireManage();$code=app(Lists::class)->update();$refresh();
                $toast=Notification::make()->title(self::result($code));
                match(true){in_array($code,['updated','identical','not_modified'],true)=>$toast->success(),str_starts_with($code,'rules_lost_')=>$toast->warning(),default=>$toast->danger()};
                $toast->send();
            });
    }
    /** Replaces "Download now" while a download that removed many rules waits for review. */
    public static function accept(callable $refresh): Action
    {
        return Action::make('psl_accept')->label('Review new list')->color('primary')->size('sm')
            ->visible(fn()=>self::manage()&&app(PslRebaseline::class)->preview()!==null)
            ->modalHeading('Use new list?')->modalSubmitActionLabel('Use new list')
            ->modalDescription(function(): string {
                $p=app(PslRebaseline::class)->preview();
                return $p===null?'The download changed. Try again.':'The new list removes '.array_sum(array_column($p['removed'],'count')).' rules. Use it anyway?';
            })
            ->schema(function(): array {
                self::requireManage();$p=app(PslRebaseline::class)->preview();if($p===null){return [];}
                $removed=array_slice(array_merge(...array_values(array_column($p['removed'],'samples'))),0,20);
                return [Hidden::make('revision')->default($p['revision']),TextEntry::make('source')->label('Source')->state($p['source_url'])->inlineLabel(),
                    TextEntry::make('downloaded')->label('Downloaded')->state(self::date($p['fetched_at']))->inlineLabel(),
                    TextEntry::make('icann')->label('ICANN rules')->state($p['before_counts']['icann'].' → '.$p['after_counts']['icann'])->inlineLabel(),
                    TextEntry::make('private')->label('Private rules')->state($p['before_counts']['private'].' → '.$p['after_counts']['private'])->inlineLabel(),
                    TextEntry::make('removed')->label('Removed rules')->state($removed)->listWithLineBreaks()];
            })
            ->action(function(array $data)use($refresh): void {
                self::requireManage();$revision=is_string($data['revision']??null)?$data['revision']:'';
                try { app(PslRebaseline::class)->accept(auth()->user(),$revision,substr($revision,0,12),self::REASON); }
                catch (PslFailure $e) { $refresh();Notification::make()->title(self::result($e->safeCode))->danger()->send();return; }
                $refresh();Notification::make()->title('List updated')->success()->send();
            });
    }
    public static function source(callable $refresh): Action
    {
        return Action::make('psl_source')->label('Change source')->link()->size('sm')->visible(fn()=>self::manage())->tooltip(fn()=>app(Lists::class)->source())
            ->modalHeading('Change source?')->modalSubmitActionLabel('Save')
            ->schema([TextInput::make('url')->label('Source URL')->required()->maxLength(2048)->default(fn()=>app(Lists::class)->source())->helperText('Default: '.Lists::SOURCE)
                ->rules([fn()=>function($attribute,$value,$fail): void { if (!is_string($value)||!PslTransport::validUrl($value)) { $fail('Use an HTTPS URL.'); } }])])
            ->action(function(array $data)use($refresh): void {
                self::requireManage();abort_unless(PslTransport::validUrl($data['url']),422);
                DB::transaction(function()use($data): void {
                    // Serialize extension-wide settings with fetch admission; saving never starts a download.
                    $status=DB::table('domains_psl_status')->where('id',1)->lockForUpdate()->firstOrFail();self::requireManage();
                    if (app(Lists::class)->source()!==$data['url']) {
                        DB::table('domains_psl_status')->where('id',1)->update(['attempt_token'=>null,...($status->last_result==='checking'?['last_result'=>'superseded']:[])]);
                    }
                    Setting::updateOrCreate(['settingable_type'=>null,'settingable_id'=>null,'key'=>Lists::SETTING],['type'=>'string','value'=>$data['url']]);
                });
                $refresh();Notification::make()->title('Source saved')->success()->send();
            });
    }
    /** Toast title for a download or review result code. */
    public static function result(?string $code): string
    {
        return match($code){
            'rebaselined','updated'=>'List updated',
            'identical','not_modified'=>'List is already up to date',
            'candidate_changed','rebaseline_not_required','confirmation_invalid'=>'The download changed. Try again.',
            'stored_rules_rejected','stored_copy_invalid'=>"Can't read the saved list. Download it again.",
            null=>'Not downloaded','checking'=>'Downloading. Check back in a minute.',
            'superseded'=>'The source changed. Try again tomorrow.',
            'rate_limited'=>'Already downloaded today. Try again tomorrow.',
            'source_invalid'=>'Use an HTTPS URL.',
            'redirect_refused'=>"Couldn't download: the source redirected.",'timeout'=>"Couldn't download: timed out.",
            'endpoint_unsafe'=>"Couldn't download: the source address is not public.",'dns_failed'=>"Couldn't download: the source name didn't resolve.",
            'too_small','too_large','too_few_rules'=>"Couldn't download: the list size looks wrong.",
            'markers_order','markers_missing','rule_invalid','invalid_utf8'=>"Couldn't download: the list format is wrong.",
            'sentinel_missing'=>"Couldn't download: the list is missing rules.",
            'rules_lost_icann','rules_lost_private'=>'Many rules were removed. Review them before using this list.',
            'unexpected_304'=>"Couldn't download. Try again tomorrow.",
            'internal_error'=>"Couldn't download. Ask your site admin.",
            'parser_unavailable'=>"Couldn't download. Ask your site admin to add the PHP intl extension.",
            default=>"Couldn't download. Try again later.",
        };
    }
}
