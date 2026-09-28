<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

use Texstar\Exchange\Contract;
use Texstar\Exchange\Dto\Manifest;
use Texstar\Exchange\Dto\Receipt;
use Texstar\Exchange\Schema\SchemaRegistry;
use Texstar\Exchange\Schema\Violation;
use Texstar\Exchange\Signing\KeyRing;
use Texstar\Exchange\Signing\Verifier;

/**
 * Reads a package without inflating it onto disk: entries are opened one at a time through zip://,
 * every inflated byte passes through one {@see InflateCounter} (cap => InflatedSizeException), and an
 * entry that inflates past the size its central-directory record declares is cut off after at most one
 * read buffer. Entry names are only ever the validated manifest paths, never filesystem components.
 *
 * Order of use: open() -> manifest() -> verify(ring) -> validateManifest() -> declaredSize() ->
 * checkHashes() -> rows()/blob()/receipts().
 */
final class PackageReader
{
    private const CHUNK = 65536;

    private \ZipArchive $zip;

    private InflateCounter $counter;

    /** @var array<string, mixed> */
    private array $manifest;

    private ?Manifest $dto = null;

    /** @var array<string, int> entry => index */
    private array $index = [];

    private bool $closed = false;

    private function __construct(private readonly string $path, int $maxInflatedBytes)
    {
        $this->counter = new InflateCounter($maxInflatedBytes);
        $this->zip = new \ZipArchive;
        $rc = $this->zip->open($path, \ZipArchive::RDONLY);
        if ($rc !== true) {
            throw new PackageException('Not a readable zip (code '.$rc.')', 'package_invalid');
        }
        for ($i = 0; $i < $this->zip->numFiles; $i++) {
            $stat = $this->zip->statIndex($i);
            if ($stat !== false) {
                $this->index[$stat['name']] = $i;
            }
        }
        if (! isset($this->index[Contract::MANIFEST_ENTRY])) {
            throw new PackageException('Package has no manifest.json', 'manifest_missing');
        }
        $raw = $this->readWhole(Contract::MANIFEST_ENTRY, Contract::MAX_MANIFEST_BYTES);
        try {
            $manifest = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PackageException('manifest.json is not valid JSON', 'manifest_invalid');
        }
        if (! is_array($manifest) || array_is_list($manifest) && $manifest !== []) {
            throw new PackageException('manifest.json is not an object', 'manifest_invalid');
        }
        $this->manifest = $manifest;
    }

    /** @param int $maxInflatedBytes the inflated-bytes cap; callers MUST pass the configured cap for untrusted input */
    public static function open(string $path, int $maxInflatedBytes = PHP_INT_MAX): self
    {
        if (! is_file($path)) {
            throw new PackageException('Package file not found', 'package_invalid');
        }

        return new self($path, $maxInflatedBytes);
    }

    /** The raw decoded manifest. @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->manifest;
    }

    public function manifestDto(): Manifest
    {
        return $this->dto ??= Manifest::fromArray($this->manifest);
    }

    public function kind(): string
    {
        return (string) ($this->manifest['kind'] ?? Contract::KIND_PACKAGE);
    }

    /** Fail closed: throws SignatureException (signature_missing | unknown_key_id | signature_invalid). */
    public function verify(KeyRing $ring): void
    {
        (new Verifier)->verify($this->manifest, $ring);
    }

    /**
     * Schema + version negotiation + path discipline, without reading any stream or blob entry.
     *
     * @return list<Violation> keywords include unsupported_version, bad_path, unexpected_entry, missing_entry
     */
    public function validateManifest(?SchemaRegistry $registry = null): array
    {
        $violations = ($registry ?? SchemaRegistry::v1())->validateManifest($this->manifest);
        $negotiation = $this->negotiation();
        if (! $negotiation->accepted) {
            $violations[] = new Violation('$.version', (string) $negotiation->rejectCode, (string) $negotiation->message);
        }

        return [...$violations, ...PathDiscipline::check($this->manifest, $this->entries())];
    }

    public function negotiation(): NegotiationResult
    {
        return Negotiation::evaluate($this->manifest);
    }

    /** @return list<string> */
    public function entries(): array
    {
        return array_keys($this->index);
    }

    /** Sum of the central directory's declared uncompressed sizes: a cheap pre-check, not a guarantee. */
    public function declaredSize(): int
    {
        $sum = 0;
        foreach ($this->index as $i) {
            $stat = $this->zip->statIndex($i);
            $sum += $stat === false ? 0 : (int) $stat['size'];
        }

        return $sum;
    }

    public function inflatedBytes(): int
    {
        return $this->counter->total();
    }

