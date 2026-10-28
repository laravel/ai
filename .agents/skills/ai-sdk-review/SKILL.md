---
name: ai-sdk-review
description: Review or triage a laravel/ai change. Use to review a PR or branch diff, triage a bug report or feature request, verify a claim against provider API documentation, or scope a change into a work plan before writing code.
---

Find the input. For code: the PR on the current branch (`gh pr view`); with no PR, diff the branch against `0.x` and treat the diff as the PR. For a bug report or feature request with no code yet: work from the report's text.

Assess four points, in order:

1. **Provider accuracy.** Confirm every claim about a provider's API — fields, endpoints, parameters, behavior — against that provider's official documentation, linked per provider in AGENTS.md. AGENTS.md is the source of truth for which capabilities each provider exposes; a claim that contradicts the docs is a finding, not a premise. This applies to the report's claims as much as the code's.
2. **Real problem.** Would a broad set of Laravel developers hit this, or is it niche or contrived? If it solves a problem nobody has, say so.
3. **Sound solution.** Is the approach right, over- or under-engineered, missing edge cases? When a fundamentally different approach is better, name it.
4. **Project style.** The change must read like the rest of the codebase: the comment rules and architecture in AGENTS.md, the naming and structure of the surrounding gateways, providers, and traits, and the conventions of neighboring tests. Flag every mismatch.

For code, check the change against every item of this ship checklist. Each unmet item is a finding:

- One user-visible change; no unrelated edits; split if it exceeds ~10 files.
- Pint (the repo's config) passes; nothing hand-formatted.
- Every new public name greps clean against the package's existing convention.
- BC impact stated; UPGRADE.md entry written with the right version; constraints bumped.
- Malformed and failure-path input tested; external payloads validated against the spec.
- Octane/queue-safe: no request-scoped state on shared services.
- Every query and payload bounded.
- Docblocks in house voice; prose tightened; claims verified against the code.
- Dead code, aliases, and speculative hooks deleted; every test assertion can fail.
- PR title describes the change, not the file operation. (boost#470)

Then produce the work plan: each improvement stated explicitly, each file to change named, and the test file named for each change. Where the API surface allows, show a realistic code example of the change in use.

Be concise and opinionated — a straight read, no hedging. The review is complete when all four points have a verdict, every checklist item is checked, every provider claim is checked against the docs, and every proposed change names its file and its test file.
