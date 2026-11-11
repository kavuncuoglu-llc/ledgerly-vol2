import torch

from scripts import hello_model


def test_pick_device_prefers_cuda_then_mps_then_cpu(monkeypatch):
    monkeypatch.setattr(torch.cuda, "is_available", lambda: True)
    assert hello_model.pick_device() == "cuda"

    monkeypatch.setattr(torch.cuda, "is_available", lambda: False)
    monkeypatch.setattr(torch.backends.mps, "is_available", lambda: True)
    assert hello_model.pick_device() == "mps"

    monkeypatch.setattr(torch.backends.mps, "is_available", lambda: False)
    assert hello_model.pick_device() == "cpu"


def test_pick_dtype_uses_half_precision_only_on_accelerators():
    assert hello_model.pick_dtype("cuda") == torch.bfloat16
    assert hello_model.pick_dtype("mps") == torch.float16
    assert hello_model.pick_dtype("cpu") == torch.float32


def test_prompt_ids_takes_the_ids_out_of_the_tokenizer_output():
    class Tokenizer:
        def apply_chat_template(self, messages, **kwargs):
            assert kwargs["add_generation_prompt"] is True
            return {"input_ids": torch.tensor([[1, 2, 3]])}

    ids = hello_model.prompt_ids(Tokenizer(), hello_model.MESSAGES, "cpu")

    assert ids.shape == (1, 3)