    /**
     * Stream one file's rows: yields seq => array, or seq => RowError (row_too_large skips to the next
     * newline; invalid_json) so seq stays the 0-based line number.
     *
     * @return \Generator<int, array<string, mixed>|RowError>
     */
    public function rows(string $stream, int $maxLineBytes = Contract::MAX_ROW_BYTES): \Generator
    {
        $entry = $this->streamEntry($stream);
        $limit = $this->entryLimit($entry, null);
        $fp = $this->openEntry($entry);
        $read = 0;
        $seq = 0;
        try {
            while (! feof($fp)) {
                $line = fgets($fp, $maxLineBytes + 2);
                if ($line === false) {
                    break;
                }
                $read = $this->count($entry, $read, strlen($line), $limit);
                if (! str_ends_with($line, "\n") && ! feof($fp)) {
                    // Longer than the cap: drain to the next newline (still counted) and report the row.
                    $bytes = strlen($line);
                    while (! feof($fp)) {
                        $more = fgets($fp, self::CHUNK);
                        if ($more === false) {
                            break;
                        }
                        $read = $this->count($entry, $read, strlen($more), $limit);
                        $bytes += strlen($more);
                        if (str_ends_with($more, "\n")) {
                            $bytes--;
                            break;
                        }
                    }
                    yield $seq++ => new RowError($seq - 1, RowError::TOO_LARGE, 'line exceeds '.$maxLineBytes.' bytes', $bytes);

                    continue;
                }
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    if (feof($fp)) {
                        break; // trailing newline
                    }
                    yield $seq++ => new RowError($seq - 1, RowError::INVALID_JSON, 'empty line', 0);

                    continue;
                }
                try {
                    $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    yield $seq++ => new RowError($seq - 1, RowError::INVALID_JSON, $e->getMessage(), strlen($line));

                    continue;
                }
                if (! is_array($row) || ($row !== [] && array_is_list($row))) {
                    yield $seq++ => new RowError($seq - 1, RowError::INVALID_JSON, 'line is not a JSON object', strlen($line));

                    continue;
                }
                yield $seq++ => $row;
            }
        } finally {
            fclose($fp);
        }
    }

    /** A readable stream of the blob's bytes (copied through the counter into php://temp). */
    public function blob(string $sha256)
    {
        $out = fopen('php://temp', 'w+b');
        $this->copyBlobTo($sha256, $out);
        rewind($out);

        return $out;
    }

    /** Copy a blob's bytes into `$dest` through the counter; returns the byte count. @param resource $dest */
    public function copyBlobTo(string $sha256, $dest): int
    {
        $sha256 = strtolower($sha256);
        $blob = $this->blobDeclaration($sha256);
        $entry = Contract::blobEntry($sha256);
        $limit = $this->entryLimit($entry, isset($blob['bytes']) ? (int) $blob['bytes'] : null);
        $fp = $this->openEntry($entry);
        $read = 0;
        try {
            while (! feof($fp)) {
                $chunk = fread($fp, self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $read = $this->count($entry, $read, strlen($chunk), $limit);
                fwrite($dest, $chunk);
            }
        } finally {
            fclose($fp);
        }

        return $read;
    }

    /**
     * Hash every stream and blob the manifest lists under the inflate guard: a stream's sha256 must match
     * files[].sha256 (and its bytes, when declared); a blob's bytes must hash to blobs[].sha256, which must
     * equal the entry name, and its length must equal blobs[].bytes. Throws HashMismatchException /
     * InflatedSizeException; returns the entries checked.
     *
     * @return list<string>
     */
    public function checkHashes(): array
    {
        $checked = [];
        foreach (is_array($this->manifest['files'] ?? null) ? $this->manifest['files'] : [] as $file) {
            $stream = is_array($file) ? (string) ($file['stream'] ?? '') : '';
            $entry = $this->streamEntry($stream);
            $declaredBytes = isset($file['bytes']) ? (int) $file['bytes'] : null;
            [$hash, $bytes] = $this->hashEntry($entry, $declaredBytes);
            if (! hash_equals((string) ($file['sha256'] ?? ''), $hash)) {
                throw new HashMismatchException($entry, 'sha256 does not match the manifest');
            }
            if ($declaredBytes !== null && $bytes !== $declaredBytes) {
                throw new HashMismatchException($entry, 'byte count does not match the manifest');
            }
            $checked[] = $entry;
        }
        foreach (is_array($this->manifest['blobs'] ?? null) ? $this->manifest['blobs'] : [] as $blob) {
            $sha = is_array($blob) ? strtolower((string) ($blob['sha256'] ?? '')) : '';
            $path = is_array($blob) ? (string) ($blob['path'] ?? '') : '';
            if (! preg_match(Contract::SHA256_PATTERN, $sha) || $path !== Contract::blobEntry($sha)) {
                throw new HashMismatchException($path, 'blob path does not match its sha256');
            }
            $declaredBytes = isset($blob['bytes']) ? (int) $blob['bytes'] : null;
            [$hash, $bytes] = $this->hashEntry($path, $declaredBytes);
            if (! hash_equals($sha, $hash)) {
                throw new HashMismatchException($path, 'blob bytes do not hash to the entry name');
            }
            if ($declaredBytes !== null && $bytes !== $declaredBytes) {
                throw new HashMismatchException($path, 'blob byte count does not match the manifest');
            }
            $checked[] = $path;
        }

        return $checked;
    }

    /** @return list<Receipt> */
    public function receipts(): array
    {
        $refs = is_array($this->manifest['receipts'] ?? null) ? $this->manifest['receipts'] : [];
        if ($refs === [] || ! isset($this->index[Contract::RECEIPT_ENTRY])) {
            return [];
        }
        try {
            $document = json_decode($this->readWhole(Contract::RECEIPT_ENTRY, Contract::MAX_MANIFEST_BYTES), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PackageException('receipt.json is not valid JSON', 'receipt_invalid');
        }
        if (! is_array($document) || ($document['format'] ?? null) !== Contract::RECEIPT_FORMAT || ! is_array($document['receipts'] ?? null)) {
            throw new PackageException('receipt.json is not a '.Contract::RECEIPT_FORMAT.' document', 'receipt_invalid');
        }

        return array_values(array_map(Receipt::fromArray(...), array_filter($document['receipts'], 'is_array')));
    }

    public function close(): void
    {
        if (! $this->closed) {
            $this->closed = true;
            $this->zip->close();
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    // ---- internals ------------------------------------------------------------------------------

    private function streamEntry(string $stream): string
    {
        if (! preg_match(Contract::STREAM_NAME_PATTERN, $stream)) {
            throw new PackageException('Bad stream name', 'unknown_stream');
        }
        $entry = Contract::streamEntry($stream);
        foreach (is_array($this->manifest['files'] ?? null) ? $this->manifest['files'] : [] as $file) {
            if (is_array($file) && ($file['stream'] ?? null) === $stream) {
                if (($file['path'] ?? null) !== $entry) {
                    throw new PackageException('Manifest path for stream '.$stream.' is outside the closed layout', 'bad_path');
                }
                if (! isset($this->index[$entry])) {
                    throw new PackageException('Zip has no entry for stream '.$stream, 'missing_entry');
                }

                return $entry;
            }
        }
        throw new PackageException('Manifest does not list stream '.$stream, 'unknown_stream');
    }

    /** @return array<string, mixed> */
    private function blobDeclaration(string $sha256): array
    {
        if (! preg_match(Contract::SHA256_PATTERN, $sha256)) {
            throw new PackageException('Bad sha256', 'hash_mismatch');
        }
        foreach (is_array($this->manifest['blobs'] ?? null) ? $this->manifest['blobs'] : [] as $blob) {
            if (is_array($blob) && strtolower((string) ($blob['sha256'] ?? '')) === $sha256) {
                if (($blob['path'] ?? null) !== Contract::blobEntry($sha256)) {
                    throw new PackageException('Manifest path for blob is outside the closed layout', 'bad_path');
                }
                if (! isset($this->index[Contract::blobEntry($sha256)])) {
                    throw new PackageException('Zip has no entry for blob '.$sha256, 'missing_entry');
                }

                return $blob;
            }
        }
        throw new PackageException('Manifest does not list blob '.$sha256, 'not_found');
    }

    /** The most an entry may inflate to: its central-directory size, tightened by the manifest's byte count when given. */
    private function entryLimit(string $entry, ?int $manifestBytes): int
    {
        $stat = $this->zip->statIndex($this->index[$entry] ?? -1);
        $declared = $stat === false ? 0 : (int) $stat['size'];

        return $manifestBytes === null ? $declared : min($declared, $manifestBytes);
    }

    /** @return resource */
    private function openEntry(string $entry)
    {
        if (! isset($this->index[$entry])) {
            throw new PackageException('Zip has no entry '.$entry, 'missing_entry');
        }
        $fp = @fopen('zip://'.$this->path.'#'.$entry, 'rb');
        if ($fp === false) {
            throw new PackageException('Cannot open zip entry '.$entry, 'package_invalid');
        }

        return $fp;
    }

    /** Count `$bytes` more for `$entry`: the global cap first, then the entry's declared size. */
    private function count(string $entry, int $read, int $bytes, int $limit): int
    {
        $this->counter->add($bytes);
        $read += $bytes;
        if ($read > $limit) {
            throw new InflatedSizeException(InflatedSizeException::ENTRY_OVERFLOW, $read, $limit, $entry);
        }

        return $read;
    }

    /** @return array{0: string, 1: int} sha256 hex and byte count */
    private function hashEntry(string $entry, ?int $manifestBytes): array
    {
        $limit = $this->entryLimit($entry, $manifestBytes);
        $fp = $this->openEntry($entry);
        $hash = hash_init('sha256');
        $read = 0;
        try {
            while (! feof($fp)) {
                $chunk = fread($fp, self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $read = $this->count($entry, $read, strlen($chunk), $limit);
                hash_update($hash, $chunk);
            }
        } finally {
            fclose($fp);
        }

        return [hash_final($hash), $read];
    }

    private function readWhole(string $entry, int $max): string
    {
        $stat = $this->zip->statIndex($this->index[$entry]);
        if ($stat === false || (int) $stat['size'] > $max) {
            throw new PackageException($entry.' is larger than '.$max.' bytes', 'package_too_large');
        }
        $fp = $this->openEntry($entry);
        $out = '';
        $read = 0;
        try {
            while (! feof($fp)) {
                $chunk = fread($fp, self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $read = $this->count($entry, $read, strlen($chunk), $max);
                $out .= $chunk;
            }
        } finally {
            fclose($fp);
        }

        return $out;
    }
}
