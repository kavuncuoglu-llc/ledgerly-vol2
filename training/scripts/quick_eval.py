import argparse
import json

try:
    from scripts.first_lora import BASE, BASE_REVISION, pick_device, pick_dtype
except ModuleNotFoundError:  # run as `python scripts/quick_eval.py`, where the script's folder is the import root
    from first_lora import BASE, BASE_REVISION, pick_device, pick_dtype

MAX_NEW_TOKENS = 200


def plain(text: str) -> str:
    return text.lower().replace(",", "").replace("$", "")


def required_facts_present(answer: str, facts: list[str]) -> bool:
    return all(plain(fact) in plain(answer) for fact in facts)


def load_cases(path: str, limit: int | None = None) -> list[dict]:
    with open(path) as f:
        cases = [json.loads(line) for line in f if line.strip()]
    return cases[:limit] if limit else cases


def messages_for(case: dict) -> list[dict]:
    if "prompt" in case:
        return case["prompt"]
    return [{"role": "user", "content": case["question"]}]


def count_passing(answers: list[str], cases: list[dict]) -> int:
    return sum(
        required_facts_present(answer, case.get("must_include", []))
        for answer, case in zip(answers, cases, strict=True)
    )


def report(base: int, tuned: int, total: int) -> str:
    return f"required facts present: base {base} / {total} · base + adapter {tuned} / {total}"


def answer_all(model, tokenizer, cases: list[dict], device: str) -> list[str]:
    answers = []
    for case in cases:
        text = tokenizer.apply_chat_template(messages_for(case), tokenize=False, add_generation_prompt=True)
        inputs = tokenizer(text, return_tensors="pt").to(device)
        output = model.generate(**inputs, max_new_tokens=MAX_NEW_TOKENS, do_sample=False)
        answers.append(tokenizer.decode(output[0][inputs["input_ids"].shape[1] :], skip_special_tokens=True))
    return answers


def main(adapter: str, cases_path: str, limit: int | None = None) -> None:
    import torch
    from peft import PeftModel
    from transformers import AutoModelForCausalLM, AutoTokenizer

    device = pick_device(torch)
    cases = load_cases(cases_path, limit)
    tokenizer = AutoTokenizer.from_pretrained(BASE, revision=BASE_REVISION)
    base = AutoModelForCausalLM.from_pretrained(BASE, revision=BASE_REVISION, dtype=pick_dtype(torch, device)).to(
        device
    )
    model = PeftModel.from_pretrained(base, adapter).to(device)
    model.eval()
    with model.disable_adapter():
        base_answers = answer_all(model, tokenizer, cases, device)
    tuned_answers = answer_all(model, tokenizer, cases, device)
    print(report(count_passing(base_answers, cases), count_passing(tuned_answers, cases), len(cases)))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--adapter", required=True)
    parser.add_argument("--cases", required=True)
    parser.add_argument("--limit", type=int)
    args = parser.parse_args()
    main(args.adapter, args.cases, args.limit)
