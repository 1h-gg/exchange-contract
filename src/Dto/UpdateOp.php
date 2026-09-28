<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

/**
 * One targeted bulk update (updates.schema.json). 1b supports `update` and `clear`; the other ops are
 * reserved for 1d's EntityMutator and are answered `unsupported_until_1d` until then.
 */
final readonly class UpdateOp
{
    use Extras;

    public const OPS_1B = ['update', 'clear'];

    public const OPS_RESERVED = ['create', 'override', 'clear_override', 'delete', 'set_status', 'link_external_ref'];

    public const ENTITIES = ['job', 'task', 'pole', 'violation', 'hold'];

    private const KNOWN = ['id', 'op', 'entity', 'key', 'field', 'value', 'reason', 'program', 'origin', 'origin_ref'];

    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $id,
        public string $op,
        public string $entity,
        public string $key,
        public string $field,
        public mixed $value = null,
        public ?string $reason = null,
        public ?string $program = null,
        public ?string $origin = null,
        public mixed $originRef = null,
        public array $extra = [],
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''), (string) ($data['op'] ?? ''), (string) ($data['entity'] ?? ''), (string) ($data['key'] ?? ''), (string) ($data['field'] ?? ''),
            $data['value'] ?? null, isset($data['reason']) ? (string) $data['reason'] : null, isset($data['program']) ? (string) $data['program'] : null,
            isset($data['origin']) ? (string) $data['origin'] : null, $data['origin_ref'] ?? null, self::unknown($data, self::KNOWN),
        );
    }

    public function isSupportedIn1b(): bool
    {
        return in_array($this->op, self::OPS_1B, true);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = ['id' => $this->id, 'op' => $this->op, 'entity' => $this->entity, 'key' => $this->key, 'field' => $this->field, 'value' => $this->value, 'reason' => $this->reason, 'program' => $this->program];
        if ($this->origin !== null) {
            $out['origin'] = $this->origin;
            $out['origin_ref'] = $this->originRef;
        }

        return $this->merged($out);
    }
}
