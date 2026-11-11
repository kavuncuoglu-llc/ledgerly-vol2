import json

import pytest
from make_dialogues import generate, late_fee, section_question
from make_pairs import HELD_OUT, build_pairs
from prepare_corpus import (
    Document,
    book_header,
    clean_book,
    drop_duplicate_paragraphs,
    parse_article,
    read_book_ids,
    read_dialogues,
    split_by_document,
    strip_gutenberg,
    write_split,
)

BOOK = """﻿The Project Gutenberg eBook of A Test Book\r
\r
Title: A Test Book\r
Language: English\r
\r
*** START OF THE PROJECT GUTENBERG EBOOK A TEST BOOK ***\r
\r
It was a “bright” cold day in April,\r
and the clocks were    striking _thirteen_.\r
\r
[Illustration: a clock]\r
\r
‘Second paragraph.’\r
\r
*** END OF THE PROJECT GUTENBERG EBOOK A TEST BOOK ***\r
\r
Section 1. General Terms of Use and Redistributing Project Gutenberg-tm electronic works\r
"""

ARTICLE = """---
title: Late fees
category: billing
summary: Explains what Ledgerly charges for late invoices.
---

A late fee is applied **automatically** 30 days after the due date (see [Invoice statuses](/help/invoices/statuses)).

## How the fee is calculated

The fee is 1.5% of the outstanding balance per month, prorated by day. Fees never compound.

| Plan | Rate |
| --- | --- |
| Pro | 1.5% |

- Disputed invoices are skipped.
"""


def test_licence_header_and_footer_are_stripped():
    body = strip_gutenberg(BOOK)

    assert "START OF THE PROJECT" not in body
    assert "General Terms of Use" not in body
    assert "Title:" not in body
    assert book_header(BOOK) == {"title": "A Test Book", "language": "English"}


def test_a_file_without_markers_is_refused():
    with pytest.raises(ValueError):
        strip_gutenberg("just some text")


def test_wrapped_lines_quotes_and_whitespace_are_normalised():
    assert clean_book(BOOK) == [
        'It was a "bright" cold day in April, and the clocks were striking thirteen.',
        "'Second paragraph.'",
    ]


def test_exact_duplicate_paragraphs_are_removed_once_seen():
    documents = [
        Document("book", "a", ["Chapter I", "Unique one.", "Shared."]),
        Document("book", "b", ["Chapter I", "Shared.", "Unique two."]),
        Document("help", "c", ["Shared."]),
    ]

    kept, removed = drop_duplicate_paragraphs(documents)

    assert removed == 3
    assert [d.paragraphs for d in kept] == [["Chapter I", "Unique one.", "Shared."], ["Unique two."]]


def test_the_split_is_by_document_and_covers_every_part():
    documents = [Document("book", f"b{i}", [f"book {i}"]) for i in range(100)]
    documents += [Document("dialogue", f"d{i}", [f"dialogue {i}"]) for i in range(50)]

    train, val = split_by_document(documents, 0.02)
    again, _ = split_by_document(documents, 0.02)

    assert {d.name for d in train} & {d.name for d in val} == set()
    assert len(train) + len(val) == 150
    assert sorted(d.part for d in val) == ["book", "book", "dialogue"]
    assert [d.name for d in train] == [d.name for d in again]


def test_documents_are_written_with_an_end_of_text_marker_between_them(tmp_path):
    path = tmp_path / "train.txt"

    write_split([Document("book", "a", ["One.", "Two."]), Document("help", "b", ["Three."])], path)

    assert path.read_text() == "One.\n\nTwo.\n<|endoftext|>\nThree.\n<|endoftext|>\n"


def test_help_articles_become_plain_text_with_sections():
    article = parse_article(ARTICLE, "billing/late-fees")

    assert article.title == "Late fees"
    assert article.summary == "Explains what Ledgerly charges for late invoices."
    assert article.paragraphs[0] == "Late fees"
    assert (
        "A late fee is applied automatically 30 days after the due date (see Invoice statuses)." in article.paragraphs
    )
    assert "Pro, 1.5%" in article.paragraphs
    assert "Disputed invoices are skipped." in article.paragraphs
    assert not any("|" in p or "**" in p or "](" in p for p in article.paragraphs)
    assert article.sections[0][0] == "How the fee is calculated"


def test_book_lists_allow_comments(tmp_path):
    path = tmp_path / "books.txt"
    path.write_text("# ids\n1342  # Pride and Prejudice\n\n84\n")

    assert read_book_ids(path) == [1342, 84]


def test_late_fees_follow_the_help_center_example():
    assert late_fee(1000.0, 30) == 15.0
    assert late_fee(1000.0, 40) == 20.0
    assert late_fee(1000.0, 29) == 0.0
    assert late_fee(50.0, 30) == 0.0


def test_dialogues_are_deterministic_and_read_back_as_one_paragraph(tmp_path):
    help_center = tmp_path / "help" / "billing"
    help_center.mkdir(parents=True)
    (help_center / "late-fees.md").write_text(ARTICLE)

    records = generate(40, tmp_path / "help", seed=7)
    path = tmp_path / "dialogues.jsonl"
    path.write_text("".join(json.dumps(r) + "\n" for r in records))
    documents = read_dialogues(path)

    assert records == generate(40, tmp_path / "help", seed=7)
    assert len(documents) == 40
    assert all(len(d.paragraphs) == 1 for d in documents)
    assert documents[0].paragraphs[0].startswith("Customer: ")
    assert "\nSupport: " in documents[0].paragraphs[0]


def test_section_headings_become_questions():
    assert section_question("Late fees", "How is the fee calculated") == "How is the fee calculated?"
    assert section_question("Late fees", "Changing the rules") == "Can you explain changing the rules in late fees?"


def test_pairs_are_unique_and_leave_out_the_chapter_question():
    sections = [("billing/late-fees", HELD_OUT, "A held-out answer.")]
    sections += [("billing/x", f"Question {i}?", f"Answer {i}.") for i in range(10)]
    dialogues = [
        {"id": f"d{i}", "topic": "late-fee", "turns": [{"text": f"Ask {i % 3}"}, {"text": f"Reply {i}"}]}
        for i in range(10)
    ]

    pairs = build_pairs(sections, dialogues, count=10, help_share=0.6)

    questions = [p["question"] for p in pairs]
    assert len(pairs) == 9
    assert HELD_OUT not in questions
    assert len(set(questions)) == len(questions)
