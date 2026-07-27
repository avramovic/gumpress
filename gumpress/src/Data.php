<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * Thin, typed replacement for v1's GumPressArrayObject. Wraps a decoded JSON
 * array so nested arrays are accessible as ->foo->bar without every caller
 * having to null-check every level by hand.
 */
final class Data implements \ArrayAccess, \Countable
{
    private array $data;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function raw(): array
    {
        return $this->data;
    }

    /**
     * @return mixed
     */
    public function __get(string $key)
    {
        $value = $this->data[$key] ?? null;

        return is_array($value) ? new self($value) : $value;
    }

    public function offsetExists($offset): bool
    {
        return isset($this->data[$offset]);
    }

    /**
     * @return mixed
     */
    public function offsetGet($offset): mixed
    {
        return $this->__get((string) $offset);
    }

    /**
     * @param mixed $value
     */
    public function offsetSet($offset, $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset($offset): void
    {
        unset($this->data[$offset]);
    }

    public function count(): int
    {
        return count($this->data);
    }
}
