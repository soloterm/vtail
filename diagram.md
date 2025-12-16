# vtail Data Flow & Rendering Architecture

## Overview

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                              LOG FILE                                       │
│                         (laravel.log)                                       │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                           tail -f -n 100                                    │
│                         (subprocess)                                        │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ stdout (non-blocking pipe)
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         collectOutput()                                     │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │  stream_get_contents($tailPipes[1])                                │    │
│  │  explode("\n", $output)                                            │    │
│  │  filter: if ($line !== '') $this->lines[] = $line                  │    │
│  └────────────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ $this->lines[] (raw log lines)
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          processLines()                                     │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │  $formatter->setContentWidth($this->getContentWidth())             │    │
│  │                              │                                      │    │
│  │                              ▼                                      │    │
│  │              ┌──────────────────────────────┐                       │    │
│  │              │  Terminal::cols()            │ ◄── WIDTH SOURCE #1  │    │
│  │              │  (now uses tput)             │                       │    │
│  │              └──────────────────────────────┘                       │    │
│  │                                                                     │    │
│  │  foreach ($this->lines as $line):                                  │    │
│  │      $result = $formatter->formatLine($line, $hideVendor)          │    │
│  │      $this->formattedLines[] = $result                             │    │
│  └────────────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ $this->formattedLines[]
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                             render()                                        │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │  echo "\e[H"  (cursor home)                                        │    │
│  │                                                                     │    │
│  │  // Status bar                                                      │    │
│  │  echo "\e[7m" . AnsiAware::pad($statusText, $terminal->cols())     │    │
│  │                                                    │                │    │
│  │                                                    ▼                │    │
│  │                              ┌──────────────────────────────┐      │    │
│  │                              │  Terminal::cols()            │ ◄── WIDTH SOURCE #2
│  │                              └──────────────────────────────┘      │    │
│  │                                                                     │    │
│  │  // Content area                                                    │    │
│  │  $visibleLines = array_slice($formattedLines, $scrollIndex, h)     │    │
│  │  for ($i = 0; $i < $contentHeight; $i++):                          │    │
│  │      $line = $visibleLines[$i] ?? ''                               │    │
│  │      echo AnsiAware::pad($line, $terminal->cols()) . "\n"          │    │
│  │                                       │                             │    │
│  │                                       ▼                             │    │
│  │                 ┌──────────────────────────────┐                   │    │
│  │                 │  Terminal::cols()            │ ◄── WIDTH SOURCE #3
│  │                 └──────────────────────────────┘                   │    │
│  │                                                                     │    │
│  │  // Hotkey bar                                                      │    │
│  │  echo AnsiAware::pad($bar, $terminal->cols())                      │    │
│  └────────────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    │ echo (raw bytes with ANSI codes)
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         TERMINAL (Ghostty)                                  │
│  ┌────────────────────────────────────────────────────────────────────┐    │
│  │  Interprets ANSI escape sequences                                  │    │
│  │  Renders characters to grid                                        │    │
│  │  AUTO-WRAPS if line exceeds actual terminal width                  │    │
│  └────────────────────────────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────────────────────────────┘
```

## LogFormatter Pipeline

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       LogFormatter::formatLine()                            │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                    ┌───────────────┼───────────────┐
                    ▼               ▼               ▼
            ┌───────────┐   ┌───────────┐   ┌───────────┐
            │ Exception │   │ Stacktrace│   │ Stack     │
            │ Header    │   │ Header    │   │ Frame     │
            │ {"except..│   │ [stack... │   │ #0 /path..│
            └───────────┘   └───────────┘   └───────────┘
                    │               │               │
                    ▼               ▼               ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                          Width Calculations                                 │
│                                                                             │
│  contentWidth = $this->contentWidth (set from Terminal::cols())             │
│                                                                             │
│  For stack frames:                                                          │
│    traceContentWidth = contentWidth - 5                                     │
│    (accounts for " │ " prefix and " │" suffix = 5 chars)                   │
│                                                                             │
│  Box characters:                                                            │
│    Header:  " ╭─Trace" (8) + dashes + "╮" (1) = contentWidth               │
│    Footer:  " ╰" (2) + equals + "╯" (1) = contentWidth                     │
│    Content: " │ " (3) + content + " │" (2) = contentWidth                  │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            wrapLine()                                       │
│                                                                             │
│  Uses AnsiAware::wordwrap($line, $width, "\n", cut: true)                  │
│                                                                             │
│  If line > width:                                                           │
│    - Splits at width boundary                                               │
│    - Continuation lines indented by $continuationIndent                     │
│    - Empty continuation lines filtered: trim($contLine) !== ''             │
│                                                                             │
│  If !$wrapLines && would wrap:                                              │
│    - Truncates to single line + " ..."                                      │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                      Final formatted line                                   │
│                                                                             │
│  Stack frame example:                                                       │
│    $this->dim(' │ ')                     = "\e[2m │ \e[22m" (3 visible)    │
│    + AnsiAware::pad($wrappedLine, traceContentWidth)                       │
│    + $this->dim(' │ ')                   = "\e[2m │ \e[22m" (3 visible)    │
│                                                                             │
│  Total visible width should = contentWidth                                  │
└─────────────────────────────────────────────────────────────────────────────┘
```

