<?php

declare(strict_types=1);

namespace Texstar\Exchange\Schema;

final readonly class Violation
{
    public function __construct(public string $path, public string $keyword, public string $message) {}

    public function __toString(): string
    {
        return $this->path.' ['.$this->keyword.'] '.$this->message;
    }

    /** @return array{path: string, keyword: string, message: string} */
    public function toArray(): array
    {
        return ['path' => $this->path, 'keyword' => $this->keyword, 'message' => $this->message];
    }
}
