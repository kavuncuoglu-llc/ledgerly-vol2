import torch
import torch.nn as nn
import torch.nn.functional as F
from model import GPT, GPTConfig
from sample import chat_prompt, generate
from sft import IGNORE, collate, encode_pair, fine_tune, masked_loss
from train_tokenizer import EOT, train_tokenizer


def tokenizer(tmp_path):
    corpus = tmp_path / "train.txt"
    corpus.write_text("Why was a late fee added? A late fee is added 30 days after the due date.\n" * 40)
    return train_tokenizer([str(corpus)], vocab_size=300)


def test_only_the_answer_and_end_of_text_are_targets(tmp_path):
    tok = tokenizer(tmp_path)
    prompt = tok.encode(chat_prompt("Why was a late fee added?")).ids
    answer = tok.encode("A late fee is added 30 days after the due date.").ids

    x, y = encode_pair(tok, "Why was a late fee added?", "A late fee is added 30 days after the due date.", ctx=256)

    assert len(x) == len(y) == len(prompt) + len(answer)
    assert x == prompt + answer
    assert y[: len(prompt) - 1] == [IGNORE] * (len(prompt) - 1)
    assert [t for t in y if t != IGNORE] == answer + [tok.token_to_id(EOT)]


def test_long_pairs_are_cut_to_the_context_window(tmp_path):
    tok = tokenizer(tmp_path)

    x, y = encode_pair(tok, "Why?", "A late fee is added. " * 40, ctx=32)

    assert len(x) == len(y) == 32


def test_padding_is_never_a_target(tmp_path):
    tok = tokenizer(tmp_path)
    rows = [encode_pair(tok, "Why?", "Late.", 64), encode_pair(tok, "Why was a late fee added?", "A late fee.", 64)]

    x, y = collate(rows, pad_id=0, device="cpu")

    assert x.shape == y.shape
    assert (y[0, len(rows[0][1]) :] == IGNORE).all()


def test_the_loss_counts_answer_positions_only():
    torch.manual_seed(0)
    model = GPT(GPTConfig(vocab=20, ctx=8, layers=1, heads=1, dim=16))
    x = torch.randint(20, (1, 6))
    y = torch.tensor([[IGNORE, IGNORE, IGNORE, 4, 5, 0]])

    loss = masked_loss(model, x, y)

    expected = F.cross_entropy(model(x)[0, 3:], y[0, 3:])
    assert torch.isclose(loss, expected)


def test_a_few_steps_of_post_training_fit_the_pairs(tmp_path):
    torch.manual_seed(0)
    tok = tokenizer(tmp_path)
    model = GPT(GPTConfig(vocab=tok.get_vocab_size(), ctx=64, layers=1, heads=2, dim=32))
    rows = [encode_pair(tok, "Why was a late fee added?", "A late fee is added 30 days after the due date.", 64)]

    _, losses = fine_tune(model, rows, steps=40, lr=3e-3, batch_size=1, device="cpu", pad_id=0)

    assert losses[-1] < losses[0] / 2


class AlwaysSays(nn.Module):
    def __init__(self, token: int, vocab: int = 10):
        super().__init__()
        self.token, self.vocab, self.config = token, vocab, GPTConfig(vocab=vocab, ctx=8)

    def forward(self, idx):
        logits = torch.full((idx.size(0), idx.size(1), self.vocab), -1e9)
        logits[..., self.token] = 0.0
        return logits


def test_sampling_stops_at_end_of_text():
    out = generate(AlwaysSays(0), [5, 6], max_new=20, temperature=0.8, stop_id=0)

    assert out == [5, 6, 0]


def test_sampling_runs_to_the_length_limit_without_end_of_text():
    out = generate(AlwaysSays(3), [5] * 12, max_new=5, temperature=0.0, stop_id=0)

    assert out == [5] * 12 + [3] * 5
