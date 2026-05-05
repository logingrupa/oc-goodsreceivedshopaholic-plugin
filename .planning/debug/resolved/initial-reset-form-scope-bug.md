---
slug: initial-reset-form-scope-bug
status: resolved
created: 2026-05-05
updated: 2026-05-05
---

# initial-reset-form-scope-bug

## Symptoms

- Initial Reset modal POST returns `{ ok: false, message: "Type RESET exactly to confirm initial reset." }` regardless of typed value (RESET, blank, or with .HTM file selected).
- Same response with empty form OR fully-filled form.
- `onInitialResetConfirm` handler reads `Input::get('confirm_typed')`, gets empty string every time.

## Root cause

`controllers/invoices/_partials/_initial_reset_confirm.htm` placed `<?= Form::open(['files' => true]) ?>` INSIDE `.modal-body`, wrapping only the inputs. The trigger `<button data-request="onInitialResetConfirm">` lived in `.modal-footer`, OUTSIDE the form scope. October Larajax serializes the form ancestor of the AJAX trigger element. With no form ancestor enclosing the button, the request fires with NO `confirm_typed` and NO `files[]`. Server-side RESET-literal gate sees empty string → rejects.

Same pattern present on `_override_confirm.htm` (typed-OVERRIDE confirmation flow) — `confirm_typed` + `prior_invoice_id` + `files[]` all stripped from the request for the same reason. Latent bug, not yet UAT-reported but identical mechanism.

## Fix

Both partials restructured: `Form::open` moved to wrap BOTH `.modal-body` AND `.modal-footer`. Trigger button now has the form as a `closest('form')` ancestor, so Larajax serializes the inputs correctly. Trigger button type changed `button` → `submit` (still AJAX-bound via `data-request`, but consistent with form-submit semantics for assistive tech / Enter-key activation).

## Verification

- `make pint-test`: PASS.
- PHPStan / PHPMD: template-only change, no PHP delta.
- Manual UAT: type RESET + select .HTM → `onInitialResetConfirm` runs the reset+apply flow.

## Files changed

- `controllers/invoices/_partials/_initial_reset_confirm.htm`
- `controllers/invoices/_partials/_override_confirm.htm`
