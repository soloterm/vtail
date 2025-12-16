# vtail

Vendor-aware tail for Laravel logs with stack trace formatting and vendor frame collapsing.

vtail is an interactive TUI for real-time log monitoring that intelligently collapses vendor frames in stack traces, making it easier to focus on your application code.

## Features

- **Vendor Frame Collapsing** - Hide vendor frames in stack traces with a single keypress, collapsing them into a count like `#… (5 vendor frames)`
- **Smart Stack Trace Formatting** - Stack traces are visually grouped with borders and dimmed decorations
- **Line Wrapping Toggle** - Switch between wrapped and truncated long lines
- **Follow Mode** - Auto-scroll to new log entries as they arrive
- **Keyboard Navigation** - Vim-style navigation (j/k/g/G) plus page up/down
- **ANSI & Unicode Aware** - Proper handling of colored output and wide characters (CJK, emoji)

## Vendor Frame Collapsing

Press `v` to toggle vendor frame visibility. Consecutive vendor frames collapse into a single line showing the count:

### Vendor frames shown (default)

```
[2024-01-15 10:23:45] production.ERROR: User not found
 {"exception":"[object] (App\\Exceptions\\UserNotFoundException(code: 0):
 User not found at /app/Services/UserService.php:128)
 ╭─Trace────────────────────────────────────────────────────────────────────╮
 │ #00 /app/Http/Controllers/UserController.php(42): store()                │
 │ #01 /vendor/laravel/framework/src/Illuminate/Routing/Router.php(693)     │
 │ #02 /vendor/laravel/framework/src/Illuminate/Routing/Router.php(670)     │
 │ #03 /vendor/laravel/framework/src/Illuminate/Routing/Router.php(636)     │
 │ #04 /vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(183)  │
 │ #05 /vendor/laravel/framework/src/Illuminate/Pipeline/Pipeline.php(119)  │
 │ #06 /app/Services/UserService.php(128): createUser()                     │
 ╰══════════════════════════════════════════════════════════════════════════╯
```

### Vendor frames hidden (press `v`)

```
[2024-01-15 10:23:45] production.ERROR: User not found
 {"exception":"[object] (App\\Exceptions\\UserNotFoundException(code: 0):
 User not found at /app/Services/UserService.php:128)
 ╭─Trace────────────────────────────────────────────────────────────────────╮
 │ #00 /app/Http/Controllers/UserController.php(42): store()                │
 │ #… (5 vendor frames)                                                     │
 │ #06 /app/Services/UserService.php(128): createUser()                     │
 ╰══════════════════════════════════════════════════════════════════════════╯
```

Your application code stands out while framework internals are summarized.

## Installation

```bash
composer global require soloterm/vtail
```

Or install locally in your project:

```bash
composer require --dev soloterm/vtail
```

## Usage

```bash
vtail [options] <file>

Options:
  -h, --help       Show help message
  -n <lines>       Initial tail count (default: 100)
  --no-vendor      Start with vendor frames hidden
  --no-wrap        Start with line wrapping disabled

Examples:
  vtail storage/logs/laravel.log
  vtail --no-vendor laravel.log
  vtail -n 50 /var/log/app.log
```

## Hotkeys

| Key | Action |
|-----|--------|
| `v` | Toggle vendor frame visibility |
| `w` | Toggle line wrapping |
| `t` | Truncate (clear) the log file |
| `f` / `Space` | Toggle follow mode |
| `j` / `Down` | Scroll down one line |
| `k` / `Up` | Scroll up one line |
| `g` | Jump to top |
| `G` | Jump to bottom (enables follow) |
| `PgUp` | Page up |
| `PgDn` | Page down |
| `q` / `Ctrl-C` | Quit |

## Architecture

