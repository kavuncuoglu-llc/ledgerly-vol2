import json


def has_tool_turns(row: dict) -> bool:
    for message in [*row["prompt"], *row["completion"]]:
        if message.get("role") == "tool" or message.get("tool_calls"):
            return True
    return False


def with_object_arguments(message: dict) -> dict:
    calls = message.get("tool_calls")
    if not calls:
        return message
    fixed = []
    for call in calls:
        function = dict(call.get("function", {}))
        arguments = function.get("arguments")
        if isinstance(arguments, str):
            function["arguments"] = json.loads(arguments) if arguments.strip() else {}
        fixed.append({**call, "function": function})
    return {**message, "tool_calls": fixed}


def messages_of(row: dict) -> list[dict]:
    return [with_object_arguments(message) for message in [*row["prompt"], *row["completion"]]]


def render_prompt_completion(tokenizer, row: dict) -> dict:
    prompt_messages = [with_object_arguments(message) for message in row["prompt"]]
    full = [*prompt_messages, *[with_object_arguments(message) for message in row["completion"]]]
    prompt = tokenizer.apply_chat_template(prompt_messages, tokenize=False, add_generation_prompt=True)
    text = tokenizer.apply_chat_template(full, tokenize=False)
    if not text.startswith(prompt):
        raise ValueError(
            "the rendered conversation does not start with the rendered prompt: the chat template changes earlier turns"
        )
    return {"prompt": prompt, "completion": text[len(prompt) :]}
