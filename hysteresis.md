# Hysteresis Trimming: A Simple Trick for Efficient Memory Bounds

When building `vtail`, a real-time log viewer, I ran into a classic problem: how do you keep memory bounded without thrashing?

## The Naive Approach

The obvious solution to limiting memory is simple: when you hit your limit, remove old items.

```php
function addLine($line) {
    $this->lines[] = $line;

    if (count($this->lines) > 1000) {
        array_shift($this->lines);  // Remove oldest
    }
}
```

This works, but there's a problem. Once you hit 1000 lines, *every single new line* triggers a removal. In PHP, `array_shift()` is O(n) because it re-indexes the array. Even with `array_slice()`, you're allocating a new array on every addition.

For a log viewer processing hundreds of lines per second, this creates constant memory churn.

## Enter Hysteresis

Hysteresis is a concept borrowed from electronics and thermodynamics. A thermostat with hysteresis doesn't turn on at 70°F and off at 70°F—it turns on at 68°F and off at 72°F. This "dead band" prevents the system from rapidly cycling on and off.

The same principle applies to buffer management:

```
Without hysteresis:          With hysteresis:

Lines  ─┬─ 1000 (limit)      Lines  ─┬─ 1200 (trim threshold)
        │ ↕ constant               │
        │   trimming               │   (buffer zone)
        │                          │
        └─ 0                 ─┼─ 1000 (trim target)
                                   │
                                   └─ 0
```

Instead of trimming at 1000, we let the buffer grow to 1200, *then* trim back to 1000. This gives us 200 lines of breathing room before the next trim operation.

## The Implementation

Here's how we implemented it in vtail:

```php
protected int $maxLines = 1000;
protected int $trimThreshold = 1200;  // 20% headroom

protected function collectOutput(): bool
{
    // ... collect new lines ...

    if (count($this->lines) > $this->trimThreshold) {
        $this->trimOldLines();
    }

    return true;
}

protected function trimOldLines(): void
{
    $removeCount = count($this->lines) - $this->maxLines;

    // Trim back to maxLines (not trimThreshold)
    $this->lines = array_slice($this->lines, $removeCount);

    // Adjust scroll position so view doesn't jump
    $this->scrollIndex = max(0, $this->scrollIndex - $removedDisplayLines);
}
```

The key insight: we trim back to `maxLines` (1000), not `trimThreshold` (1200). This means after trimming, we have 200 lines of capacity before we need to trim again.

## Why 20%?

The threshold multiplier is a tradeoff:

- **Too small (5%)**: Trim operations happen frequently, defeating the purpose
- **Too large (100%)**: You're using twice the memory you asked for
- **20% sweet spot**: Infrequent trims with reasonable memory overhead

We calculate it dynamically from the user's max:

```php
$this->trimThreshold = (int) ($maxLines * 1.2);
```

## The Math

At a log rate of 100 lines/second with a 1000-line buffer and 20% hysteresis:

- **Without hysteresis**: 100 trim operations/second
- **With hysteresis**: 1 trim operation every 2 seconds (when 200-line buffer fills)

That's a 200x reduction in trim frequency.

## Scroll Position Stability

There's a subtle UX concern: when you trim lines from the top, the user's view shouldn't jump. If someone is scrolled up reading old logs and we remove 200 lines, their scroll position needs adjustment:

```php
// Calculate how many display lines we're removing
$removedDisplayLines = 0;
for ($i = 0; $i < $removeCount; $i++) {
    $removedDisplayLines += $this->lines[$i]->wrapCount();
}

// Shift scroll position up by the same amount
$this->scrollIndex = max(0, $this->scrollIndex - $removedDisplayLines);
```

Note that we count *display* lines, not raw lines—a single log entry might wrap to multiple screen lines.

## When Hysteresis Isn't Enough

Hysteresis helps with trim frequency, but `array_slice()` is still O(n). For truly high-throughput scenarios, you'd want a ring buffer or `SplDoublyLinkedList`. But for our use case—a human-readable log viewer—the simplicity of array slicing every few seconds is the right tradeoff.

---

Hysteresis is one of those patterns that shows up everywhere once you start looking: thermostats, Schmitt triggers, autoscaling policies, rate limiters. The core idea is always the same: add a buffer zone to prevent oscillation at boundaries.

Sometimes the best optimizations aren't clever algorithms—they're just giving your system room to breathe.
