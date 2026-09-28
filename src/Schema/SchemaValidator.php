<?php

declare(strict_types=1);

namespace Texstar\Exchange\Schema;

/**
 * Hand-written validator for the JSON Schema 2020-12 SUBSET the contract schemas use (no library,
 * no Laravel). Keywords: type (incl. arrays of types), required, properties, enum, const, pattern,
 * minLength/maxLength, minimum/maximum, minItems/maxItems, items, format (date-time, date, uuid),
 * $ref (#/$defs/... and sibling files), allOf/anyOf/oneOf. `additionalProperties` is NEVER enforced:
 * unknown fields are ignored on the wire by design. {@see KeywordAudit} keeps the schemas inside this set.
 *
 * Data is the array form of json_decode(..., true): `{}` and `[]` both arrive as [] and satisfy both
 * `object` and `array`.
 */
final class SchemaValidator
{
    public const VALIDATION_KEYWORDS = [
        'type', 'required', 'properties', 'enum', 'const', 'pattern', 'minLength', 'maxLength', 'minimum', 'maximum',
        'minItems', 'maxItems', 'items', 'format', '$ref', 'allOf', 'anyOf', 'oneOf',
    ];

    public const ANNOTATION_KEYWORDS = ['$schema', '$id', '$defs', 'title', 'description', 'default', 'examples', 'deprecated', '$comment', 'readOnly'];

    public const FORMATS = ['date-time', 'date', 'uuid'];

    private const ISO_DATETIME = '~^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:?\d{2})$~';

    private const SQL_DATETIME = '~^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$~';

    private const DATE = '~^\d{4}-\d{2}-\d{2}$~';

    private const UUID = '~^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$~i';

    /** @var callable(string): array */
    private $loader;

    /** @param  null|callable(string): array  $loader  resolves a sibling schema file by basename (e.g. "common.schema.json") */
    public function __construct(?callable $loader = null)
    {
        $this->loader = $loader ?? static fn (string $file): array => throw new SchemaException('No loader for sibling schema '.$file);
    }

    /**
     * @param  array<string, mixed>  $schema  a whole schema document (its `$defs` serve local refs)
     * @return list<Violation>
     */
    public function validate(mixed $data, array $schema, string $path = '$'): array
    {
        $violations = [];
        $this->check($data, $schema, $schema, $path, $violations);

        return $violations;
    }

    public function isValid(mixed $data, array $schema): bool
    {
        return $this->validate($data, $schema) === [];
    }

    /** @param list<Violation> $out */
    private function check(mixed $data, array $schema, array $root, string $path, array &$out): void
    {
        if (isset($schema['$ref'])) {
            [$target, $targetRoot] = $this->resolveRef((string) $schema['$ref'], $root);
            $this->check($data, $target, $targetRoot, $path, $out);
        }
        if (isset($schema['type']) && ! $this->matchesType($data, $schema['type'])) {
            $out[] = new Violation($path, 'type', 'expected '.(is_array($schema['type']) ? implode('|', $schema['type']) : $schema['type']).', got '.$this->typeOf($data));

            return; // the other keywords assume the type
        }
        if (array_key_exists('const', $schema) && $data !== $schema['const']) {
            $out[] = new Violation($path, 'const', 'must equal '.json_encode($schema['const']));
        }
        if (isset($schema['enum']) && ! in_array($data, $schema['enum'], true)) {
            $out[] = new Violation($path, 'enum', 'must be one of '.json_encode($schema['enum']));
        }
        if (is_string($data)) {
            $this->checkString($data, $schema, $path, $out);
        }
        if (is_int($data) || is_float($data)) {
            if (isset($schema['minimum']) && $data < $schema['minimum']) {
                $out[] = new Violation($path, 'minimum', 'must be >= '.$schema['minimum']);
            }
            if (isset($schema['maximum']) && $data > $schema['maximum']) {
                $out[] = new Violation($path, 'maximum', 'must be <= '.$schema['maximum']);
            }
        }
        if (is_array($data)) {
            $this->checkObject($data, $schema, $root, $path, $out);
            $this->checkArray($data, $schema, $root, $path, $out);
        }
        foreach ($schema['allOf'] ?? [] as $i => $sub) {
            $this->check($data, $sub, $root, $path, $out);
        }
        if (isset($schema['anyOf'])) {
            $matched = false;
            foreach ($schema['anyOf'] as $sub) {
                $tmp = [];
                $this->check($data, $sub, $root, $path, $tmp);
                if ($tmp === []) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                $out[] = new Violation($path, 'anyOf', 'matches none of the alternatives');
            }
        }
        if (isset($schema['oneOf'])) {
            $matches = 0;
            foreach ($schema['oneOf'] as $sub) {
                $tmp = [];
                $this->check($data, $sub, $root, $path, $tmp);
                if ($tmp === []) {
                    $matches++;
                }
            }
            if ($matches !== 1) {
                $out[] = new Violation($path, 'oneOf', 'matches '.$matches.' alternatives, expected exactly one');
            }
        }
    }

