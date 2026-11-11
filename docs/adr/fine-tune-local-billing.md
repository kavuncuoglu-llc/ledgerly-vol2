# Fine-tune the local model for billing explanations

Status: accepted, pilot · Owner: support-AI team · Review: after two iterations or 90 days

## Context

Billing is about 30% of support traffic (Chapter 38's illustrative figure; read your own from `ai:cascade-report`). Data-residency tenants must have billing questions answered on Ledgerly's own hardware (Chapter 34), so for them routing up a tier is not an option at any price. On cost alone the answer would be to wait: Figure 38-7 puts the monthly cost of a fine-tune near $1,500 against a benefit near $375 at 30,000 conversations a month, and after Chapter 37's cascade the token saving is almost nothing. The requirement is what justifies the pilot.

Failure analysis, run on this repository on 2026-11-11: `php artisan eval:failure-modes --role=local --category=billing --since=30d`, `llama3.1:8b` through native Ollama, the golden set's 41 billing cases at this tag (32 written by people, 9 synthetic; no promoted production failures yet), the reranker and the query rewriter off (the dev setup on one machine). 9 of 41 cases pass every quality gate (22%, against the scorecard's 95% floor). The 32 that fail sort as:

| Bucket | Cases | Share | Goes to |
| --- | --- | --- | --- |
| behavior and format | 17 | 53% | fine-tuning (Section 7) |
| retrieval miss | 15 | 47% | retrieval tuning (Volume 1, Chapter 16): `docs/backlog/billing-retrieval-and-coverage.md` |
| arithmetic | 0 | 0% | `calculate_proration` |
| coverage gap | 0 | 0% | help-center article requested |
| unclear | 0 | 0% | a person |

Inside behavior and format: the model did not call `lookup_invoice` for a question about an invoice it could not know 9 times, the reply had the wrong structure (no citation, markdown) 4 times, left out a fact that was in its context 2 times, and handed off a question its sources answer 2 times.

The book's Figure 38-8 prints 63% behavior and format, 16% retrieval and 13% arithmetic. This run has the same first bucket but a smaller share of it, a much larger retrieval share and no arithmetic at all: the retrieval setup is the degraded one (11 of the 15 misses are one article, `late-fees#how-the-fee-is-calculated`, because a question that names an invoice is matched on the invoice number and the word "fee"), and no golden case asks for a computed amount that is not in a tool result. Re-measure with the reranker and a query rewrite on, and with cases that need a proration, before reading the shares as final. (Chapter 39 adds 20 held-out late-fee cases to the golden set; on that set of 61 the retrieval share is 67%, for the same reason.)

## Options

1. Wait; keep the cascade. Cheapest on cost alone; fails the residency requirement.
2. Route residency tenants' billing to `mid` hosted in-region. Rejected: no in-region option meets the DPA terms on file.
3. LoRA fine-tune of the local 8B on billing explanations. Chosen, as a pilot. The order of work changes with the evidence above: the retrieval backlog comes first, because a fine-tune can only fix the failures that are behavior, about half of them in this run, and the pilot's criteria below are stated against those.

## Success criteria

Billing pass rate on local at least 96% (measured with retrieval fixed) · never-gates 0 · injection model-level success not higher than base · tripwire drop at most 2 points on every task · system prompt for billing at least 30% shorter (Chapter 40 gives the fine-tuned role its own, shortened system message).

## Plan and upkeep

Dataset: Chapter 40 (`billing-v1`). Training: Chapters 42 and 43. Serving and registry: Chapter 44. Retrain quarterly and on any base-model change. Arithmetic moves to the `calculate_proration` tool (a plan change prorated by the days left, a late fee at 1.5% a month prorated by day, nothing under 1.00); retrieval misses go to the retrieval backlog, coverage gaps to the help center. Rerun `eval:failure-modes` after every retrieval change and before every retrain: it is how this record's evidence is refreshed.

## Hosted baseline

