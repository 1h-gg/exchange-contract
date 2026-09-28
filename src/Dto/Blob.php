<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

/** One `blobs[]` entry: a file physically included under files/<sha256>. */
final readonly class Blob
{
    use Extras;

    private const KNOWN = ['sha256', 'bytes', 'mime', 'path'];

    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $sha256,
        public int $bytes,
        public string $mime,
        public string $path,
        public array $extra = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $sha = (string) ($data['sha256'] ?? '');

        return new self($sha, (int) ($data['bytes'] ?? 0), (string) ($data['mime'] ?? 'application/octet-stream'), (string) ($data['path'] ?? 'files/'.$sha), self::unknown($data, self::KNOWN));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->merged(['sha256' => $this->sha256, 'bytes' => $this->bytes, 'mime' => $this->mime, 'path' => $this->path]);
    }
}
