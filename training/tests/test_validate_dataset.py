import json

from scripts.validate_dataset import main, validate


def write(tmp_path, rows, raw=None):
    path = tmp_path / "sample.jsonl"
    path.write_text(raw if raw is not None else "\n".join(json.dumps(row) for row in rows) + "\n")

    return path


def conversation(answer="A late fee is a charge.", **extra):
    return {
        "messages": [{"role": "user", "content": "What is a late fee?"}, {"role": "assistant", "content": answer}]
    } | extra


def failed(report):
    return [c["check"] for c in report["checks"] if not c["passed"]]


def test_a_clean_file_passes_every_check(tmp_path):
    report = validate(write(tmp_path, [conversation(), conversation("Another answer.")]))

    assert report["ok"] is True
    assert failed(report) == []


def test_a_missing_file_fails_without_a_traceback(tmp_path):
    report = validate(tmp_path / "nope.jsonl")

    assert report["ok"] is False
    assert failed(report) == ["file exists"]


def test_a_line_that_is_not_json_is_reported(tmp_path):
    report = validate(write(tmp_path, [], raw=json.dumps(conversation()) + "\n{broken\n"))

    assert "every line is JSON" in failed(report)


def test_an_empty_assistant_message_is_reported(tmp_path):
    report = validate(write(tmp_path, [conversation(answer="  ")]))

    assert "messages are well formed" in failed(report)


def test_a_row_ending_with_the_user_is_reported(tmp_path):
    row = {"messages": [{"role": "user", "content": "Hello?"}]}

    assert "conversations end with the assistant" in failed(validate(write(tmp_path, [row])))


def test_duplicates_are_counted(tmp_path):
    report = validate(write(tmp_path, [conversation(), conversation()]))

    assert failed(report) == ["no duplicate rows"]


def test_consent_flags_are_checked_only_when_the_file_has_them(tmp_path):
    clean = validate(write(tmp_path, [conversation(consented=True, redacted=True)]))
    unredacted = validate(write(tmp_path, [conversation(consented=True, redacted=False)]))

    assert clean["ok"] is True
    assert failed(unredacted) == ["consented and redacted flags are true"]


def test_json_mode_prints_only_the_report_on_stdout(tmp_path, capsys):
    path = write(tmp_path, [conversation()])

    code = main(["--json", str(path)])

    captured = capsys.readouterr()
    assert code == 0
    assert json.loads(captured.out)["ok"] is True
    assert "validating" in captured.err
