import os

from langchain_openai import ChatOpenAI, OpenAIEmbeddings
from ragas.embeddings import LangchainEmbeddingsWrapper
from ragas.llms import LangchainLLMWrapper


def judge_name() -> str:
    return os.environ.get("JUDGE_MODEL", "llama3.1:8b")


def base_url() -> str:
    return os.environ.get("JUDGE_BASE_URL", "http://localhost:11434/v1")


def api_key() -> str:
    return os.environ.get(
        "JUDGE_API_KEY", "ollama"
    )  # a local server ignores it; a hosted one needs a real key from the environment


def judge_llm() -> LangchainLLMWrapper:
    return LangchainLLMWrapper(
        ChatOpenAI(model=judge_name(), base_url=base_url(), api_key=api_key(), temperature=0, max_retries=1)
    )


def judge_embeddings() -> LangchainEmbeddingsWrapper:
    return LangchainEmbeddingsWrapper(
        OpenAIEmbeddings(
            model=os.environ.get("JUDGE_EMBED_MODEL", "nomic-embed-text"),
            base_url=base_url(),
            api_key=api_key(),
            check_embedding_ctx_length=False,  # send strings, not token ids: Ollama's endpoint takes strings
        )
    )
