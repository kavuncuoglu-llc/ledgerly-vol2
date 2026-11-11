import importlib.util
import sys
import unittest
from pathlib import Path

SIDECAR_DIR = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(SIDECAR_DIR))

REQUIRED_PACKAGES = ("fastapi", "httpx", "multipart", "pydantic")
MISSING_PACKAGES = [name for name in REQUIRED_PACKAGES if importlib.util.find_spec(name) is None]

PDF_BYTES = b"%PDF-1.7\nfake body"


@unittest.skipIf(
    MISSING_PACKAGES,
    f"requires {', '.join(REQUIRED_PACKAGES)}; missing: {', '.join(MISSING_PACKAGES)} (pip install -r requirements.txt -r requirements-dev.txt)",
)
class AppTest(unittest.TestCase):
    def setUp(self):
        from fastapi.testclient import TestClient

        from app import create_app
        from ledgerly_sidecar.core import ParserUnavailable, ParseService, RerankService

        self.TestClient = TestClient
        self.create_app = create_app
        self.ParserUnavailable = ParserUnavailable
        self.ParseService = ParseService
        self.RerankService = RerankService

    def scorer_loader(self):
        class Scorer:
            def predict(self, pairs):
                return [float(len(passage)) for _, passage in pairs]

        return lambda: Scorer()

    def client(self, loader=None, converter=None):
        rerank = self.RerankService(loader or self.scorer_loader(), model_name="fake-model")
        parse = self.ParseService(converter or (lambda data: "# Parsed"))

        return self.TestClient(self.create_app(rerank=rerank, parse=parse))

    def test_health_reports_loaded_reranker(self):
        with self.client() as client:
            body = client.get("/health").json()

        self.assertEqual(
            body,
            {"status": "ok", "reranker": "fake-model", "reranker_loaded": True, "parser": "marker"},
        )

    def test_health_survives_a_reranker_that_cannot_load(self):
        def failing():
            raise OSError("no model cached")

        with self.client(loader=failing) as client:
            response = client.get("/health")

        self.assertEqual(response.status_code, 200)
        self.assertFalse(response.json()["reranker_loaded"])

    def test_rerank_returns_scores_in_order(self):
        with self.client() as client:
            response = client.post("/rerank", json={"question": "q", "passages": ["a", "abc", "ab"]})

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {"scores": [1.0, 3.0, 2.0]})

    def test_rerank_with_no_passages_returns_empty_scores(self):
        with self.client() as client:
            response = client.post("/rerank", json={"question": "q", "passages": []})

        self.assertEqual(response.json(), {"scores": []})

    def test_rerank_blank_question_is_422_with_detail(self):
        with self.client() as client:
            response = client.post("/rerank", json={"question": " ", "passages": ["a"]})

        self.assertEqual(response.status_code, 422)
        self.assertIn("question", response.json()["detail"])

    def test_rerank_too_many_passages_is_422(self):
        with self.client() as client:
            response = client.post("/rerank", json={"question": "q", "passages": ["a"] * 101})

        self.assertEqual(response.status_code, 422)

    def test_rerank_malformed_body_is_422(self):
        with self.client() as client:
            response = client.post("/rerank", json={"question": "q"})

        self.assertEqual(response.status_code, 422)

    def test_rerank_is_503_until_lazy_retry_succeeds(self):
        attempts = {"count": 0}
        good_loader = self.scorer_loader()

        def flaky():
            attempts["count"] += 1

            if attempts["count"] == 1:
                raise OSError("no model cached")

            return good_loader()

        with self.client(loader=flaky) as client:
            first = client.post("/rerank", json={"question": "q", "passages": ["abc"]})
            second = client.post("/rerank", json={"question": "q", "passages": ["abc"]})
            health = client.get("/health").json()

        self.assertEqual(first.status_code, 200)
        self.assertEqual(second.json(), {"scores": [3.0]})
        self.assertTrue(health["reranker_loaded"])

    def test_rerank_is_503_when_model_never_loads(self):
        def failing():
            raise OSError("no model cached")

        with self.client(loader=failing) as client:
            response = client.post("/rerank", json={"question": "q", "passages": ["abc"]})

        self.assertEqual(response.status_code, 503)
        self.assertIn("no model cached", response.json()["detail"])

    def test_parse_returns_markdown(self):
        with self.client() as client:
            response = client.post("/parse", files={"file": ("a.pdf", PDF_BYTES, "application/pdf")})

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {"markdown": "# Parsed", "parser": "marker"})

    def test_parse_rejects_non_pdf_with_422(self):
        with self.client() as client:
            response = client.post("/parse", files={"file": ("a.pdf", b"hello", "application/pdf")})

        self.assertEqual(response.status_code, 422)
        self.assertIn("not a PDF", response.json()["detail"])

    def test_parse_rejects_empty_file_with_422(self):
        with self.client() as client:
            response = client.post("/parse", files={"file": ("a.pdf", b"", "application/pdf")})

        self.assertEqual(response.status_code, 422)

    def test_parse_without_file_field_is_422(self):
        with self.client() as client:
            response = client.post("/parse")

        self.assertEqual(response.status_code, 422)

    def test_parse_converter_failure_is_422(self):
        def explode(data):
            raise RuntimeError("boom")

        with self.client(converter=explode) as client:
            response = client.post("/parse", files={"file": ("a.pdf", PDF_BYTES, "application/pdf")})

        self.assertEqual(response.status_code, 422)
        self.assertIn("boom", response.json()["detail"])

    def test_parse_is_503_when_parser_unavailable(self):
        def missing(data):
            raise self.ParserUnavailable("marker-pdf is not installed")

        with self.client(converter=missing) as client:
            response = client.post("/parse", files={"file": ("a.pdf", PDF_BYTES, "application/pdf")})

        self.assertEqual(response.status_code, 503)


if __name__ == "__main__":
    unittest.main()
