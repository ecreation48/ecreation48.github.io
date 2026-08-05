#!/usr/bin/env bash
set -euo pipefail

WHISPER_DIR="${WHISPER_DIR:-/opt/whisper.cpp}"
MODEL="${MODEL:-tiny}"
USE_CUDA="${USE_CUDA:-false}"

if [[ "$EUID" -ne 0 ]]; then
  echo "Lance ce script en root : sudo bash scripts/linux/install-whisper.sh"
  exit 1
fi

apt-get update
apt-get install -y build-essential cmake git ffmpeg

if [[ ! -d "$WHISPER_DIR/.git" ]]; then
  git clone https://github.com/ggml-org/whisper.cpp "$WHISPER_DIR"
else
  git -C "$WHISPER_DIR" pull --ff-only
fi

cmake_args=(-B "$WHISPER_DIR/build" -S "$WHISPER_DIR")
if [[ "$USE_CUDA" == "true" ]]; then
  cmake_args+=(-DGGML_CUDA=1)
fi

cmake "${cmake_args[@]}"
cmake --build "$WHISPER_DIR/build" -j"$(nproc)"
"$WHISPER_DIR/models/download-ggml-model.sh" "$MODEL"

echo "Whisper installé : $WHISPER_DIR/build/bin/whisper-cli"
echo "Modèle installé : $WHISPER_DIR/models/ggml-$MODEL.bin"
