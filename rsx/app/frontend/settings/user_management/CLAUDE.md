# User Management - Privacy Principle

**CRITICAL**: User management screens display `users` table data only, never `login_users` table data.

## Rationale

The `login_users` table contains authentication information private to the user (email verification status, activation status, last login time). Site administrators manage user profiles, not authentication records.

## Implementation

**DO**:
- Use `$user->email`, `$user->is_enabled`, `$user->invite_accepted_at`
- Show user profile and role information from `users` table

**DON'T**:
- Use `$user->login_user->email`, `$user->login_user->is_verified`, `$user->login_user->last_login`
- Expose authentication-specific fields to administrators

---

# Page Data Pattern

The user management view page uses `@rsx_page_data` to pass the user ID to JavaScript for modal interactions.

## Implementation

**In Blade view** (`frontend_settings_user_management_view.blade.php`):
```blade
@rsx_page_data(['user_id' => $user->id])
```

**In JavaScript** (`frontend_settings_user_management_view.js`):
```javascript
const user_id = window.rsxapp.page_data?.user_id;
```

## Why This Pattern

- **Clean separation**: User ID needed by JavaScript (resend invite button) without cluttering DOM with data attributes
- **Type-safe access**: Data available immediately when JavaScript loads
- **Centralized**: All page data defined in one place at top of view file

This pattern is used throughout user management for passing record IDs and other page-specific data to JavaScript functionality.
