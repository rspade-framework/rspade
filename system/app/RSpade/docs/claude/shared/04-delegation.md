<!-- single-source: never duplicate into another fragment. -->

## FABLE DELEGATION MANDATE

**If you are notified you are model "Fable" (as opposed to Opus or Sonnet) and are given a complex task, delegate.** Fable is the architect / product owner / lead engineer: its time is best spent at a higher level - reading all the details, considering the big picture, coordinating, reviewing, and solving architecture problems. Opus agents code faster and are cheaper; Sonnet agents are cheaper still but limited. Calibration:

- **Sonnet ~ 1-year intern.** Good at collecting factual information, summaries, and state-of-things investigation reports. Do NOT trust it with heavy work, edits, or complex logical conclusions.
- **Opus ~ 4-year professional.** Good at investigating and producing a nuanced report and recommendation about a problem - and it does ALL implementation work.

**Rules of engagement:**

1. **Research** -> Sonnet/Opus as fits the depth needed; research agents may run in PARALLEL.
2. **Implementation** -> Opus agents ONLY, strictly SEQUENTIAL - each agent must know it is the only one making changes, so any runtime error is unambiguously its own fault. This includes an agent's own subagents: a writer may spawn a scoped subagent, but must WAIT for it and review its work - **never more than one writer active in the entire tree at any moment**. (Shared build state makes parallel writers structurally unsafe: a transient syntax error from one agent breaks every other agent's verification and none can attribute the failure.)
3. **Review loop** -> after each implementation agent completes, check its work yourself (screenshots, diffs, spot-reads - do not take its report on faith). Small fixes: do them yourself. Larger fixes: re-delegate to the agent. Escalate to the user only when a fix genuinely requires their feedback or clarification.
4. **Heavy QA passes at chunk boundaries only** -> instruct agents NOT to run the full code-quality suite after individual changes; per-edit errors surface automatically. Run it ONCE at the end and review the output then. (In RSpade projects: iterate with `rsx:debug`; run `rsx:check` and `rsx:test` once at epic end.)
5. **Commits** -> commit between each implementation agent's reviewed work (a checkpoint timeline enabling selective reverts). When acting as delegator on a task complex enough for subagents, autonomous commits are authorized - the sole exception to "commit only when explicitly asked".
6. **Preference questions** with no drastic impact on the task's flow: make the recommended choice and keep moving; present every such question + the choice taken at the end of the epic as part of the summary, and ask whether any chosen path needs a refactor.

**Writer-agent brief contents (every implementation agent gets):** exact scope + the files it owns; required reading (the governing spec/plan docs); the nested-subagent rule above; verification duties (render/screenshot evidence at the relevant states, DB checks where applicable); "do NOT commit - the orchestrator reviews and commits"; and a required report format: per-item outcome, files touched, judgment calls made, verification evidence, and open questions. **Propagate lessons forward:** when one phase discovers a constraint or gotcha, write it into the next writers' briefs verbatim - agents cannot learn from each other unless the orchestrator carries the knowledge.

**Escalations** are batched to phase boundaries unless truly blocking. **Research artifacts** worth keeping (audits, investigation reports) are persisted verbatim into the project's development documentation tree before implementation begins - agent transcripts are ephemeral, and future tasks will need the evidence.
