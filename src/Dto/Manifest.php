<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

use Texstar\Exchange\Contract;

/**
 * manifest.json. `kind` defaults to package; `direction` is one of the ASCII wire values.
 * `scope` is null when the producer sent none (readers resolve it with {@see Scope::resolve()}).
 * `signature` is null only on kind=rows mini-packages.
 */
final readonly class Manifest
{
    use Extras;

    private const KNOWN = ['format', 'version', 'contract', 'kind', 'package_id', 'direction', 'created_at', 'producer', 'scope', 'cursors', 'files', 'blobs', 'receipts', 'extras', 'signature'];

    /**
     * @param  array<string, mixed>  $cursors
     * @param  list<StreamFile>  $files
     * @param  list<Blob>  $blobs
     * @param  list<ReceiptRef>  $receipts
     * @param  array<string, mixed>  $extras
     * @param  array{alg: string, key_id: string, value: string}|null  $signature
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $packageId,
        public string $direction,
        public string $createdAt,
        public Producer $producer,
        public ?Scope $scope = null,
        public string $kind = Contract::KIND_PACKAGE,
        public array $cursors = [],
        public array $files = [],
        public array $blobs = [],
        public array $receipts = [],
        public array $extras = [],
        public ?array $signature = null,
        public string $format = Contract::FORMAT,
        public int $version = Contract::MAJOR,
        public string $contract = Contract::VERSION,
        public array $extra = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $list = static fn (mixed $v, callable $make): array => is_array($v) ? array_values(array_map($make, array_filter($v, 'is_array'))) : [];
        $signature = $data['signature'] ?? null;

        return new self(
            (string) ($data['package_id'] ?? ''),
            (string) ($data['direction'] ?? ''),
            (string) ($data['created_at'] ?? ''),
            Producer::fromArray(is_array($data['producer'] ?? null) ? $data['producer'] : []),
            is_array($data['scope'] ?? null) ? Scope::resolve($data['scope']) : null,
            (string) ($data['kind'] ?? Contract::KIND_PACKAGE),
            is_array($data['cursors'] ?? null) ? $data['cursors'] : [],
            $list($data['files'] ?? null, StreamFile::fromArray(...)),
            $list($data['blobs'] ?? null, Blob::fromArray(...)),
            $list($data['receipts'] ?? null, ReceiptRef::fromArray(...)),
            is_array($data['extras'] ?? null) ? $data['extras'] : [],
            is_array($signature) && $signature !== [] ? ['alg' => (string) ($signature['alg'] ?? ''), 'key_id' => (string) ($signature['key_id'] ?? ''), 'value' => (string) ($signature['value'] ?? '')] : null,
            (string) ($data['format'] ?? Contract::FORMAT),
            (int) ($data['version'] ?? Contract::MAJOR),
            (string) ($data['contract'] ?? Contract::VERSION),
            self::unknown($data, self::KNOWN),
        );
    }

    /** The scope as sent, or UNSCOPED when the producer sent none. */
    public function resolvedScope(): Scope
    {
        return $this->scope ?? Scope::unscoped();
    }

    public function isSigned(): bool
    {
        return $this->signature !== null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = [
            'format' => $this->format,
            'version' => $this->version,
            'contract' => $this->contract,
            'kind' => $this->kind,
            'package_id' => $this->packageId,
            'direction' => $this->direction,
            'created_at' => $this->createdAt,
            'producer' => $this->producer->toArray(),
        ];
        if ($this->scope !== null) {
            $out['scope'] = $this->scope->toArray();
        }
        $out['cursors'] = $this->cursors === [] ? new \stdClass : $this->cursors;
        $out['files'] = array_map(static fn (StreamFile $f) => $f->toArray(), $this->files);
        $out['blobs'] = array_map(static fn (Blob $b) => $b->toArray(), $this->blobs);
        $out['receipts'] = array_map(static fn (ReceiptRef $r) => $r->toArray(), $this->receipts);
        $out['extras'] = $this->extras === [] ? new \stdClass : $this->extras;
        $out['signature'] = $this->signature;

        return $this->merged($out);
    }
}
