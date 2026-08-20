# Flash Alert System

The Flash system provides server-to-client messaging that persists across redirects and Ajax calls, with client-side queue persistence for seamless navigation.

## Architecture Overview

**Data Flow:**
1. PHP code creates flash message → stored in database with type_id, session_id and is_portal
2. Message expires after 1 minute if not rendered
3. On page render OR Ajax response → messages retrieved and deleted from database
4. Client receives messages via `window.rsxapp.flash_alerts` or `response.flash_alerts`
5. `Server_Side_Flash.js` processes messages → calls `Flash_Alert.js` display methods
6. Client queue persisted to sessionStorage (per-tab) → survives page navigations
7. On page load → restore queue from sessionStorage (messages < 20 seconds old)

**Two contexts, two behaviors:** a web request queues against the browser's session AND
the experience it is serving, and a console process does not queue at all (it prints).
See SESSION / EXPERIENCE AWARENESS and CLI CONTRACT below.

## PHP Side - Creating Flash Messages

### Basic Usage

```php
use App\RSpade\Lib\Flash\Flash_Alert;

// In controllers
Flash_Alert::success('Account created successfully!');
Flash_Alert::error('Invalid email address');
Flash_Alert::info('Your session will expire in 5 minutes');
Flash_Alert::warning('This action cannot be undone');
```

### How It Works

**Storage:**
- Messages stored in the `_flash_alerts` table with `type_id` (enum), `message`, `session_id`
  and `is_portal` (the experience that queued it)
- Uses integer enum: 1=success, 2=error, 3=info, 4=warning (see Flash_Alert_Model)
- `session_id` is an FK to `_sessions` with `ON DELETE CASCADE` - alerts genuinely die
  with the session that queued them
- Queueing an alert CREATES a session if the visitor does not have one yet (the browser
  has to come back for the message)

**Retrieval:**
- `Flash_Alert::get_pending_messages()` retrieves all pending messages for the current
  session AND experience
- Automatically deletes expired messages (> 1 minute old) before retrieval
- Automatically deletes retrieved messages (one-time display)
- Returns array: `[['type' => 'success', 'message' => '...'], ...]`
- Returns the WHOLE set, never a page of it - the bound lives on the writer (see the cap below)

**Automatic Integration:**
- Page rendering: Added to `window.rsxapp.flash_alerts` in bundle render
  (`Rsx_Bundle_Abstract::_build_rsxapp_data`) - serves both experiences, since every page
  render builds its rsxapp payload there
- Ajax responses: Added to `response.flash_alerts` in both the success and error
  response builders in `Ajax.php` - portal Ajax rides the same transport, so a portal
  endpoint's flash is delivered by the same code

## SESSION / EXPERIENCE AWARENESS

A flash alert is scoped by TWO things, because they answer two different questions.

**`session_id` - which BROWSER.** One browser has one session (one `rsx` cookie, one
`_sessions` row), shared by the staff app and the portal. This is what keeps one browser's
alerts out of another browser's page. It cannot say which experience queued a row, because
it is the same value in both.

**`is_portal` - which EXPERIENCE.** Stamped on write from `Rsx_Portal::is_portal_request()`
(the same signal `@csrf`, `Auth_Gates` and the rsxapp payload use), filtered on read. A
portal page delivers only portal-queued alerts; a staff page only staff-queued ones. Both
tabs can be open on the same browser session, and neither steals the other's messages.

| Context | Write | Read |
|---|---|---|
| Portal request | `Portal_Session::get_session_id()`, `is_portal = 1` | `Portal_Session::has_session()` → `get_session_id()`, filtered `is_portal = 1` |
| Staff request | `Session::get_session_id()`, `is_portal = 0` | `Session::has_session()` → `get_session_id()`, filtered `is_portal = 0` |

The write still resolves the session through the request's own FACADE (not just to pick a
session id - both facades return the same one - but because the portal facade is where the
portal's site contract lives, and it refuses to invent a site).