Not a result. No hosted fine-tuning account was available when this repository was built, so this section holds the output of the offline run: the fake fine-tuning driver and the scripted replies of the fake chat endpoint, on three golden billing cases. It proves that the command, the roles and the record agree; its figures are the fake's. Rerun `ai:ft:prepare`, `ai:ft:submit`, `ai:ft:poll`, `ai:ft:register` and `eval:compare` against a provider (Chapter 41, Build) and `adr:append` again: the section is replaced.

Run 2026-11-11 on golden:billing (3 cases) with gates.

| | frontier | frontier-billing-ft |
| --- | --- | --- |
| Golden pass rate | 100% | 33% |
| Required facts present | — | — |
| Judge pass rate | — | — |
| Injection model-level success | — | — |
| Tripwire, generation tasks | — | — |
| Tripwire, all tasks | — | — |
| Input tokens per answer | ~4,474 | ~3,005 |
| Cost per answer | $0.0000 | $0.0000 |

## Local fine-tune

Not run at the scale this record is about. Chapter 42 asks for the local 8B model fine-tuned on all of `billing-v1` on a machine in the data's region, compared with the production cascade and the hosted baseline including the full tripwire. The repository's pipeline is built and was run end to end on a 135M model (SmolLM2-135M-Instruct, MLX, 90 iterations over the 200 synthetic rows, about 40 seconds, peak 0.97 GB, validation loss 1.55 to 0.001, fused, converted to a 16-bit GGUF, created in Ollama, template check 143 = 143 tokens, 20 of 20 required facts on the held-out cases, registered as candidate version 1). That shows the steps work; it says nothing about 8B quality, speed or the thresholds above. To fill this section: `train_estimate.py` on the target machine, `run_mlx.py` or `train_lora.py` with `billing-v1`, `export_to_ollama.py`, `ai:template-check`, then `php artisan eval:compare --roles=cascade,local-billing-ft,frontier-billing-ft --with=gates,judge,injection,tripwire:all` and `adr:append --section="Local fine-tune"`. One known risk: with tool turns in the rows, the Ollama template for Llama 3.1 and the Hugging Face template disagree (Chapter 40: 538 against 557 tokens); `to_mlx.py --tool-turns=drop` is the way out if `ai:template-check` still fails after the export.

## Serving and registry

Chapter 44. The serving path and the release path are built and were exercised on the 135M model of the section above, not on the 8B model this record is about, so none of the figures below says anything about 8B quality, size or speed.

- **Quantization ladder** (`eval:quant-ladder`, llama.cpp b11776, importance matrix from the first 300 rows of the training split): on the 20 held-out billing cases the 135M fine-tune kept 100% of the required facts at every level from 16-bit to Q3_K_M (271 MB down to 94 MB), while its base fell from 47.5% to 32.5%, so the smallest level, Q3_K_M, was the one selected. A model this small is not a guide to where an 8B fine-tune's advantage starts to shrink: rerun the ladder on it before choosing a level.
- **Serving**: the merged Q4_K_M GGUF on an Ollama server (`serve:fine-tune billing 1 --ollama=local`), after the file's SHA-256 and signature (Ed25519, key held by the pipeline) were checked against the registry; `registry:verify` also matched the base's weights to the checksum the Hub publishes. The adapter on vLLM was not served: there is no NVIDIA machine here.
- **Release**: shadow, canary at 10% and 50%, promotion (refused without a stored comparison; forced here, and the history says so), a deliberately flawed second version (the invoice number dropped from every completion) promoted with `--force` and rolled back with `registry:rollback --time`: the old version answered its first request 0.5 s after the command started with the previous version still loaded, and 1.0 s after it had been retired and had to be loaded again from its archive. The 8B numbers will be larger, because a 5 GB file takes longer to load than a 105 MB one.
- **Still open**: the promotion criteria above are checked against a stored `eval:compare` run; none has been made for the 8B fine-tune, so nothing has been promoted on evidence. A canary needs live traffic: the guardrail path (`ai:guardrails`, `GuardrailBreached`) is covered by tests with a faked scorecard, not by customers.

## Exit

If two iterations miss the success criteria, stop and record why.
