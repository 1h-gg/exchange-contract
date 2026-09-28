<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

/** One `receipts[]` entry of the manifest: a receipt for an earlier package, carried in receipt.json. */
final readonly class ReceiptRef
{
    use Extras;

    private const KNOWN = ['package_id', 'path'];

    /** @param array<string, mixed> $extra */
    public function __construct(public string $packageId, public string $path = 'receipt.json', public array $extra = []) {}

    public static function fromArray(array $data): self
    {
        return new self((string) ($data['package_id'] ?? ''), (string) ($data['path'] ?? 'receipt.json'), self::unknown($data, self::KNOWN));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->merged(['package_id' => $this->packageId, 'path' => $this->path]);
    }
}
