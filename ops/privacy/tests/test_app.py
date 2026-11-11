import importlib.util
import sys
import unittest
from pathlib import Path
from types import SimpleNamespace

PRIVACY_DIR = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(PRIVACY_DIR))

REQUIRED_PACKAGES = ("fastapi", "httpx", "pydantic")
MISSING_PACKAGES = [name for name in REQUIRED_PACKAGES if importlib.util.find_spec(name) is None]


class FakeEngine:
    def __init__(self, results):
        self.results = results
        self.calls = []

    def analyze(self, text, language, entities, score_threshold):
        self.calls.append(
            {"text": text, "language": language, "entities": entities, "score_threshold": score_threshold}
        )

        return self.results


@unittest.skipIf(
    MISSING_PACKAGES,
    f"requires {', '.join(REQUIRED_PACKAGES)}; missing: {', '.join(MISSING_PACKAGES)}",
)
class AppTest(unittest.TestCase):
    def setUp(self):
        import app as service
        from fastapi.testclient import TestClient

        self.service = service
        self.engine = FakeEngine(
            [
                SimpleNamespace(entity_type="PERSON", start=8, end=22, score=0.85),
                SimpleNamespace(entity_type="LOCATION", start=40, end=53, score=0.62),
                SimpleNamespace(entity_type="PERSON", start=60, end=64, score=0.3),
            ]
        )
        service.engine = lambda: self.engine
        self.client = TestClient(service.app)

    def test_health_loads_the_engine_and_says_ok(self):
        response = self.client.get("/health")

        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json(), {"status": "ok", "engine": "presidio"})

    def test_analyze_returns_spans_in_the_apps_vocabulary_above_the_threshold(self):
        response = self.client.post(
            "/pii/analyze",
            json={"text": "Hello, Dana Whitfield wrote from San Francisco.", "score_threshold": 0.5},
        )

        self.assertEqual(response.status_code, 200)
        self.assertEqual(
            response.json()["spans"],
            [
                {"type": "PERSON", "start": 8, "end": 22, "score": 0.85},
                {"type": "ADDRESS", "start": 40, "end": 53, "score": 0.62},
            ],
        )

    def test_analyze_asks_only_for_names_and_addresses_by_default(self):
        self.client.post("/pii/analyze", json={"text": "x"})

        self.assertEqual(self.engine.calls[0]["entities"], ["PERSON", "LOCATION"])
        self.assertEqual(self.engine.calls[0]["language"], "en")

    def test_analyze_narrows_to_the_entities_asked_for(self):
        self.client.post("/pii/analyze", json={"text": "x", "entities": ["person", "CREDIT_CARD"]})

        self.assertEqual(self.engine.calls[0]["entities"], ["PERSON"])

    def test_analyze_refuses_a_language_that_is_not_loaded(self):
        response = self.client.post("/pii/analyze", json={"text": "x", "language": "de"})

        self.assertEqual(response.status_code, 422)

    def test_analyze_refuses_a_text_over_the_limit(self):
        from privacy_sidecar.core import MAX_TEXT

        response = self.client.post("/pii/analyze", json={"text": "x" * (MAX_TEXT + 1)})

        self.assertEqual(response.status_code, 422)


if __name__ == "__main__":
    unittest.main()