```
                              vtail Data Flow
    ============================================================

    ┌─────────────────┐
    │   laravel.log   │
    │   (Log File)    │
    └────────┬────────┘
             │
             │ tail -f -n <count>
             ▼
    ┌─────────────────┐
    │  Tail Process   │  Subprocess via proc_open()
    │  (Non-blocking) │  with async pipe reading
    └────────┬────────┘
             │
             │ Raw log lines (strings)
             ▼
    ┌─────────────────────────────────────────────────────────┐
    │                    LogFormatter                         │
    │  ┌───────────────────────────────────────────────────┐  │
    │  │  • Detect stack frames (#N pattern)               │  │
    │  │  • Detect exception headers                       │  │
    │  │  • Identify vendor frames (/vendor/ path)         │  │
    │  │  • Group consecutive vendor frames                │  │
    │  │  • Apply ANSI styling (borders, dim text)         │  │
    │  │  • Wrap/truncate to terminal width                │  │
    │  └───────────────────────────────────────────────────┘  │
    └────────┬────────────────────────────────────────────────┘
             │
             │ Line objects with metadata
             ▼
    ┌─────────────────────────────────────────────────────────┐
    │                   LineCollection                        │
    │  ┌───────────────────────────────────────────────────┐  │
    │  │  • Store formatted Line objects                   │  │
    │  │  • Apply hideVendor filter                        │  │
    │  │  • Collapse vendor groups → "#... (N frames)"     │  │
    │  │  • Calculate scroll position adjustments          │  │
    │  └───────────────────────────────────────────────────┘  │
    └────────┬────────────────────────────────────────────────┘
             │
             │ Display-ready strings
             ▼
    ┌─────────────────────────────────────────────────────────┐
    │                    Application                          │
    │  ┌───────────────────────────────────────────────────┐  │
    │  │  Event Loop (25ms / 40 FPS)                       │  │
    │  │  ┌─────────────────────────────────────────────┐  │  │
    │  │  │ 1. Collect output from tail subprocess      │  │  │
    │  │  │ 2. Process lines if new data or dirty flag  │  │  │
    │  │  │ 3. Render viewport to terminal              │  │  │
    │  │  │ 4. Wait for user input (25ms timeout)       │  │  │
    │  │  │ 5. Handle hotkey → update state → repeat    │  │  │
    │  │  └─────────────────────────────────────────────┘  │  │
    │  └───────────────────────────────────────────────────┘  │
    └────────┬────────────────────────────────────────────────┘
             │
             │ ANSI escape sequences
             ▼
    ┌─────────────────────────────────────────────────────────┐
    │                      Terminal                           │
    │  ┌───────────────────────────────────────────────────┐  │
    │  │  ┌─────────────────────────────────────────────┐  │  │
    │  │  │ STATUS BAR                                  │  │  │
    │  │  │ laravel.log | Lines: 247 | VENDOR: hidden   │  │  │
    │  │  ├─────────────────────────────────────────────┤  │  │
    │  │  │ CONTENT AREA                                │  │  │
    │  │  │ ╭─Trace──────────────────────────────────╮  │  │  │
    │  │  │ │ #01 /app/Http/Controllers/Api.php(42)  │  │  │  │
    │  │  │ │ #… (5 vendor frames)                   │  │  │  │
    │  │  │ │ #07 /app/Models/User.php(128)          │  │  │  │
    │  │  │ ╰────────────────────────────────────────╯  │  │  │
    │  │  ├─────────────────────────────────────────────┤  │  │
    │  │  │ HOTKEY BAR                                  │  │  │
    │  │  │ v vendor  w wrap  t truncate  f follow      │  │  │
    │  │  └─────────────────────────────────────────────┘  │  │
    │  └───────────────────────────────────────────────────┘  │
    └─────────────────────────────────────────────────────────┘

                           ▲
                           │ Keyboard input
                           │
                    ┌──────┴──────┐
                    │    User     │
                    └─────────────┘
```

### Component Overview

| Component | File | Responsibility |
|-----------|------|----------------|
| **Application** | `src/Application.php` | Event loop, state management, rendering |
| **LogFormatter** | `src/Formatting/LogFormatter.php` | Parse logs, detect vendors, apply styling |
| **LineCollection** | `src/Formatting/LineCollection.php` | Filter lines, collapse vendor groups |
| **Line** | `src/Formatting/Line.php` | Data class for formatted lines |
| **AnsiAware** | `src/Formatting/AnsiAware.php` | ANSI-safe string operations |
| **Terminal** | `src/Terminal/Terminal.php` | TTY control, raw mode, cursor |
| **KeyPressListener** | `src/Input/KeyPressListener.php` | Hotkey bindings |

### Vendor Detection

A stack frame is considered "vendor" if:

1. The path contains `/vendor/` (except when `BoundMethod.php` is calling app code)
2. The frame is `{main}` (root of execution)

Consecutive vendor frames are grouped and can be collapsed into a single line showing the count.

## Requirements

- PHP 8.1+
- Extensions: `pcntl`, `posix`, `mbstring`
- Unix-like OS (Linux, macOS)

## License

MIT

## Related Projects

vtail is part of the [SoloTerm](https://github.com/soloterm) project:

- **[solo](https://github.com/soloterm/solo)** - Laravel TUI for running multiple dev commands
- **[screen](https://github.com/soloterm/screen)** - Pure PHP terminal renderer
- **[grapheme](https://github.com/soloterm/grapheme)** - Unicode grapheme width calculator
