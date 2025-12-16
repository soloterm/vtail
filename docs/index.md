---
title: Introduction
description: Vendor-aware Laravel log viewer with smart stack trace formatting and real-time monitoring.
---

# vtail

vtail is an interactive terminal UI for real-time log monitoring that intelligently collapses vendor frames in stack traces, making it easier to focus on your application code.

```bash
vtail storage/logs/laravel.log
```

## The Problem

Laravel stack traces are noisy. When an exception occurs, you're presented with dozens of frames from the framework, HTTP kernel, middleware pipeline, and other vendor code. Buried somewhere in that wall of text are the 2-3 frames that actually matter—your application code.

Standard log viewers show everything equally, forcing you to mentally filter through vendor frames every time you debug an issue.

## The Solution

vtail collapses vendor frames into a single line showing the count:

```
╭─Trace───────────────────────────────────────────────╮
│ #01 /app/Http/Controllers/UserController.php(42)    │
│ #… (12 vendor frames)                               │
│ #14 /app/Services/PaymentService.php(128)           │
│ #… (5 vendor frames)                                │
│ #20 /app/Jobs/ProcessPayment.php(67)                │
╰─────────────────────────────────────────────────────╯
```

Toggle vendor frames on/off with a single keypress. Focus on what matters.

## Key Features

### Vendor Frame Collapsing

Hide vendor frames with `v`. Consecutive vendor frames collapse into a count like `#… (5 vendor frames)`. Press `v` again to expand them.

### Smart Stack Trace Formatting

Stack traces get visual grouping with borders. Frame numbers and file paths are dimmed so exception messages stand out.

### Line Wrapping Toggle

Long lines can wrap or truncate. Press `w` to switch between modes. Wrapped lines maintain proper indentation for readability.

### Follow Mode

Auto-scroll to new log entries as they arrive. Press `f` or `Space` to toggle. Jump to bottom with `G` to re-enable following.

### Vim-Style Navigation

Navigate with `j`/`k` for line-by-line scrolling, `g`/`G` to jump to top/bottom, and `PgUp`/`PgDn` for page navigation.

### ANSI & Unicode Aware

Proper rendering of colored output and wide characters (CJK, emoji). Built on [soloterm/screen](https://github.com/soloterm/screen) and [soloterm/grapheme](https://github.com/soloterm/grapheme).

## Quick Start

Install vtail globally:

```bash
composer global require soloterm/vtail
```

Start monitoring a log file:

```bash
vtail storage/logs/laravel.log
```

Press `v` to hide vendor frames. Press `q` to quit.

## Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.1 or higher |
| OS | Unix-like (macOS, Linux) |
| Extensions | ext-pcntl, ext-posix, ext-mbstring |

vtail uses Unix process control features and cannot run on Windows.

## Next Steps

- [Installation](installation) - Complete setup guide
- [Usage](usage) - Command-line options and examples
- [Keybindings](keybindings) - All keyboard controls
- [Stack Traces](stack-traces) - How vendor detection works
