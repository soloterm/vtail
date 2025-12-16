---
title: Usage
description: Command-line options and examples for vtail.
---

# Usage

## Basic Syntax

```bash
vtail [options] <file>
```

The file argument is required. If the file doesn't exist, vtail will create it and wait for content.

## Options

| Option | Description |
|--------|-------------|
| `-h`, `--help` | Show help message |
| `-n <lines>` | Number of lines to show initially (default: 100) |
| `--max-lines <n>` | Maximum lines to keep in memory (default: 1000) |
| `--no-vendor` | Start with vendor frames hidden |
| `--no-wrap` | Start with line wrapping disabled |

## Examples

### Basic log monitoring

Monitor Laravel's default log file:

```bash
vtail storage/logs/laravel.log
```

### Hide vendor frames by default

Start with vendor frames already collapsed:

```bash
vtail --no-vendor storage/logs/laravel.log
```

### Show more initial lines

Load the last 500 lines instead of the default 100:

```bash
vtail -n 500 storage/logs/laravel.log
```

### Disable line wrapping

Start with long lines truncated instead of wrapped:

```bash
vtail --no-wrap storage/logs/laravel.log
```

### Combine options

Hide vendor frames and show more history:

```bash
vtail --no-vendor -n 200 storage/logs/laravel.log
```

### Increase memory buffer

Keep more lines in memory for long monitoring sessions:

```bash
vtail --max-lines 5000 storage/logs/laravel.log
```

## Status Bar

The top of the screen shows a status bar with current settings:

```
 laravel.log | Lines: 247 | VENDOR: hidden | WRAP: on | FOLLOWING
```

| Field | Description |
|-------|-------------|
| Filename | The log file being monitored |
| Lines | Total display lines (accounts for wrapping and collapsed frames) |
| VENDOR | `hidden` or `shown` - current vendor frame visibility |
| WRAP | `on` or `off` - current line wrapping mode |
| FOLLOWING | Shown when auto-scrolling is active |

## Hotkey Bar

The bottom of the screen shows available actions:

```
 v hide vendor  w disable wrapping  t truncate file  f unfollow  q quit
```

Labels update to reflect current state. When vendor frames are hidden, it shows "show vendor". When following, it shows "unfollow".

## Buffer Management

vtail maintains a buffer of log lines in memory (default: 1,000 lines, configurable with `--max-lines`). When the buffer exceeds 120% of the limit (e.g., 1,200 lines at the default setting), older lines are automatically trimmed. The scroll position adjusts to maintain your current view.

This keeps memory usage bounded during long monitoring sessions while avoiding constant trimming on every new line.
