import ast
import json
import re
import tomllib
from pathlib import Path

NOTEBOOK = Path(__file__).resolve().parents[1] / "notebooks" / "billing_qlora_colab.ipynb"
LOCK = Path(__file__).resolve().parents[1] / "uv.lock"


def cells():
    return json.loads(NOTEBOOK.read_text())["cells"]


def code(cell):
    return "".join(cell["source"])


def test_it_is_a_notebook_with_no_saved_output_and_no_secrets():
    notebook = json.loads(NOTEBOOK.read_text())

    assert notebook["nbformat"] == 4
    for cell in cells():
        if cell["cell_type"] == "code":
            assert cell["outputs"] == [] and cell["execution_count"] is None
    text = NOTEBOOK.read_text()
    assert not re.search(r"hf_[A-Za-z0-9]{20,}", text)
    assert "userdata.get('HF_TOKEN')" in text


def test_every_code_cell_is_valid_python_once_magics_are_removed():
    for cell in (c for c in cells() if c["cell_type"] == "code"):
        source = "\n".join(line for line in code(cell).splitlines() if not line.lstrip().startswith(("%", "!")))
        ast.parse(source)


def test_the_first_code_cell_prints_the_gpu_and_picks_the_compute_type_from_what_it_reports():
    first = next(code(c) for c in cells() if c["cell_type"] == "code")

    assert "get_device_name" in first and "total_memory" in first
    assert "is_bf16_supported()" in first and "torch.float16" in first


def test_unsloth_is_imported_before_trl_and_checkpoints_resume():
    train = next(code(c) for c in cells() if "FastLanguageModel" in code(c))

    assert train.index("from unsloth import") < train.index("from trl import")
    assert "resume_from_checkpoint=has_checkpoint(OUT)" in train and "save_steps=200" in train
    assert "use_gradient_checkpointing='unsloth'" in train


def test_the_pinned_installs_are_the_versions_in_the_lock():
    locked = {}
    for package in tomllib.loads(LOCK.read_text())["package"]:
        locked.setdefault(package["name"], set()).add(package["version"])
    pins = dict(re.findall(r"'([A-Za-z_-]+)==([0-9.]+)'", next(code(c) for c in cells() if "%pip install" in code(c))))

    assert {"unsloth", "unsloth_zoo", "bitsandbytes", "transformers", "trl", "peft"} <= set(pins)
    for name, version in pins.items():
        assert version in locked[name.replace("_", "-")], f"{name}=={version} is not in uv.lock"
