#!/bin/bash
#
# Ollama container entrypoint.
#
# Starts `ollama serve` in the background, waits for the API to come up,
# then pre-pulls the embedding model so the first Drupal request doesn't
# block for minutes downloading weights. Pre-pull is best-effort — if it
# fails (network hiccup, registry outage), the server stays up and the
# first real request will retry the pull lazily.

set -u

echo "[ollama-entrypoint] starting ollama serve"
ollama serve &
SERVER_PID=$!

# Propagate SIGTERM/SIGINT to the server child so `docker stop` is clean.
trap 'kill -TERM "$SERVER_PID" 2>/dev/null' TERM INT

# Wait up to 60s for the local API to respond.
echo "[ollama-entrypoint] waiting for server to accept requests"
for i in $(seq 1 60); do
  if ollama list >/dev/null 2>&1; then
    echo "[ollama-entrypoint] server ready after ${i}s"
    break
  fi
  sleep 1
done

# Pre-pull the embedding model if not already cached on the mounted volume.
if ollama list 2>/dev/null | awk 'NR>1 {print $1}' | grep -Fxq 'nomic-embed-text:latest'; then
  echo "[ollama-entrypoint] nomic-embed-text already cached"
else
  echo "[ollama-entrypoint] pulling nomic-embed-text (first run, ~300 MB)"
  if ! ollama pull nomic-embed-text; then
    echo "[ollama-entrypoint] WARNING: pull failed, will retry on first request"
  fi
fi

# Hand off: keep the entrypoint attached to the server process so the
# container exits only when ollama serve exits.
wait "$SERVER_PID"
