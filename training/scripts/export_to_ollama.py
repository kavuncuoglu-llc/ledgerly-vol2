import argparse
import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path

MODELFILE = """FROM ./{gguf}
PARAMETER num_ctx {num_ctx}
PARAMETER temperature 0
"""


def backend_of(adapter: Path) -> str:
    if (adapter / "adapters.safetensors").exists():
        return "mlx"
    if (adapter / "adapter_model.safetensors").exists():
        return "peft"
    raise ValueError(f"{adapter} holds neither adapters.safetensors (mlx-lm) nor adapter_model.safetensors (peft)")


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1 << 20), b""):
            digest.update(block)
    return "sha256:" + digest.hexdigest()


def fuse_command(backend: str, base: str, adapter: Path, fused: Path, dequantize: bool) -> list[str] | None:
    if backend != "mlx":
        return None
    command = [
        sys.executable,
        "-m",
        "mlx_lm",
        "fuse",
        "--model",
        base,
        "--adapter-path",
        str(adapter),
        "--save-path",
        str(fused),
    ]
    if dequantize:
        command.append("--dequantize")
    return command


def fuse_peft(base: str, adapter: Path, fused: Path, revision: str | None = None) -> None:
    import torch
    from peft import PeftModel
    from transformers import AutoModelForCausalLM, AutoTokenizer

    model = AutoModelForCausalLM.from_pretrained(base, dtype=torch.bfloat16, revision=revision)
    merged = PeftModel.from_pretrained(model, str(adapter)).merge_and_unload()
    merged.save_pretrained(str(fused))
    AutoTokenizer.from_pretrained(base, revision=revision).save_pretrained(str(fused))


def convert_command(llama_cpp: Path, fused: Path, outfile: Path) -> list[str]:
    return [
        sys.executable,
        str(llama_cpp / "convert_hf_to_gguf.py"),
        str(fused),
        "--outfile",
        str(outfile),
        "--outtype",
        "f16",
    ]


def llama_tool(llama_cpp: Path, name: str) -> str:
    built = llama_cpp / "build" / "bin" / name
    return str(built) if built.exists() else name


def quantize_command(
    f16: Path, out: Path, quant: str, tool: str = "llama-quantize", imatrix: Path | None = None
) -> list[str]:
    command = [tool]
    if imatrix is not None:
        command += ["--imatrix", str(imatrix)]
    return command + [str(f16), str(out), quant]


def pinned_llama_cpp(llama_cpp: Path) -> dict[str, str]:
    lock = Path(__file__).resolve().parents[1] / "llama-cpp.lock"
    pinned = dict(line.split("=", 1) for line in lock.read_text().split() if "=" in line) if lock.exists() else {}
    return {"tag": pinned.get("tag", "unpinned"), "commit": pinned.get("commit", "unknown"), "path": str(llama_cpp)}


def tool_versions(llama_cpp: Path) -> dict[str, str]:
    from importlib import metadata

    pinned = pinned_llama_cpp(llama_cpp)
    versions = {"llama.cpp": f"{pinned['tag']} ({pinned['commit'][:7]})"}
    for package in ("mlx-lm", "peft", "transformers"):
        try:
            versions[package] = metadata.version(package)
        except metadata.PackageNotFoundError:
            continue
    return versions


def create_command(name: str, modelfile: Path) -> list[str]:
    return ["ollama", "create", name, "-f", str(modelfile)]


