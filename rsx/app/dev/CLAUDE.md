# rsx/app/dev — the framework showcase, closed and deletable

**Every surface here declares `#[Auth('closed')]` / `@auth('closed')`** — a check that is
always false, so no identity in any mode reaches it. It is a worked reference, not a running
feature. Two halves share one bundle: Blade pages under `Dev_Layout` (index, ACL tester,
flash alerts, attachments, modals) and SPA actions under `Dev_Spa_Layout` (SPA test, an
uncaught-exception page, ORM timing, document preview, attachment thumbnail). The modals and
flash pages are the fullest exercises; `modals/test_modal_form.jqhtml` and
`pin_verification_form.jqhtml` are the canonical "a modal is chrome around a form" examples.

**To delete it cleanly:** remove the directory. Nothing outside it references it. Deleting it
together with `../ssr_test/` frees the theme components `Page`, `Page_Header`,
`Page_Header_Left` and `Page_Title` (`rsx/theme/components/page/`), which have no other
consumer. `Card_Title` stays — every datagrid uses it.

**To open it instead:** replace `closed` with a real check on every class
`grep -rn "closed" rsx/app/dev` finds, and fix the two `// TODO: Implement developer role
check` sites in `acl/dev_acl_controller.php` first.

**Related:** `../CLAUDE.md` · `rsx/theme/components/page/CLAUDE.md` · app skill `modals`
