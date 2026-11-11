import json

import pytest

from scripts import export_to_ollama as ex


class Args:
    adapter = "runs/billing-mlx/adapters"
    base = "meta-llama/Llama-3.1-8B-Instruct"
    revision = None
    quant = "Q4_K_M"
    name = "ledgerly-billing-ft"
    out = "models"
    num_ctx = 8192
    llama_cpp = "llama.cpp"
    dequantize = False


def make_adapter(tmp_path, kind):
    path = tmp_path / kind
    path.mkdir()
    (path / ("adapters.safetensors" if kind == "mlx" else "adapter_model.safetensors")).write_bytes(b"weights")
    return path


def test_the_backend_is_known_from_the_adapter_file(tmp_path):
    assert ex.backend_of(make_adapter(tmp_path, "mlx")) == "mlx"
    assert ex.backend_of(make_adapter(tmp_path, "peft")) == "peft"
    with pytest.raises(ValueError):
        ex.backend_of(tmp_path)


def test_the_book_route_fuses_converts_quantizes_writes_the_modelfile_and_creates():
    steps = ex.plan(Args(), "mlx")

    assert [s["step"] for s in steps] == ["fuse", "convert", "quantize", "modelfile", "create"]
    assert steps[0]["command"][1:4] == ["-m", "mlx_lm", "fuse"]
    assert steps[1]["command"][1].endswith("convert_hf_to_gguf.py") and "f16" in steps[1]["command"]
    assert steps[2]["command"][0] == "llama-quantize" and steps[2]["command"][-1] == "Q4_K_M"
    assert (
        steps[3]["text"] == "FROM ./ledgerly-billing-ft.Q4_K_M.gguf\nPARAMETER num_ctx 8192\nPARAMETER temperature 0\n"
    )
    assert steps[4]["command"][:3] == ["ollama", "create", "ledgerly-billing-ft"]


def test_a_peft_adapter_is_merged_in_python_not_by_a_command():
    steps = ex.plan(Args(), "peft")

    assert steps[0]["command"] is None and steps[0]["python"] == "fuse_peft"


def test_a_four_bit_base_is_dequantized_before_the_conversion():
    args = Args()
    args.dequantize = True

    assert "--dequantize" in ex.plan(args, "mlx")[0]["command"]


def test_none_serves_the_16_bit_file_and_skips_the_quantizer():
    args = Args()
    args.quant = "none"

    steps = ex.plan(args, "mlx")

    assert [s["step"] for s in steps] == ["fuse", "convert", "modelfile", "create"]
    assert steps[2]["text"].startswith("FROM ./ledgerly-billing-ft.f16.gguf")


def test_execute_runs_each_command_in_order_and_writes_the_modelfile(tmp_path):
    args = Args()
    args.out = str(tmp_path)
    ran = []

    ex.execute(
        ex.plan(args, "mlx"),
        args,
        "mlx",
        run=lambda command, check, env=None: ran.append(command[0] if command[0] != ex.sys.executable else "python"),
    )

    assert ran == ["python", "python", "llama-quantize", "ollama"]
    assert (
        (tmp_path / "ledgerly-billing-ft" / "Modelfile")
        .read_text()
        .startswith("FROM ./ledgerly-billing-ft.Q4_K_M.gguf")
    )


def test_the_export_record_has_a_hash_for_the_adapter_and_the_gguf(tmp_path):
    args = Args()
    args.adapter = str(make_adapter(tmp_path, "mlx"))
    args.out = str(tmp_path / "models")
    out = tmp_path / "models" / args.name
    out.mkdir(parents=True)
    (out / f"{args.name}.Q4_K_M.gguf").write_bytes(b"gguf")

    record = json.loads(ex.write_export(args, "mlx").read_text())

    assert record["artifacts"]["adapter"].startswith("sha256:") and record["artifacts"]["gguf"].startswith("sha256:")
    assert record["quantization"] == "Q4_K_M" and record["backend"] == "mlx"


def test_dry_run_prints_every_step_and_changes_nothing(tmp_path, capsys):
    adapter = make_adapter(tmp_path, "mlx")

    assert (
        ex.main(
            [
                str(adapter),
                "--base",
                "b",
                "--name",
                "x",
                "--out",
                str(tmp_path / "m"),
                "--llama-cpp",
                str(tmp_path / "none"),
                "--dry-run",
            ]
        )
        == 0
    )

    out = capsys.readouterr().out
    assert "fuse:" in out and "quantize: llama-quantize" in out and not (tmp_path / "m").exists()
