import json

from scripts import dataset_stats


class FakeTokenizer:
    def __call__(self, texts, truncation):
        assert truncation is False
        return {"input_ids": [list(range(len(text.split()))) for text in texts]}


def write_chunks(path, texts):
    path.write_text("\n".join(json.dumps({"id": i, "text": text}) for i, text in enumerate(texts, start=1)) + "\n")


def test_stats_counts_the_chunks_longer_than_the_limit():
    result = dataset_stats.stats([100, 512, 513, 900], rerank_max=512)

    assert result == {"rows": 4, "max_tokens": 900, "mean_tokens": 506.25, "over": 2, "share_over_rerank_max": 0.5}


def test_a_chunk_of_exactly_the_limit_is_not_truncated():
    assert dataset_stats.stats([512], rerank_max=512)["over"] == 0


def test_summary_reads_like_the_books_line():
    result = dataset_stats.stats([10, 20, 600])

    assert dataset_stats.summary(result) == "3 chunks · max 600 · 1 over 512 (33.3%)"


def test_measure_reads_json_lines_with_datasets_and_counts_tokens(tmp_path):
    path = tmp_path / "help_chunks.jsonl"
    write_chunks(path, ["one two three", "four", "five six"])

    assert dataset_stats.measure(str(path), FakeTokenizer()) == [3, 1, 2]


def test_log_run_records_params_and_metrics_in_mlflow(tmp_path):
    import mlflow

    uri = f"sqlite:///{tmp_path}/mlflow.db"
    result = dataset_stats.stats([100, 600])

    dataset_stats.log_run(result, uri)

    client = mlflow.MlflowClient(tracking_uri=uri)
    [experiment] = [e for e in client.search_experiments() if e.name == dataset_stats.EXPERIMENT]
    [run] = client.search_runs([experiment.experiment_id])
    assert run.data.params["tokenizer"] == "BAAI/bge-reranker-base"
    assert run.data.params["rerank_max"] == "512"
    assert run.data.params["rows"] == "2"
    assert run.data.metrics["max_tokens"] == 600
    assert run.data.metrics["share_over_rerank_max"] == 0.5
    assert run.info.run_name == "chunk-lengths"


def test_main_prints_the_numbers_even_when_the_tracker_is_down(monkeypatch, tmp_path, capsys):
    path = tmp_path / "help_chunks.jsonl"
    write_chunks(path, ["a b c"] * 4)
    monkeypatch.setattr("transformers.AutoTokenizer.from_pretrained", lambda *args, **kwargs: FakeTokenizer())

    def refuse(*args, **kwargs):
        raise ConnectionError("no tracker")

    monkeypatch.setattr(dataset_stats, "log_run", refuse)

    code = dataset_stats.main(str(path))

    captured = capsys.readouterr()
    assert code == 1
    assert "4 chunks · max 3 · 0 over 512 (0.0%)" in captured.out
    assert "did not take the run" in captured.err


def test_main_reads_the_tracking_uri_from_the_environment(monkeypatch, tmp_path):
    path = tmp_path / "help_chunks.jsonl"
    write_chunks(path, ["a b c"])
    seen = {}
    monkeypatch.setattr("transformers.AutoTokenizer.from_pretrained", lambda *args, **kwargs: FakeTokenizer())
    monkeypatch.setattr(dataset_stats, "log_run", lambda result, uri: seen.update(uri=uri))
    monkeypatch.setenv("MLFLOW_TRACKING_URI", "http://mlflow.test:5000")

    assert dataset_stats.main(str(path)) == 0
    assert seen["uri"] == "http://mlflow.test:5000"


def test_the_tokenizer_revision_is_a_full_commit_hash():
    assert len(dataset_stats.TOKENIZER_REVISION) == 40
    assert int(dataset_stats.TOKENIZER_REVISION, 16) > 0
