# AGENTS.md

This repository defines **GitHub Actions workflows** that build a portable **llama-cli** binary (and optionally a Qwen2.5 GGUF model) for **Ionos shared hosting** (GLIBC 2.31). There is no application server or package manager manifest in-tree.

## Cursor Cloud specific instructions

### What lives where

| Path | Purpose |
|------|---------|
| `.github/workflows/build-llama-ionos.yml` | CI: build `llama-cli` only (Ubuntu 20.04 container) |
| `.github/workflows/build-llama-qwen-ionos.yml` | CI: build binary + download GGUF artifact |
| `.dev/llama.cpp` | Local clone of [ggerganov/llama.cpp](https://github.com/ggerganov/llama.cpp) at ref `b4279` (not committed) |
| `.dev/llama.cpp/build/bin/llama-cli` | Locally built CLI |
| `.dev/models/` | Optional GGUF files for local inference tests (not committed) |

### System packages (one-time on a fresh VM)

Install the same packages as the workflows:

```bash
sudo apt-get update
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
  build-essential ca-certificates cmake git libssl-dev pkg-config file binutils g++ libstdc++-13-dev
```

Use **GCC** for CMake on this image (default `c++` may be Clang and fail linking without extra libs):

```bash
export CC=gcc CXX=g++
```

### Build llama-cli locally

```bash
cd .dev/llama.cpp
rm -rf build
cmake -B build \
  -DCMAKE_BUILD_TYPE=Release \
  -DLLAMA_BUILD_TESTS=OFF \
  -DLLAMA_BUILD_EXAMPLES=ON \
  -DLLAMA_CUDA=OFF \
  -DLLAMA_METAL=OFF \
  -DLLAMA_SYCL=OFF \
  -DLLAMA_CURL=OFF \
  -DBUILD_SHARED_LIBS=OFF \
  -DGGML_BACKEND_DL=OFF \
  -DGGML_NATIVE=OFF
cmake --build build --config Release -j"$(nproc)" --target llama-cli
```

Verify the binary:

```bash
./build/bin/llama-cli --version
objdump -T build/bin/llama-cli | grep -o 'GLIBC_[0-9.]*' | sort -u
```

**Ionos / GLIBC 2.31 note:** A native build on Ubuntu 24.04 requires **GLIBC 2.34+** and is unsuitable for Ionos upload. For production artifacts, run the GitHub workflow (Ubuntu 20.04 container) or reproduce the same build inside `ubuntu:20.04` Docker.

### Download model (optional, ~470 MB)

```bash
mkdir -p .dev/models
curl -L -o .dev/models/qwen2.5-0.5b-instruct-q4_k_m.gguf \
  "https://huggingface.co/Qwen/Qwen2.5-0.5B-Instruct-GGUF/resolve/main/qwen2.5-0.5b-instruct-q4_k_m.gguf"
```

### Hello-world inference

```bash
.dev/llama.cpp/build/bin/llama-cli \
  -m .dev/models/qwen2.5-0.5b-instruct-q4_k_m.gguf \
  -p "Say hello in one short sentence." \
  -n 32
```

### Lint / test

There are **no** in-repo linters or unit tests. `DLLAMA_BUILD_TESTS=OFF` in CI. Validation is: successful `cmake` build, GLIBC check (in workflow), and optional `llama-cli` run with a GGUF.

### CI

Trigger manually: **Actions** → **Build llama-cli for Ionos Shared Hosting** or **Build llama-cli + Qwen2.5-0.5B for Ionos** (`workflow_dispatch`).
