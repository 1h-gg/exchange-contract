<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

final readonly class NegotiationResult
{
    /**
     * @param  list<string>  $streams  accepted stream names
     * @param  list<array{stream: string, schema: string, reason: string}>  $skipped  warnings, never rejections
     */
    public function __construct(public bool $accepted, public ?string $rejectCode, public ?string $message, public array $streams, public array $skipped) {}

    /** @return list<string> */
    public function warnings(): array
    {
        return array_map(static fn (array $s) => 'stream '.$s['stream'].' ('.$s['schema'].') skipped: '.$s['reason'], $this->skipped);
    }
}
