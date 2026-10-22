<?php
/**
 * CODING CONVENTION:
 * This file follows the coding convention where variable_names and function_names
 * use snake_case (underscore_wherever_possible).
 */

namespace Rsx\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\RSpade\Core\Ajax\Ajax;
use App\RSpade\Core\Dispatch\Rsx_Request_Channel;
use App\RSpade\Core\Models\Email_Queue_Model;
use App\RSpade\Core\Models\Login_User_Model;
use App\RSpade\Core\Models\Portal_User_Model;
use App\RSpade\Core\Models\Site_Model;
use App\RSpade\Core\Models\User_Model;
use App\RSpade\Core\Portal\Portal_Session;
use App\RSpade\Core\Portal\Rsx_Portal;
use App\RSpade\Core\Response\Error_Response;
use App\RSpade\Core\Testing\Rsx_Test_Abstract;
use Rsx\App\Frontend\Clients\Frontend_Clients_Controller;
use Rsx\App\Frontend\Contacts\Frontend_Contacts_Controller;
use Rsx\App\Frontend\Settings\SiteSettings\Frontend_Settings_Site_Settings_Controller;
use Rsx\App\Login\Signup\Signup_Controller;
use Rsx\Models\Client_Model;
use Rsx\Models\Contact_Model;
use Rsx\Models\Portal_Invitation_Model;

/**
 * This application on a per-site client portal (rsx:man portal).
 *
 *   A SITE WITH NO PORTAL SENDS NO PORTAL LINK. Every staff endpoint that would email or
 *   hand out a portal link - the bulk invite, the resend, View as Client - answers "This site
 *   has no client portal." as a validation error and writes and sends nothing, whether the
 *   portal is off for the install (PORTAL_ENABLED) or for the site (sites.is_portal_enabled).
 *   A link it does send is built for the RECORD's site.
 *
 *   SITE SETTINGS save the site's portal switch and, when PORTAL_CUSTOM_DOMAINS is not
 *   'off', its custom domain - normalised, refused when it is not a hostname, refused when
 *   another site holds it - and show the portal address as it is served.
 *
 *   SELF-SIGNUP gives a new site a slug read from its name: a DNS label, made unique with a
 *   numeric suffix.
 *
 * Multi tenancy is exercised through config overrides (the dev box stays single). Fixtures
 * that toggle a site's portal are fresh sites, never site 1: the portal's site lookups are
 * cached outside the test transaction.
 */
class Portal_Tenancy_App_Test extends Rsx_Test_Abstract
{
    /** View as Client moves a browser's portal sign-in, so every test acts as a browser. */
    protected static $acts_as_browser = true;

    private const SITE_ID = 1;

    private const NO_PORTAL = 'This site has no client portal.';

    private static array $saved_config = [];

    public static function setup(): void
    {
        self::$saved_config = [
            'rsx.tenancy' => config('rsx.tenancy'),
            'rsx.portal.url' => config('rsx.portal.url'),
            'rsx.portal.enabled' => config('rsx.portal.enabled'),
            'rsx.portal.custom_domains' => config('rsx.portal.custom_domains'),
        ];
    }

