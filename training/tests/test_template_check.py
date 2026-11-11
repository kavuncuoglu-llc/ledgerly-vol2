import json

from scripts.template_check import first_row, render, tool_arguments_as_objects


class FakeTokenizer:
    def apply_chat_template(self, messages, tokenize, add_generation_prompt):
        text = "".join(f"<{m['role']}>{m.get('content') or ''}" for m in messages)
        return text + ("<assistant>" if add_generation_prompt else "")

    def __call__(self, text, add_special_tokens):
        return {"input_ids": [ord(c) for c in text]}


def test_renders_the_prompt_with_a_generation_prompt_and_its_token_ids():
    rendered = render(
        FakeTokenizer(), {"prompt": [{"role": "system", "content": "s"}, {"role": "user", "content": "u"}]}
    )

    assert rendered["text"] == "<system>s<user>u<assistant>"
    assert rendered["ids"] == [ord(c) for c in rendered["text"]]


def test_tool_call_arguments_become_objects_for_templates_that_expect_them():
    messages = [{"role": "assistant", "tool_calls": [{"id": "1", "function": {"name": "f", "arguments": '{"a": 1}'}}]}]

    assert tool_arguments_as_objects(messages)[0]["tool_calls"][0]["function"]["arguments"] == {"a": 1}


def test_messages_without_tool_calls_are_left_alone():
    messages = [{"role": "user", "content": "hi"}]

    assert tool_arguments_as_objects(messages) == messages


def test_reads_the_chosen_row(tmp_path):
    path = tmp_path / "train.jsonl"
    path.write_text("\n".join(json.dumps({"prompt": [{"role": "user", "content": str(i)}]}) for i in range(3)) + "\n")

    assert first_row(str(path), 2)["prompt"][0]["content"] == "2"
