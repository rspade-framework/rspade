---
name: date-time
description: Working with dates and times in RSX using Rsx_Time and Rsx_Date classes. Use when formatting dates/times for display, handling user timezones, working with datetime columns, converting between formats, or displaying relative times like "2 hours ago".
---

# RSX Date & Time Handling

## Two Classes - Strict Separation

| Class | Purpose | Example |
|-------|---------| --------|
| `Rsx_Time` | Moments in time (with timezone) | `2025-12-24T15:30:00Z` |
| `Rsx_Date` | Calendar dates (no timezone) | `2025-12-24` |

**Critical**: Functions throw if wrong type passed (datetime to date function or vice versa). This is intentional - mixing types causes bugs.

---

## Strings, Not Objects

RSX uses ISO strings, not Carbon objects:
- **Dates**: `"2025-12-24"`
- **Datetimes**: `"2025-12-24T15:30:00Z"`

Same format in PHP, JavaScript, JSON, and database queries. No serialization surprises.

```php
$model->created_at      // "2025-12-24T15:30:45.123Z" (string)
$model->due_date        // "2025-12-24" (string)
Rsx_Time::now_iso()     // "2025-12-24T15:30:45.123Z" (string)
```

Carbon is used internally for calculations, never exposed externally.

---

## Model Casts (Automatic)

`Rsx_Model_Abstract` auto-applies casts based on column type:
- DATE columns → `Rsx_Date_Cast`
- DATETIME columns → `Rsx_DateTime_Cast`

**Never define** `$casts` with `'date'`, `'datetime'`, or `'timestamp'` - these use Carbon and are blocked by `rsx:check`.

---

## Rsx_Time - Moments in Time

### PHP Usage

```php
use App\RSpade\Core\Time\Rsx_Time;

// Current time
Rsx_Time::now();                    // Carbon (for calculations only)
Rsx_Time::now_iso();                // ISO 8601: 2025-12-24T15:30:00Z

// Formatting for display
Rsx_Time::format_datetime($datetime);        // "Dec 24, 2025 3:30 PM"
Rsx_Time::format_datetime_with_tz($datetime); // "Dec 24, 2025 3:30 PM CST"
Rsx_Time::format_time($datetime);            // "3:30 PM"
Rsx_Time::relative($datetime);               // "2 hours ago"

// Database storage (always UTC)
Rsx_Time::to_database($datetime);   // Converts to MySQL format

// Timezone
Rsx_Time::get_user_timezone();      // User's timezone or default
Rsx_Time::to_user_timezone($datetime); // Convert to user's timezone
```

### JavaScript Usage

```javascript
// Current time (synced with server)
Rsx_Time.now();                     // Date object
Rsx_Time.now_iso();                 // ISO string

// Formatting
Rsx_Time.format_datetime(datetime);          // "Dec 24, 2025 3:30 PM"
Rsx_Time.format_datetime_with_tz(datetime);  // "Dec 24, 2025 3:30 PM CST"
Rsx_Time.format_time(datetime);              // "3:30 PM"
Rsx_Time.relative(datetime);                 // "2 hours ago", "in 3 days"

// Arithmetic
Rsx_Time.add(datetime, 3600);       // Add seconds, returns ISO string
Rsx_Time.subtract(datetime, 3600);  // Subtract seconds
```

---

## Rsx_Date - Calendar Dates

### PHP Usage

```php
use App\RSpade\Core\Time\Rsx_Date;

// Current date
Rsx_Date::today();                  // "2025-12-24" (user's timezone)

// Formatting
Rsx_Date::format($date);            // "Dec 24, 2025"

// Comparisons
Rsx_Date::is_today($date);          // Boolean
Rsx_Date::is_past($date);           // Boolean
Rsx_Date::is_future($date);         // Boolean
Rsx_Date::diff_days($date1, $date2); // Days between
```

### JavaScript Usage

```javascript
// Current date
Rsx_Date.today();                   // "2025-12-24"

// Formatting
Rsx_Date.format(date);              // "Dec 24, 2025"

// Comparisons
Rsx_Date.is_today(date);            // Boolean
Rsx_Date.is_past(date);             // Boolean
```

---

## Live Countdown/Countup (JavaScript)

For real-time updating displays:

```javascript
// Countdown to future time
const ctrl = Rsx_Time.countdown(this.$sid('timer'), deadline, {
    short: true,                    // "2h 30m" vs "2 hours and 30 minutes"
    on_complete: () => this.reload()
});

// Stop countdown when leaving page
this.on_stop(() => ctrl.stop());

// Countup from past time (elapsed time)
Rsx_Time.countup(this.$sid('elapsed'), started_at, { short: true });
```

---

## Server Time Sync

Client time syncs automatically via `rsxapp` data on page load and AJAX responses. No manual sync required.

```javascript
// Client always has accurate server time
const server_now = Rsx_Time.now();  // Synced with server, corrects for clock skew
```

---

## User Timezone

Stored in `login_users.timezone` (IANA format, e.g., `America/Chicago`).

