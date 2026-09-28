<?php

declare(strict_types=1);

namespace Texstar\Exchange\Schema;

/** Loads schemas/v1 once and hands out documents by role or stream; also the sibling-file loader for $ref. */
final class SchemaRegistry
{
    /** @var array<string, array<string, mixed>> basename => document */
    private array $documents = [];

    private ?SchemaValidator $validator = null;

    public function __construct(private readonly string $directory = __DIR__.'/../../schemas/v1')
    {
        if (! is_dir($this->directory)) {
            throw new SchemaException('Schema directory not found: '.$this->directory);
        }
    }

    public static function v1(): self
    {
        static $instance = null;

        return $instance ??= new self;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function validator(): SchemaValidator
    {
        return $this->validator ??= new SchemaValidator($this->document(...));
    }

    /** @return array<string, mixed> */
    public function document(string $basename): array
    {
        if (isset($this->documents[$basename])) {
            return $this->documents[$basename];
        }
        if (! preg_match('~^[a-z0-9_]+\.schema\.json$~', $basename)) {
            throw new SchemaException('Bad schema name '.$basename);
        }
        foreach ([$this->directory.'/'.$basename, $this->directory.'/streams/'.$basename] as $path) {
            if (is_file($path)) {
                $json = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($json)) {
                    throw new SchemaException('Schema is not an object: '.$basename);
                }

                return $this->documents[$basename] = $json;
            }
        }
        throw new SchemaException('Unknown schema '.$basename);
    }

    /** @return array<string, mixed> */
    public function common(): array
    {
        return $this->document('common.schema.json');
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->document('manifest.schema.json');
    }

    /** @return array<string, mixed> */
    public function receipt(): array
    {
        return $this->document('receipt.schema.json');
    }

    /** @return array<string, mixed> */
    public function updates(): array
    {
        return $this->document('updates.schema.json');
    }

    /** @return array<string, mixed> */
    public function scope(): array
    {
        return $this->document('scope.schema.json');
    }

    /** @return array<string, mixed> */
    public function forStream(string $stream): array
    {
        if ($stream === 'updates') {
            return $this->updates();
        }
        if (! preg_match('~^[a-z0-9_]{1,40}$~', $stream)) {
            throw new SchemaException('Bad stream name');
        }

        return $this->document($stream.'.schema.json');
    }

    public function hasStream(string $stream): bool
    {
        return $stream === 'updates' || (preg_match('~^[a-z0-9_]{1,40}$~', $stream) && is_file($this->directory.'/streams/'.$stream.'.schema.json'));
    }

    /** Every schema file (basename => absolute path), top level and streams/. @return array<string, string> */
    public function files(): array
    {
        $out = [];
        foreach (array_merge(glob($this->directory.'/*.schema.json') ?: [], glob($this->directory.'/streams/*.schema.json') ?: []) as $path) {
            $out[basename($path)] = $path;
        }
        ksort($out);

        return $out;
    }

    /** @return list<Violation> */
    public function validateRow(string $stream, mixed $row): array
    {
        return $this->validator()->validate($row, $this->forStream($stream));
    }

    /** @return list<Violation> */
    public function validateManifest(mixed $manifest): array
    {
        return $this->validator()->validate($manifest, $this->manifest());
    }

    /** @return list<Violation> */
    public function validateReceipt(mixed $receipt): array
    {
        return $this->validator()->validate($receipt, $this->receipt());
    }
}
