# Hardware notes (Chapter 21)

What to change on the machine so a model larger than a gaming GPU can use its memory, and how to check it. The sizing sum is `php artisan ai:doctor --size`; the measurement is `php artisan ai:profile`. Figures here are the ones in Chapter 21 and move with every chip generation; Appendix E keeps the current table.

| File | For |
| --- | --- |
| [macos.md](macos.md) | Apple Silicon: the GPU's share of RAM and the `sysctl` that raises it |
| [linux-apu.md](linux-apu.md) | AMD Ryzen AI Max / Strix Halo: firmware carve-out, GTT, ROCm |

Nothing in this directory runs. Every command below changes the machine you run it on, so read it first. None of it is needed for Chapters 0 to 20, 22 or 23 on a laptop.
