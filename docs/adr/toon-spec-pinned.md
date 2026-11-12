# TOON for account data, pinned to a spec version

Status: accepted (adopted, with the spec version pinned beside the package) · Owner: platform team · Review: 2027-02-11

## Context

Volume 1's Chapter 12 put a customer's invoices into the prompt as TOON (a header with the field names and the row count, then one line per row) because JSON repeats every field name on every row. It did so through `helgesverre/toon`, installed as `^3.3` and called with no version, so the text the app wrote depended on whichever wire format the package chose as its default. The package carries three: its default is the compatibility profile of an older release (spec 3.3, which differs from 29 of the 389 fixtures the 3.3 specification publishes), and 4.1 and 4.4 are opt-in per call. Two libraries that both say "TOON" can write text the other refuses, and one library's next release can change every prompt.

Chapter 48 asks for the spec version to be pinned beside the package version and tested against the spec's own fixtures. `ToonEncoder` (`App\Ai\Context\Encoders`) is that: it names the spec version on every call, from `ai.context.toon.spec_version` (`AI_TOON_SPEC_VERSION`), and decodes strictly. `ToonFormat`, one of the formats behind `DataEncoder`, writes through it.

## Decision

- **Adopted: `helgesverre/toon` 3.3.0 (exact) writing TOON spec 4.4.** The package passes all 564 of the 4.4 specification's encode and decode fixtures (`tests/Fixtures/toon-spec/4.4/`, from `toon-format/spec` tag `v4.4.0`, commit `d09c3d3`, MIT; see its `SOURCE.md`), run by `ToonConformanceTest` through `ToonEncoder`. Its default profile is not pinned, because it does not pass its own spec's fixtures. 4.4 is the newest version the package implements and the one it passes completely.
- **TOON is for context only.** The app writes account data in TOON and asks models to answer in JSON validated against a schema (Volume 1, Chapter 10). The app's decoding is `ToonFormat::read()` and `ToonEncoder::decode()`, strict, and nothing sends a model's TOON anywhere; a model's TOON that fails strict decoding raises `ToonDecodeError`, to be retried or repaired like any structured-output failure.
- **`compact-v1` is kept, as the 3.3 profile Volume 1 shipped**, so the two can be compared (`eval:compare --encoders`). The book's "hand-rolled compact encoder" does not exist in the repository: Volume 1 already used this package.
- **A version change is a decision.** Changing `AI_TOON_SPEC_VERSION` needs a fixtures directory for the new version beside `4.4/`; a version with no fixtures has no conformance claim and the test fails.

## What it changed

For the rows the assistant sends (`list_invoices`: number, status, due date, total, late fee, days overdue) spec 4.4 and the 3.3 profile write **the same text**: 391 tokens for the seeded Acme account's 14 invoices, against 564 for compact JSON (31% fewer). Pinning 4.4 therefore saves nothing over what Volume 1 shipped; it makes the text a promise instead of a default. Where the specifications differ the output changes: an empty list is `invoices: []` in 4.4 and was `invoices[0]:` in the profile, so a customer with no invoices now sees the first form (the test that pinned the old text, in Chapter 12's `ListInvoicesEncodingTest`, was updated).

## Measurements

`php artisan eval:compare --set=golden:support --encoders=json,compact-v1,toon-spec --with=gates --limit=6`, native Ollama 0.40.15 serving `llama3.1:8b`, the first six golden cases, semantic cache and reranker off. Six cases is 17 points a case and the model is nondeterministic: read the pass rates as "no evidence of a difference", not as a ranking. `compact-v1` and `toon-spec` wrote identical account data, so their 83% and 50% are the same input answered differently on two runs. Input tokens per answer are what Ollama reports as evaluated, which its prefix cache makes depend on run order. The account-data row is exact (o200k_base, the seeded account's 14 invoices in the declared six fields).

| | json | compact-v1 | toon-spec |
| --- | --- | --- | --- |
| Golden pass rate | 67% | 83% | 50% |
| Input tokens per answer | ~3,547 | ~3,242 | ~2,840 |
| Account data, tokens | ~564 | ~391 | ~391 |
| Answer latency, p50 | 34.4 s | 23.3 s | 23.1 s |

The chapter's prediction (how many tokens a spec-compliant library saves over the earlier compact encoder): none on tabular account data, because the earlier encoder was this library. Against JSON the saving is 31% of the account data's tokens.

## Audit

`php artisan ai:package-audit helgesverre/toon`, 2026-11-11: MIT (on the allowlist); no advisories for the installed version; last release five days ago, three releases in six months, not abandoned; no AI packages in its dependency tree; no migrations and no configured stores; no telemetry or hosted-monitoring defaults; contract suite not applicable. Open items: documentation quality, team fit, golden-set behaviour (above), and which spec version it implements, which this record closes: 4.4.

## What would change this

- The package dropping 4.4 or changing its output for it: `ToonConformanceTest` fails on the upgrade, before any prompt changes.
- A golden-set category where TOON's pass rate is lower than JSON's on enough cases to see (Exercise 3): switch that feature with `ai.encoding.features` (configuration, not code).
- A model-written TOON requirement. The app asks for JSON; if that changes, strict decoding and a repair path come first.
- A newer spec version that the package passes completely: add its fixtures, run them, then move the pin.
