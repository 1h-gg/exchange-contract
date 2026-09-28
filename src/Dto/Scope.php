<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

/**
 * {tenant, contract, program, instance} — the Phase 1f scope stamped on the manifest and on every row.
 * Absent or partial scopes resolve to UNSCOPED (comcast-nesc) without error: unscoped v1 packages stay
 * accepted for one contract version. The HMAC key id is "<tenant>:<contract>".
 */
final readonly class Scope
{
    public const UNSCOPED = ['tenant' => 'texstar', 'contract' => 'comcast', 'program' => 'comcast-nesc', 'instance' => null];

    public function __construct(
        public string $tenant,
        public string $contract,
        public string $program,
        public ?string $instance = null,
    ) {}

    /** Fill missing or blank keys from UNSCOPED; never errors. */
    public static function resolve(?array $scope): self
    {
        $scope = is_array($scope) ? $scope : [];
        $pick = static function (string $key) use ($scope): ?string {
            $v = $scope[$key] ?? null;

            return is_string($v) && trim($v) !== '' ? $v : null;
        };

        return new self(
            $pick('tenant') ?? self::UNSCOPED['tenant'],
            $pick('contract') ?? self::UNSCOPED['contract'],
            $pick('program') ?? self::UNSCOPED['program'],
            $pick('instance'),
        );
    }

    public static function unscoped(): self
    {
        return self::resolve(null);
    }

    public static function fromArray(array $data): self
    {
        return self::resolve($data);
    }

    public function keyId(): string
    {
        return $this->tenant.':'.$this->contract;
    }

    /** Same tenant, contract and program (the instance is informational and never compared). */
    public function sameProgramAs(self $other): bool
    {
        return $this->tenant === $other->tenant && $this->contract === $other->contract && $this->program === $other->program;
    }

    public function isUnscoped(): bool
    {
        return $this->toArray() === self::UNSCOPED;
    }

    /** @return array{tenant: string, contract: string, program: string, instance: ?string} */
    public function toArray(): array
    {
        return ['tenant' => $this->tenant, 'contract' => $this->contract, 'program' => $this->program, 'instance' => $this->instance];
    }
}
