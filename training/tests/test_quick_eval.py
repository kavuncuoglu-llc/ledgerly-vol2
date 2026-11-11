import json

from scripts.quick_eval import count_passing, load_cases, messages_for, plain, report, required_facts_present


def test_a_fact_is_present_whatever_the_money_formatting():
    assert required_facts_present("A late fee of $1,284.50 was added to INV-4471.", ["INV-4471", "1284.50"])


def test_every_required_fact_must_be_there():
    assert not required_facts_present("A late fee was added to INV-4471.", ["INV-4471", "21.60"])


def test_a_case_with_no_required_facts_passes():
    assert required_facts_present("anything", [])


def test_plain_ignores_case_commas_and_dollar_signs():
    assert plain("$1,000.00 INV-4471") == "1000.00 inv-4471"


def test_the_prompt_in_the_case_wins_over_the_bare_question():
    prompt = [{"role": "system", "content": "s"}, {"role": "user", "content": "u"}]
    assert messages_for({"question": "q", "prompt": prompt}) == prompt
    assert messages_for({"question": "q"}) == [{"role": "user", "content": "q"}]


def test_counts_passing_answers_against_each_cases_facts():
    cases = [{"must_include": ["INV-1", "10.00"]}, {"must_include": ["INV-2"]}, {}]
    assert count_passing(["INV-1 costs $10.00", "nothing", "x"], cases) == 2


def test_the_report_line_is_the_books():
    assert report(9, 17, 20) == "required facts present: base 9 / 20 · base + adapter 17 / 20"


def test_the_held_out_cases_carry_a_prompt_and_facts():
    cases = load_cases("../tests/golden/billing_holdout.jsonl")
    assert len(cases) == 20
    assert all(case["must_include"] and case["prompt"][-1]["role"] == "user" for case in cases)
    assert len(load_cases("../tests/golden/billing_holdout.jsonl", limit=3)) == 3


def test_held_out_invoices_are_not_in_the_training_rows():
    held = {json.loads(line)["id"].split("-", 2)[2] for line in open("../tests/golden/billing_holdout.jsonl")}
    training = open("data/fixtures/billing_sft_small.jsonl").read()
    assert not any(number in training for number in held)
