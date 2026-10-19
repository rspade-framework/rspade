# rsx/resource/conformance - this application's own conformance audits

One file per audit, `<id>.md`, each describing ONE contract this application's code
must honour that no lint rule can check. Each is a prompt a read-only agent follows
to review the code and report what does not conform. They run only when a developer
asks ("run the conformance audits", "run the launch conformance audits"), one agent
per audit, and never automatically.

An application audit has the same shape as a framework one: the header between two
`---` lines (`id` = the file's basename, `title`, `severity`, `when`, `scope`,
optional `related`) and the four-section body (`## The contract`,
`## Find every instance`, `## What does not conform`, `## Report`). Format:
`php artisan rsx:man conformance_audits`; writing and running one: skill
`rspade:conformance-audits`.

**An id must not repeat a framework audit's id.** The two directories are one
namespace; an application audit adds to the framework library and never replaces one,
and a repeated id throws when the library is read. `php artisan rsx:conformance:list`
shows both, with their origin.

The directory starts empty: the framework library covers the framework's contracts,
and an audit belongs here only for a contract this application defines.
