import json

import pytest

from data.split import build, parse_mix, split_by_conversation, stratum


def cleaned(i, subcategory="late-fees", conversation=None, source="production"):
    meta = {"id": f"id{i}", "source": source, "subcategory": subcategory, "conversation": conversation or f"c{i}"}
    return {
        "id": f"id{i}",
        "prompt": [{"role": "user", "content": f"q{i}"}],
        "completion": [{"role": "assistant", "content": f"a{i}"}],
        "meta": meta,
    }


def write(path, rows):
    path.write_text("".join(json.dumps(row) + "\n" for row in rows))


def test_parse_mix_reads_shares_and_refuses_what_cannot_be_mixed():
    assert parse_mix("synthetic=0.15,replay=0.15") == {"synthetic": 0.15, "replay": 0.15}
    with pytest.raises(ValueError):
        parse_mix("production=0.5")
    with pytest.raises(ValueError):
        parse_mix("synthetic=0.6,replay=0.5")


def test_every_example_of_a_conversation_lands_in_the_same_split():
    rows = [cleaned(i, conversation=f"c{i // 3}") for i in range(60)]

    train, validation = split_by_conversation(rows, 0.1, seed=1)

    assert {r["meta"]["conversation"] for r in train}.isdisjoint({r["meta"]["conversation"] for r in validation})
    assert len(train) + len(validation) == 60
    assert len(validation) % 3 == 0


def test_validation_covers_every_stratum_that_has_enough_conversations():
    rows = [cleaned(i, subcategory="late-fees") for i in range(40)] + [
        cleaned(100 + i, subcategory="payments") for i in range(6)
    ]

    _, validation = split_by_conversation(rows, 0.05, seed=3)

    assert {stratum(r) for r in validation} == {"late-fees", "payments"}


def test_the_split_is_the_same_every_time():
    rows = [cleaned(i) for i in range(50)]

    first = split_by_conversation(rows, 0.1, seed=7)
    second = split_by_conversation(rows, 0.1, seed=7)

    assert [r["id"] for r in first[1]] == [r["id"] for r in second[1]]


def test_build_mixes_in_exemplars_synthetic_and_replay_and_writes_plain_rows(tmp_path, monkeypatch):
    for name in ("exemplars", "synthetic", "replay"):
        write(
            tmp_path / f"{name}.jsonl",
            [
                {
                    "prompt": [{"role": "user", "content": f"{name}{i}"}],
                    "completion": [{"role": "assistant", "content": "a"}],
                }
                for i in range(40)
            ],
        )
    monkeypatch.setattr(
        "data.split.SOURCE_FILES", {name: tmp_path / f"{name}.jsonl" for name in ("exemplars", "synthetic", "replay")}
    )
    write(tmp_path / "cleaned.jsonl", [cleaned(i) for i in range(60)])
    write(tmp_path / "train.jsonl", [{"raw": 1}])
    write(tmp_path / "manifest.jsonl", [{"raw": 1}])

    report = build(tmp_path, {"synthetic": 0.15, "replay": 0.15}, 0.1, 42)

    train = [json.loads(line) for line in (tmp_path / "train.jsonl").read_text().splitlines()]
    meta = [json.loads(line) for line in (tmp_path / "train.meta.jsonl").read_text().splitlines()]
    assert report["composition"]["production"] == 60
    assert report["composition"]["exemplars"] > 0
    assert 0.1 < report["shares_made"]["synthetic"] < 0.2
    assert 0.1 < report["shares_made"]["replay"] < 0.2
    assert all(set(row) == {"prompt", "completion"} for row in train)
    assert len(train) == len(meta) == report["train"]
    assert (tmp_path / "export" / "train.jsonl").read_text() == json.dumps({"raw": 1}) + "\n"
