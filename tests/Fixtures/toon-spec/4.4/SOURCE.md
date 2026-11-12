# Where these fixtures came from

These are the TOON specification's own conformance fixtures, copied unchanged.

| | |
| --- | --- |
| Repository | https://github.com/toon-format/spec |
| Tag | `v4.4.0` (specification version 4.4, dated 2026-10-08) |
| Commit | `d09c3d33e37389345a74bd96981eb96dd9be6c5e` |
| Path copied | `tests/fixtures/` (`encode/`, `decode/`, `fixtures.schema.json`) and `tests/README.md` |
| Fetched | 2026-11-11, with `git clone` and `git checkout v4.4.0` |
| Licence | MIT, Copyright (c) 2025-PRESENT Johann Schopplich: the `LICENSE` beside this file is the repository's, unchanged |
| Fixtures | 564 (the encode and decode files together) |

`ToonConformanceTest` runs every fixture through `App\Ai\Context\Encoders\ToonEncoder`, which writes and reads with `helgesverre/toon` 3.3.0 at spec version 4.4. The package passes all 564.

The spec's README says conformance is defined by `SPEC.md` (section 13 and appendix C), not by the fixtures: they cover the core requirements. Fixtures for a spec version other than the pinned one are not here, so the app makes no conformance claim for them; to move the pin, add that version's fixtures as a sibling directory of this one and change `ai.context.toon.spec_version` (`AI_TOON_SPEC_VERSION`) together.

The fixtures' `options` that this version defines are `delimiter`, `indentSize` and `strict`. The key-folding and path-expansion options belong to the 3.x specifications and to an optional feature the package does not expose; no 4.4 fixture uses them.