## AnsiAware Width Calculation

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                       AnsiAware::mb_strlen()                                │
│                                                                             │
│  Input: "\e[2m │ \e[22m#0 /path/to/file.php\e[0m"                          │
│                                                                             │
│  Step 1: plain() - strip ANSI                                               │
│    Regex: /\x1b\[[0-9;]*[A-Za-z]/                                          │
│    Result: " │ #0 /path/to/file.php"                                       │
│                                                                             │
│  Step 2: mb_strlen()                                                        │
│    Counts Unicode codepoints                                                │
│    "│" = 1 codepoint (U+2502 BOX DRAWINGS LIGHT VERTICAL)                  │
│                                                                             │
│  POTENTIAL ISSUE:                                                           │
│    mb_strlen counts CODEPOINTS, not DISPLAY WIDTH                           │
│    Some terminals render box chars as 2 cells (ambiguous width)             │
│    If terminal uses 2 cells but we count 1, lines overflow                  │
└─────────────────────────────────────────────────────────────────────────────┘
```

## AnsiAware::pad() Flow

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         AnsiAware::pad()                                    │
│                                                                             │
│  Input: $text = formatted line, $width = terminal cols                      │
│                                                                             │
│  $visibleLength = self::mb_strlen($text)   // strips ANSI, counts chars    │
│  $padding = max(0, $width - $visibleLength)                                │
│                                                                             │
│  if ($padding === 0):                                                       │
│      return $text   // NO PADDING - line already at or over width!         │
│                     // ^^^ THIS IS A PROBLEM IF LINE IS OVER WIDTH         │
│                                                                             │
│  return $text . str_repeat(' ', $padding)                                  │
└─────────────────────────────────────────────────────────────────────────────┘
```

## State Management

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          Application State                                  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  Terminal State:                                                            │
│    $terminal        Terminal instance (provides cols/lines)                 │
│                                                                             │
│  Data State:                                                                │
│    $lines[]         Raw log lines from tail                                 │
│    $formattedLines[] Processed/formatted lines                              │
│                                                                             │
│  View State:                                                                │
│    $scrollIndex     Current scroll position                                 │
│    $following       Auto-scroll to bottom on new content                    │
│                                                                             │
│  Display Options:                                                           │
│    $hideVendor      Collapse vendor stack frames                            │
│    $wrapLines       Wrap long lines vs truncate                             │
│                                                                             │
│  Render State:                                                              │
│    $dirty           Flag to trigger re-render                               │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Event Loop

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                            eventLoop()                                      │
│                                                                             │
│  while ($this->running):                                                    │
│      │                                                                      │
│      ├──► collectOutput()                                                   │
│      │        │                                                             │
│      │        └──► if new data: $dirty = true                              │
│      │                                                                      │
│      ├──► if ($dirty):                                                      │
│      │        │                                                             │
│      │        ├──► processLines()                                          │
│      │        │        │                                                    │
│      │        │        └──► LogFormatter::formatLine() for each line       │
│      │        │                                                             │
│      │        ├──► render()                                                │
│      │        │        │                                                    │
│      │        │        └──► echo to terminal                               │
│      │        │                                                             │
│      │        └──► $dirty = false                                          │
│      │                                                                      │
│      └──► waitForInput(25000)  // 40 FPS = 25ms                            │
│               │                                                             │
│               └──► process keypresses via $listener                        │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Signal Handling

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                          SIGWINCH Handler                                   │
│                                                                             │
│  pcntl_signal(SIGWINCH, function() {                                       │
│      $this->handleResize();                                                │
│  });                                                                        │
│                                                                             │
│  handleResize():                                                            │
│      $this->screen = new Screen(1000, $this->getContentHeight())           │
│      $this->formatter->setContentWidth($this->getContentWidth())           │
│      $this->dirty = true                                                   │
│                                                                             │
│  NOTE: Screen is created but NOT USED for rendering!                        │
│        Application echoes directly to terminal.                             │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Potential Bug Locations

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                        SUSPECT AREAS                                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│  1. WIDTH MISMATCH (partially fixed)                                        │
│     Terminal::cols() vs actual terminal width                               │
│     - Fixed: now uses tput instead of env vars                              │
│     - Still possible: tput returns wrong value?                             │
│                                                                             │
│  2. UNICODE WIDTH AMBIGUITY                                                 │
│     Box drawing chars (│ ╭ ╮ ╰ ╯ ─ ═) are "ambiguous width"               │
│     - mb_strlen() counts them as 1                                          │
│     - Some terminals render them as 2 cells                                 │
│     - Would cause consistent overflow                                       │
│                                                                             │
│  3. ANSI ESCAPE SEQUENCE LEAKAGE                                            │
│     If ANSI codes not fully stripped by plain():                            │
│     - mb_strlen would undercount                                            │
│     - pad() would under-pad                                                 │
│     - Terminal would show extra chars                                       │
│                                                                             │
│  4. NO TRUNCATION ON OVERFLOW                                               │
│     AnsiAware::pad() returns line unchanged if >= width                     │
│     - Should truncate to exactly width                                      │
│     - Currently allows overflow                                             │
│                                                                             │
│  5. BOX CALCULATIONS (FIXED)                                                │
│     traceContentWidth = contentWidth - 5                                    │
│     - Left border: " │ " = 3 chars                                         │
│     - Right border: " │" = 2 chars (no trailing space)                     │
│     - Total border overhead = 5 chars                                       │
│     - Right │ aligns with ╮ and ╯ corners at final column                  │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Test vs Live Differences

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                    TestableApplication                                      │
│                                                                             │
│  Uses MockTerminal:                                                         │
│    - cols() returns fixed $mockCols                                         │
│    - lines() returns fixed $mockLines                                       │
│    - Never calls tput or checks env                                         │
│                                                                             │
│  Renders to Screen buffer:                                                  │
│    - $this->virtualScreen->write($output)                                  │
│    - return $this->virtualScreen->output()                                 │
│    - Screen interprets ANSI codes                                          │
│                                                                             │
│  Checks formatted lines:                                                    │
│    - Inspects $formattedLines array                                        │
│    - Uses AnsiAware::mb_strlen() for width checks                          │
│    - Same calculation as live app                                          │
│                                                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                    Live Application                                         │
│                                                                             │
│  Uses real Terminal:                                                        │
│    - cols() calls `tput cols`                                              │
│    - lines() calls `tput lines`                                            │
│    - May differ from actual terminal!                                       │
│                                                                             │
│  Renders directly to TTY:                                                   │
│    - echo $line . "\n"                                                     │
│    - Terminal interprets and renders                                        │
│    - Terminal auto-wraps on overflow                                        │
│                                                                             │
│  No verification:                                                           │
│    - Trusts that width calculations are correct                            │
│    - No runtime check that output fits                                     │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Recommended Debug Points

```
1. Check actual tput output:
   $ tput cols
   $ echo $COLUMNS
   
2. Check line widths at render time:
   Add to render(): file_put_contents('/tmp/widths.log', 
       "cols={$this->terminal->cols()} line_len=" . AnsiAware::mb_strlen($line) . "\n",
       FILE_APPEND);

3. Check raw bytes being sent:
   $ script -q /tmp/vtail-output.txt ./bin/vtail test.log
   $ hexdump -C /tmp/vtail-output.txt | less

4. Test box char width:
   $ php -r "echo '│' . strlen('│') . ' ' . mb_strlen('│');"
   Should output: │3 1 (3 bytes UTF-8, 1 codepoint)
```
