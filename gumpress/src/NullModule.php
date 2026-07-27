<?php

declare(strict_types=1);

namespace GumPress\V2;

/**
 * Safe stand-in returned instead of a real Module whenever caller resolution
 * fails, a v1 conflict is detected, or an unknown product id is requested.
 * A licensing library must never fatal or wp_die() (v1 did both), and a
 * resolution failure must never be silently treated as "valid" either — it
 * always reports not-licensed, with the __call() catch-all making sure no
 * unexpected method name can fatal a caller that expected a real Module.
 */
final class NullModule
{
    private string $product;

    public function __construct(string $product)
    {
        $this->product = $product;
    }

    public function valid(): bool
    {
        return false;
    }

    public function status(): Status
    {
        return new Status(Status::UNKNOWN);
    }

    public function reason(): string
    {
        return sprintf('GumPress: module "%s" is not available.', $this->product);
    }

    public function license_key(): ?string
    {
        return null;
    }

    public function license(): ?License
    {
        return null;
    }

    public function is_subscription(): bool
    {
        return false;
    }

    public function tier(): ?string
    {
        return null;
    }

    public function has_tier(string $tier): bool
    {
        return false;
    }

    /**
     * @return array|string|null
     */
    public function meta(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    /**
     * @return mixed
     */
    public function extra(?string $key = null)
    {
        return $key === null ? [] : null;
    }

    public function owned_dirs(): array
    {
        return [];
    }

    public function boot_hooks(): void
    {
    }

    /**
     * @param array<int,mixed> $args
     * @return mixed
     */
    public function __call(string $name, array $args)
    {
        return null;
    }
}
