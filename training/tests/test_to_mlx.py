import json

import pytest

from data.chat_rows import has_tool_turns, render_prompt_completion, with_object_arguments
from data.to_mlx import convert, main, split_file, to_chat


def row(question="What is due?", answer="$10.", tools=False):
    prompt = [{"role": "system", "content": "s"}, {"role": "user", "content": question}]
    if tools:
        prompt += [
            {
                "role": "assistant",
                "content": "",
                "tool_calls": [
                    {
                        "id": "c1",
                        "type": "function",
                        "function": {"name": "lookup_invoice", "arguments": '{"number": "INV-1"}'},
                    }
                ],
            },
            {"role": "tool", "tool_call_id": "c1", "content": '{"total": "10.00"}'},
        ]
    return {"prompt": prompt, "completion": [{"role": "assistant", "content": answer}]}


def write(path, rows):
    path.write_text("".join(json.dumps(r) + "\n" for r in rows))


def test_a_row_becomes_one_chat_with_the_completion_last():
    chat = to_chat(row())

    assert [m["role"] for m in chat["messages"]] == ["system", "user", "assistant"]
    assert chat["messages"][-1]["content"] == "$10."


def test_tool_call_arguments_become_objects_because_chat_templates_render_objects():
    messages = to_chat(row(tools=True))["messages"]

    assert messages[2]["tool_calls"][0]["function"]["arguments"] == {"number": "INV-1"}
    assert (
        with_object_arguments({"role": "assistant", "tool_calls": [{"function": {"name": "x", "arguments": ""}}]})[
            "tool_calls"
        ][0]["function"]["arguments"]
        == {}
    )


def test_a_directory_is_converted_to_train_and_valid_with_provenance(tmp_path):
    source, out = tmp_path / "billing-v1", tmp_path / "mlx"
    source.mkdir()
    write(source / "train.jsonl", [row(f"q{i}") for i in range(4)])
    write(source / "validation.jsonl", [row("v")])

    summary = convert(source, out)

    assert (summary["train"], summary["valid"]) == (4, 1)
    assert len((out / "train.jsonl").read_text().splitlines()) == 4
    assert json.loads((out / "valid.jsonl").read_text())["messages"][1]["content"] == "v"
    provenance = json.loads((out / "source.json").read_text())
    assert provenance["source"] == "billing-v1" and provenance["train_file_hash"].startswith("sha256:")


def test_one_file_is_split_by_position_so_the_split_never_changes(tmp_path):
    rows = [row(f"q{i}") for i in range(20)]

    train, valid = split_file(rows)

    assert len(train) == 18 and len(valid) == 2
    assert valid[0]["prompt"][1]["content"] == "q9"


def test_rows_with_tool_turns_are_counted_and_can_be_dropped(tmp_path):
    source = tmp_path / "rows.jsonl"
    write(source, [row("a"), row("b", tools=True), *[row(f"c{i}") for i in range(8)]])

    kept = convert(source, tmp_path / "keep")
    dropped = convert(source, tmp_path / "drop", tool_turns="drop")

    assert kept["with_tool_turns"] == 1 and kept["dropped_tool_rows"] == 0
    assert dropped["dropped_tool_rows"] == 1 and dropped["train"] + dropped["valid"] == 9
    assert not any(has_tool_turns(r) for r in [row("a")])


def test_an_unknown_tool_turns_mode_is_refused(tmp_path):
    with pytest.raises(ValueError):
        convert(tmp_path, tmp_path / "out", tool_turns="flatten")


def test_the_command_line_prints_the_counts(tmp_path, capsys):
    source = tmp_path / "rows.jsonl"
    write(source, [row(f"q{i}", tools=i == 0) for i in range(10)])

    assert main([str(source), str(tmp_path / "out")]) == 0

    captured = capsys.readouterr()
    assert "train 9 · valid 1" in captured.out and "1 rows have tool turns" in captured.err


class FakeTokenizer:
    def apply_chat_template(self, messages, tokenize=False, add_generation_prompt=False):
        text = "".join(f"<{m['role']}>{m.get('content', '')}</>" for m in messages)
        return text + ("<assistant>" if add_generation_prompt else "")


def test_a_row_is_rendered_into_the_prompt_the_model_sees_and_the_completion_it_must_write():
    rendered = render_prompt_completion(FakeTokenizer(), row("q", "the answer"))

    assert rendered["prompt"].endswith("<user>q</><assistant>")
    assert rendered["completion"] == "the answer</>"
