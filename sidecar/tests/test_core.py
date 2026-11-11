import os
import subprocess
import sys
import unittest
from pathlib import Path
from unittest import mock

SIDECAR_DIR = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(SIDECAR_DIR))

from ledgerly_sidecar.core import (  # noqa: E402
    ModelUnavailable,
    ParseFailed,
    ParserUnavailable,
    ParseService,
    RerankService,
    marker_convert,
)

PDF_BYTES = b"%PDF-1.7\nfake body"


class FakeScorer:
    def __init__(self):
        self.calls = []

    def predict(self, pairs):
        self.calls.append(list(pairs))

        return [len(passage) * 1.5 for _, passage in pairs]


class LoaderSpy:
    def __init__(self, scorer=None, failures=0):
        self.scorer = scorer or FakeScorer()
        self.failures = failures
        self.calls = 0

    def __call__(self):
        self.calls += 1

        if self.calls <= self.failures:
            raise OSError("model not cached")

        return self.scorer


class RerankServiceTest(unittest.TestCase):
    def make(self, **kwargs):
        loader = LoaderSpy(**kwargs)

        return RerankService(loader, model_name="fake-model", max_passage_chars=10), loader

    def test_scores_keep_order_and_length(self):
        service, _ = self.make()

        scores = service.rerank("q", ["a", "abcd", "ab"])

        self.assertEqual(scores, [1.5, 6.0, 3.0])
        self.assertTrue(all(isinstance(score, float) for score in scores))

    def test_pairs_are_question_passage_tuples(self):
        service, loader = self.make()

        service.rerank("what?", ["one", "two"])

        self.assertEqual(loader.scorer.calls, [[("what?", "one"), ("what?", "two")]])

    def test_long_passages_are_truncated_before_scoring(self):
        service, loader = self.make()

        service.rerank("q", ["x" * 5000, "short"])

        sent = [passage for _, passage in loader.scorer.calls[0]]
        self.assertEqual(sent, ["x" * 10, "short"])

    def test_default_truncation_is_two_thousand_characters(self):
        with mock.patch.dict(os.environ, {}, clear=False):
            os.environ.pop("SIDECAR_MAX_PASSAGE_CHARS", None)
            service = RerankService(LoaderSpy())

        self.assertEqual(service.max_passage_chars, 2000)

    def test_truncation_limit_comes_from_environment(self):
        with mock.patch.dict(os.environ, {"SIDECAR_MAX_PASSAGE_CHARS": "7"}):
            service = RerankService(LoaderSpy())

        self.assertEqual(service.max_passage_chars, 7)

    def test_invalid_environment_value_falls_back_to_default(self):
        with mock.patch.dict(os.environ, {"SIDECAR_MAX_PASSAGE_CHARS": "lots"}):
            service = RerankService(LoaderSpy())

        self.assertEqual(service.max_passage_chars, 2000)

    def test_empty_passages_return_empty_list_without_loading(self):
        service, loader = self.make()

        self.assertEqual(service.rerank("q", []), [])
        self.assertEqual(loader.calls, 0)
        self.assertFalse(service.loaded)

    def test_hundred_passages_are_allowed(self):
        service, _ = self.make()

        self.assertEqual(len(service.rerank("q", ["p"] * 100)), 100)

    def test_more_than_hundred_passages_are_rejected(self):
        service, _ = self.make()

        with self.assertRaisesRegex(ValueError, "at most 100"):
            service.rerank("q", ["p"] * 101)

    def test_blank_or_non_string_question_is_rejected(self):
        service, _ = self.make()

        for question in ["", "   ", None, 5]:
            with self.subTest(question=question):
                with self.assertRaisesRegex(ValueError, "question"):
                    service.rerank(question, ["p"])

    def test_passages_must_be_a_list_of_strings(self):
        service, _ = self.make()

        with self.assertRaisesRegex(ValueError, "list of strings"):
            service.rerank("q", "not a list")

        with self.assertRaisesRegex(ValueError, r"passages\[1\]"):
            service.rerank("q", ["ok", 3])

    def test_load_happens_once_and_lazily(self):
        service, loader = self.make()

        self.assertFalse(service.loaded)

        service.rerank("q", ["a"])
        service.rerank("q", ["b"])

        self.assertTrue(service.loaded)
        self.assertEqual(loader.calls, 1)

    def test_explicit_load_then_rerank_does_not_load_again(self):
        service, loader = self.make()

        self.assertTrue(service.load())
        service.rerank("q", ["a"])

        self.assertEqual(loader.calls, 1)

    def test_failing_loader_is_reported_as_not_loaded(self):
        service, _ = self.make(failures=1)

        self.assertFalse(service.load())
        self.assertFalse(service.loaded)
        self.assertIn("model not cached", service.last_error)

    def test_rerank_raises_model_unavailable_then_retries_and_succeeds(self):
        service, loader = self.make(failures=1)

        with self.assertRaises(ModelUnavailable):
            service.rerank("q", ["abc"])

        self.assertEqual(service.rerank("q", ["abc"]), [4.5])
        self.assertTrue(service.loaded)
        self.assertEqual(loader.calls, 2)
        self.assertEqual(service.last_error, "")

    def test_score_count_mismatch_is_an_error(self):
        class BrokenScorer:
            def predict(self, pairs):
                return [1.0]

        service = RerankService(lambda: BrokenScorer(), model_name="m")

        with self.assertRaises(RuntimeError):
            service.rerank("q", ["a", "b"])


