from data.replay import generate, good, replay_row


def test_keeps_the_base_models_good_answers_only():
    prompts = [
        {"prompt": [{"role": "user", "content": "a"}]},
        {"prompt": [{"role": "user", "content": "b"}]},
        {"prompt": [{"role": "user", "content": "c"}]},
    ]
    answers = {"a": "Fine.", "b": "", "c": "word " * 400}

    rows = generate(prompts, "base", ask_fn=lambda url, model, messages: answers[messages[0]["content"]])

    assert [r["completion"][0]["content"] for r in rows] == ["Fine."]


def test_a_replay_row_is_prompt_and_completion():
    assert replay_row([{"role": "user", "content": "q"}], "a") == {
        "prompt": [{"role": "user", "content": "q"}],
        "completion": [{"role": "assistant", "content": "a"}],
    }


def test_empty_and_overlong_answers_are_not_good():
    assert good("A short answer.")
    assert not good("")
    assert not good("word " * 300)


def test_a_prompt_that_should_be_refused_keeps_only_an_answer_that_refuses():
    prompts = [
        {"prompt": [{"role": "user", "content": "a"}], "expect": "refuse"},
        {"prompt": [{"role": "user", "content": "b"}], "expect": "refuse"},
    ]
    answers = {"a": "I can't help with that.", "b": "Sure, here is how."}

    rows = generate(prompts, "base", ask_fn=lambda url, model, messages: answers[messages[0]["content"]])

    assert [r["completion"][0]["content"] for r in rows] == ["I can't help with that."]
    assert good("I cannot do that.", "refuse")
    assert not good("Here you go.", "refuse")


def test_the_shipped_replay_set_has_refusals_that_refuse():
    import json

    prompts = {
        json.loads(line)["prompt"][0]["content"]: json.loads(line)["expect"]
        for line in open("data/fixtures/replay_prompts.jsonl")
    }
    rows = [json.loads(line) for line in open("data/fixtures/replay_from_base.jsonl")]

    assert len(rows) >= 30
    assert all(good(r["completion"][0]["content"], prompts[r["prompt"][0]["content"]]) for r in rows)
