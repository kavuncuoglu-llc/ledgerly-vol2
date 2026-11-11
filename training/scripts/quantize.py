import argparse
import hashlib
import json
import os
import subprocess
import sys
from pathlib import Path

LEVELS = {
    "f16": None,
    "q8_0": "Q8_0",
    "q6_k": "Q6_K",
    "q5_k_m": "Q5_K_M",
    "q4_k_m": "Q4_K_M",
    "q3_k_m": "Q3_K_M",
}


def parse_level(level: str) -> tuple[str, bool]:
    name, _, flag = level.lower().partition("+")
    if name not in LEVELS:
        raise ValueError(f"unknown level {level!r}: one of {', '.join(LEVELS)}, optionally +imatrix")
    if flag not in ("", "imatrix"):
        raise ValueError(f"unknown option +{flag} in {level!r}: only +imatrix")
    if name == "f16" and flag:
        raise ValueError("f16 is the source: there is nothing to calibrate")
    return name, flag == "imatrix"


def calibration_text(rows: Path, limit: int) -> str:
    lines = []
    with rows.open() as handle:
        for line in handle:
            if len(lines) >= limit:
                break
            row = json.loads(line)
            turns = [*row.get("prompt", []), *row.get("completion", [])]
            lines.append(
                "\n".join(f"{turn['role']}: {turn.get('content') or ''}" for turn in turns if turn.get("content"))
            )
    return "\n\n".join(lines) + "\n"


def tool(llama_cpp: Path, name: str) -> str:
    built = llama_cpp / "build" / "bin" / name
    if not built.exists():
        raise FileNotFoundError(
            f"{built} is missing: run ops/models/build-llama-cpp.sh (it builds the tag training/llama-cpp.lock pins)"
        )
    return str(built)


def imatrix_command(llama_cpp: Path, f16: Path, calibration: Path, out: Path, chunks: int) -> list[str]:
    return [
        tool(llama_cpp, "llama-imatrix"),
        "-m",
        str(f16),
        "-f",
        str(calibration),
        "-o",
        str(out),
        "--chunks",
        str(chunks),
    ]


def quantize_command(llama_cpp: Path, f16: Path, out: Path, quant: str, imatrix: Path | None) -> list[str]:
    command = [tool(llama_cpp, "llama-quantize")]
    if imatrix is not None:
        command += ["--imatrix", str(imatrix)]
    return [*command, str(f16), str(out), quant]


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for block in iter(lambda: handle.read(1 << 20), b""):
            digest.update(block)
    return "sha256:" + digest.hexdigest()


def output_name(stem: str, level: str) -> str:
    name, imatrix = parse_level(level)
    suffix = "f16" if name == "f16" else LEVELS[name]
    return f"{stem}.{suffix}{'.imatrix' if imatrix else ''}.gguf"


def build_levels(args, run=subprocess.run) -> dict:
    f16 = Path(args.f16)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    stem = f16.name.removesuffix(".f16.gguf")
    llama_cpp = Path(args.llama_cpp)
    levels = [parse_level(level) for level in args.levels.split(",")]
    imatrix = None

    if any(wanted for _, wanted in levels):
        if not args.calibration:
            raise ValueError("+imatrix needs --calibration: a text file of your task's own text (training split)")
        imatrix = out / f"{stem}.imatrix"
        run(imatrix_command(llama_cpp, f16, Path(args.calibration), imatrix, args.chunks), check=True)

    built = {}
    for name, wanted in levels:
        key = name + ("+imatrix" if wanted else "")
        target = out / output_name(stem, key)
        if name == "f16":
            built[key] = {"file": f16.name, "path": str(f16)}
            continue
        run(quantize_command(llama_cpp, f16, target, LEVELS[name], imatrix if wanted else None), check=True)
        built[key] = {"file": target.name, "path": str(target)}

    for entry in built.values():
        path = Path(entry["path"])
        entry["bytes"] = path.stat().st_size
        entry["sha256"] = sha256_of(path)
    return built


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="One 16-bit GGUF to several quantization levels (Chapter 44, Topics 2 and 3)"
    )
    parser.add_argument("f16", help="the merged model as <name>.f16.gguf")
    parser.add_argument("--levels", default="q8_0,q5_k_m,q4_k_m+imatrix,q4_k_m,q3_k_m")
    parser.add_argument("--out", required=True)
    parser.add_argument("--calibration", help="task text for the importance matrix (calibration_sample writes it)")
    parser.add_argument(
        "--chunks", type=int, default=200, help="how many 512-token chunks of the calibration text llama-imatrix reads"
    )
    parser.add_argument(
        "--llama-cpp",
        default=os.environ.get("LLAMA_CPP_DIR", str(Path(__file__).resolve().parents[1] / "tools" / "llama.cpp")),
    )
    parser.add_argument(
        "--sample-from", help="a train.jsonl: write --calibration from its first --rows rows, then continue"
    )
    parser.add_argument("--rows", type=int, default=300)
    args = parser.parse_args(argv)

    if args.sample_from:
        if not args.calibration:
            parser.error("--sample-from needs --calibration: where to write the text")
        Path(args.calibration).parent.mkdir(parents=True, exist_ok=True)
        Path(args.calibration).write_text(calibration_text(Path(args.sample_from), args.rows))

    built = build_levels(args)
    manifest = Path(args.out) / "levels.json"
    manifest.write_text(json.dumps({"source": args.f16, "levels": built}, indent=2) + "\n")
    for key, entry in built.items():
        print(f"{key:<16} {entry['bytes'] / 1e6:9.1f} MB  {entry['file']}")
    print(f"levels written to {manifest}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