**Resolution chain**: `login_users.timezone` -> `sites.timezone` -> `config('rsx.datetime.default_timezone')` -> `'America/Chicago'`. A PORTAL request has no user tier (portal accounts carry no timezone column) and starts at the site.

```php
// Get the resolved timezone for the current viewer
$tz = Rsx_Time::get_user_timezone();

// The site's own timezone, skipping the user tier (PHP only - a portal
// account has no personal zone, so site-level code reads this directly)
$site_tz = Rsx_Time::get_site_timezone();

// All Rsx_Time methods automatically use the resolved timezone for display
```

---

## Setting the Timezone

```php
// Staff only. Writes login_users.timezone (+ timezone_auto when non-null),
// clears the resolution cache, returns whether the RESOLVED zone moved.
$changed = Rsx_Time::set_user_timezone('Europe/Berlin', false);

// identifier => 'Europe/Berlin (UTC+02:00)', DST-honest at call time
$options = Rsx_Time::timezone_options();
```

Unknown identifier throws `InvalidArgumentException`; a portal request or no signed-in login user is `shouldnt_happen()`.

From JavaScript, use the framework controller directly (staff realm, `#[Auth('is_logged_in')]`) - do not wrap it in an app endpoint:

```javascript
await Rsx_Timezone_Controller.timezone_options();   // [{value, label}, ...]
await Rsx_Timezone_Controller.get_settings();       // {timezone, resolved_timezone, timezone_auto}
await Rsx_Timezone_Controller.set_timezone({timezone, timezone_auto});  // {changed, timezone}
```

**Auto-set at boot**: `login_users.timezone_auto` defaults to 1, so on the first signed-in page load of a browser session `Rsx_Timezone_Auto` compares the browser's IANA identifier with the rendered zone and, on a mismatch, sets it, halts boot and reloads (one attempt per session, sentinel-guarded; staff only). A manual choice in the UI posts `timezone_auto: false`. Contract: `rsx:man time` (TIMEZONE PREFERENCE). Worked example: `/rsx/app/frontend/settings/user_settings/`.

---

## Date and Time Entry (no picker components)

There is **no date-picker or datetime-picker component** in RSX. A date field is a `Text_Input` with a native input type:

```jqhtml
<Text_Input $name="start_date" $type="date" $max_length=-1 />
```

- `type="date"` -> `"YYYY-MM-DD"` - exactly an `Rsx_Date` string, no conversion.
- `type="time"` -> `"HH:MM"` (TIME columns read back as `"HH:MM:SS"`).
- `type="datetime-local"` -> `"YYYY-MM-DDTHH:MM"` - **local wall time, no offset, no seconds. NOT an `Rsx_Time` string**; interpret it in the user's resolved zone on the server before storing.

A cleared native input submits `""`, not null. Full treatment: `php artisan rsx:man datetime_inputs`.

---

## Common Patterns

### Display in Template

```jqhtml
<span><%= Rsx_Time.format_datetime(this.data.record.created_at) %></span>
<span><%= Rsx_Time.relative(this.data.record.updated_at) %></span>
<span><%= Rsx_Date.format(this.data.record.due_date) %></span>
```

### Conditional Display

```jqhtml
<% if (Rsx_Date.is_past(this.data.task.due_date)) { %>
    <span class="text-danger">Overdue</span>
<% } %>
```

### Save to Database

Plain assignment. `Rsx_DateTime_Cast::set()` accepts an ISO 8601 string (any offset -> normalized to UTC), a MySQL `"Y-m-d H:i:s"` string (taken as already UTC), a Unix timestamp in seconds or ms, or a Carbon object, and turns `""`/null into NULL.

```php
$record->scheduled_at = $params['scheduled_at'];  // ISO in, UTC stored
$record->due_date = $params['due_date'];          // Already "YYYY-MM-DD"
$record->save();
```

`Rsx_Time::to_database()` still exists for places that need the MySQL string explicitly (raw `DB::statement()`, a query binding) - it is **not** required to assign a model attribute.

### Parse User Input

```php
// If user enters a datetime string
$datetime = Rsx_Time::parse($params['datetime']);  // Returns Carbon
$iso = Rsx_Time::to_iso($datetime);                // Convert back to string

// If user enters a date string
$date = Rsx_Date::parse($params['date']);  // Returns "YYYY-MM-DD"
```

---

## Key Rules

1. **Never use Carbon directly** - RSX uses string-based dates
2. **Never use PHP's date()** - Use Rsx_Time/Rsx_Date
3. **Store datetimes in UTC** - automatic: assign the ISO string and the cast normalizes it
4. **Display in user's timezone** - Automatic via Rsx_Time format methods
5. **Dates have no timezone** - Use Rsx_Date for calendar dates
6. **Wrong types throw** - Date functions reject datetimes and vice versa
7. **Render through a formatter** - never interpolate a raw `*_at` / `*_date` attribute into a template (`JQHTML-DATETIME-01`)

## More Information

Details: `php artisan rsx:man time` (timezone chain, preference API, boot auto-set), `php artisan rsx:man datetime_inputs` (date/time entry in forms)