class ParseServiceTest(unittest.TestCase):
    def make(self, converter=None, max_bytes=None):
        return ParseService(converter or (lambda data: "# Title"), max_bytes=max_bytes)

    def test_returns_markdown_and_parser_name(self):
        result = self.make().parse(PDF_BYTES, "report.pdf")

        self.assertEqual(result, {"markdown": "# Title", "parser": "marker"})

    def test_converter_receives_the_raw_bytes(self):
        received = []
        service = self.make(lambda data: received.append(data) or "ok")

        service.parse(PDF_BYTES, "a.pdf")

        self.assertEqual(received, [PDF_BYTES])

    def test_empty_data_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "empty"):
            self.make().parse(b"", "a.pdf")

    def test_oversized_data_is_rejected(self):
        service = self.make(max_bytes=20)

        with self.assertRaisesRegex(ValueError, "exceeds 20 bytes"):
            service.parse(b"%PDF-" + b"x" * 20, "a.pdf")

    def test_default_limit_is_twenty_five_megabytes(self):
        with mock.patch.dict(os.environ, {}, clear=False):
            os.environ.pop("SIDECAR_MAX_BYTES", None)
            service = ParseService(lambda data: "")

        self.assertEqual(service.max_bytes, 25 * 1024 * 1024)

    def test_non_pdf_bytes_are_rejected(self):
        with self.assertRaisesRegex(ValueError, "not a PDF"):
            self.make().parse(b"PK\x03\x04 zip file", "a.pdf")

    def test_converter_failure_is_wrapped_in_parse_failed(self):
        def explode(data):
            raise RuntimeError("layout model crashed")

        with self.assertRaises(ParseFailed) as context:
            self.make(explode).parse(PDF_BYTES, "a.pdf")

        self.assertIn("layout model crashed", str(context.exception))
        self.assertIsInstance(context.exception.__cause__, RuntimeError)

    def test_parser_unavailable_is_not_wrapped(self):
        def missing(data):
            raise ParserUnavailable("no marker")

        with self.assertRaises(ParserUnavailable):
            self.make(missing).parse(PDF_BYTES, "a.pdf")

    def test_non_string_converter_result_is_a_failure(self):
        with self.assertRaises(ParseFailed):
            self.make(lambda data: None).parse(PDF_BYTES, "a.pdf")


class ProductionLoadersTest(unittest.TestCase):
    def test_marker_convert_raises_parser_unavailable_without_marker(self):
        if "marker" in sys.modules:
            self.skipTest("marker is importable here")

        try:
            import marker  # noqa: F401
        except ImportError:
            pass
        else:
            self.skipTest("marker is importable here")

        with self.assertRaises(ParserUnavailable):
            marker_convert(PDF_BYTES)


class ImportIsolationTest(unittest.TestCase):
    def test_importing_core_does_not_import_third_party_packages(self):
        script = (
            "import sys\n"
            f"sys.path.insert(0, {str(SIDECAR_DIR)!r})\n"
            "import ledgerly_sidecar.core\n"
            "banned = ['fastapi', 'sentence_transformers', 'marker', 'torch', 'pydantic']\n"
            "loaded = [name for name in banned if name in sys.modules]\n"
            "sys.exit(1 if loaded else 0)\n"
        )

        completed = subprocess.run(
            [sys.executable, "-I", "-c", script],
            capture_output=True,
            text=True,
            timeout=60,
        )

        self.assertEqual(completed.returncode, 0, completed.stderr)


if __name__ == "__main__":
    unittest.main()
