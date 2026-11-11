import textwrap

from scripts import train_lora as tl

CONFIG = textwrap.dedent(
    """
    base: Qwen/Qwen2.5-0.5B-Instruct
    max_seq_length: 512
    lora: {r: 16, lora_alpha: 16, lora_dropout: 0.05}
    trainer: {output_dir: runs/x, num_train_epochs: 2, learning_rate: 1.0e-4}
    """
)


class Torch:
    def __init__(self, cuda=False, bf16=False, mps=False):
        self.cuda = type(
            "cuda", (), {"is_available": staticmethod(lambda: cuda), "is_bf16_supported": staticmethod(lambda: bf16)}
        )
        self.backends = type("b", (), {"mps": type("mps", (), {"is_available": staticmethod(lambda: mps)})})


def test_the_device_is_cuda_then_mps_then_cpu_and_cpu_can_be_forced():
    assert tl.pick_device(Torch(cuda=True)) == "cuda"
    assert tl.pick_device(Torch(mps=True)) == "mps"
    assert tl.pick_device(Torch()) == "cpu"
    assert tl.pick_device(Torch(cuda=True), "cpu") == "cpu"


def test_bfloat16_only_where_the_gpu_supports_it():
    assert tl.pick_dtype_name(Torch(cuda=True, bf16=True), "cuda") == "bfloat16"
    assert tl.pick_dtype_name(Torch(cuda=True, bf16=False), "cuda") == "float16"
    assert tl.pick_dtype_name(Torch(mps=True), "mps") == "float32"


def test_lora_settings_come_from_the_config_with_every_linear_layer_targeted(tmp_path):
    config = tmp_path / "c.yaml"
    config.write_text(CONFIG)

    kwargs = tl.lora_kwargs(tl.load_config(str(config)))

    assert kwargs == {
        "r": 16,
        "lora_alpha": 16,
        "lora_dropout": 0.05,
        "target_modules": "all-linear",
        "task_type": "CAUSAL_LM",
    }


def test_trainer_settings_follow_the_dtype_and_the_overrides_on_the_command_line(tmp_path):
    config = tmp_path / "c.yaml"
    config.write_text(CONFIG)
    cfg = tl.load_config(str(config))

    gpu = tl.trainer_kwargs(cfg, "float16", "cuda")
    cpu = tl.trainer_kwargs(cfg, "float32", "cpu", {"max_steps": 4, "output_dir": None})

    assert gpu["fp16"] is True and gpu["bf16"] is False and gpu["max_length"] == 512
    assert gpu["include_num_input_tokens_seen"] is True and "use_cpu" not in gpu
    assert cpu["use_cpu"] is True and cpu["max_steps"] == 4 and cpu["output_dir"] == "runs/x"
    assert cpu["fp16"] is False and cpu["bf16"] is False


def test_unsloth_gets_the_seven_projection_layers_no_dropout_and_its_own_checkpointing(tmp_path):
    config = tmp_path / "c.yaml"
    config.write_text(CONFIG + "seed: 7\n")

    kwargs = tl.unsloth_lora_kwargs(tl.load_config(str(config)))

    assert kwargs["target_modules"] == tl.UNSLOTH_TARGETS and len(kwargs["target_modules"]) == 7
    assert kwargs["lora_dropout"] == 0 and kwargs["use_gradient_checkpointing"] == "unsloth"
    assert kwargs["r"] == 16 and kwargs["lora_alpha"] == 16 and kwargs["random_state"] == 7


def test_the_unsloth_path_loads_the_base_through_unsloth_and_leaves_peft_to_it(monkeypatch):
    calls = {}

    class FakeFastLanguageModel:
        @staticmethod
        def from_pretrained(**kwargs):
            calls["load"] = kwargs
            return "model", "tokenizer"

        @staticmethod
        def get_peft_model(model, **kwargs):
            calls["peft"] = (model, kwargs)
            return "peft-model"

    fake = type("unsloth", (), {"FastLanguageModel": FakeFastLanguageModel})
    monkeypatch.setitem(__import__("sys").modules, "unsloth", fake)
    cfg = {"base": "b", "max_seq_length": 2048, "lora": {"r": 16, "lora_alpha": 16}}

    model, tokenizer, peft_config = tl.build_unsloth(cfg, load_in_4bit=True)

    assert (model, tokenizer, peft_config) == ("peft-model", "tokenizer", None)
    assert calls["load"] == {"model_name": "b", "max_seq_length": 2048, "load_in_4bit": True}
    assert calls["peft"][0] == "model" and calls["peft"][1]["use_gradient_checkpointing"] == "unsloth"
