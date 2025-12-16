# CLAUDE.md

This file provides guidance to Claude Code when working with code in this repository.

## Project Overview

**vtail** is a vendor-aware, real-time log viewer TUI for Laravel logs. It provides:
- Stack trace formatting with bordered trace boxes
- Vendor frame detection and collapsing
- Line wrapping toggle
- Follow mode for real-time log tailing

## Commands

```bash
# Run tests
./vendor/bin/phpunit

# Run specific test
./vendor/bin/phpunit --filter test_name

# Run the CLI tool
./bin/vtail /path/to/laravel.log
```

## Architecture

### Source Structure (`src/`)

| File | Purpose |
|------|---------|
| `Application.php` | Main TUI event loop, keyboard handling, rendering |
| `Formatting/LogFormatter.php` | Parses log lines, detects stack frames, applies formatting |
| `Formatting/LineCollection.php` | Manages display lines, vendor collapsing, scroll calculations |
| `Formatting/Line.php` | Data class for formatted lines with metadata |
| `Formatting/AnsiAware.php` | ANSI-safe string operations (width, wrap, pad, substr) |
| `Terminal/Terminal.php` | TTY control, raw mode, cursor positioning |
| `Input/KeyPressListener.php` | Hotkey bindings |

### Key Concepts

**Trace Box Structure:**
```
 ╭─Trace────────────────────────────────────╮
 │ #00 /path/to/file.php(50): method()      │
 │ #01 /app/Controllers/Home.php(25): index │
 ╰══════════════════════════════════════════╯
```

**Width Calculations:**
- `traceContentWidth = contentWidth - 5`
- Left border: ` │ ` (3 chars)
- Right border: ` │` (2 chars, no trailing space)
- Header/footer corners align with content borders

**State Tracking:**
- `$inStackTrace` - Whether currently inside a trace block
- `$inVendorGroup` - Whether in a consecutive vendor frame group
- `$vendorGroupId` - Groups consecutive vendor frames for collapsing

**Pre-wrapped Log Handling:**
Log files may contain stack frames pre-wrapped by the logger. Lines inside a trace that don't start with `#` are treated as continuations and inherit the vendor group status.

## Testing

- Unit tests in `tests/Unit/`
- Integration tests in `tests/Integration/`
- Snapshot tests compare rendered output
- Update snapshots: `UPDATE_SNAPSHOTS=1 ./vendor/bin/phpunit`

## Dependencies

- `soloterm/screen` - Virtual terminal renderer
- `soloterm/grapheme` - Unicode width calculation
- `symfony/console` - CLI foundation
- Requires: `ext-pcntl`, `ext-posix`, `ext-mbstring`
