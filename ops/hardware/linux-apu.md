# AMD Ryzen AI Max / Strix Halo on Linux (Chapter 21, Topic 4)

The GPU-reachable memory on an AMD APU is a firmware carve-out ("VRAM") plus a dynamically shared region (GTT). `ai:doctor --size` adds the two.

```text
128 GB LPDDR5X, one controller, ~250 GB/s
|- firmware carve-out ........ 64 GB   (BIOS: UMA Frame Buffer Size; dedicated to the iGPU)
|- GTT (shared, dynamic) ..... up to ~48 GB (amdgpu.gttsize; the GPU maps it on demand)
`- system RAM for OS + app ... the remainder
GPU-reachable for a model = carve-out + GTT = 100+ GB: 70B Q4 (40 GB) with its cache fits; 70B Q8 (75 GB) is tight
```

```bash
# What ROCm sees
rocm-smi --showmeminfo vram gtt

# The GTT size the amdgpu driver was loaded with (MB; -1 or unset means the driver default)
cat /sys/module/amdgpu/parameters/gttsize

# Set it at boot, for example 48 GB: add to the kernel command line
#   amdgpu.gttsize=49152
# then update the bootloader config and reboot. Change the carve-out in the BIOS, not here.
```

If the integrated GPU is not a listed target for your ROCm release, name a nearby supported one:

```bash
HSA_OVERRIDE_GFX_VERSION=11.0.0 ollama serve          # or the nearest supported gfx version
cmake -B build -DGGML_HIP=ON -DAMDGPU_TARGETS=gfx1151 && cmake --build build -j     # llama.cpp for HIP (Chapter 23)
```

Vulkan (`-DGGML_VULKAN=ON`) is the second fallback and runs almost anywhere at a cost in speed. See also Appendix L's troubleshooting table.

Both the carve-out and the GTT are a trade with everything else on the box. A 64 GB carve-out leaves 64 GB for Linux, Postgres, Redis and the app; size the sum with `ai:doctor --size` before you change the BIOS (Exercise 5).

## What `ai:profile` reads here

- `ps -axo rss=,comm=` for the resident memory of the model server.
- `/sys/class/drm/card*/device/mem_info_vram_used` and `mem_info_gtt_used` (bytes) for the GPU's memory; `nvidia-smi --query-gpu=memory.used` on a discrete NVIDIA card instead.
- `/proc/cpuinfo` to recognise a Ryzen AI Max for the predicted decode rate (about 250 GB/s). Pass `--bandwidth=GB/s` otherwise.
