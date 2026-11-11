from pathlib import Path

import yaml

CONFIGS = Path(__file__).resolve().parents[1] / "configs"


def load(name):
    return yaml.safe_load((CONFIGS / name).read_text())


def test_the_mlx_config_is_the_books_run_with_prompt_masking_and_alpha_over_rank_of_one():
    config = load("billing-mlx.yaml")

    assert config["model"] == "meta-llama/Llama-3.1-8B-Instruct" and config["fine_tune_type"] == "lora"
    assert config["mask_prompt"] is True and config["grad_checkpoint"] is True
    assert config["lora_parameters"] == {"rank": 16, "scale": 1.0, "dropout": 0.05}
    assert config["batch_size"] == 2 and config["max_seq_length"] == 2048 and config["num_layers"] == -1


def test_the_pytorch_config_has_alpha_equal_to_rank_and_the_book_settings():
    config = load("billing-peft.yaml")

    assert config["lora"]["r"] == config["lora"]["lora_alpha"] == 16
    assert config["trainer"]["num_train_epochs"] == 2 and config["trainer"]["gradient_checkpointing"] is True
    assert isinstance(config["trainer"]["learning_rate"], float)


def test_the_smoke_configs_name_an_ungated_model_and_stay_small():
    mlx, peft = load("billing-mlx-smoke.yaml"), load("billing-peft-smoke.yaml")

    assert (
        mlx["model"].startswith("HuggingFaceTB/SmolLM2-135M") and peft["base"] == "HuggingFaceTB/SmolLM2-135M-Instruct"
    )
    assert mlx["iters"] == 90 and peft["trainer"]["max_steps"] == 4
    assert peft["revision"] == "12fd25f77366fa6b3b4b768ec3050bf629380bac"


def test_the_axolotl_config_is_the_billing_run_as_a_qlora_with_every_linear_layer():
    config = load("axolotl/billing-qlora.yml")

    assert config["adapter"] == "qlora" and config["load_in_4bit"] is True
    assert config["lora_r"] == config["lora_alpha"] == 16 and config["lora_target_linear"] is True
    assert config["datasets"] == [{"path": "data/billing-synthetic/train.jsonl", "type": "chat_template"}]
    assert config["learning_rate"] == 0.0002 and config["num_epochs"] == 2 and config["optimizer"] == "paged_adamw_8bit"
    assert config["mlflow_experiment_name"] == "billing-sweep"
