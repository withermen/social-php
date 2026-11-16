<?php

namespace Social\Support;

final class Config
{
    private array $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public static function load(string $path): self
    {
        $items = require $path;

        if (!is_array($items)) {
            throw new \RuntimeException('Configuration file must return an array.');
        }

        return new self($items);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $items = &$this->items;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $items[$segment] = $value;
                break;
            }

            if (!isset($items[$segment]) || !is_array($items[$segment])) {
                $items[$segment] = [];
            }

            $items = &$items[$segment];
        }
    }

    public function all(): array
    {
        return $this->items;
    }
}
