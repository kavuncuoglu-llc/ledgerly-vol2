import argparse
import json
import os
import random
import re
import time
from dataclasses import dataclass, field
from pathlib import Path

import requests

EOT = "<|endoftext|>"
GUTENBERG_URL = "https://www.gutenberg.org/cache/epub/{id}/pg{id}.txt"
START = re.compile(r"^\*\*\*\s*START OF (THE|THIS) PROJECT GUTENBERG E-?BOOK.*$", re.IGNORECASE | re.MULTILINE)
END = re.compile(r"^\*\*\*\s*END OF (THE|THIS) PROJECT GUTENBERG E-?BOOK.*$", re.IGNORECASE | re.MULTILINE)
QUOTES = str.maketrans({"‘": "'", "’": "'", "‚": "'", "‛": "'", "“": '"', "”": '"', "„": '"', "‟": '"', " ": " "})
LINK = re.compile(r"\[([^\]]+)\]\([^)]*\)")


@dataclass
class Document:
    part: str
    name: str
    paragraphs: list[str]


@dataclass
class Article:
    slug: str
    title: str
    summary: str
    paragraphs: list[str]
    sections: list[tuple[str, list[str]]] = field(default_factory=list)


def normalise_line(line: str) -> str:
    return re.sub(r"[ \t\f\v]+", " ", line.translate(QUOTES)).strip()


def prose_paragraphs(text: str) -> list[str]:
    text = text.replace("﻿", "").replace("\r\n", "\n").replace("\r", "\n")
    paragraphs = []
    for block in re.split(r"\n\s*\n", text):
        joined = normalise_line(" ".join(block.split("\n")))  # Gutenberg hard-wraps lines; join them
        if joined:
            paragraphs.append(joined)
    return paragraphs


def book_header(raw: str) -> dict:
    head = START.split(raw, maxsplit=1)[0]
    fields = {}
    for key in ("Title", "Language"):
        found = re.search(rf"^{key}:\s*(.+)$", head, re.MULTILINE)
        fields[key.lower()] = found.group(1).strip() if found else ""
    return fields


def strip_gutenberg(raw: str) -> str:
    start, end = START.search(raw), END.search(raw)
    if start is None or end is None or end.start() < start.end():
        raise ValueError("no Project Gutenberg start and end markers")
    return raw[start.end() : end.start()]  # drops the licence header and footer


def clean_book(raw: str) -> list[str]:
    body = strip_gutenberg(raw).replace("_", "")  # _italics_ markers
    return [p for p in prose_paragraphs(body) if not p.startswith("[Illustration")]


def read_book_ids(path: Path) -> list[int]:
    ids = []
    for line in path.read_text().splitlines():
        value = line.split("#", 1)[0].strip()
        if value:
            ids.append(int(value))
    return ids


def fetch_book(book_id: int, cache: Path, delay: float) -> str:
    cached = cache / f"pg{book_id}.txt"
    if cached.exists():
        return cached.read_text(encoding="utf-8")
    response = requests.get(
        GUTENBERG_URL.format(id=book_id), timeout=60, headers={"User-Agent": "ledgerly-tiny-corpus/1.0"}
    )
    response.raise_for_status()
    response.encoding = "utf-8"
    cache.mkdir(parents=True, exist_ok=True)
    cached.write_text(response.text, encoding="utf-8")
    time.sleep(delay)  # one request at a time, politely
    return response.text


def markdown_paragraphs(markdown: str) -> list[str]:
    paragraphs, current = [], []

    def flush():
        if current:
            paragraphs.append(normalise_line(" ".join(current)))
            current.clear()

    for line in markdown.splitlines():
        text = LINK.sub(r"\1", line).replace("**", "").replace("`", "").strip()
        if not text:
            flush()
        elif re.fullmatch(r"\|?[\s:|-]+\|?", text):
            continue  # a table's separator row
        elif text.startswith("|"):
            flush()
            paragraphs.append(", ".join(cell.strip() for cell in text.strip("|").split("|") if cell.strip()))
        elif re.match(r"^(#+|[-*]|\d+\.)\s+", text):
            flush()
            paragraphs.append(normalise_line(re.sub(r"^(#+|[-*]|\d+\.)\s+", "", text)))
        else:
            current.append(text)
    flush()
    return [p for p in paragraphs if p]


def parse_article(markdown: str, slug: str) -> Article:
    meta, body = {}, markdown
    if markdown.startswith("---"):
        _, front, body = markdown.split("---", 2)
        for line in front.strip().splitlines():
            key, _, value = line.partition(":")
            meta[key.strip()] = value.strip()
    title = meta.get("title", slug)
    sections, heading, lines = [], "", []
    for line in body.splitlines():
        if line.startswith("## "):
            if heading:
                sections.append((heading, markdown_paragraphs("\n".join(lines))))
            heading, lines = normalise_line(line[3:]), []
        elif heading:
            lines.append(line)
    if heading:
        sections.append((heading, markdown_paragraphs("\n".join(lines))))
    return Article(slug, title, meta.get("summary", ""), [title, *markdown_paragraphs(body)], sections)


