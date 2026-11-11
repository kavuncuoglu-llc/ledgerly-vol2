import json

from scripts.token_lengths import summary, token_lengths


class WordTokenizer:
    def encode(self, text: str) -> list[int]:
        return list(range(len(text.split())))


def write_jsonl(path, texts):
    path.write_text("\n".join(json.dumps({"text": text}) for text in texts) + "\n")


def test_token_lengths_counts_one_entry_per_row(tmp_path):
    path = tmp_path / "sample.jsonl"
    write_jsonl(path, ["one two three", "four", "five six"])

    assert token_lengths(str(path), WordTokenizer()) == [3, 1, 2]


def test_summary_reports_rows_max_and_mean():
    assert summary([3, 1, 2]) == "3 rows, max 3, mean 2"