def plan(args, backend: str) -> list[dict]:
    out = Path(args.out) / args.name
    fused = out / "fused"
    f16 = out / f"{args.name}.f16.gguf"
    steps = []
    command = fuse_command(backend, args.base, Path(args.adapter), fused, args.dequantize)
    steps.append({"step": "fuse", "command": command, "python": "fuse_peft" if command is None else None})
    steps.append(
        {
            "step": "convert",
            "command": convert_command(Path(args.llama_cpp), fused, f16),
            "env": {"PYTHONPATH": str(Path(args.llama_cpp) / "gguf-py")},
        }
    )
    served = f16
    if args.quant.lower() != "none":
        served = out / f"{args.name}.{args.quant}.gguf"
        steps.append(
            {
                "step": "quantize",
                "command": quantize_command(
                    f16, served, args.quant, llama_tool(Path(args.llama_cpp), "llama-quantize")
                ),
            }
        )
    steps.append(
        {
            "step": "modelfile",
            "text": MODELFILE.format(gguf=served.name, num_ctx=args.num_ctx),
            "path": out / "Modelfile",
        }
    )
    steps.append({"step": "create", "command": create_command(args.name, out / "Modelfile")})
    return steps


def execute(steps: list[dict], args, backend: str, run=subprocess.run) -> None:
    for step in steps:
        print(f"==> {step['step']}", flush=True)
        if step["step"] == "modelfile":
            step["path"].parent.mkdir(parents=True, exist_ok=True)
            step["path"].write_text(step["text"])
            continue
        if step.get("python") == "fuse_peft":
            fuse_peft(args.base, Path(args.adapter), Path(args.out) / args.name / "fused", args.revision)
            continue
        run(step["command"], check=True, env={**os.environ, **step["env"]} if "env" in step else None)


def write_export(args, backend: str) -> Path:
    out = Path(args.out) / args.name
    adapter = Path(args.adapter)
    weights = adapter / ("adapters.safetensors" if backend == "mlx" else "adapter_model.safetensors")
    gguf = out / (f"{args.name}.f16.gguf" if args.quant.lower() == "none" else f"{args.name}.{args.quant}.gguf")
    record = {
        "name": args.name,
        "base": args.base,
        "backend": backend,
        "quantization": args.quant,
        "artifacts": {"adapter": sha256_of(weights)},
        "files": {"adapter": os.path.relpath(weights.resolve(), out.resolve())},
        "tools": tool_versions(Path(args.llama_cpp)),
    }
    if gguf.exists():
        record["artifacts"]["gguf"] = sha256_of(gguf)
        record["files"]["gguf"] = gguf.name
    path = out / "export.json"
    path.write_text(json.dumps(record, indent=2) + "\n")
    return path


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Adapter to Ollama: fuse, convert to GGUF, quantize, create (Chapter 42, Topic 7)"
    )
    parser.add_argument("adapter", help="the adapter directory (mlx-lm adapters.safetensors or a peft adapter)")
    parser.add_argument("--base", required=True, help="the exact base the adapter was trained on")
    parser.add_argument("--revision", help="the base's Hub revision (peft)")
    parser.add_argument("--quant", default="Q4_K_M", help="a llama-quantize type, or none to serve the 16-bit GGUF")
    parser.add_argument("--name", required=True)
    parser.add_argument("--out", default="models")
    parser.add_argument("--num-ctx", type=int, default=8192)
    parser.add_argument(
        "--llama-cpp",
        default=os.environ.get("LLAMA_CPP_DIR", str(Path(__file__).resolve().parents[1] / "tools" / "llama.cpp")),
        help="the pinned checkout ops/models/build-llama-cpp.sh makes (training/llama-cpp.lock names its tag)",
    )
    parser.add_argument("--dequantize", action="store_true", help="the base was 4-bit (mlx-lm fuse)")
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args(argv)

    backend = backend_of(Path(args.adapter))
    steps = plan(args, backend)
    if args.dry_run:
        for step in steps:
            print(
                f"{step['step']}: "
                + (" ".join(step["command"]) if step.get("command") else step.get("python") or f"write {step['path']}")
            )
        return 0
    execute(steps, args, backend)
    path = write_export(args, backend)
    print(f"exported {args.name} ({backend}, {args.quant}); hashes in {path}")
    print(f"next: php artisan ai:template-check {args.name} --against=training")
    print(
        f"      php artisan registry:add {args.name} --dataset=<name> --run=mlflow:<run> --status=candidate --export={path}"  # noqa: E501
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
