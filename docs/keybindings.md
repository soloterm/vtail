---
title: Keybindings
description: Keyboard controls for navigating and interacting with vtail.
---

# Keybindings

vtail uses vim-style keybindings for navigation and single-key toggles for features.

## Quick Reference

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
| `q` / `Ctrl-C` / `Ctrl-D` | Quit |

## Navigation

### Line-by-Line

| Key | Action |
|-----|--------|
| `j` | Scroll down one line |
| `k` | Scroll up one line |
| `Down` | Scroll down one line |
| `Up` | Scroll up one line |

Scrolling disables follow mode so new content won't push you away from what you're reading.

### Page Navigation

| Key | Action |
|-----|--------|
| `PgUp` | Scroll up one page |
| `PgDn` | Scroll down one page |

A "page" is the content height minus one line, providing overlap for context.

### Jump Navigation

| Key | Action |
|-----|--------|
| `g` | Jump to top of log |
| `G` | Jump to bottom of log |

Jumping to bottom with `G` automatically enables follow mode.

## Toggles

### Vendor Frames (`v`)

Toggle visibility of vendor frames in stack traces:

- **Shown**: All stack frames displayed
- **Hidden**: Vendor frames collapsed into counts like `#… (5 vendor frames)`

The status bar shows current state: `VENDOR: hidden` or `VENDOR: shown`.

### Line Wrapping (`w`)

Toggle how long lines are handled:

- **On**: Lines wrap to fit terminal width with continuation indentation
- **Off**: Lines truncate at terminal edge

The status bar shows current state: `WRAP: on` or `WRAP: off`.

### Follow Mode (`f` / `Space`)

Toggle auto-scrolling:

- **Following**: New log entries automatically scroll into view
- **Not following**: View stays fixed; new content added below

The status bar shows `FOLLOWING` when active.

Follow mode is automatically:
- **Disabled** when you scroll up
- **Enabled** when you jump to bottom with `G` or scroll to the very end

## Actions

### Truncate File (`t`)

Clears the log file contents. This:

1. Empties the actual log file on disk
2. Clears the in-memory buffer
3. Resets scroll position to top

Useful when you want a fresh start without restarting vtail.

### Quit (`q` / `Ctrl-C` / `Ctrl-D`)

Exit vtail and return to your shell. The terminal is restored to its normal state.
