import json
from pathlib import Path

import pytest

from scripts import export_to_ollama as ex
from scripts import quantize as q


def test_levels_parse_with_and_without_an_importance_matrix():
    assert q.parse_level("q4_k_m+imatrix") == ("q4_k_m", True)
    assert q.parse_level("Q8_0") == ("q8_0", False)
    assert q.parse_level("f16") == ("f16", False)
    for bad in ("q2_k", "q4_k_m+awq", "f16+imatrix"):
        with pytest.raises(ValueError):
            q.parse_level(bad)


def test_the_calibration_text_is_the_tasks_own_conversations(tmp_path):
    rows = tmp_path / "train.jsonl"
    rows.write_text(
        "\n".join(
            json.dumps(
                {
                    "prompt": [
                        {"role": "system", "content": "You are Ledgerly."},
                        {"role": "user", "content": f"q{i}"},
                    ],
                    "completion": [{"role": "assistant", "content": f"a{i}"}],
                }
            )
            for i in range(5)
        )
    )

    text = q.calibration_text(rows, 3)

    assert text.count("user: q") == 3 and "assistant: a2" in text and "q3" not in text


def test_the_commands_use_the_pinned_build_and_an_imatrix_only_when_asked(tmp_path):
    (tmp_path / "build" / "bin").mkdir(parents=True)
    for name in ("llama-quantize", "llama-imatrix"):
        (tmp_path / "build" / "bin" / name).write_text("")

    f16 = Path("m.f16.gguf")

    plain = q.quantize_command(tmp_path, f16, Path("m.Q4_K_M.gguf"), "Q4_K_M", None)
    tuned = q.quantize_command(tmp_path, f16, Path("m.Q4_K_M.imatrix.gguf"), "Q4_K_M", Path("m.imatrix"))

    assert plain == [str(tmp_path / "build/bin/llama-quantize"), "m.f16.gguf", "m.Q4_K_M.gguf", "Q4_K_M"]
    assert tuned[1:3] == ["--imatrix", "m.imatrix"] and tuned[-1] == "Q4_K_M"
    assert q.imatrix_command(tmp_path, f16, Path("cal.txt"), Path("m.imatrix"), 50)[1:] == [
        "-m", "m.f16.gguf", "-f", "cal.txt", "-o", "m.imatrix", "--chunks", "50",
    ]  # fmt: skip


def test_a_missing_build_says_how_to_make_it(tmp_path):
    with pytest.raises(FileNotFoundError, match="build-llama-cpp.sh"):
        q.tool(tmp_path, "llama-quantize")


def test_build_levels_runs_the_imatrix_once_then_each_level_and_records_hashes(tmp_path):
    (tmp_path / "bin").mkdir()
    for name in ("llama-quantize", "llama-imatrix"):
        (tmp_path / "build" / "bin").mkdir(parents=True, exist_ok=True)
        (tmp_path / "build" / "bin" / name).write_text("")
    f16 = tmp_path / "ft.f16.gguf"
    f16.write_bytes(b"sixteen")
    calibration = tmp_path / "cal.txt"
    calibration.write_text("text")
    ran = []

    def fake_run(command, check):
        ran.append(command)
        if "llama-quantize" in command[0]:
            Path(command[-2]).write_bytes(b"q" * 4)
        if "llama-imatrix" in command[0]:
            Path(command[command.index("-o") + 1]).write_bytes(b"imatrix")

    class Args:
        pass

    args = Args()
    args.f16, args.out, args.llama_cpp, args.calibration, args.chunks = (
        str(f16),
        str(tmp_path / "out"),
        str(tmp_path),
        str(calibration),
        10,
    )
    args.levels = "f16,q4_k_m+imatrix,q4_k_m"

    built = q.build_levels(args, run=fake_run)

    assert [Path(c[0]).name for c in ran] == ["llama-imatrix", "llama-quantize", "llama-quantize"]
    assert set(built) == {"f16", "q4_k_m+imatrix", "q4_k_m"}
    assert built["q4_k_m+imatrix"]["file"] == "ft.Q4_K_M.imatrix.gguf" and built["q4_k_m"]["bytes"] == 4
    assert built["f16"]["sha256"].startswith("sha256:") and "--imatrix" in ran[1] and "--imatrix" not in ran[2]


def test_an_importance_matrix_without_task_text_is_refused(tmp_path):
    class Args:
        f16 = str(tmp_path / "ft.f16.gguf")
        out = str(tmp_path / "out")
        llama_cpp = str(tmp_path)
        calibration = None
        chunks = 10
        levels = "q4_k_m+imatrix"

    with pytest.raises(ValueError, match="task's own text"):
        q.build_levels(Args(), run=lambda *a, **k: None)


def test_the_export_record_names_each_file_relative_to_itself_and_the_pinned_tools(tmp_path):
    out = tmp_path / "models" / "ledgerly-billing-ft"
    out.mkdir(parents=True)
    adapter = tmp_path / "runs" / "adapters"
    adapter.mkdir(parents=True)
    (adapter / "adapters.safetensors").write_bytes(b"weights")
    (out / "ledgerly-billing-ft.Q4_K_M.gguf").write_bytes(b"gguf")

    class Args:
        name = "ledgerly-billing-ft"
        base = "meta-llama/Llama-3.1-8B-Instruct"
        quant = "Q4_K_M"
        llama_cpp = "llama.cpp"

    Args.out = str(tmp_path / "models")
    Args.adapter = str(adapter)

    record = json.loads(ex.write_export(Args, "mlx").read_text())

    assert record["files"] == {
        "adapter": "../../runs/adapters/adapters.safetensors",
        "gguf": "ledgerly-billing-ft.Q4_K_M.gguf",
    }
    assert record["artifacts"]["gguf"].startswith("sha256:") and record["tools"]["llama.cpp"].startswith("b")


def test_llama_cpp_is_pinned_by_tag_and_commit():
    pinned = ex.pinned_llama_cpp(Path("x"))

    assert pinned["tag"].startswith("b") and len(pinned["commit"]) == 40
