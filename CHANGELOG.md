# Changelog

Chapter tags (`ch26` to `ch51`, `vol2-start`, `vol3-start`) never move. A fix that changes what a chapter's code does goes on `main` and is listed here. If the chapter's code had to change, it also gets a new tag beside the old one, such as `ch32-r2`.

## Unreleased

- `training/scripts/first_lora.py` (Chapter 39): `sft_config()` turned `bf16` on whatever the device, so building the config failed with `Your setup doesn't support bf16` on a machine with no CUDA or Apple GPU (a Linux CPU runner, for example). `bf16` is now on only when CUDA reports bf16 support or the Apple GPU (MPS) is available. Behaviour on a Mac or an NVIDIA GPU is unchanged. The `ch39` to `vol3-start` tags still contain the old code; check out `main` for the fix.
