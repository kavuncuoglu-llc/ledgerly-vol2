import os
import tempfile
import threading

MAX_PASSAGES = 100
PDF_MAGIC = b"%PDF-"
DEFAULT_RERANK_MODEL = "BAAI/bge-reranker-base"
DEFAULT_MAX_PASSAGE_CHARS = 2000
DEFAULT_MAX_BYTES = 25 * 1024 * 1024


class ParseFailed(Exception):
    pass


class ParserUnavailable(Exception):
    pass


class ModelUnavailable(Exception):
    pass


def env_int(name, default):
    try:
        value = int(os.environ.get(name, ""))
    except ValueError:
        return default

    if value <= 0:
        return default

    return value


def rerank_model_name():
    return os.environ.get("SIDECAR_RERANK_MODEL") or DEFAULT_RERANK_MODEL


class RerankService:
    def __init__(self, loader, model_name=None, max_passage_chars=None):
        self._loader = loader
        self._scorer = None
        self._lock = threading.Lock()
        self.model_name = model_name or rerank_model_name()
        self.max_passage_chars = max_passage_chars or env_int(
            "SIDECAR_MAX_PASSAGE_CHARS", DEFAULT_MAX_PASSAGE_CHARS
        )
        self.last_error = ""

    @property
    def loaded(self):
        return self._scorer is not None

    def load(self):
        with self._lock:
            if self._scorer is not None:
                return True

            try:
                self._scorer = self._loader()
            except Exception as error:
                self.last_error = f"{type(error).__name__}: {error}"
                return False

            self.last_error = ""
            return True

    def rerank(self, question, passages):
        self._validate(question, passages)

        if not passages:
            return []

        if not self.load():
            raise ModelUnavailable(f"Reranker model is not available ({self.last_error}).")

        pairs = [(question, passage[: self.max_passage_chars]) for passage in passages]
        scores = [float(score) for score in self._scorer.predict(pairs)]

        if len(scores) != len(passages):
            raise RuntimeError("Scorer returned a different number of scores than passages.")

        return scores

    def _validate(self, question, passages):
        if not isinstance(question, str) or not question.strip():
            raise ValueError("question must be a non-empty string.")

        if not isinstance(passages, list):
            raise ValueError("passages must be a list of strings.")

        if len(passages) > MAX_PASSAGES:
            raise ValueError(f"passages may contain at most {MAX_PASSAGES} items.")

        for index, passage in enumerate(passages):
            if not isinstance(passage, str):
                raise ValueError(f"passages[{index}] must be a string.")


class ParseService:
    def __init__(self, converter, parser_name="marker", max_bytes=None):
        self._converter = converter
        self.parser_name = parser_name
        self.max_bytes = max_bytes or env_int("SIDECAR_MAX_BYTES", DEFAULT_MAX_BYTES)

    def parse(self, data, filename):
        self._validate(data, filename)

        try:
            markdown = self._converter(data)
        except ParserUnavailable:
            raise
        except Exception as error:
            raise ParseFailed(f"Could not convert {filename or 'upload'}: {error}") from error

        if not isinstance(markdown, str):
            raise ParseFailed(f"Converter returned {type(markdown).__name__}, expected str.")

        return {"markdown": markdown, "parser": self.parser_name}

    def _validate(self, data, filename):
        if not isinstance(data, (bytes, bytearray)) or len(data) == 0:
            raise ValueError("The uploaded file is empty.")

        if len(data) > self.max_bytes:
            raise ValueError(f"The uploaded file exceeds {self.max_bytes} bytes.")

        if not bytes(data[: len(PDF_MAGIC)]) == PDF_MAGIC:
            raise ValueError(f"{filename or 'The uploaded file'} is not a PDF (missing %PDF- header).")


def load_cross_encoder():
    from sentence_transformers import CrossEncoder

    return CrossEncoder(rerank_model_name(), max_length=512)


_marker_state = {"converter": None}
_marker_lock = threading.Lock()


def marker_convert(data):
    # marker-pdf's API differs between releases; this is the only function to adjust on upgrade.
    try:
        from marker.converters.pdf import PdfConverter
        from marker.models import create_model_dict
        from marker.output import text_from_rendered
    except ImportError as error:
        raise ParserUnavailable("marker-pdf is not installed in this environment.") from error

    with _marker_lock:
        if _marker_state["converter"] is None:
            _marker_state["converter"] = PdfConverter(artifact_dict=create_model_dict())

        converter = _marker_state["converter"]

    with tempfile.NamedTemporaryFile(suffix=".pdf") as handle:
        handle.write(data)
        handle.flush()
        rendered = converter(handle.name)

    text, _, _ = text_from_rendered(rendered)

    return text
