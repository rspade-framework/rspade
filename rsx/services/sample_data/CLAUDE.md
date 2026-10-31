# rsx/services/sample_data - the sample dataset

## WHAT IS HERE

`Sample_Data_Service::seed` (`php artisan rsx_app:seed [--site=<id>]`) fills an EMPTY site
with fictional clients, contacts, projects, tasks, documents and the rest, so a new install
or a demo has something in every screen. It refuses a site that already holds application
data, in every mode. The first-run setup screen offers it while
`rsx.first_run.sample_data_task` (`rsx/resource/config/rsx.php`) names it.

| File | What it is |
|---|---|
| `sample_data_service.php` | The seeder. Reads the JSON below; owns nothing else. |
| `resource/sample_data.json` | THE DATA: every client with its contacts, departments, projects (subprojects, tasks, subtasks), documents and request threads; plus reference documents, announcements, parties, products and unattached tasks. Dates are day offsets from the day of seeding. |
| `resource/seed_gags.txt` | The cast: every character and client, its source, and its gag. Keep it in step with the JSON. |
| `resource/documents/` | The committed documents the seeder attaches - generated (pdf, docx, xlsx, png, jpg) plus `reference/`, two real public-domain documents with their provenance in `reference/README.md`. |
| `resource/generate_documents.php` (+ `render_html.js`) | Regenerates `resource/documents/` from the JSON's `documents[]` entries. A developer tool; seeding never runs it. |
| `resource/dev_snapshot.php` | `save` / `restore` this box's database and blob store around a refresh. |
| `resource/last_refresh.json` | When the data was last brought up to date, and the commit it was compared against. |

## THE RULES THE DATA FOLLOWS

- **Fictional characters only**, each with a first and last name; parody companies that
  twist each film's own fictional firm. No real people. Semi-cult and well liked over the most
  famous; no Star Wars, little Star Trek. The humour is in what they are tracked doing, and it
  stays professional - this ships in the template.
- **Every email is a TEST address and every phone a TEST number** - `<name>@test.<company>.com`
  and `(nnn) 555-01nn` - which the framework records as sent and never delivers
  (`rsx:man email`, TEST ADDRESSES). Nothing seeded can reach a real person.
- **No accounts, no portal access.** Contacts are records only; nothing is invited. The
  client-level portal switch IS on for active clients (`portal_enabled`), because that is
  what shows staff the Portal tab where a client's documents, request threads and
  announcements live - it admits nobody.
- **Data, not code.** A new kind of record is a new JSON key plus a few lines in the service;
  the service names no character, company or document itself.

## REFRESHING THE DATA (the procedure)

Run it when the application has grown since `resource/last_refresh.json` - new modules,
new fields, new kinds of record - so the sample data shows them.

1. **See what changed.** `git log --oneline <compared_against_commit>..HEAD -- rsx system/app/RSpade`
   (from the project root), and read `rsx/models/` and the module `CLAUDE.md` files for what
   arrived. List: new models that need sample rows, new columns that existing rows should
   fill, new screens that would be empty.
2. **Extend the data.** Add the new values to existing records in `sample_data.json`, and
   new kinds of record under a new key with a few lines in the service. Add new characters to
   `seed_gags.txt` first and keep to the rules above. New or changed documents:
   `php rsx/services/sample_data/resource/generate_documents.php`, then LOOK at a few of the
   generated files.
3. **Save this box's data.** `php rsx/services/sample_data/resource/dev_snapshot.php save`.
4. **Rebuild the shipped snapshot from zero** (only when asked to - it is the owner's call):
   `php artisan rsx:db:rebuild_provision_cache_snapshot`. It migrates a scratch database from
   nothing, records it as the schema cache, and puts the live data back itself.
5. **Reset to a fresh install and seed it.** `php artisan rsx:database_and_storage_reset --yes`,
   create the first account on the first-run screen with "Add sample data" ticked (or
   `php artisan rsx_app:seed`), and wait for the run in `/_sys/tasks`.
6. **Test.** `php artisan rsx:test Sample_Data_Seed_Test`, then walk the screens with
   `rsx:debug` - clients, a client's documents and threads, projects, tasks, parties, the
   dashboard, the activity log.
7. **Ask the owner to review** the seeded site in a browser and take their critiques. Fix,
   then repeat 5-6 for anything that changed.
8. **Record the refresh**: update `last_refresh.json` (today, and the commit the data was
   compared against), then commit.
9. **Put this box's data back.** `php rsx/services/sample_data/resource/dev_snapshot.php restore`.

## HOW TO CUSTOMIZE

- **Your own sample data**: edit the JSON and the gag chart; regenerate documents.
- **Your own seeder**: write a `#[Task]` of your own and name it in
  `rsx.first_run.sample_data_task`. **No sample data at all**: remove that key, and this
  directory.
- Keep the empty-site refusal. It is what makes the command safe to leave installed.

## RELATED

`../CLAUDE.md` · `rsx/tests/Sample_Data_Seed_Test.php` · skill `rspade:task-commands` ·
`rsx:man email` (TEST ADDRESSES) · `rsx:man initial_user`