    /** @param list<Violation> $out */
    private function checkString(string $data, array $schema, string $path, array &$out): void
    {
        $len = null;
        if (isset($schema['minLength']) || isset($schema['maxLength'])) {
            $len = mb_strlen($data);
        }
        if (isset($schema['minLength']) && $len < $schema['minLength']) {
            $out[] = new Violation($path, 'minLength', 'shorter than '.$schema['minLength']);
        }
        if (isset($schema['maxLength']) && $len > $schema['maxLength']) {
            $out[] = new Violation($path, 'maxLength', 'longer than '.$schema['maxLength']);
        }
        if (isset($schema['pattern']) && ! preg_match('~'.str_replace('~', '\~', $schema['pattern']).'~u', $data)) {
            $out[] = new Violation($path, 'pattern', 'does not match '.$schema['pattern']);
        }
        if (isset($schema['format']) && ! $this->matchesFormat($data, (string) $schema['format'])) {
            $out[] = new Violation($path, 'format', 'not a valid '.$schema['format']);
        }
    }

    /** @param list<Violation> $out */
    private function checkObject(array $data, array $schema, array $root, string $path, array &$out): void
    {
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $data)) {
                $out[] = new Violation($path.'.'.$key, 'required', 'missing');
            }
        }
        foreach ($schema['properties'] ?? [] as $key => $sub) {
            if (array_key_exists($key, $data)) {
                $this->check($data[$key], $sub, $root, $path.'.'.$key, $out);
            }
        }
    }

    /** @param list<Violation> $out */
    private function checkArray(array $data, array $schema, array $root, string $path, array &$out): void
    {
        if (! isset($schema['items']) && ! isset($schema['minItems']) && ! isset($schema['maxItems'])) {
            return;
        }
        if (! array_is_list($data)) {
            return; // an object under an array-only keyword is caught by `type`
        }
        $n = count($data);
        if (isset($schema['minItems']) && $n < $schema['minItems']) {
            $out[] = new Violation($path, 'minItems', 'fewer than '.$schema['minItems'].' items');
        }
        if (isset($schema['maxItems']) && $n > $schema['maxItems']) {
            $out[] = new Violation($path, 'maxItems', 'more than '.$schema['maxItems'].' items');
        }
        if (isset($schema['items'])) {
            foreach ($data as $i => $item) {
                $this->check($item, $schema['items'], $root, $path.'['.$i.']', $out);
            }
        }
    }

    private function matchesType(mixed $data, string|array $type): bool
    {
        foreach ((array) $type as $t) {
            $ok = match ($t) {
                'null' => $data === null,
                'boolean' => is_bool($data),
                'string' => is_string($data),
                'integer' => is_int($data) || (is_float($data) && is_finite($data) && floor($data) === $data),
                'number' => is_int($data) || is_float($data),
                'object' => is_array($data) && ($data === [] || ! array_is_list($data)),
                'array' => is_array($data) && array_is_list($data),
                default => throw new SchemaException('Unsupported type '.$t),
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private function matchesFormat(string $data, string $format): bool
    {
        return match ($format) {
            'date-time' => (bool) (preg_match(self::ISO_DATETIME, $data) || preg_match(self::SQL_DATETIME, $data)),
            'date' => (bool) preg_match(self::DATE, $data),
            'uuid' => (bool) preg_match(self::UUID, $data),
            default => true, // unknown formats are annotations
        };
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} the target schema and the document it lives in */
    private function resolveRef(string $ref, array $root): array
    {
        $hash = strpos($ref, '#');
        $file = $hash === false ? $ref : substr($ref, 0, $hash);
        $pointer = $hash === false ? '' : substr($ref, $hash + 1);
        $document = $root;
        if ($file !== '') {
            $document = ($this->loader)(basename($file));
        }
        $target = $document;
        foreach (array_filter(explode('/', ltrim($pointer, '/')), static fn ($s) => $s !== '') as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));
            if (! is_array($target) || ! array_key_exists($segment, $target)) {
                throw new SchemaException('Unresolvable $ref '.$ref);
            }
            $target = $target[$segment];
        }
        if (! is_array($target)) {
            throw new SchemaException('$ref '.$ref.' does not point at a schema');
        }

        return [$target, $document];
    }

    private function typeOf(mixed $data): string
    {
        return match (true) {
            $data === null => 'null',
            is_bool($data) => 'boolean',
            is_int($data) => 'integer',
            is_float($data) => 'number',
            is_string($data) => 'string',
            is_array($data) => array_is_list($data) && $data !== [] ? 'array' : 'object',
            default => get_debug_type($data),
        };
    }
}