def read_articles(folder: Path) -> list[Article]:
    articles = []
    for path in sorted(folder.rglob("*.md")) + sorted(folder.rglob("*.txt")):
        slug = path.relative_to(folder).with_suffix("").as_posix()
        articles.append(parse_article(path.read_text(encoding="utf-8"), slug))
    return articles


def dialogue_text(record: dict) -> str:
    speaker = {"customer": "Customer", "agent": "Support"}
    return "\n".join(f"{speaker[turn['role']]}: {normalise_line(turn['text'])}" for turn in record["turns"])


def read_dialogues(path: Path) -> list[Document]:
    documents = []
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.strip():
            record = json.loads(line)
            documents.append(Document("dialogue", record["id"], [dialogue_text(record)]))  # one paragraph each
    return documents


def drop_duplicate_paragraphs(documents: list[Document]) -> tuple[list[Document], int]:
    seen, removed, kept = set(), 0, []
    for document in documents:
        paragraphs = []
        for paragraph in document.paragraphs:
            if paragraph in seen:
                removed += 1
                continue
            seen.add(paragraph)
            paragraphs.append(paragraph)
        if paragraphs:
            kept.append(Document(document.part, document.name, paragraphs))
    return kept, removed


def split_by_document(documents: list[Document], val_fraction: float, seed: int = 45):
    train, val = [], []
    for part in sorted({d.part for d in documents}):
        group = sorted((d for d in documents if d.part == part), key=lambda d: d.name)
        random.Random(f"{seed}:{part}").shuffle(group)
        n_val = max(1, round(len(group) * val_fraction)) if len(group) > 1 else 0  # every part is in validation
        val.extend(group[:n_val])
        train.extend(group[n_val:])
    return train, val


def write_split(documents: list[Document], path: Path) -> int:
    text = f"\n{EOT}\n".join("\n\n".join(d.paragraphs) for d in documents)
    path.write_text(text + f"\n{EOT}\n", encoding="utf-8")
    return len(text)


def load_books(ids: list[int], cache: Path, delay: float) -> tuple[list[Document], list[dict]]:
    documents, used = [], []
    for book_id in ids:
        raw = fetch_book(book_id, cache, delay)
        header = book_header(raw)
        if header["language"] and "English" not in header["language"]:
            print(f"  skip {book_id}: language {header['language']}")
            continue
        documents.append(Document("book", f"pg{book_id}", clean_book(raw)))
        used.append({"id": book_id, "title": header["title"]})
    return documents, used


def main():
    parser = argparse.ArgumentParser(description="Build the tiny model's corpus, split by document")
    parser.add_argument("--gutenberg-list", type=Path, default=Path("books.txt"))
    parser.add_argument("--help-center", type=Path, default=Path("../../resources/help/"))
    parser.add_argument("--synthetic", type=Path, default=Path("../data/synthetic/support-dialogue.jsonl"))
    parser.add_argument("--out", type=Path, default=Path("corpus/"))
    parser.add_argument("--val-fraction", type=float, default=0.02)
    parser.add_argument("--cache", type=Path, default=Path(os.environ.get("TINY_CACHE", "~/.cache/ledgerly-tiny")))
    parser.add_argument("--delay", type=float, default=2.0)
    parser.add_argument("--seed", type=int, default=45)
    args = parser.parse_args()

    books, used = load_books(read_book_ids(args.gutenberg_list), args.cache.expanduser() / "gutenberg", args.delay)
    articles = [Document("help", a.slug, a.paragraphs) for a in read_articles(args.help_center)]
    dialogues = read_dialogues(args.synthetic)
    documents, removed = drop_duplicate_paragraphs(books + articles + dialogues)
    train, val = split_by_document(documents, args.val_fraction, args.seed)

    args.out.mkdir(parents=True, exist_ok=True)
    train_chars, val_chars = write_split(train, args.out / "train.txt"), write_split(val, args.out / "val.txt")
    manifest = {
        "books": used,
        "help_articles": len(articles),
        "dialogues": len(dialogues),
        "duplicate_paragraphs_removed": removed,
        "validation": sorted(d.name for d in val),
        "train_chars": train_chars,
        "val_chars": val_chars,
    }
    (args.out / "manifest.json").write_text(json.dumps(manifest, indent=2))
    share = train_chars / (train_chars + val_chars)
    print(
        f"  books {len(books)} (headers and footers stripped) · help articles {len(articles)} · "
        f"dialogues {len(dialogues):,}"
    )
    print(
        f"  duplicate paragraphs removed {removed:,} · split by document: "
        f"train {share:.0%} · validation {1 - share:.0%} ({len(train)} / {len(val)} documents)"
    )


if __name__ == "__main__":
    main()
