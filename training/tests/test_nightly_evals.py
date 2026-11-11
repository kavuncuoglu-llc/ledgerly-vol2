import math

from deepeval.models import DeepEvalBaseLLM

from evals import nightly_conversations, nightly_rag


class FakeFrame:
    def __init__(self, rows):
        self.rows = rows

    def iterrows(self):
        return enumerate(self.rows)


class FakeResult:
    def __init__(self, rows):
        self.rows = rows

    def to_pandas(self):
        return FakeFrame(self.rows)


ROWS = [
    {"id": "message:1", "question": "q1", "answer": "a1", "contexts": ["c1"], "reference": None},
    {"id": "shadow:2", "question": "q2", "answer": "a2", "contexts": ["c2"], "reference": None},
]


def test_live_answers_are_scored_on_the_two_metrics_that_need_no_reference():
    metrics = nightly_rag.metrics_for(ROWS)

    assert [type(m).__name__ for m in metrics] == ["Faithfulness", "ResponseRelevancy"]


def test_golden_rows_with_references_add_the_two_context_metrics():
    golden = [{**row, "reference": "the reference"} for row in ROWS]

    assert {type(m).__name__ for m in nightly_rag.metrics_for(golden)} >= {
        "LLMContextPrecisionWithReference",
        "LLMContextRecall",
    }


def test_ragas_columns_become_the_contract_s_metric_names_and_nan_is_left_out(monkeypatch):
    monkeypatch.setenv("JUDGE_BASE_URL", "http://judge.test/v1")
    seen = {}

    def evaluate(dataset, metrics, llm, embeddings, show_progress):
        seen["rows"] = len(dataset)
        seen["judge"] = llm.langchain_llm.model_name

        return FakeResult(
            [
                {"faithfulness": 0.75, "answer_relevancy": 0.9},
                {"faithfulness": math.nan, "answer_relevancy": 1.4},
            ]
        )

    scored = nightly_rag.score(ROWS, evaluate=evaluate)

    assert seen == {"rows": 2, "judge": "llama3.1:8b"}
    assert scored == [
        {"id": "message:1", "metrics": {"faithfulness": 0.75, "response_relevancy": 0.9}},
        {"id": "shadow:2", "metrics": {"response_relevancy": 1.0}},  # clamped to the 0 to 1 range the contract allows
    ]


def test_the_judge_comes_from_the_environment(monkeypatch):
    monkeypatch.setenv("JUDGE_MODEL", "judge-from-env")
    monkeypatch.setenv("JUDGE_BASE_URL", "http://judge.test/v1")

    llm = nightly_rag.judge_llm()

    assert llm.langchain_llm.model_name == "judge-from-env"
    assert str(llm.langchain_llm.openai_api_base) == "http://judge.test/v1"


def test_conversations_are_scored_with_deepeval_s_two_conversational_metrics(monkeypatch):
    from deepeval.metrics import ConversationCompletenessMetric, KnowledgeRetentionMetric

    def fake_measure(self, test_case, *args, **kwargs):
        self.score = 1.0 if isinstance(self, ConversationCompletenessMetric) else 0.5

        return self.score

    monkeypatch.setattr(ConversationCompletenessMetric, "measure", fake_measure)
    monkeypatch.setattr(KnowledgeRetentionMetric, "measure", fake_measure)

    scored = nightly_conversations.score(
        [
            {
                "id": "conversation:5",
                "turns": [{"role": "user", "content": "Hi"}, {"role": "assistant", "content": "Hello."}],
            }
        ],
        model=StubModel(),
    )

    assert scored == [
        {"id": "conversation:5", "metrics": {"conversation_completeness": 1.0, "knowledge_retention": 0.5}}
    ]


class StubModel(DeepEvalBaseLLM):
    def load_model(self):
        return self

    def generate(self, prompt, schema=None):
        return "{}"

    async def a_generate(self, prompt, schema=None):
        return "{}"

    def get_model_name(self):
        return "stub"


def test_the_conversation_judge_is_a_local_openai_compatible_model(monkeypatch):
    monkeypatch.setenv("JUDGE_MODEL", "judge-from-env")
    monkeypatch.setenv("JUDGE_BASE_URL", "http://judge.test/v1")

    model = nightly_conversations.judge_model()

    assert model.get_model_name().startswith("judge-from-env")
