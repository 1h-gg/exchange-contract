<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

/** One running counter every byte a {@see PackageReader} inflates passes through. */
final class InflateCounter
{
    private int $total = 0;

    public function __construct(private readonly int $cap = PHP_INT_MAX) {}

    public function add(int $bytes): void
    {
        $this->total += $bytes;
        if ($this->total > $this->cap) {
            throw new InflatedSizeException(InflatedSizeException::CAP, $this->total, $this->cap);
        }
    }

    public function total(): int
    {
        return $this->total;
    }

    public function cap(): int
    {
        return $this->cap;
    }
}
