import math

import torch
import torch.nn.functional as F
from model import GPT, GPTConfig, count_parameters, expected_parameters

SMALL = GPTConfig(vocab=50, ctx=16, layers=2, heads=2, dim=32)


def test_parameter_count_matches_figure_45_4():
    model = GPT(GPTConfig())
    total = count_parameters(model)
    parts = expected_parameters(GPTConfig())

    assert total == 13_817_856
    assert abs(total - 13.8e6) < 0.05e6
    assert round(parts["token embedding"] / 1e6, 2) == 3.07
    assert round(parts["position embedding"] / 1e6, 2) == 0.10
    assert round(parts["attention per block"] / 1e6, 2) == 0.59
    assert round(parts["feed-forward per block"] / 1e6, 2) == 1.18
    assert round(parts["blocks in total"] / 1e6, 2) == 10.65


def test_output_layer_is_tied_to_the_token_embedding():
    model = GPT(SMALL)

    state_numbers = sum(t.numel() for t in model.state_dict().values())  # lists the shared matrix twice

    assert model.head.weight.data_ptr() == model.tok.weight.data_ptr()
    assert count_parameters(model) == state_numbers - SMALL.vocab * SMALL.dim


def test_changing_a_later_token_never_changes_earlier_logits():
    torch.manual_seed(0)
    model = GPT(SMALL).eval()
    tokens = torch.randint(SMALL.vocab, (1, SMALL.ctx))
    changed = tokens.clone()
    changed[0, 10] = (changed[0, 10] + 1) % SMALL.vocab

    with torch.no_grad():
        before, after = model(tokens), model(changed)

    assert torch.allclose(before[:, :10], after[:, :10], atol=1e-6)
    assert not torch.allclose(before[:, 10:], after[:, 10:], atol=1e-6)


def test_without_the_mask_earlier_positions_see_the_future():
    torch.manual_seed(0)
    model = GPT(GPTConfig(vocab=50, ctx=16, layers=2, heads=2, dim=32, causal=False)).eval()
    tokens = torch.randint(50, (1, 16))
    changed = tokens.clone()
    changed[0, 10] = (changed[0, 10] + 1) % 50

    with torch.no_grad():
        assert not torch.allclose(model(tokens)[:, :10], model(changed)[:, :10], atol=1e-6)


def test_random_weights_give_a_near_uniform_first_loss():
    torch.manual_seed(0)
    model = GPT(GPTConfig(vocab=8000, ctx=32, layers=2, heads=6, dim=384))
    x, y = torch.randint(8000, (4, 32)), torch.randint(8000, (4, 32))

    loss = F.cross_entropy(model(x).view(-1, 8000), y.view(-1)).item()

    assert abs(loss - math.log(8000)) < 0.3
