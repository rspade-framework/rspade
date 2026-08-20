# Application Pre-Launch Checklist

This file holds THIS APPLICATION's own pre-launch audit items - the things
specific to what you built on top of the RSpade template that should be walked
and verified before going live.

It is NOT the framework's checklist. The FRAMEWORK-required pre-launch audits
(hard-to-lint, framework-wide obligations) live separately and MUST also be
reviewed before launch:

    php artisan rsx:man prelaunch_checklist

Review both lists before every launch: the framework list above, and your own
application items below.

--------------------------------------------------------------------------------

## Your application items

The bullets below are EXAMPLES showing the shape - replace them with your own.

- [ ] (example) Audit every app-specific permission check - each domain
  `Permission::can_*()` gate is present at the endpoints that need it.
- [ ] (example) Verify all app email templates render and their links resolve.
- [ ] (example) Confirm seed/demo data is removed or clearly marked before
  production.
