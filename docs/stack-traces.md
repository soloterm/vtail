---
title: Stack Traces
description: How vtail detects and collapses vendor frames in Laravel stack traces.
---

# Stack Traces

vtail's primary feature is intelligent handling of stack traces. This page explains how vendor detection works and how stack traces are formatted.

## Vendor Frame Detection

A stack frame is classified as a **vendor frame** when:

1. The file path contains `/vendor/` (with one exception below)
2. OR the frame is `{main}` (the root of execution)

### The BoundMethod Exception

Laravel's `BoundMethod.php` is special. It often appears when calling into your application code from the framework. When `BoundMethod.php` is calling your `App\` namespace code, vtail treats it as **non-vendor** so you can see the transition point.

```php
// This frame is NOT marked as vendor:
#08 vendor/laravel/framework/.../BoundMethod.php(36): App\Services\UserService->create()

// This frame IS marked as vendor:
#08 vendor/laravel/framework/.../BoundMethod.php(36): Illuminate\Container\Container->make()
```

## Visual Formatting

### Trace Borders

Stack traces are wrapped in visual borders:

```
╭─Trace───────────────────────────────────────────────╮
│ #01 /app/Http/Controllers/UserController.php(42)    │
│ #02 /app/Services/UserService.php(128)              │
╰─────────────────────────────────────────────────────╯
```

The `╭─Trace` header appears at `[stacktrace]` markers in the log. The closing border appears at the end of the exception JSON object.

### Dimmed Elements

Frame numbers and file locations are dimmed so the important parts stand out:

- Frame numbers (`#01`, `#02`, etc.) - dimmed
- Colons and line numbers (`:42`) - dimmed
- File paths - normal brightness
- Method names - normal brightness

### Frame Number Padding

Single-digit frame numbers are zero-padded for alignment:

```
#01 /app/...
#02 /app/...
...
#10 /app/...
```

## Collapsed Vendor Frames

When vendor frames are hidden (press `v`), consecutive vendor frames collapse into a single summary line showing the count:

### Before (vendor frames shown)

```
╭─Trace───────────────────────────────────────────────╮
│ #01 /app/Http/Controllers/UserController.php(42)    │
│ #02 /vendor/laravel/framework/.../Router.php(693)   │
│ #03 /vendor/laravel/framework/.../Router.php(670)   │
│ #04 /vendor/laravel/framework/.../Router.php(636)   │
│ #05 /vendor/laravel/framework/.../Pipeline.php(183) │
│ #06 /vendor/laravel/framework/.../Pipeline.php(119) │
│ #07 /app/Services/UserService.php(128)              │
╰─────────────────────────────────────────────────────╯
```

### After (vendor frames hidden)

```
╭─Trace───────────────────────────────────────────────╮
│ #01 /app/Http/Controllers/UserController.php(42)    │
│ #… (5 vendor frames)                                │
│ #07 /app/Services/UserService.php(128)              │
╰─────────────────────────────────────────────────────╯
```

## Vendor Groups

Consecutive vendor frames form a **vendor group**. Each group has a unique ID, allowing vtail to:

1. Collapse multiple vendor frames into one summary line
2. Preserve scroll position when toggling visibility
3. Track which frames belong together

When you toggle vendor visibility, vtail adjusts your scroll position so you stay looking at the same logical content—not a random position in the newly resized output.

## Exception Headers

Exception messages are formatted separately from stack traces:

```
[2024-01-15 10:23:45] local.ERROR: User not found
 {"exception":"[object] (App\\Exceptions\\UserNotFoundException...
```

The timestamp and message appear normally. The JSON exception object is indented for visual separation from the stack trace that follows.

## Line Wrapping in Traces

Long file paths wrap within the trace borders:

```
╭─Trace───────────────────────────────────────────────╮
│ #01 /app/Http/Controllers/Api/V2/Users/            │
│     UserProfileController.php(142)                  │
╰─────────────────────────────────────────────────────╯
```

Continuation lines are indented 4 spaces to show they belong to the same frame. When wrapping is disabled (`w`), long lines truncate with `...`:

```
│ #01 /app/Http/Controllers/Api/V2/Users/UserProf... │
```
