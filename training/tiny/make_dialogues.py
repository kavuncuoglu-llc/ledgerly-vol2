import argparse
import json
import random
import re
from datetime import date, timedelta
from pathlib import Path

from prepare_corpus import read_articles

# Deterministic templates over the fictional Ledgerly facts in resources/help/: no model, no customer data.
NAMES = ["Ana", "Ben", "Chloe", "Dev", "Elif", "Farid", "Grace", "Hugo", "Ines", "Jon", "Kemal", "Lena", "Mo", "Nora"]
BUSINESSES = ["a design studio", "a bakery", "a plumbing firm", "a small agency", "a consultancy", "a print shop"]
TERMS = {"Net 7": 7, "Net 14": 14, "Net 30": 30, "Net 45": 45, "Net 60": 60}
PLANS = {"Starter": (19, 190), "Pro": (49, 490)}
OPENERS = ["Hi,", "Hello,", "Hi there.", "Good morning.", "Quick question.", ""]
CLOSERS = [
    ("Thanks, that makes sense.", "You're welcome. Anything else I can help with?"),
    ("Great, thank you.", "Happy to help."),
    ("OK, got it.", "Glad that's clear. Have a good day."),
]


def money(value: float) -> str:
    return f"${value:,.2f}"


def late_fee(balance: float, days_past_due: int) -> float:
    if days_past_due < 30:
        return 0.0
    fee = round(balance * 0.0005 * days_past_due, 2)  # 1.5% a month is 0.05% a day
    return fee if fee >= 1.0 else 0.0


def invoice_number(rng: random.Random) -> str:
    return f"INV-{rng.randint(1000, 9999)}"


def late_fee_dialogue(rng: random.Random):
    number, balance, days = invoice_number(rng), rng.randint(80, 9000) + rng.choice([0, 0.5, 0.25]), rng.randint(30, 75)
    fee = late_fee(balance, days)
    ask = rng.choice(
        [
            f"Why was a late fee added to invoice {number}?",
            f"There's a late fee on {number} that I don't understand. Where did it come from?",
            f"My client says invoice {number} now has a late fee. Why?",
            f"Can you explain the late fee on {number}?",
        ]
    )
    if fee == 0:
        answer = (
            f"Invoice {number} has a balance of {money(balance)} and is {days} days past due. "
            "The late fee is 1.5% per month, prorated by day, "
            "but a fee under 1.00 is not charged, so there is none yet."
        )
    else:
        answer = (
            f"Invoice {number} was not paid within 30 days of its due date, so Ledgerly added a late fee. "
            f"The fee is 1.5% of the balance per month, prorated by day (0.05% a day). "
            f"At {days} days past due on a balance of {money(balance)}, that comes to {money(fee)}."
        )
    follow = rng.choice(
        [
            (
                "Can I remove it?",
                "Yes. Open the invoice, choose Waive on the Late fee line, pick a reason and confirm.",
            ),
            (
                "Will it keep growing?",
                "It grows by one more day each night until the invoice is paid. Fees never compound.",
            ),
            ("Does my client see it?", "Yes. It appears as a separate Late fee row on the invoice and in the portal."),
        ]
    )
    return "late-fee", [ask, answer, *follow]


def grace_dialogue(rng: random.Random):
    number, days = invoice_number(rng), rng.randint(1, 29)
    ask = rng.choice(
        [
            f"Invoice {number} is {days} days overdue. Has a late fee been charged?",
            f"{number} went overdue {days} days ago. Will my client be charged a fee?",
        ]
    )
    answer = (
        f"Not yet. A late fee is applied 30 days after the due date, and {number} is {days} days past due. "
        f"If it is paid in full within the next {30 - days} days, no fee is added."
    )
    return "late-fee-grace", [ask, answer]


def due_date_dialogue(rng: random.Random):
    term, days = rng.choice(list(TERMS.items()))
    issued = date(2026, 1, 1) + timedelta(days=rng.randint(0, 300))
    due = issued + timedelta(days=days)
    ask = f"I issued an invoice on {issued:%B} {issued.day} with {term} terms. When is it due?"
    answer = (
        f"With {term} the due date is the issue date plus {days} calendar days, so it is due on {due:%B} {due.day}. "
        f"It turns Overdue on {(due + timedelta(days=1)):%B} {(due + timedelta(days=1)).day} if a balance remains."
    )
    return "due-date", [ask, answer]


def plan_dialogue(rng: random.Random):
    plan = rng.choice(list(PLANS))
    monthly, yearly = PLANS[plan]
    ask = rng.choice([f"How much is the {plan} plan?", f"What does {plan} cost per month and per year?"])
    answer = f"{plan} is ${monthly} per month or ${yearly} per year. Enterprise pricing is arranged with sales."
    return "plans", [ask, answer]


