<?php
// MIT, Copyright (c) 2026 Autonomic Engineering.
declare(strict_types=1);
namespace Paymenter\Extensions\Servers\Domains\Models;
use Paymenter\Extensions\Servers\Domains\Dns\RoutingProfile;
/** Routing rules edited through the scoped staff action; discovery remains default off. */
final class WatchProfile extends WatchRecord
{
    protected $table = 'domains_watch_profiles';
    protected $attributes = ['revision' => 1, 'enabled' => false];
    protected array $identity = ['id'];
    protected $casts = ['revision' => 'integer', 'enabled' => 'boolean', 'nameservers' => 'array', 'website_endpoints' => 'array', 'email_endpoints' => 'array'];
    protected function validateRecord(): void
    {
        if (!is_string($this->name) || trim($this->name) === '' || strlen($this->name) > 128 ||
            !is_string($this->reason) || trim($this->reason) === '' || strlen($this->reason) > 1000 || (int) $this->edited_by < 1) {
            throw new \DomainException('A profile name, actor and reason are required.');
        }
        $profile = $this->profile();
        $this->nameservers = $profile->nameservers; $this->website_endpoints = $profile->website; $this->email_endpoints = $profile->email;
    }
    public function profile(): RoutingProfile { return new RoutingProfile($this->nameservers, $this->website_endpoints, $this->email_endpoints); }
    protected static function booted(): void
    {
        parent::booted();
        static::saved(static function (self $row): void {
            WatchAudit::create(['subject_type' => 'dns_profile', 'subject_id' => $row->id, 'actor_id' => $row->edited_by,
                'event' => 'dns_profile_saved', 'reason' => $row->reason, 'before_revision' => (string) ($row->revision - 1),
                'after_revision' => (string) $row->revision, 'data' => ['nameservers' => $row->nameservers, 'website' => $row->website_endpoints,
                    'email' => $row->email_endpoints, 'enabled' => $row->enabled], 'created_at' => now()]);
        });
    }
}