The read asks `has_session()`, not `get_session_id()`, to decide whether anything is
pending: `get_session_id()` would create a session for an anonymous visitor who has never
triggered activation. `has_session()` resolves the browser's `rsx` cookie and creates nothing.

The one-minute read-path expiry carries the experience predicate too - a staff read has no
business deleting the portal's rows, stale or not. The hourly retention task is the sweep
that IS experience-blind (see RETENTION).

The per-session cap is keyed on the same pair, `(session_id, is_portal)`. That is not
decoration: the cap EVICTS, so a shared cap would let a portal page that queues 50 alerts
silently delete the staff alerts sitting beside them on the same browser session.

## CLI CONTRACT

In console context (tasks, tests, artisan commands) a flash alert:

- does NOT create a session
- does NOT write the database
- does NOT read the database (`get_pending_messages()` returns `[]` without querying)
- IS written to **STDERR** as `[FLASH:LEVEL] message` (plain ASCII, no color; STDOUT
  stays clean for the command's actual result)
- error and warning ALSO reach the Laravel log (`Log::error` / `Log::warning`); success
  and info are terminal-only

This is deliberate policy, not a limitation. The guard predates CLI sessions and used to
be a consequence of there being no session to attach to; a CLI process now mints a real
`_sessions` row on demand, and flash still refuses to use it. A flash alert is a message
for a browser that is about to render; a command has an operator reading its output right
now, and that terminal is the honest delivery channel (owner ruling 2026-08-09).

## RETENTION

Two mechanisms, because the first one only reaches sessions that come back:

1. **Read-path expiry (1 minute).** Every read sweeps that session's alerts older than a
   minute before returning the rest. Session-scoped by construction.
2. **`Flash_Alert_Cleanup_Service::cleanup_expired_alerts`** - hourly `#[Exclusive]` task
   deleting every row older than 30 minutes, on any session, in EITHER experience (an
   abandoned alert is abandoned whichever page queued it - `is_portal` routes delivery, it
   has nothing to say about retention). A visitor handed an alert who
   never comes back leaves the row behind, and without this it would sit there until their
   session is deleted months later by the FK cascade. Chunked DELETEs; a raw age-bounded
   statement, since `Flash_Alert_Model` has no realtime or lifecycle-hook surface to fire.

## THE PER-SESSION CAP

`rsx.flash.max_alerts_per_session` (default 50; 0/null disables) bounds what ONE
EXPERIENCE of one session may hold - the key is `(session_id, is_portal)` - enforced at the
WRITER. It cannot live on the read: the read hands the whole set to the browser and deletes
it, so a LIMIT there would silently drop alerts the user was meant to see. When a queue
exceeds the cap the OLDEST overflow is dropped - the newest alerts describe what the user
just did. The experience half of the key is what stops a runaway portal page from evicting
the staff alerts on the same browser session.

## JavaScript Side - Displaying Flash Messages

### Client Components

**Flash_Alert.js** (`/system/app/RSpade/Lib/Flash/Flash_Alert.js`):
- Client-side display component with queue system, auto-dismiss, animations
- Methods: `Flash_Alert.success()`, `.error()`, `.info()`, `.warning()`
- Features: 2.5s queue spacing, auto-dismiss (4s success, 6s others), click-to-dismiss
- Queue persistence: Saves state to sessionStorage on queue changes, restores on page load
- Stale message filtering: Only restores messages < 20 seconds old
- Styling: Bootstrap alert classes with icons

**Server_Side_Flash.js** (`/system/app/RSpade/Lib/Flash/Server_Side_Flash.js`):
- Bridge between server data and Flash_Alert display
- Processes `flash_alerts` arrays from server
- Restores persisted queue state from sessionStorage on framework init
- Called automatically on page load (framework init hook)
- Called automatically on Ajax responses (Ajax.js:190)

### How Client Processing Works

**Page Load:**
```javascript
// Automatic via _on_framework_core_init() hook
// 1. Restore persisted queue from sessionStorage (only fresh messages)
Flash_Alert._restore_queue_state();

// 2. Process new server messages
if (window.rsxapp && window.rsxapp.flash_alerts) {
    Server_Side_Flash.process(window.rsxapp.flash_alerts);
}
```

**Ajax Responses:**
```javascript
// Automatic in Ajax.js success handler
if (response.flash_alerts && Array.isArray(response.flash_alerts)) {
    Server_Side_Flash.process(response.flash_alerts);
}
```

**Processing Logic:**
```javascript
Server_Side_Flash.process(flash_alerts) {
    flash_alerts.forEach(alert => {
        const method = alert.type; // 'success', 'error', 'info', 'warning'
        Flash_Alert[method](alert.message); // Calls Flash_Alert.success(), etc.
    });
}
```

## Database Schema

**Table:** `_flash_alerts`

```sql
CREATE TABLE `_flash_alerts` (
    `id` BIGINT NOT NULL AUTO_INCREMENT,
    `session_id` BIGINT DEFAULT NULL,
    `is_portal` TINYINT(1) NOT NULL DEFAULT 0,   -- the experience that queued it
    `type_id` BIGINT NOT NULL,  -- 1=success, 2=error, 3=info, 4=warning
    `message` LONGTEXT NOT NULL,
    `created_at` TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3),
    `created_by_id` BIGINT DEFAULT NULL,
    `created_by_type` BIGINT DEFAULT NULL,
    `updated_by_id` BIGINT DEFAULT NULL,
    `updated_by_type` BIGINT DEFAULT NULL,
    `updated_at` TIMESTAMP(3) NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_session_id` (`session_id`),
    KEY `created_at` (`created_at`),
    KEY `updated_at` (`updated_at`),
    CONSTRAINT `flash_alerts_session_fk` FOREIGN KEY (`session_id`)
        REFERENCES `_sessions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`session_id` points at `_sessions` - one row per browser, shared by both experiences.
This is the only session FK in the schema, and it is correct: a flash alert genuinely
dies with its session. `is_portal` is the experience discriminator the shared session
cannot supply. No index over the pair: `idx_session_id` already narrows to one session,
whose rows are capped at `rsx.flash.max_alerts_per_session` per experience and live at
most a minute.

**Model:** `Flash_Alert_Model` with type_id enum definition. No `$realtime`, no
lifecycle-hook overrides - a bulk write on this model is one raw statement by design.

## Common Use Cases

**Post-Redirect Flash:**
```php
public static function create_account(Request $request, array $params = []) {
    $user = new User_Model();
    $user->email = $request->input('email');
    $user->save();

    Flash_Alert::success('Account created successfully!');
    return redirect(Rsx::Route('Dashboard_Controller'));
}
```

**Ajax Error Handling:**
```php
#[Ajax_Endpoint]
#[Auth('can_manage_site_settings')]
public static function save_settings(Request $request, array $params = []) {
    if (!$request->input('email')) {
        Flash_Alert::error('Email is required');
        return response_form_error('Validation failed', ['email' => 'Required']);
    }

    // Save settings...
    Flash_Alert::success('Settings saved!');
    return ['saved' => true];
}
```

**Multi-Step Workflows:**
```php
// Step 1
Flash_Alert::info('Please verify your email before continuing');
return redirect(Rsx::Route('Verify_Email_Controller'));

// Step 2 (after email verified)
Flash_Alert::success('Email verified! Your account is now active');
return redirect(Rsx::Route('Dashboard_Controller'));
```

**Portal Flash (identical API):**
```php
// rsx/portal/auth/Portal_Login_Controller.php - no portal-specific call exists
Portal_Session::set_portal_user_id($portal_user->id);
Flash_Alert::success('Welcome to the Client Portal!');
return redirect(static::_post_auth_destination($portal_user, $client_id));
```
The experience is resolved from the request, so portal code writes flash alerts exactly the
way staff code does. There is no `Portal_Flash_Alert` and there never should be.

## Key Design Decisions

**Why 1-minute expiration?**
- Prevents stale messages from appearing hours/days later
- Covers normal redirect timing (< 1 second)
- Covers slow network conditions
- Aggressive cleanup keeps database small

**Why delete on retrieval?**
- One-time display semantics (flash = temporary)
- Prevents duplicate display on page refresh
- Simpler than tracking "displayed" status

**Why session-based?**
- Links messages to a specific browser session
- Automatic cleanup when the session is deleted (FK cascade)
- The session id is what keeps one browser's alerts out of another browser's page

**Why an experience column?**
- One session per browser means `session_id` cannot say which experience queued the alert
- Without it, a portal page's "Welcome to the Client Portal" would be shown by whichever
  page of that browser rendered next - including a staff admin screen
- It is a stamped fact of the WRITE (the request's own experience), never re-derived on
  read; see SESSION / EXPERIENCE AWARENESS above

**Why both page render AND Ajax integration?**
- Page render: Handles redirects (Flash_Alert::success() → redirect → display)
- Ajax: Handles same-page updates (Flash_Alert::success() → Ajax response → display)
- Unified API works everywhere

**Why sessionStorage persistence?**
- Handles Ajax + immediate redirect scenario (message queued, then page navigates)
- Per-tab isolation (messages don't leak across browser tabs)
- Messages survive page navigation (up to 20 seconds)
- Automatic cleanup (stale messages filtered out, removed on dismiss)
- No server-side complexity (client handles queue restoration)

## Queue Persistence System

### Architecture Overview

**Two-queue design:**
- **Working queue** (`_queue`): Controls display timing and spacing (2.5s between alerts)
- **Persistence queue** (`_persistence_queue`): Saved to sessionStorage, survives navigation

**Why two queues?**
- Working queue: Messages removed when displayed → prevents duplicate displays
- Persistence queue: Messages removed when fadeout starts → maintains state across navigation
- Separation ensures proper timing while enabling seamless cross-page experience

### Storage Mechanism

**Storage type:** sessionStorage (per-tab, survives navigation, cleared on tab close)

**Storage key:** `rsx_flash_queue`

**Stored data structure:**
```javascript
{
    last_updated: 1234567890,  // Timestamp of last save
    messages: [
        {
            message: "Success!",
            level: "success",
            timeout: null,
            position: "top",
            queued_at: 1234567890,
            fade_in_complete: false,      // Set true after fade-in animation
            fadeout_start_time: null      // Timestamp when fadeout should begin
        }
    ]
}
```

### Message Lifecycle & State Tracking

**Lifecycle stages:**
1. **Queued**: Added to both queues, saved to sessionStorage
2. **Displaying** (fade-in): 400ms fade-in animation
3. **Fade-in complete**: `fade_in_complete = true`, `fadeout_start_time` calculated and saved
4. **Visible**: Display duration (4s success, 6s others)
5. **Fadeout**: 1s opacity fade + 250ms slide up, removed from persistence queue
6. **Removed**: Element removed from DOM

**State tracking:**
- `fade_in_complete`: Marks when fade-in animation completes
- `fadeout_start_time`: Absolute timestamp when fadeout should begin
- `last_updated`: Queue-level timestamp for staleness check

**Why track timing state?**
- Enables SPA-like experience: alerts maintain consistent timing across page navigations
- Prevents timing restarts: navigating mid-alert doesn't reset the display duration
- Allows immediate display: alerts that completed fade-in on previous page show instantly on next page

### When State Changes

**State saved to sessionStorage:**
- New message queued (`Flash_Alert._show()`)
- Fade-in completes (`_mark_fade_in_complete()`)
- Fadeout scheduled (`_set_fadeout_start_time()`)
- Message removed from persistence queue (`_remove_from_persistence_queue()`)

**State restored from sessionStorage:**
- On page load via `Server_Side_Flash._on_framework_core_init()` hook
- Before processing new server messages
- Applies staleness filter and fadeout time filter

**State cleared:**
- Fadeout begins for individual messages (removed from persistence queue)
- Entire queue becomes stale (last_updated > 20 seconds)
- All messages dismissed/removed (storage key deleted)

### Staleness & Filtering

**20-second rule:**
- If `last_updated` timestamp is > 20 seconds old, entire queue is discarded
- Prevents ancient messages from appearing after long delays
- Based on queue-level timestamp, not individual message age

**Fadeout time filter:**
- On restore, messages past their `fadeout_start_time` are discarded
- Prevents "zombie" messages that should have already faded out
- Only applies to messages with scheduled fadeouts (fade-in complete)

### Navigation Behavior

**On page navigation (beforeunload):**
- Alerts still fading in: Hidden immediately (will restore and continue fade-in on next page)
- Alerts fully visible: Remain visible during navigation (with scheduled fadeout)
- Persistence queue: Unchanged (all state preserved to sessionStorage)

**On page load (restoration):**
- Messages with `fade_in_complete = true`:
  - Displayed immediately (no fade-in animation, no queue delay)
  - Honor original `fadeout_start_time` (not restarted)
  - Displayed outside normal queue (doesn't block queue processing)
- Messages with `fade_in_complete = false`:
  - Added to working queue for normal processing
  - Display with 2.5s spacing and full fade-in animation
  - Calculate new `fadeout_start_time` after fade-in completes

### Common Scenarios

**Scenario 1: Ajax + Redirect (primary use case)**
1. Ajax response includes flash message
2. JavaScript queues message and saves to sessionStorage
3. Page redirects immediately (before message displays)
4. New page loads, restores queue from sessionStorage
5. Message displays normally with 2.5s queue spacing
6. Message removed from storage when fadeout begins

**Scenario 2: Mid-animation navigation**
1. Alert is fading in (fade_in_complete = false)
2. User navigates away (alert hidden by beforeunload)
3. New page loads, message restored to working queue
4. Alert displays with normal queue timing and fade-in animation
5. After fade-in completes, fade_in_complete = true saved

**Scenario 3: Visible alert navigation (seamless SPA-like)**
1. Alert fully visible (fade_in_complete = true, fadeout_start_time set)
2. User navigates away (alert remains visible during navigation)
3. New page loads, message has fade_in_complete = true
4. Alert displayed immediately (no animation, no delay)
5. Honors original fadeout_start_time (e.g., if 2s remaining, fades out in 2s)
6. Creates seamless experience: alert appears to "survive" navigation

**Scenario 4: Multiple messages + navigation**
1. Queue has 3 messages, first one displaying (fade_in_complete = true)
2. User navigates away
3. New page loads:
   - First message (fade_in_complete = true): Shows immediately
   - Second message (fade_in_complete = false): Added to queue, displays in 2.5s
   - Third message (fade_in_complete = false): Added to queue, displays in 5s
4. Queue processing continues normally for remaining messages

## File Locations

**PHP:**
- `/system/app/RSpade/Lib/Flash/Flash_Alert.php` - Main Flash class (session resolution, CLI contract, cap)
- `/system/app/RSpade/Lib/Flash/Flash_Alert_Model.php` - Database model with type_id enum
- `/system/app/RSpade/Lib/Flash/Flash_Alert_Cleanup_Service.php` - hourly retention sweep
- Integration in `/system/app/RSpade/Core/Bundle/Rsx_Bundle_Abstract.php` (`_build_rsxapp_data`)
- Integration in `/system/app/RSpade/Core/Ajax/Ajax.php` (success + error response builders)

**JavaScript:**
- `/system/app/RSpade/Lib/Flash/Flash_Alert.js` - Display component with persistence
- `/system/app/RSpade/Lib/Flash/Flash_Alert.scss` - Styles
- `/system/app/RSpade/Lib/Flash/Server_Side_Flash.js` - Server bridge
- Integration in `/system/app/RSpade/Core/Js/Ajax.js`

**Config:**
- `rsx.flash.max_alerts_per_session` in `/system/config/rsx.php`

**Tests:**
- `/system/app/RSpade/tests/flash/` - cap, session scoping, CLI contract, retention sweep