    public static function teardown(): void
    {
        config(self::$saved_config);
        Rsx_Request_Channel::reset();
        static::__reset_session();
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    /**
     * Multi tenancy with a path template; the rest of the portal settings at their defaults.
     */
    private static function __multi(array $settings = []): void
    {
        config(array_merge([
            'rsx.tenancy' => 'multi',
            'rsx.portal.url' => '/_portal/:slug',
            'rsx.portal.enabled' => true,
            'rsx.portal.custom_domains' => 'off',
        ], $settings));
        Rsx_Request_Channel::reset();
    }

    private static function __site(bool $is_portal_enabled = true): Site_Model
    {
        $site = new Site_Model();
        $site->name = 'Tenancy fixture';
        $site->slug = 'tenancy-' . strtolower(random_hash(10));
        $site->is_enabled = 1;
        $site->is_portal_enabled = $is_portal_enabled;
        $site->save();

        return $site;
    }

    private static function __client(): Client_Model
    {
        $client = new Client_Model();
        $client->name = 'Tenancy client ' . uniqid();
        $client->portal_enabled = true;
        $client->save();

        return $client;
    }

    private static function __contact(Client_Model $client): Contact_Model
    {
        $contact = new Contact_Model();
        $contact->client_id = $client->id;
        $contact->first_name = 'Invited';
        $contact->last_name = 'Person';
        $contact->email = 'tenancy_' . uniqid() . '@example.com';
        $contact->is_active = true;
        $contact->save();

        return $contact;
    }

    private static function __assert_no_portal(mixed $result, string $what): void
    {
        static::__assert_instance_of(Error_Response::class, $result, "{$what} is refused");
        static::__assert_equals(Ajax::ERROR_VALIDATION, $result->get_error_code(), "{$what}: a validation error");
        static::__assert_equals(self::NO_PORTAL, $result->get_metadata()['_message'] ?? null, "{$what}: says why");
    }

    private static function __emails_to(string $address): int
    {
        return Email_Queue_Model::without_site_scope(fn () => Email_Queue_Model::where('to_address', $address)->count());
    }

    // =====================================================================
    // Staff endpoints on a site with no portal
    // =====================================================================

    public static function test_a_site_with_its_portal_switched_off_sends_no_invitation()
    {
        static::__multi();
        $site = static::__site(false);
        static::__acting_as_site($site->id);

        $client = static::__client();
        $contact = static::__contact($client);

        $result = Frontend_Clients_Controller::portal_bulk_invite(new Request(), [
            'client_id' => $client->id,
            'contact_ids' => [$contact->id],
        ]);

        static::__assert_no_portal($result, 'the bulk invite');
        static::__assert_count(0, Portal_Invitation_Model::get_pending_for_client($client->id), 'no invitation was written');
        static::__assert_equals(0, static::__emails_to($contact->email), 'nothing was sent');

        $members = Frontend_Clients_Controller::portal_members(new Request(), ['id' => $client->id]);
        static::__assert_false($members['has_portal'], 'the panel is told the site has no portal');
    }

    public static function test_a_resend_is_refused_and_writes_nothing()
    {
        static::__multi();
        $site = static::__site(false);
        static::__acting_as_site($site->id);

        $client = static::__client();
        $contact = static::__contact($client);
        $invitation = Portal_Invitation_Model::create_invitation(
            (int) $site->id,
            $contact->email,
            ['contact_id' => (int) $contact->id, 'client_id' => (int) $client->id, 'role_id' => 1]
        );
        $expires_at = $invitation->fresh()->expires_at;

        static::__assert_false($invitation->get_invitation_url(), 'the invitation has no link');

        $result = Frontend_Clients_Controller::portal_resend_invite(new Request(), [
            'client_id' => $client->id,
            'contact_id' => $contact->id,
        ]);

        static::__assert_no_portal($result, 'the resend');
        static::__assert_equals($expires_at, $invitation->fresh()->expires_at, 'the expiry was not refreshed');
        static::__assert_equals(0, static::__emails_to($contact->email), 'nothing was sent');
    }

    public static function test_the_portal_switched_off_for_the_install_refuses_an_invite_in_single_tenancy()
    {
        config(['rsx.tenancy' => 'single', 'rsx.portal.enabled' => false]);
        static::__acting_as_site(self::SITE_ID);

        $client = static::__client();
        $contact = static::__contact($client);

        $result = Frontend_Clients_Controller::portal_bulk_invite(new Request(), [
            'client_id' => $client->id,
            'contact_ids' => [$contact->id],
        ]);

        static::__assert_no_portal($result, 'the bulk invite');
        static::__assert_equals(0, static::__emails_to($contact->email), 'nothing was sent');
    }

    public static function test_an_invitation_link_is_built_for_the_records_site()
    {
        static::__multi();
        $site = static::__site();
        static::__acting_as_site($site->id);

        $client = static::__client();
        $contact = static::__contact($client);

        $result = Frontend_Clients_Controller::portal_bulk_invite(new Request(), [
            'client_id' => $client->id,
            'contact_ids' => [$contact->id],
        ]);

        static::__assert_equals(1, $result['invited_new'] ?? null, 'the contact was invited');
        static::__assert_equals(1, static::__emails_to($contact->email), 'one email was queued');

        $invitation = Portal_Invitation_Model::get_pending_for_client($client->id)->first();
        static::__assert_contains('/_portal/' . $site->slug . '/register', (string) $invitation->get_invitation_url(), "the link names the site's portal");
    }

    public static function test_view_as_client_is_refused_without_a_portal()
    {
        config(['rsx.tenancy' => 'single', 'rsx.portal.enabled' => false]);
        Rsx_Portal::set_portal_request(false);
        static::__acting_as_site(self::SITE_ID);

        $email = 'tenancy_staff_' . uniqid() . '@example.com';
        $login_user = new Login_User_Model();
        $login_user->email = $email;
        $login_user->password = Hash::make('secret-password');
        $login_user->is_activated = true;
        $login_user->is_verified = true;
        $login_user->status_id = Login_User_Model::STATUS_ACTIVE;
        $login_user->save();

        $user = new User_Model();
        $user->login_user_id = $login_user->id;
        $user->email = $email;
        $user->first_name = 'Tenancy';
        $user->last_name = 'Staff';
        $user->role_id = User_Model::ROLE_MANAGER;
        $user->is_enabled = 1;
        $user->save();

        static::__acting_as_user($user->id);

        $contact = static::__contact(static::__client());

        $portal_user = new Portal_User_Model();
        $portal_user->site_id = self::SITE_ID;
        $portal_user->email = $contact->email;
        $portal_user->set_password('secret-password');
        $portal_user->is_verified = true;
        $portal_user->status_id = Portal_User_Model::STATUS_ACTIVE;
        $portal_user->contact_id = $contact->id;
        $portal_user->save();

        $result = Frontend_Contacts_Controller::begin_portal_impersonation(new Request(), ['id' => $contact->id]);

        static::__assert_no_portal($result, 'View as Client');
        static::__assert_false(Portal_Session::is_impersonating(), 'nothing was started');
    }

    // =====================================================================
    // Site Settings
    // =====================================================================

    public static function test_site_settings_save_the_portal_switch_and_show_the_address()
    {
        static::__multi();
        $site = static::__site();
        static::__acting_as_site($site->id);

        $settings = Frontend_Settings_Site_Settings_Controller::get_settings(new Request(), []);
        static::__assert_equals('/_portal/' . $site->slug, parse_url((string) $settings['portal_url'], PHP_URL_PATH), 'the address is shown');
        static::__assert_equals('1', $settings['form_data']['is_portal_enabled']);
        static::__assert_false(array_key_exists('portal_domain', $settings['form_data']), 'no domain field while custom domains are off');

        $saved = Frontend_Settings_Site_Settings_Controller::update(new Request(), [
            'name' => 'Renamed',
            'is_portal_enabled' => '0',
        ]);
        static::__assert_equals('Renamed', $saved['name'] ?? null);
        static::__assert_false((bool) $site->fresh()->is_portal_enabled, 'the switch was saved');

        $settings = Frontend_Settings_Site_Settings_Controller::get_settings(new Request(), []);
        static::__assert_null($settings['portal_url'], 'no client portal');

        // Custom domains off: a submitted domain is not written.
        Frontend_Settings_Site_Settings_Controller::update(new Request(), [
            'name' => 'Renamed',
            'portal_domain' => 'ignored.example.test',
        ]);
        static::__assert_null($site->fresh()->portal_domain, 'a domain is not written while custom domains are off');
    }

    public static function test_site_settings_validate_the_custom_domain()
    {
        static::__multi(['rsx.portal.custom_domains' => 'override']);
        $other = static::__site();
        $other->portal_domain = 'taken-' . strtolower(random_hash(8)) . '.example.test';
        $other->save();

        $site = static::__site();
        static::__acting_as_site($site->id);

        $settings = Frontend_Settings_Site_Settings_Controller::get_settings(new Request(), []);
        static::__assert_true($settings['custom_domains_enabled']);
        static::__assert_equals('', $settings['form_data']['portal_domain']);
        static::__assert_equals(Site_Model::field_length('portal_domain'), $settings['portal_domain_max_length']);

        $invalid = Frontend_Settings_Site_Settings_Controller::update(new Request(), [
            'name' => 'Domains',
            'portal_domain' => 'not a hostname',
        ]);
        static::__assert_instance_of(Error_Response::class, $invalid, 'a non-hostname is refused');
        static::__assert_array_has_key('portal_domain', $invalid->get_metadata(), 'on the domain field');

        $duplicate = Frontend_Settings_Site_Settings_Controller::update(new Request(), [
            'name' => 'Domains',
            'portal_domain' => strtoupper($other->portal_domain),
        ]);
        static::__assert_instance_of(Error_Response::class, $duplicate, "another site's domain is refused");
        static::__assert_contains('Another site', (string) ($duplicate->get_metadata()['portal_domain'] ?? ''));
        static::__assert_null($site->fresh()->portal_domain, 'nothing was written');

        $domain = 'mine-' . strtolower(random_hash(8)) . '.example.test';
        Frontend_Settings_Site_Settings_Controller::update(new Request(), [
            'name' => 'Domains',
            'portal_domain' => 'https://' . strtoupper($domain) . '/login',
        ]);
        static::__assert_equals($domain, $site->fresh()->portal_domain, 'a valid domain is saved normalised');

        $settings = Frontend_Settings_Site_Settings_Controller::get_settings(new Request(), []);
        static::__assert_equals('https://' . $domain, $settings['portal_url'], 'the portal is served on it');

        Frontend_Settings_Site_Settings_Controller::update(new Request(), ['name' => 'Domains', 'portal_domain' => '']);
        static::__assert_null($site->fresh()->portal_domain, 'a blank domain clears it');
    }

    // =====================================================================
    // Self-signup slug
    // =====================================================================

    public static function test_a_signup_slug_is_read_from_the_site_name()
    {
        $tag = strtolower(random_hash(6));

        static::__assert_equals("acme-sons-ltd-{$tag}", Signup_Controller::site_slug_for("  Acme & Sons, Ltd. {$tag}!"));
        static::__assert_equals("cafe-munchen-{$tag}", Signup_Controller::site_slug_for("Café München {$tag}"));
        static::__assert_equals('site', Signup_Controller::site_slug_for('!!! ---'), 'nothing usable gives "site"');

        $long = Signup_Controller::site_slug_for(str_repeat('abcdefghij ', 12) . $tag);
        static::__assert_true(strlen($long) <= 63, 'at most 63 characters');
        static::__assert_true((bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $long), "a DNS label: {$long}");
    }

    public static function test_a_signup_slug_takes_the_first_free_suffix()
    {
        $name = 'Suffix Works ' . strtolower(random_hash(6));
        $base = Signup_Controller::site_slug_for($name);

        foreach ([$base, $base . '-2'] as $slug) {
            $site = new Site_Model();
            $site->name = $name;
            $site->slug = $slug;
            $site->is_enabled = 1;
            $site->save();
        }

        static::__assert_equals($base . '-3', Signup_Controller::site_slug_for($name), 'the first free suffix');

        // A deleted site still holds its slug (the unique index covers it).
        Site_Model::where('slug', $base . '-2')->first()->delete();
        static::__assert_equals($base . '-3', Signup_Controller::site_slug_for($name), 'a deleted site keeps its slug');

        // A base at the length limit is shortened to make room for its suffix.
        $long_name = str_repeat('z', 70) . strtolower(random_hash(4));
        $long_base = Signup_Controller::site_slug_for($long_name);
        static::__assert_equals(63, strlen($long_base));

        $site = new Site_Model();
        $site->name = $long_name;
        $site->slug = $long_base;
        $site->is_enabled = 1;
        $site->save();

        $next = Signup_Controller::site_slug_for($long_name);
        static::__assert_equals(63, strlen($next), 'still 63 characters');
        static::__assert_true(str_ends_with($next, '-2'), "suffixed: {$next}");
    }
}
