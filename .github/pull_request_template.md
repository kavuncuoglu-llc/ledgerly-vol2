## What this changes

## Agent-authored?

- [ ] No, a person wrote it.
- [ ] Yes. The `agent-authored` label is set, and the person assigned to this pull request reviewed the whole diff and owns the merge.

## Checked

- [ ] `php artisan test --parallel`
- [ ] `vendor/bin/pint --test`
- [ ] `php artisan ai:contract-test` (if a driver changed)
- [ ] `php artisan eval:check` (if a prompt changed)
- [ ] A new dependency went through the package rubric (`php artisan ai:package-audit <vendor/package>`)
