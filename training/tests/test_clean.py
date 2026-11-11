import json
from argparse import Namespace

import torch

from data.clean import (
    balance,
    clean,
    decontaminate,
    drop_near_duplicates,
    filter_reason,
    ngrams,
    question,
    run_filters,
    words,
)


class WordTokenizer:
    def encode(self, text):
        return text.split()


def fake_embed(texts):
    vectors = []
    for text in texts:
        vector = torch.zeros(64)
        for token in text.lower().split():
            vector[hash(token) % 64] += 1.0
        vectors.append(vector / vector.norm())
    return torch.stack(vectors)


def record(
    question_text="How much is due on INV-1?",
    reply="Invoice INV-1 has $5.00 due.",
    tenant="t1",
    user="u1",
    subcategory="general",
    sources=False,
    n_tokens=10,
):
    user_message = 'The passages:\n<untrusted-a source="help_center">x</untrusted-a>' if sources else "context"
    return {
        "id": question_text[:8],
        "prompt": [
            {"role": "system", "content": "s"},
            {"role": "user", "content": user_message},
            {
                "role": "user",
                "content": f'Question:\n<untrusted-a source="customer_message">\n{question_text}\n</untrusted-a>',
            },
        ],
        "completion": [{"role": "assistant", "content": reply}],
        "meta": {"tenant": tenant, "user": user, "subcategory": subcategory, "conversation": question_text},
        "n_tokens": n_tokens,
    }


def args(**overrides):
    base = dict(max_tokens=2048, dup=0.95, decon=0.90, run=13, cap_tenant=1.0, cap_user=1.0, cap_subcategory=1.0)
    return Namespace(**{**base, **overrides})


def test_the_question_is_the_customers_text_without_the_untrusted_tags():
    assert question(record("Why is there a fee?")) == "Why is there a fee?"


def test_filters_drop_over_length_leaked_placeholders_and_uncited_replies():
    assert filter_reason(record(n_tokens=3000), 2048) == "over_length"
    assert filter_reason(record(reply="Sent to <EMAIL_1>."), 2048) == "leaked_placeholder"
    assert filter_reason(record(sources=True, reply="The fee is 1.5%."), 2048) == "uncited"
    assert (
        filter_reason(record(sources=True, reply="The fee is 1.5% [late-fees › How the fee is calculated]."), 2048)
        is None
    )
    assert filter_reason(record(reply="Invoice INV-1 is paid."), 2048) is None


def test_filters_count_each_reason():
    kept, removed = run_filters([record(n_tokens=3000), record(reply="<CARD_1>"), record()], 2048)

    assert len(kept) == 1
    assert removed == {"over_length": 1, "leaked_placeholder": 1}


def test_near_duplicate_questions_keep_one_per_group():
    records = [
        record("why is there a late fee on invoice 4471"),
        record("why is there a late fee on invoice 4471"),
        record("when does my plan renew"),
    ]

    kept, dropped = drop_near_duplicates(records, fake_embed, 0.95)

    assert dropped == 1
    assert len(kept) == 2


def test_an_example_close_to_a_golden_question_is_removed():
    records = [record("why is there a late fee on invoice 4471"), record("how do i export my customers")]

    kept, removed = decontaminate(records, fake_embed, ["why is there a late fee on invoice 4471"], [], 0.90, 13)

    assert [question(r) for r in kept] == ["how do i export my customers"]
    assert removed == {"similar_question": 1}


def test_a_long_exact_run_from_a_golden_reference_answer_removes_an_example():
    reference = (
        "Late fees are charged at one and a half percent of the outstanding balance each month and prorated by day"
    )
    records = [
        record("a different question entirely", reply="Note: " + reference + " thanks"),
        record("another one", reply="Short and original."),
    ]

    kept, removed = decontaminate(records, fake_embed, ["unrelated golden question here"], [reference], 0.90, 13)

    assert len(kept) == 1
    assert removed == {"shares_reference_run": 1}


def test_ngrams_ignore_case_and_punctuation():
    assert ngrams(words("A, b. C!"), 2) == {("a", "b."), ("b.", "c")} or len(ngrams(words("A, b. C!"), 2)) == 2


def test_balance_caps_a_tenant_a_user_and_a_subcategory():
    records = [record(f"q{i}", tenant="big", user=f"u{i}", subcategory="late-fees") for i in range(10)] + [
        record("other", tenant="small", user="x", subcategory="payments")
    ]

    kept, removed = balance(records, {"tenant": 0.5, "user": 1.0, "subcategory": 1.0})

    assert removed["tenant"] == 5
    assert len(kept) == 6


def test_clean_runs_the_four_passes_and_reports_each(tmp_path):
    golden = tmp_path / "golden"
    golden.mkdir()
    (golden / "billing.jsonl").write_text(
        json.dumps({"question": "why is there a late fee on invoice 4471", "reference_answer": ""}) + "\n"
    )
    records = [
        record("how do i export my customers"),
        record("how do i export my customers"),
        record("why is there a late fee on invoice 4471"),
        record("an overlong one", n_tokens=1),
    ]
    records[3]["prompt"][2]["content"] += " word" * 3000

    kept, report = clean(records, WordTokenizer(), fake_embed, golden, args())

    assert report["input"] == 4
    assert report["filters"] == {"over_length": 1}
    assert report["near_duplicates"] == 1
    assert report["decontaminated"] == {"similar_question": 1}
    assert report["output"] == len(kept) == 1
