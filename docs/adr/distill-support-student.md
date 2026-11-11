# Distil a support student and tune the embedding model

Status: proposed, not accepted · Owner: support-AI team · Review: after the first run at the real scale

## Context

Chapter 46. Billing has its own fine-tune (`fine-tune-local-billing.md`). How-to, plans and integrations questions are the next largest share of traffic, human examples for them are scarce, and the larger in-region model (`mid-in-region`) already answers them well at a cost and a wait that a small model would cut. The embedding model decides what every tier is shown, and retrieval misses were the largest single bucket in the billing failure analysis.

## Options

1. Leave the cascade as it is. No build cost; every how-to question that escalates uses the in-region model's scarce GPU time.
2. Distil a student from `mid-in-region`'s answers (sequence level) into a second adapter on the vLLM base of the billing fine-tune. Chosen as the pilot: the teacher runs on Ledgerly's own hardware, so no question or answer leaves it, and the only terms that apply are the teacher's license, whose basis is recorded in `ai.providers.mid-in-region.distillation_basis`.
3. Logit-level distillation (Exercise 5). Not started: needs the teacher's weights run over every batch.
4. Fine-tune the embedding model on question and passage pairs. Chosen as a second pilot: cheapest of the three, and it changes every tier at once.

## Success criteria

Student: golden pass rate on how-to, plans and integrations at least the floor with the margin (0.96) on the categories it takes over, never-gates 0, tripwire drop at most 2 points, and no category worse on the scorecard after `eval:route-table` moves traffic to it. Embedding model: recall at 5 and mean reciprocal rank on the held-out golden retrieval questions both higher than the live model's, and the golden set end to end no worse (`eval:retrieval --min-gain`, `eval:compare --retrieval=live,shadow`); otherwise the shadow vectors are not promoted.

## Plan and upkeep

`dataset:distill` (a versioned set naming its teacher, the teacher's version and its license basis), `train_lora.py` with `configs/support-distill-peft.yaml`, `registry:add support 1`, the Chapter 44 ladder (shadow, canary, promotion, rollback). Retrain the student quarterly and whenever the teacher or the base changes; retrain the embedding model rarely, and re-embed into the shadow table every time. The route table decides which categories move; nobody edits it by hand.

## Run on this repository

Run on 2026-11-11 as a small trial of every step, with these stand-ins. The numbers show that the pipeline works and what its filter does; they are not evidence about an 8B student, which was not built.

- **Teacher**: `llama3.1:8b` through the native Ollama, in the `mid-in-region` role (the 70B model the book names does not fit in 32 GB). Its recorded basis is the Llama 3.1 Community License, reviewed by the build machine, which is a stand-in for a review by legal and says so. The judge was the same model, which is the weakest arrangement (a judge from another family finds more). The teacher answered with the long system message and four retrieved passages, with no tools.
- **Questions**: 21 teacher-written (seven per category) and 12 consented requested. None of the 240 consented conversations in the seeded corpus cites a how-to, plans or integrations article (they are all billing), so no consented question qualified.
- **The filter**: of 21 teacher answers the gates rejected 11 (7 cite a passage the teacher was not shown, 3 use markdown in a plain-text channel, 1 was given passages and cites none) and the judge 1 (a score of 2), 57% in all; 9 were kept (how-to 3, plans 1, integrations 5), 7 for training and 2 for validation, and 1 queued for review. A first run, in which the judge's server was misconfigured, rejected everything and said why: a judge that cannot answer rejects.
- **The student**: SmolLM2-135M-Instruct, LoRA rank 16, 24 steps on CPU (about 0.9 to 1.15 a step, train loss 0.79 over the run), exported to a Q4_K_M GGUF and registered as `support` v1, a candidate that passed the template check. The same run on MPS produced NaN gradients and an adapter of no use (`train_lora.py`, trl 0.29.1: see HANDOFF). The template check failed the first set of rows (882 tokens by training, 879 by Ollama): Ollama merges consecutive messages of one role and the Hugging Face template does not, and the assembled prompt carries the passages and the question as two user messages. Rows and requests to a fine-tuned role are now one user message.
- **The embedding model**: 90 pairs (one teacher question for each of the first 90 chunks), nomic-embed-text-v1.5 for 3 epochs on MPS (18 steps). On 108 held-out golden questions, ranked over all 330 chunks by the sentence-transformers evaluator: recall at 5 (the share of an article's passages found) 0.624 before and 0.693 after, MRR at 10 0.740 and 0.732. Through the shadow table, vector retrieval only and the app's own metrics: the live model and the same base through the Python worker agree to three decimals (recall at 5 0.917, MRR 0.770), and the tuned model scores recall at 5 0.898 and MRR 0.765, so `eval:retrieval --min-gain=0` fails and the vectors are not promoted. Ninety pairs are enough to move the in-process metric and not enough to beat the base on the app's.

## Exit

If two iterations miss the success criteria, stop and record why.
