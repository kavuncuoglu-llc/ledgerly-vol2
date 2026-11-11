import unittest
from types import SimpleNamespace

from privacy_sidecar.core import to_span, to_spans, wanted


def result(entity, start, end, score):
    return SimpleNamespace(entity_type=entity, start=start, end=end, score=score)


class CoreTest(unittest.TestCase):
    def test_wanted_defaults_to_every_entity_the_app_knows(self):
        self.assertEqual(wanted(None), ["PERSON", "LOCATION"])

    def test_wanted_accepts_the_apps_names_and_ignores_unknown_ones(self):
        self.assertEqual(wanted(["address", "CREDIT_CARD"]), ["LOCATION"])
        self.assertEqual(wanted(["PERSON"]), ["PERSON"])

    def test_a_result_becomes_a_span_with_the_apps_type(self):
        self.assertEqual(
            to_span(result("LOCATION", 3, 9, 0.8512)),
            {"type": "ADDRESS", "start": 3, "end": 9, "score": 0.851},
        )

    def test_spans_below_the_threshold_are_dropped_and_the_rest_are_in_text_order(self):
        results = [result("PERSON", 20, 30, 0.9), result("PERSON", 0, 5, 0.6), result("PERSON", 8, 10, 0.2)]
        spans = to_spans(results, 0.5)

        self.assertEqual([s["start"] for s in spans], [0, 20])


if __name__ == "__main__":
    unittest.main()
