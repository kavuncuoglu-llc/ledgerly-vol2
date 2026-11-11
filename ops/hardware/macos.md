# Apple Silicon (Chapter 21, Topic 3)

macOS gives the GPU about 75 percent of RAM by default (a little less on small configurations): a 128 GB Mac gives a model roughly 96 GB. `ai:doctor --size` reads this and prints the number it used.

```bash
# See the cap (0 = the default, about 75%)
sysctl iogpu.wired_limit_mb

# Raise it until the next reboot; the value is MB. 110000 on a 128 GB machine leaves about 18 GB for macOS and everything else.
sudo sysctl iogpu.wired_limit_mb=110000

# Confirm Metal is in use and the whole model is resident
ollama ps
```

Raise it only when `ai:doctor --size` says the model needs the last 10 to 20 percent, and you know what else the machine will not be doing. Too high a value starves the OS and the machine stalls; it does not damage anything, and a reboot puts the default back. The setting does not persist: to keep it, put the `sysctl` in a launchd job you control.

If the model fits at the default cap, raising it changes nothing about speed (Exercise 2).

## What `ai:profile` reads here

- `ps -axo rss=,comm=` for the resident memory of `ollama` and `llama-server` processes.
- `ioreg -r -c IOAccelerator` for "In use system memory", which is what Metal has mapped. The book names `powermetrics`; it needs `sudo` and does not report memory, so the repository does not use it.
- `sysctl -n machdep.cpu.brand_string` to find the chip tier for the predicted decode rate: base 100 to 120 GB/s, Pro 150 to 270, Max 400 to 550, Ultra 800+ (the tier midpoint is used). Pass `--bandwidth=GB/s` for your chip's real figure.

Docker Desktop cannot use Metal. Run Ollama natively and point the app at it (`OLLAMA_URL=http://localhost:11434/v1`, or `host.docker.internal` from a container).
