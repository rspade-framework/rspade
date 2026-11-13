<?php

namespace Rsx\Emails;

use App\RSpade\Core\Api\Api_Key_Event;
use App\RSpade\Core\Api\Api_Scopes;
use App\RSpade\Core\Time\Rsx_Time;
use App\RSpade\Lib\Geo\Ip\Rsx_Geo_Ip;
use App\RSpade\Lib\Messaging\Mail\Rsx_Email_Abstract;

/**
 * Api_Key_Notice_Email - "an API key was created on your account" / "... was revoked", to the
 * holder's own sign-in address. Sent by Api_Key_Audit_Service from the framework's
 * api_key.created / api_key.revoked events (Api_Key_Event_Handlers) for every key that is
 * neither a CLI temporary key nor an /apidocs tester key (usable only from the browser session
 * that minted it, so there is nothing to warn about), whoever acted: the holder, an administrator, or the command line.
 *
 * It names the key, says when, by whom and from which address (with the address's location,
 * resolved here as the message is composed - rsx:man geo_ip, rule 1 - and the DB-IP credit
 * in the footer when one is printed), for a creation what the key may do, and what to do if it
 * was not them.
 *
 * SECURITY: a warning that somebody acted on the recipient's own account, so it ignores the
 * recipient opt-out and the site block list and carries no unsubscribe link (the builder
 * passes none for this category, so the shared layout's footer has nothing to offer).
 */
class Api_Key_Notice_Email extends Rsx_Email_Abstract
{
    const CATEGORY = self::SECURITY;

    /** $event for a creation; a revocation passes Api_Key_Event::HOW_REVOKED or HOW_DELETED. */
    const EVENT_CREATED = 'created';

    /** $by when the holder did it themself. */
    const BY_YOU = 'you';

    /** $by when nobody was signed in (a command). */
    const BY_COMMAND_LINE = 'command_line';

    /**
     * @param string $email The holder's sign-in address
     * @param string $event EVENT_CREATED, Api_Key_Event::HOW_REVOKED or HOW_DELETED
     * @param string $name The key's name
     * @param string $key_prefix The key's display prefix (never the key)
     * @param bool $read_only The key may execute GET requests only
     * @param string|null $scopes The key's scope text; null = unrestricted
     * @param string|null $expires_at When the key stops working by itself (ISO); null = never
     * @param string $occurred_at When it happened (ISO)
     * @param string|null $timezone The zone to print times in; null = the default
     * @param string $by BY_YOU, BY_COMMAND_LINE, or the acting administrator's address
     * @param string|null $ip_address Where it was done from; null when no address was recorded
     */
    public function __construct(
        public string $email,
        public string $event,
        public string $name,
        public string $key_prefix,
        public bool $read_only,
        public ?string $scopes,
        public ?string $expires_at,
        public string $occurred_at,
        public ?string $timezone,
        public string $by,
        public ?string $ip_address
    ) {
    }

    public function subject(): string
    {
        $app_name = config('rsx.name', 'RSpade');

        return $this->event === self::EVENT_CREATED
            ? "An API key was created on your {$app_name} account"
            : "An API key was revoked on your {$app_name} account";
    }

    /**
     * The kind of notice on the queue row: created, revoked or deleted.
     */
    public function tag(): ?string
    {
        return $this->event;
    }

    public function data(): array
    {
        $is_created = $this->event === self::EVENT_CREATED;

        return [
            'app_name' => config('rsx.name', 'RSpade'),
            'email' => $this->email,
            'is_created' => $is_created,
            'what_happened' => match ($this->event) {
                self::EVENT_CREATED => 'was created',
                Api_Key_Event::HOW_DELETED => 'was revoked and deleted',
                default => 'was revoked; it no longer works',
            },
            'name' => $this->name,
            'key_prefix' => $this->key_prefix,
            'by_whom' => match ($this->by) {
                self::BY_YOU => 'You, signed in to your account',
                self::BY_COMMAND_LINE => 'An operator, from the command line',
                default => 'An administrator, ' . $this->by,
            },
            'occurred_when' => Rsx_Time::format_timestamp($this->occurred_at, $this->timezone),
            'ip_address' => $this->ip_address,
            // Resolved now, as the message is composed: the message states where the address
            // was when it was sent, like its timestamp (rsx:man geo_ip, rule 1).
            'ip_address__location' => $this->ip_address === null ? null : Rsx_Geo_Ip::label($this->ip_address),
            'access' => $this->read_only ? 'Read-only (GET requests only)' : 'Read and write',
            'scope_lines' => Api_Scopes::is_unrestricted($this->scopes)
                ? []
                : Api_Scopes::parse_all($this->scopes)['valid'],
            'expires_when' => $this->expires_at === null ? null : Rsx_Time::format_timestamp($this->expires_at, $this->timezone),
        ];
    }

    public static function sample(): static
    {
        return new static(
            'someone@example.com',
            self::EVENT_CREATED,
            'Nightly export',
            'rsx_live_AbCd...',
            true,
            "/api/v1/contacts/*",
            null,
            '2026-11-12T14:30:00.000Z',
            null,
            self::BY_YOU,
            '203.0.113.7'
        );
    }
}
