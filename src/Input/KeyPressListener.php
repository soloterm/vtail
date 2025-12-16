<?php

namespace SoloTerm\Vtail\Input;

class KeyPressListener
{
    /**
     * @var array<string, callable>
     */
    protected array $bindings = [];

    /**
     * Bind a key or array of keys to a callback.
     *
     * @param  string|array<string>  $keys
     */
    public function on(string|array $keys, callable $callback): static
    {
        $keys = (array) $keys;

        foreach ($keys as $key) {
            $this->bindings[$key] = $callback;
        }

        return $this;
    }

    /**
     * Process a key press (or buffered sequence of keys).
     */
    public function processKey(string $key): void
    {
        // Check for exact match first (escape sequences)
        if (isset($this->bindings[$key])) {
            ($this->bindings[$key])();

            return;
        }

        // For regular character input, process each character
        foreach (mb_str_split($key) as $char) {
            if (isset($this->bindings[$char])) {
                ($this->bindings[$char])();
            }
        }
    }

    /**
     * Clear all bindings.
     */
    public function clear(): void
    {
        $this->bindings = [];
    }

    /**
     * Check if a key has a binding.
     */
    public function has(string $key): bool
    {
        return isset($this->bindings[$key]);
    }
}
