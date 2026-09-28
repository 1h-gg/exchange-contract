<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

final readonly class Producer
{
    use Extras;

    private const KNOWN = ['app', 'version', 'instance', 'contract'];

    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $app,
        public ?string $version = null,
        public ?string $instance = null,
        public ?string $contract = null,
        public array $extra = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['app'] ?? ''),
            isset($data['version']) ? (string) $data['version'] : null,
            isset($data['instance']) ? (string) $data['instance'] : null,
            isset($data['contract']) ? (string) $data['contract'] : null,
            self::unknown($data, self::KNOWN),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->merged(['app' => $this->app, 'version' => $this->version, 'instance' => $this->instance, 'contract' => $this->contract]);
    }
}
