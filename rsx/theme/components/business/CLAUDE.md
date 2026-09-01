# rsx/theme/components/business — application-domain widgets

## WHAT IS HERE

- `textbox_click_to_copy/textbox_click_to_copy.{jqhtml,js,scss}` —
  `Textbox_Click_To_Copy`: a read-only text box with an integrated copy button. Clicking
  anywhere in the field or on the icon copies `$value` to the clipboard and plays a brief
  success checkmark.

That is currently the whole group.

## HOW IT IS USED

This directory is the home for a reusable widget that is about THIS APPLICATION's domain
rather than about generic page furniture — the sibling groups (`view/`, `ui/`, `section/`)
are domain-free vocabulary, and a widget that knows what a client or an API key is belongs
here instead.

The one shipped member is used for secrets shown exactly once:
`rsx/app/frontend/settings/api_keys/api_key_created_modal_body.jqhtml` and
`rsx/app/frontend/settings/user_management/send_invite/invite_success_modal_body.jqhtml`.

It is not in `rsx/resource/conventions/semantic_component_registry.md`: the registry
tracks the semantic view vocabulary, and this group sits beside it.

## HOW TO CUSTOMIZE

- **Restyle** in the component's own SCSS, single-class wrapped
  (`.Textbox_Click_To_Copy { &__button }`).
- A component used by ONE feature belongs with that feature under `rsx/app/`, not here.
  Promote it into this directory when a second consumer appears — that is the same
  two-consumer bar the semantic vocabulary uses.
- Before adding anything here, check whether the shape already exists in `../view/` or
  `../ui/`: a domain name on a generic shape is still a duplicate.
- The whole group is deletable if the fork's own domain widgets replace it; nothing in
  `rsx/theme/` depends on it.

## RELATED

`../CLAUDE.md` (the group index and the search-before-you-create rule) ·
app skill `semantic-components` · skill `rspade:jqhtml` ·
scaffold: `php artisan rsx:app:component:create --name=<name> --path=rsx/theme/components/business`
