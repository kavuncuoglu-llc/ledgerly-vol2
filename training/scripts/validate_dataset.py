import argparse
import json
import sys
from pathlib import Path

ROLES = {"system", "user", "assistant"}


def check(name: str, passed: bool, detail: str) -> dict:
    return {"check": name, "passed": passed, "detail": detail}


def read_rows(path: Path) -> tuple[list[dict], list[int]]:
    rows, bad_lines = [], []

    for number, line in enumerate(path.read_text().splitlines(), start=1):
        if not line.strip():
            continue

        try:
            rows.append(json.loads(line))
        except json.JSONDecodeError:
            bad_lines.append(number)

    return rows, bad_lines


def message_problems(rows: list[dict]) -> list[str]:
    problems = []

    for index, row in enumerate(rows, start=1):
        messages = row.get("messages") if isinstance(row, dict) else None

        if not isinstance(messages, list) or not messages:
            problems.append(f"row {index}: no messages list")
            continue

        for message in messages:
            if not isinstance(message, dict) or message.get("role") not in ROLES:
                problems.append(f"row {index}: unknown role")
                break

            if not isinstance(message.get("content"), str) or not message["content"].strip():
                problems.append(f"row {index}: empty content")
                break

    return problems


def last_not_assistant(rows: list[dict]) -> list[int]:
    return [
        index
        for index, row in enumerate(rows, start=1)
        if isinstance(row, dict)
        and isinstance(row.get("messages"), list)
        and row["messages"]
        and isinstance(row["messages"][-1], dict)
        and row["messages"][-1].get("role") != "assistant"
    ]


def duplicate_rows(rows: list[dict]) -> int:
    seen = set()
    duplicates = 0

    for row in rows:
        key = json.dumps(row, sort_keys=True)
        duplicates += key in seen
        seen.add(key)

    return duplicates


def consent_gaps(rows: list[dict]) -> list[int]:
    return [
        index
        for index, row in enumerate(rows, start=1)
        if isinstance(row, dict) and not (row.get("consented") is True and row.get("redacted") is True)
    ]


def validate(path: Path) -> dict:
    if not path.is_file():
        return {"ok": False, "checks": [check("file exists", False, f"{path} is not a file")]}

    rows, bad_lines = read_rows(path)

    checks = [
        check("file has rows", bool(rows), f"{len(rows)} rows"),
        check("every line is JSON", not bad_lines, "ok" if not bad_lines else f"lines {bad_lines[:5]}"),
    ]

    problems = message_problems(rows)
    checks.append(check("messages are well formed", not problems, "ok" if not problems else "; ".join(problems[:3])))

    wrong_end = last_not_assistant(rows)
    checks.append(
        check("conversations end with the assistant", not wrong_end, "ok" if not wrong_end else f"rows {wrong_end[:5]}")
    )

    duplicates = duplicate_rows(rows)
    checks.append(check("no duplicate rows", duplicates == 0, f"{duplicates} duplicates"))

    if any(isinstance(row, dict) and ("consented" in row or "redacted" in row) for row in rows):
        gaps = consent_gaps(rows)
        checks.append(
            check("consented and redacted flags are true", not gaps, "ok" if not gaps else f"rows {gaps[:5]}")
        )

    return {"ok": all(c["passed"] for c in checks), "checks": checks}


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(
        description="Check an exported chat dataset (JSON Lines) before it leaves the machine"
    )
    parser.add_argument("path")
    parser.add_argument("--json", action="store_true", help="print the report as JSON on stdout")
    args = parser.parse_args(argv)

    print(f"validating {args.path}", file=sys.stderr)
    report = validate(Path(args.path))

    if args.json:
        print(json.dumps(report))
    else:
        for c in report["checks"]:
            print(f"{'ok  ' if c['passed'] else 'FAIL'} {c['check']}: {c['detail']}")

    return 0


if __name__ == "__main__":
    sys.exit(main())
