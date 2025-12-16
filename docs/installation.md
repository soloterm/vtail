---
title: Installation
description: How to install vtail for Laravel log viewing.
---

# Installation

## Requirements

Before installing vtail, ensure your system meets these requirements:

| Requirement | Details |
|-------------|---------|
| PHP | 8.1 or higher |
| Operating System | Unix-like (macOS, Linux) |
| PHP Extensions | pcntl, posix, mbstring |

vtail uses Unix process control features (`pcntl`, `posix`) for terminal handling and signal management. These extensions are not available on Windows.

## Global Installation (Recommended)

Install vtail globally to use it from any directory:

```bash
composer global require soloterm/vtail
```

Make sure Composer's global bin directory is in your PATH. Add this to your shell configuration file (`.bashrc`, `.zshrc`, etc.):

```bash
export PATH="$HOME/.composer/vendor/bin:$PATH"
```

Verify the installation:

```bash
vtail --help
```

## Project Installation

Install vtail as a dev dependency in your Laravel project:

```bash
composer require --dev soloterm/vtail
```

Run via Composer's vendor bin:

```bash
./vendor/bin/vtail storage/logs/laravel.log
```

## Dependencies

vtail depends on these SoloTerm packages (installed automatically):

- **[soloterm/screen](https://github.com/soloterm/screen)** - Terminal rendering with ANSI escape sequence support
- **[soloterm/grapheme](https://github.com/soloterm/grapheme)** - Unicode grapheme width calculation for proper alignment

## Troubleshooting

### Command not found

If `vtail` isn't recognized after global installation, verify Composer's bin directory is in your PATH:

```bash
composer global config bin-dir --absolute
```

Add the output directory to your PATH.

### Missing extensions

Check for required extensions:

```bash
php -m | grep -E 'pcntl|posix|mbstring'
```

All three should be listed. If any are missing, install them via your system's package manager or recompile PHP with the required extensions.
