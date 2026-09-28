<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

use Texstar\Exchange\Contract;

/** The importer's answer for one package (receipt.schema.json). Produced only for a package whose manifest verified. */
final readonly class Receipt
{
    use Extras;

    public const STATUS_APPLIED = 'applied';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PARTIAL = 'partial';

    private const KNOWN = ['format', 'version', 'package_id', 'receipt_id', 'produced_at', 'producer', 'scope', 'streams', 'status', 'message'];

    /**
     * @param  array<string, StreamReceipt>  $streams
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $packageId,
        public string $receiptId,
        public string $producedAt,
        public Producer $producer,
        public Scope $scope,
        public array $streams,
        public string $status,
        public ?string $message = null,
        public string $format = Contract::RECEIPT_FORMAT,
        public int $version = Contract::MAJOR,
        public array $extra = [],
    ) {}

    public static function fromArray(array $data): self
    {
        $streams = [];
        foreach (is_array($data['streams'] ?? null) ? $data['streams'] : [] as $name => $s) {
            if (is_array($s)) {
                $streams[(string) $name] = StreamReceipt::fromArray($s);
            }
        }

        return new self(
            (string) ($data['package_id'] ?? ''), (string) ($data['receipt_id'] ?? ''), (string) ($data['produced_at'] ?? ''),
            Producer::fromArray(is_array($data['producer'] ?? null) ? $data['producer'] : []), Scope::resolve($data['scope'] ?? null),
            $streams, (string) ($data['status'] ?? ''), isset($data['message']) ? (string) $data['message'] : null,
            (string) ($data['format'] ?? Contract::RECEIPT_FORMAT), (int) ($data['version'] ?? Contract::MAJOR), self::unknown($data, self::KNOWN),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $streams = [];
        foreach ($this->streams as $name => $s) {
            $streams[$name] = $s->toArray();
        }
        $out = [
            'format' => $this->format, 'version' => $this->version, 'package_id' => $this->packageId, 'receipt_id' => $this->receiptId,
            'produced_at' => $this->producedAt, 'producer' => $this->producer->toArray(), 'scope' => $this->scope->toArray(),
            'streams' => $streams === [] ? new \stdClass : $streams, 'status' => $this->status,
        ];
        if ($this->message !== null) {
            $out['message'] = $this->message;
        }

        return $this->merged($out);
    }
}
