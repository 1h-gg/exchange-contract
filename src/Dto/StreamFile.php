<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

/** One `files[]` entry of the manifest: a stream's NDJSON file inside the zip. */
final readonly class StreamFile
{
    use Extras;

    private const KNOWN = ['path', 'stream', 'schema', 'rows', 'sha256', 'bytes', 'mode', 'set_checksum', 'set_total'];

    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $path,
        public string $stream,
        public string $schema,
        public int $rows,
        public string $sha256,
        public ?int $bytes = null,
        public string $mode = 'delta',
        public ?string $setChecksum = null,
        public ?int $setTotal = null,
        public array $extra = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['path'] ?? ''),
            (string) ($data['stream'] ?? ''),
            (string) ($data['schema'] ?? ''),
            (int) ($data['rows'] ?? 0),
            (string) ($data['sha256'] ?? ''),
            isset($data['bytes']) ? (int) $data['bytes'] : null,
            (string) ($data['mode'] ?? 'delta'),
            isset($data['set_checksum']) ? (string) $data['set_checksum'] : null,
            isset($data['set_total']) ? (int) $data['set_total'] : null,
            self::unknown($data, self::KNOWN),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['path' => $this->path, 'stream' => $this->stream, 'schema' => $this->schema, 'rows' => $this->rows, 'sha256' => $this->sha256, 'bytes' => $this->bytes, 'mode' => $this->mode];
        if ($this->setChecksum !== null) {
            $out['set_checksum'] = $this->setChecksum;
        }
        if ($this->setTotal !== null) {
            $out['set_total'] = $this->setTotal;
        }

        return $this->merged($out);
    }
}