def refund_dialogue(rng: random.Random):
    plan_type = rng.choice(["annual", "monthly"])
    ask = f"I pay for Ledgerly {'yearly' if plan_type == 'annual' else 'monthly'}. Can I get a refund if I cancel?"
    if plan_type == "annual":
        days = rng.randint(1, 60)
        answer = (
            f"Annual plans get a full refund if you ask within 30 days of purchase or renewal. "
            f"You bought {days} days ago, so {'you qualify' if days <= 30 else 'the refund window has closed'}."
        )
    else:
        answer = "Monthly plans are not refunded for time already used. Cancelling stops the next renewal."
    return "refunds", [ask, answer]


def reminder_dialogue(rng: random.Random):
    ask = rng.choice(
        [
            "When does Ledgerly send payment reminders?",
            "How often are my clients reminded about an overdue invoice?",
            "Can you tell me the reminder schedule?",
        ]
    )
    answer = "Reminders go out at 3, 7 and 14 days past due. The late fee is applied later, at 30 days past due."
    return "reminders", [ask, answer]


def trial_dialogue(rng: random.Random):
    ask = rng.choice(["How long is the free trial?", "Do I need a card for the trial?", "What plan is the trial on?"])
    answer = "Every new workspace starts with a 14-day free trial of the Pro plan, and you do not need to enter a card."
    return "trial", [ask, answer]


def first_sentences(paragraphs: list[str], limit: int = 2) -> str:
    prose = [p for p in paragraphs if p.endswith((".", "!", "?")) and not p.startswith(("Read ", "See "))]
    sentences = re.split(r"(?<=[.!?])\s+", " ".join(prose))  # table rows and list fragments are left out
    return " ".join(sentences[:limit]).strip()


QUESTION = re.compile(
    r"^(how|what|when|why|who|where|which|can|do|does|is|are)\s+(do|does|to|is|are|can|much|many|long|often|if|i|you)\b",
    re.IGNORECASE,
)


def section_question(title: str, heading: str) -> str:
    if heading.endswith("?") or QUESTION.match(heading):
        return heading.rstrip("?") + "?"
    return f"Can you explain {heading[0].lower() + heading[1:]} in {title.lower()}?"


def help_sections(help_center: Path) -> list[tuple[str, str, str]]:
    found = []
    for article in read_articles(help_center):
        for heading, paragraphs in article.sections:
            answer = first_sentences(paragraphs)
            if len(answer.split()) >= 8:
                found.append((article.slug, section_question(article.title, heading), answer))
    return found


def generate(count: int, help_center: Path, seed: int = 45) -> list[dict]:
    rng = random.Random(seed)
    sections = help_sections(help_center)
    makers = [late_fee_dialogue, grace_dialogue, due_date_dialogue, plan_dialogue, refund_dialogue]
    makers += [reminder_dialogue, trial_dialogue]
    weights = [20, 8, 10, 8, 7, 6, 4]
    records = []
    for i in range(count):
        if rng.random() < 0.37:
            slug, question, answer = rng.choice(sections)
            topic, lines = f"help:{slug}", [question, answer]
        else:
            topic, lines = rng.choices(makers, weights)[0](rng)
        opener = rng.choice(OPENERS)
        intro = f"{opener} I run {rng.choice(BUSINESSES)}. " if rng.random() < 0.3 else f"{opener} "
        lines[0] = (intro + lines[0]).strip()
        if rng.random() < 0.4:
            lines += list(rng.choice(CLOSERS))
        roles = ["customer", "agent"] * (len(lines) // 2)
        turns = [{"role": role, "text": text} for role, text in zip(roles, lines, strict=True)]
        records.append({"id": f"dialogue-{i:05d}", "topic": topic, "customer": rng.choice(NAMES), "turns": turns})
    return records


def main():
    parser = argparse.ArgumentParser(description="Write synthetic, template-based support dialogues")
    parser.add_argument("--help-center", type=Path, default=Path("../../resources/help/"))
    parser.add_argument("--out", type=Path, default=Path("../data/synthetic/support-dialogue.jsonl"))
    parser.add_argument("--count", type=int, default=6000)
    parser.add_argument("--seed", type=int, default=45)
    args = parser.parse_args()
    records = generate(args.count, args.help_center, args.seed)
    args.out.parent.mkdir(parents=True, exist_ok=True)
    args.out.write_text("".join(json.dumps(r) + "\n" for r in records), encoding="utf-8")
    print(f"  {len(records):,} dialogues -> {args.out}")


if __name__ == "__main__":
    main()
