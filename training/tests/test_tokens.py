from scripts import tokens


def test_render_pads_names_and_right_aligns_counts():
    lines = tokens.render({"Qwen2.5 (byte-level BPE)": 24, "o200k_base (tiktoken)": 21})

    assert lines == [
        "Qwen2.5 (byte-level BPE)      24 tokens",
        "o200k_base (tiktoken)         21 tokens",
    ]


def test_count_all_asks_three_vocabularies(monkeypatch):
    monkeypatch.setattr(tokens, "hub_count", lambda text, repo, revision: len(repo))
    monkeypatch.setattr(tokens, "tiktoken_count", lambda text, encoding: 7)

    counts = tokens.count_all("anything")

    assert list(counts) == ["Qwen2.5 (byte-level BPE)", "bge-small (WordPiece)", "o200k_base (tiktoken)"]
    assert counts["o200k_base (tiktoken)"] == 7


def test_the_pinned_revisions_are_full_commit_hashes():
    for _repo, revision in (tokens.QWEN, tokens.BGE):
        assert len(revision) == 40
        assert int(revision, 16) >= 0
