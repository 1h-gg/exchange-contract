<?php

declare(strict_types=1);

namespace Texstar\Exchange\Testing;

use Texstar\Exchange\Dto\Scope;
use Texstar\Exchange\Package\HashMismatchException;
use Texstar\Exchange\Package\PackageException;
use Texstar\Exchange\Package\PackageReader;
use Texstar\Exchange\Package\RowError;
use Texstar\Exchange\Schema\SchemaRegistry;
use Texstar\Exchange\Signing\KeyRing;
use Texstar\Exchange\Signing\SignatureException;
use Texstar\Exchange\Stream\RowKey;

/**
 * The golden fixtures as a runnable suite either app can invoke from its own test runner.
 *
 * A fixture is a directory under fixtures/v1/<name>/ holding the committed package tree
 * (manifest.json, streams/<stream>.ndjson, files/<sha256>, receipt.json, optional entries.json for
 * exotic zip entry names) and expectations.json. `cases()` zips each tree VERBATIM into the system
 * temp dir and yields {name, path, keyRing, expected}; `assertReader()` drives a PackageReader the
 * way an importer does (verify -> validate -> hashes -> rows) and reports every mismatch through the
 * `$fail` callable, so PHPUnit, Pest or a plain script can host it.
 *
 * expectations.json:
 *   kind, direction, key_id, scope {tenant, contract, program, instance}, scope_declared (bool),
 *   verify: ok | signature_missing | unknown_key_id | signature_invalid,
 *   scope_binding: ok | scope_mismatch   (checked when verify is ok; default ok) — PackageReader::assertScope
 *            against Scope::UNSCOPED, the texstar / comcast / comcast-nesc instance both apps serve today,
 *   validate: ok | <primary violation keyword>, violations: [keywords],
 *   hashes: ok | hash_mismatch | skipped,
 *   streams: {<name>: {rows, rejects, reject_codes?, rejected_rows?}}   rows = parsed row objects that
 *            pass the stream schema and can be keyed; rejects = RowError lines + schema-invalid rows +
 *            unkeyable rows; rejected_rows = [{seq, code}] with the receipt row codes (row_too_large,
 *            invalid_json, schema_invalid, row_key),
 *   skipped: [stream names the negotiation skipped], receipts: int (-1 = receipt.json refused),
 *   source: {<stream>: "producer file:line"}, build: {recompute, sign}   (used by fixtures:sign only)
 */
final class ContractSuite
{
    public const FIXTURES_DIR = __DIR__.'/../../fixtures/v1';

    /** Files of a fixture tree that are never zip entries. */
    private const META = ['expectations.json', 'entries.json'];

    /** @var list<string> */
    private static array $built = [];

    private function __construct() {}

    public static function fixturesDir(): string
    {
        return realpath(self::FIXTURES_DIR) ?: self::FIXTURES_DIR;
    }

    /** The test-only key ring (fixtures/v1/keys.json). */
    public static function keyRing(): KeyRing
    {
        $keys = json_decode((string) file_get_contents(self::fixturesDir().'/keys.json'), true, 8, JSON_THROW_ON_ERROR);

        return KeyRing::fromArray(is_array($keys) ? $keys : []);
    }

    /** @return list<string> fixture names, sorted */
    public static function names(): array
    {
        $names = [];
        foreach (glob(self::fixturesDir().'/*/expectations.json') ?: [] as $path) {
            $names[] = basename(dirname($path));
        }
        sort($names);

        return $names;
    }

    /** @return array<string, mixed> */
    public static function expected(string $name): array
    {
        $path = self::dir($name).'/expectations.json';
        $json = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($json)) {
            throw new \RuntimeException('expectations.json of '.$name.' is not an object');
        }

