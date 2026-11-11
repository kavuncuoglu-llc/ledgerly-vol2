import json

from data.mix import mix


def write_rows(path, label, count):
    path.write_text("\n".join(json.dumps({"id": f"{label}-{i}"}) for i in range(count)) + "\n")


def test_mixes_replay_in_at_about_the_share_asked_for(tmp_path):
    write_rows(tmp_path / "task.jsonl", "task", 2000)
    write_rows(tmp_path / "replay.jsonl", "replay", 2000)

    mixed = mix(str(tmp_path / "task.jsonl"), str(tmp_path / "replay.jsonl"), replay_share=0.15, seed=42)

    replay = sum(1 for row in mixed if row["id"].startswith("replay"))
    assert 0.10 < replay / len(mixed) < 0.20


def test_the_same_seed_gives_the_same_mix(tmp_path):
    write_rows(tmp_path / "task.jsonl", "task", 100)
    write_rows(tmp_path / "replay.jsonl", "replay", 100)

    first = mix(str(tmp_path / "task.jsonl"), str(tmp_path / "replay.jsonl"))
    second = mix(str(tmp_path / "task.jsonl"), str(tmp_path / "replay.jsonl"))

    assert [row["id"] for row in first] == [row["id"] for row in second]
