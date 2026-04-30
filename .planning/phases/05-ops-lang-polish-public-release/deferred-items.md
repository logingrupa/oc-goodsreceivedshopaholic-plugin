# Deferred Items — Phase 5

Out-of-scope discoveries logged during plan execution. Each entry should be
addressed in the appropriate plan's closeout, NOT as drive-by fixes during
unrelated work.

## DI-05-04-01 (logged 2026-04-30 during plan 05-04 execution)

**Discovery:** Commit `36c336e docs(05-03): mark D11-D15 locked + move 50 v1
reqs Active → Validated (OPS-02)` exists in git log, indicating plan 05-03
ran to a code commit, but the post-plan metadata-update step was never run:

- `ROADMAP.md` line 134 still shows `[ ] 05-03-PLAN.md - PROJECT.md update
  D11-D15 + 56 reqs Validated (OPS-02)` unchecked.
- `STATE.md` plan count was at 34/38 (89%) when plan 05-04 started — would
  have been 35/38 (92%) had 05-03's closeout run.
- REQUIREMENTS.md OPS-02 status (line 77 at 05-04 read time) was still
  `[ ]` Pending — needs checking against the actual PROJECT.md state to
  determine whether 05-03's code commit fully closed OPS-02 or only partially.

**Why deferred:** Out of scope for plan 05-04 execution per executor
"SCOPE BOUNDARY" rule (only fix issues directly caused by current task's
changes). Silently flipping plan 05-03's metadata during plan 05-04's
execution would mask the prior closeout-step omission and confuse the audit
trail.

**Recommended fix:** Open a small reconciliation plan (05-03-RECONCILE or
extend 05-06 final-gate scope) to:

1. Read commit `36c336e` to confirm what PROJECT.md / REQUIREMENTS.md changes
   actually landed.
2. Verify OPS-02 acceptance criteria against the file state.
3. Update REQUIREMENTS.md OPS-02 row + traceability table row to reflect the
   actual closure state.
4. Update ROADMAP.md plan 05-03 to `[x]` with closure annotation.
5. Update STATE.md (if not done by 05-04 + 05-05 + ... cascading).

After my plan-05-04 run completed, the milestone count is reported at 35/38
based on plan 05-04 closeout alone. If plan 05-03 was in fact a fully closed
plan, the true count is 35/38 (counting 05-03 as the 35th + 05-04 as the
36th would yield 36/38 = 95%) — but I cannot make that determination without
reading the 36c336e commit diff and the current PROJECT.md state.

**Owner:** Next plan execution (05-03 reconciliation pre-step, or 05-05
metadata sweep, or 05-06 final-gate audit).