        return $json;
    }

    public static function dir(string $name): string
    {
        if (! preg_match('~^[a-z0-9-]{1,60}$~', $name)) {
            throw new \InvalidArgumentException('Bad fixture name');
        }
        $dir = self::fixturesDir().'/'.$name;
        if (! is_dir($dir)) {
            throw new \RuntimeException('Unknown fixture '.$name);
        }

        return $dir;
    }

    /**
     * Zip a fixture tree verbatim. Standard entries come from the tree layout; entries.json maps extra
     * zip entry names (e.g. "streams/../x.ndjson") to files under the tree.
     */
    public static function build(string $name, ?string $zipPath = null): string
    {
        $dir = self::dir($name);
        $zipPath ??= tempnam(sys_get_temp_dir(), 'txx-fixture-').'-'.$name.'.txx.zip';
        @unlink($zipPath);
        $zip = new \ZipArchive;
        if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create '.$zipPath);
        }
        foreach (self::entries($name) as $entry => $source) {
            $zip->addFile($source, $entry);
            $zip->setCompressionName($entry, str_starts_with($entry, 'files/') ? \ZipArchive::CM_STORE : \ZipArchive::CM_DEFLATE);
        }
        if ($zip->close() !== true) {
            throw new \RuntimeException('Cannot write '.$zipPath);
        }
        self::$built[] = $zipPath;

        return $zipPath;
    }

    /** The zip entries a fixture tree produces: entry name => source file. @return array<string, string> */
    public static function entries(string $name): array
    {
        $dir = self::dir($name);
        $out = [];
        foreach (['manifest.json', 'receipt.json'] as $file) {
            if (is_file($dir.'/'.$file)) {
                $out[$file] = $dir.'/'.$file;
            }
        }
        foreach (glob($dir.'/streams/*.ndjson') ?: [] as $path) {
            $out['streams/'.basename($path)] = $path;
        }
        foreach (glob($dir.'/files/*') ?: [] as $path) {
            $out['files/'.basename($path)] = $path;
        }
        if (is_file($dir.'/entries.json')) {
            $extra = json_decode((string) file_get_contents($dir.'/entries.json'), true, 8, JSON_THROW_ON_ERROR);
            foreach (is_array($extra) ? $extra : [] as $entry => $relative) {
                $source = $dir.'/'.$relative;
                if (! is_file($source)) {
                    throw new \RuntimeException('entries.json of '.$name.' points at a missing file '.$relative);
                }
                $out[(string) $entry] = $source;
            }
        }

        return $out;
    }

    /**
     * Every fixture, zipped on demand.
     *
     * @return \Generator<string, array{name: string, path: string, keyRing: KeyRing, expected: array<string, mixed>}>
     */
    public static function cases(): \Generator
    {
        $ring = self::keyRing();
        foreach (self::names() as $name) {
            yield $name => ['name' => $name, 'path' => self::build($name), 'keyRing' => $ring, 'expected' => self::expected($name)];
        }
    }

    /** Delete the zips `cases()`/`build()` wrote into the temp dir. */
    public static function cleanup(): void
    {
        foreach (self::$built as $path) {
            @unlink($path);
        }
        self::$built = [];
    }

    /**
     * Drive the reader like an importer and report every mismatch through `$fail(string)`.
     *
     * @param  array<string, mixed>  $expected
     * @param  callable(string): void  $fail
     */
    public static function assertReader(PackageReader $reader, array $expected, callable $fail, ?KeyRing $ring = null): void
    {
        foreach (self::check($reader, $expected, $ring ?? self::keyRing()) as $problem) {
            $fail($problem);
        }
    }

    /**
     * The mismatches between a reader and a fixture's expectations (empty = pass).
     *
     * @param  array<string, mixed>  $expected
     * @return list<string>
     */
    public static function check(PackageReader $reader, array $expected, KeyRing $ring): array
    {
        $problems = [];
        $manifest = $reader->manifest();
        $dto = $reader->manifestDto();

        if (isset($expected['kind']) && $reader->kind() !== $expected['kind']) {
            $problems[] = 'kind: expected '.$expected['kind'].', got '.$reader->kind();
        }
        if (isset($expected['direction']) && $dto->direction !== $expected['direction']) {
            $problems[] = 'direction: expected '.$expected['direction'].', got '.$dto->direction;
        }
        if (array_key_exists('key_id', $expected)) {
            $keyId = is_array($manifest['signature'] ?? null) ? ($manifest['signature']['key_id'] ?? null) : null;
            if ($keyId !== $expected['key_id']) {
                $problems[] = 'signature.key_id: expected '.json_encode($expected['key_id']).', got '.json_encode($keyId);
            }
        }
        if (array_key_exists('scope_declared', $expected) && ($dto->scope !== null) !== (bool) $expected['scope_declared']) {
            $problems[] = 'scope: expected declared='.json_encode($expected['scope_declared']);
        }
        if (isset($expected['scope']) && $dto->resolvedScope()->toArray() !== Scope::resolve($expected['scope'])->toArray()) {
            $problems[] = 'scope: expected '.json_encode($expected['scope']).', got '.json_encode($dto->resolvedScope()->toArray());
        }

        // verify: fail closed, one code
        $verify = 'ok';
        try {
            $reader->verify($ring);
        } catch (SignatureException $e) {
            $verify = $e->wireCode();
        }
        if (($expected['verify'] ?? 'ok') !== $verify) {
            $problems[] = 'verify: expected '.($expected['verify'] ?? 'ok').', got '.$verify;
        }

        // scope binding: the verified key belongs to the package's own scope, and that scope is ours
        if ($verify === 'ok') {
            $binding = 'ok';
            try {
                $reader->assertScope(Scope::unscoped());
            } catch (PackageException $e) {
                $binding = $e->wireCode();
            }
            if (($expected['scope_binding'] ?? 'ok') !== $binding) {
                $problems[] = 'scope_binding: expected '.($expected['scope_binding'] ?? 'ok').', got '.$binding;
            }
        }

        // validate: schema + negotiation + path discipline
        $violations = $reader->validateManifest();
        $keywords = array_values(array_unique(array_map(static fn ($v) => $v->keyword, $violations)));
        $validate = $keywords === [] ? 'ok' : $keywords[0];
        $expectedValidate = $expected['validate'] ?? 'ok';
        if ($expectedValidate === 'ok' ? $keywords !== [] : ! in_array($expectedValidate, $keywords, true)) {
            $problems[] = 'validate: expected '.$expectedValidate.', got '.($keywords === [] ? 'ok' : implode(',', $keywords)).' ('.implode('; ', array_map('strval', $violations)).')';
        }
        foreach ((array) ($expected['violations'] ?? []) as $keyword) {
            if (! in_array($keyword, $keywords, true)) {
                $problems[] = 'validate: missing violation '.$keyword.' (got '.implode(',', $keywords).')';
            }
        }
        $negotiation = $reader->negotiation();
        $skipped = array_map(static fn (array $s) => $s['stream'], $negotiation->skipped);
        sort($skipped);
        $expectedSkipped = (array) ($expected['skipped'] ?? []);
        sort($expectedSkipped);
        if ($skipped !== $expectedSkipped) {
            $problems[] = 'skipped: expected '.json_encode($expectedSkipped).', got '.json_encode($skipped);
        }

        // hashes
        $expectedHashes = $expected['hashes'] ?? 'ok';
        if ($expectedHashes !== 'skipped') {
            $hashes = 'ok';
            try {
                $reader->checkHashes();
            } catch (HashMismatchException $e) {
                $hashes = 'hash_mismatch';
            } catch (PackageException $e) {
                $hashes = $e->wireCode();
            }
            if ($hashes !== $expectedHashes) {
                $problems[] = 'hashes: expected '.$expectedHashes.', got '.$hashes;
            }
        }

        // rows, per accepted stream
        $registry = SchemaRegistry::v1();
        foreach ((array) ($expected['streams'] ?? []) as $stream => $counts) {
            if (! $negotiation->accepted || ! in_array($stream, $negotiation->streams, true)) {
                if (($counts['rows'] ?? 0) !== 0 || ($counts['rejects'] ?? 0) !== 0) {
                    $problems[] = 'stream '.$stream.': expected rows but the negotiation did not accept it';
                }

                continue;
            }
            $rows = 0;
            $rejects = 0;
            $codes = [];
            $rejected = [];
            try {
                foreach ($reader->rows($stream) as $seq => $row) {
                    if ($row instanceof RowError) {
                        $rejects++;
                        $codes[] = $row->code;
                        $rejected[] = ['seq' => $seq, 'code' => $row->code];

                        continue;
                    }
                    $bad = $registry->validateRow($stream, $row);
                    if ($bad !== []) {
                        $rejects++;
                        $codes[] = 'schema:'.$bad[0]->path.':'.$bad[0]->keyword;
                        $rejected[] = ['seq' => $seq, 'code' => 'schema_invalid'];

                        continue;
                    }
                    if (RowKey::tryFor($stream, $row) === null) {
                        $rejects++;
                        $codes[] = 'row_key';
                        $rejected[] = ['seq' => $seq, 'code' => 'row_key'];

                        continue;
                    }
                    $rows++;
                }
            } catch (PackageException $e) {
                $problems[] = 'stream '.$stream.': '.$e->wireCode().' '.$e->getMessage();

                continue;
            }
            if ($rows !== (int) ($counts['rows'] ?? 0) || $rejects !== (int) ($counts['rejects'] ?? 0)) {
                $problems[] = 'stream '.$stream.': expected rows='.($counts['rows'] ?? 0).' rejects='.($counts['rejects'] ?? 0).', got rows='.$rows.' rejects='.$rejects.($codes ? ' ['.implode(', ', $codes).']' : '');
            }
            if (isset($counts['reject_codes']) && array_values((array) $counts['reject_codes']) !== $codes) {
                $problems[] = 'stream '.$stream.': expected reject codes '.json_encode($counts['reject_codes']).', got '.json_encode($codes);
            }
            if (isset($counts['rejected_rows'])) {
                $want = array_map(static fn ($r) => ['seq' => (int) ($r['seq'] ?? -1), 'code' => (string) ($r['code'] ?? '')], array_values((array) $counts['rejected_rows']));
                if ($want !== $rejected) {
                    $problems[] = 'stream '.$stream.': expected rejected rows '.json_encode($want).', got '.json_encode($rejected);
                }
            }
        }
        $declared = array_map(static fn ($f) => (string) ($f['stream'] ?? ''), is_array($manifest['files'] ?? null) ? $manifest['files'] : []);
        foreach ($declared as $stream) {
            if (! array_key_exists($stream, (array) ($expected['streams'] ?? [])) && ! in_array($stream, $skipped, true) && ($expected['validate'] ?? 'ok') === 'ok') {
                $problems[] = 'stream '.$stream.' is in the manifest but not in expectations.streams';
            }
        }

        // receipts
        if (array_key_exists('receipts', $expected)) {
            try {
                $count = count($reader->receipts());
            } catch (PackageException $e) {
                $count = -1;
            }
            if ($count !== (int) $expected['receipts']) {
                $problems[] = 'receipts: expected '.$expected['receipts'].', got '.$count;
            }
        }

        return $problems;
    }
}
