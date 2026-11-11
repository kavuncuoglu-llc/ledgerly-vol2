import numpy as np
from train_tokenizer import EOT, SPECIAL_TOKENS, documents, encode_documents, train_tokenizer

TEXT = "Your invoice is overdue. A late fee of 1.5% per month is added after 30 days. Invoices and reminders.\n"


def small_tokenizer(tmp_path):
    corpus = tmp_path / "train.txt"
    corpus.write_text((TEXT * 50 + f"\n{EOT}\n") * 3)
    return train_tokenizer([str(corpus)], vocab_size=400), corpus


def test_special_tokens_are_reserved_first_and_never_merged(tmp_path):
    tok, _ = small_tokenizer(tmp_path)

    assert [tok.token_to_id(t) for t in SPECIAL_TOKENS] == [0, 1, 2]
    assert not any("<|" in token for token in tok.get_vocab() if token not in SPECIAL_TOKENS)
    assert tok.encode(f"Hi{EOT}").tokens[-1] == EOT


def test_any_text_round_trips_through_byte_level_bpe(tmp_path):
    tok, _ = small_tokenizer(tmp_path)
    text = "Café ünïcode — photosynthesis 🙂"

    assert tok.decode(tok.encode(text).ids) == text


def test_documents_are_written_as_uint16_ids_with_end_of_text_between(tmp_path):
    tok, corpus = small_tokenizer(tmp_path)
    docs = documents(corpus)

    ids = encode_documents(tok, docs)

    assert len(docs) == 3
    assert ids.dtype == np.uint16
    assert (ids == tok.token_to_id(EOT)).sum() == 3
    assert ids[-1] == tok.token_to_id(EOT)
    assert tok.decode(ids[: list(ids).index(0)].tolist()) == docs[0]
