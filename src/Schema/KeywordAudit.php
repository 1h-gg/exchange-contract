<?php

declare(strict_types=1);

namespace Texstar\Exchange\Schema;

/**
 * Walks every schema document and reports keywords outside the subset {@see SchemaValidator} implements,
 * a missing 2020-12 `$schema`, or a `$ref` that does not resolve. Test-enforced: PMO may run a full
 * validator over the same files, so nothing in them may be silently ignored by ours.
 */
final class KeywordAudit
{
    public const DIALECT = 'https://json-schema.org/draft/2020-12/schema';

    public function __construct(private readonly SchemaRegistry $registry) {}

    /** @return list<string> problems, empty when clean */
    public function run(): array
    {
        $problems = [];
        $allowed = array_flip([...SchemaValidator::VALIDATION_KEYWORDS, ...SchemaValidator::ANNOTATION_KEYWORDS]);
        foreach ($this->registry->files() as $basename => $path) {
            $doc = $this->registry->document($basename);
            if (($doc['$schema'] ?? null) !== self::DIALECT) {
                $problems[] = $basename.': $schema must be '.self::DIALECT;
            }
            $this->walk($doc, $basename, '#', $allowed, $problems);
        }

        return $problems;
    }

    /** @param array<string, int> $allowed @param list<string> $problems */
    private function walk(array $schema, string $file, string $pointer, array $allowed, array &$problems): void
    {
        foreach ($schema as $keyword => $value) {
            if (! isset($allowed[$keyword])) {
                $problems[] = $file.' '.$pointer.': keyword "'.$keyword.'" is outside the validator subset';

                continue;
            }
            switch ($keyword) {
                case '$ref':
                    $this->checkRef((string) $value, $file, $pointer, $problems);
                    break;
                case 'format':
                    if (! in_array($value, SchemaValidator::FORMATS, true)) {
                        $problems[] = $file.' '.$pointer.': format "'.$value.'" is not implemented';
                    }
                    break;
                case 'type':
                    foreach ((array) $value as $t) {
                        if (! in_array($t, ['null', 'boolean', 'string', 'integer', 'number', 'object', 'array'], true)) {
                            $problems[] = $file.' '.$pointer.': type "'.$t.'" is not implemented';
                        }
                    }
                    break;
                case 'properties':
                case '$defs':
                    foreach ((array) $value as $name => $sub) {
                        if (is_array($sub)) {
                            $this->walk($sub, $file, $pointer.'/'.$keyword.'/'.$name, $allowed, $problems);
                        }
                    }
                    break;
                case 'items':
                    if (is_array($value)) {
                        $this->walk($value, $file, $pointer.'/items', $allowed, $problems);
                    }
                    break;
                case 'allOf':
                case 'anyOf':
                case 'oneOf':
                    foreach ((array) $value as $i => $sub) {
                        if (is_array($sub)) {
                            $this->walk($sub, $file, $pointer.'/'.$keyword.'/'.$i, $allowed, $problems);
                        }
                    }
                    break;
            }
        }
    }

    /** @param list<string> $problems */
    private function checkRef(string $ref, string $file, string $pointer, array &$problems): void
    {
        $hash = strpos($ref, '#');
        $target = $hash === false ? $ref : substr($ref, 0, $hash);
        $frag = $hash === false ? '' : substr($ref, $hash + 1);
        try {
            $doc = $target === '' ? $this->registry->document($file) : $this->registry->document(basename($target));
        } catch (\Throwable $e) {
            $problems[] = $file.' '.$pointer.': $ref "'.$ref.'" file not found';

            return;
        }
        $node = $doc;
        foreach (array_filter(explode('/', ltrim($frag, '/')), static fn ($s) => $s !== '') as $segment) {
            if (! is_array($node) || ! array_key_exists($segment, $node)) {
                $problems[] = $file.' '.$pointer.': $ref "'.$ref.'" does not resolve';

                return;
            }
            $node = $node[$segment];
        }
    }
}
