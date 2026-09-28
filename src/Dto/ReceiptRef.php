<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

/**
 * One `receipts[]` entry of the manifest: a receipt for an earlier package, carried in receipt.json.
 *
 * `sha256` / `bytes` bind receipt.json into the signed manifest exactly as files[] binds a stream:
 * every entry names the same receipt.json, so every entry carries the same hash and length, and a
 * reader refuses a receipt.json that does not hash to it (`hash_mismatch`). A receipt inside
 * receipt.json whose package_id no entry declares is ignored.
 */
final readonly class ReceiptRef
{
    use Extras;

    private const KNOWN = ['package_id', 'path', 'sha256', 'bytes'];

    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $packageId,
        public string $path = 'receipt.json',
        public ?string $sha256 = null,
        public ?int $bytes = null,
        public array $extra = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['package_id'] ?? ''),
            (string) ($data['path'] ?? 'receipt.json'),
            isset($data['sha256']) ? strtolower((string) $data['sha256']) : null,
            isset($data['bytes']) && is_numeric($data['bytes']) ? (int) $data['bytes'] : null,
            self::unknown($data, self::KNOWN),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->merged(['package_id' => $this->packageId, 'path' => $this->path, 'sha256' => $this->sha256, 'bytes' => $this->bytes]);
    }
}
