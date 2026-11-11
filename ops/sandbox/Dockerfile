# Chapter 33, Topic 7: the image generated code runs in. Nothing is installed: the interpreter and nothing else, no pip, no compiler, no network at run time.
FROM python:3.12.11-slim-bookworm

RUN rm -rf /var/lib/apt/lists/* /usr/local/bin/pip* /usr/local/lib/python3.12/site-packages/pip* /usr/local/lib/python3.12/ensurepip

WORKDIR /tmp

USER 65534:65534

ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 HOME=/tmp

CMD ["python", "--version"]
