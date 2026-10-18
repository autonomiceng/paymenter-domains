<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;

use App\Models\Server;

trait HasAccountBinding
{
    protected static function bootHasAccountBinding(): void
    {
        static::creating(static function ($record): void {
            $account = Account::where('server_id', $record->server_id)->first();
            $server = Server::find($record->server_id);
            if (!$account || !$server || !$server->enabled || $server->extension !== 'Domains'
                || $record->registrar_key !== $account->registrar_key
                || $record->account_fingerprint !== $account->account_fingerprint) {
                throw new \DomainException('Record requires its accepted registrar account binding.');
            }
            if ($record instanceof Operation) {
                $domain = Domain::find($record->domain_id);
                if (!$domain || (int) $record->authority_generation < 1
                    || (int) $record->authority_generation !== (int) $domain->authority_generation
                    || (int) $record->service_id !== (int) $domain->service_id
                    || (int) $record->server_id !== (int) $domain->server_id
                    || $record->account_fingerprint !== $domain->account_fingerprint
                    || $record->registrar_key !== $domain->registrar_key) {
                    throw new \DomainException('Operation requires the frozen domain binding and generation.');
                }
            }
        });
        static::updating(static function ($record): void {
            $fields = ['id', 'server_id', 'registrar_key', 'account_fingerprint'];
            if ($record instanceof Domain) { $fields = [...$fields, 'service_id', 'user_id']; }
            if ($record instanceof Operation) { $fields = [...$fields, 'domain_id', 'service_id', 'authority_generation']; }
            if ($record->isDirty($fields)) { throw new \DomainException('Registrar record binding is immutable.'); }
        });
    }
}
