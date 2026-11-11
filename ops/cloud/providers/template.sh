#!/usr/bin/env bash
# Chapter 43, Topic 6: what a provider script for ops/cloud/gpu-session.sh train must define. Copy it to providers/<your-provider>.sh and fill each function in
# with that provider's CLI. The session script sources the file; it never reads a provider's credentials itself, and nothing here may print one.
#
# Every function prints only what its description says, returns non-zero on failure, and must be safe to call twice (the exit handler calls the last two even
# when the run failed). Cloud CLI calls are yours to write: this repository has no provider's CLI to copy from, so none is guessed here.

provider_create() {
  # args: region gpu interruptible(0|1). Create a GPU machine in the region (an interruptible one when asked) and print its id.
  echo "providers/template.sh: provider_create is not written" >&2
  return 1
}

provider_volume_create() {
  # args: instance. Create an encrypted volume (a key you control, not the provider's default), attach it and print its id.
  return 1
}

provider_volume_encrypted() {
  # args: volume. Return 0 only if the provider reports the volume as encrypted. The session refuses to put data on it otherwise.
  return 1
}

provider_issue_credentials() {
  # args: dataset ttl_seconds. Give the machine short-lived credentials that read only this dataset version (a pre-signed URL, a scoped token),
  # and a read-only Hugging Face token. Never the app's own keys, never a write token.
  return 1
}

provider_set_deadline() {
  # args: instance seconds. Make the machine shut itself down after this many seconds whatever is running, so a crashed script cannot leave it billing.
  return 1
}

provider_train() {
  # args: instance volume config dataset results. Run the training on the machine, in the foreground, with checkpoints every 200 steps to results.
  # Exit 75 if the instance was reclaimed (interruptible machines), so the session resumes on a new one; otherwise the training's own exit code.
  return 1
}

provider_gpu_utilization() {
  # args: instance. Print the GPU's utilization as an integer percent (nvidia-smi --query-gpu=utilization.gpu --format=csv,noheader,nounits over ssh).
  echo 100
}

provider_push_results() {
  # args: instance volume results. Copy the adapter and the MLflow run to the results destination, and nothing else.
  return 1
}

provider_volume_destroy() {
  # args: volume. Destroy the volume and its key.
  return 1
}

provider_stop() {
  # args: instance. Stop and delete the machine.
  return 1
}
