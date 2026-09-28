<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

use Texstar\Exchange\Contract;
use Texstar\Exchange\Dto\Blob;
use Texstar\Exchange\Dto\Manifest;
use Texstar\Exchange\Dto\Receipt;
use Texstar\Exchange\Dto\ReceiptRef;
use Texstar\Exchange\Dto\Scope;
use Texstar\Exchange\Dto\StreamFile;
use Texstar\Exchange\Signing\KeyRing;
use Texstar\Exchange\Signing\Signer;
use Texstar\Exchange\Stream\StreamCatalogue;

/**
 * Streams a package onto disk: rows go line by line through a temp file (counted and hashed as they
 * pass), blobs are re-hashed and stored under files/<recomputed sha256>, receipt.json is written from
 * Receipt DTOs, and manifest.json is written LAST, signed. No media is ever embedded as base64.
 *
 * `finalizeUnsigned()` is permitted only for a draft whose kind is `rows` (the API mini-package);
 * every kind=package zip carries an HMAC signature.
 *
 * `verbatim` mode (fixtures) copies pre-built entries and a pre-built manifest without touching them.
 */
final class PackageBuilder
{
    private const ROW_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

    private const OFFSET_EVERY = 1000;

    private \ZipArchive $zip;

    /** @var array<string, mixed> */
    private array $draft;

    /** @var array<string, StreamFile> */
    private array $files = [];

    /** @var array<string, Blob> */
    private array $blobs = [];

    /** @var list<ReceiptRef> */
    private array $receipts = [];

    /** @var list<string> */
    private array $tempFiles = [];

    private bool $closed = false;

    private function __construct(private readonly string $path, Manifest $draft, private readonly bool $verbatim, private readonly bool $stampScope)
    {
        $this->draft = $draft->toArray();
        $this->zip = new \ZipArchive;
        $rc = $this->zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($rc !== true) {
            throw new PackageException('Cannot create zip at '.$path.' (code '.$rc.')', 'package_write');
        }
    }

    /**
     * @param  Manifest  $draft  package_id, direction, created_at, producer, scope, kind, cursors (files/blobs/receipts are filled here)
     * @param  bool  $stampScope  add `scope` (the manifest's resolved scope) to rows that lack it
     */
    public static function open(string $path, Manifest $draft, bool $verbatim = false, bool $stampScope = true): self
    {
        if (! in_array($draft->kind, Contract::KINDS, true)) {
            throw new PackageException('Unknown manifest kind '.$draft->kind, 'manifest_invalid');
        }
        if (! in_array($draft->direction, Contract::DIRECTIONS, true)) {
            throw new PackageException('Unknown direction '.$draft->direction, 'manifest_invalid');
        }

        return new self($path, $draft, $verbatim, $stampScope);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Write one stream. Rows are encoded as they arrive (the emitter's key order is kept), a line over
     * MAX_ROW_BYTES throws {@see RowTooLargeException}, an inspection_reports row with a non-null
     * content_base64 is refused, and `$onOffset(seq, byteOffset)` fires every 1000 rows (and at row 0).
     *
     * @param  iterable<array<string, mixed>>  $rows
     * @param  null|callable(int, int): void  $onOffset
     */
    public function addStreamRows(string $stream, iterable $rows, ?callable $onOffset = null, ?string $mode = null, ?string $setChecksum = null, ?int $setTotal = null): StreamFile
    {
        $this->assertOpen();
        if (! preg_match(Contract::STREAM_NAME_PATTERN, $stream)) {
            throw new PackageException('Bad stream name', 'unknown_stream');
        }
        if (isset($this->files[$stream])) {
            throw new PackageException('Stream '.$stream.' already added', 'duplicate_stream');
        }
        $mode ??= StreamCatalogue::has($stream) ? StreamCatalogue::mode($stream) : StreamCatalogue::MODE_DELTA;
        $schema = StreamCatalogue::has($stream) ? StreamCatalogue::schemaId($stream) : $stream.'@'.StreamCatalogue::SCHEMA_MAJOR;
        $scope = is_array($this->draft['scope'] ?? null) ? $this->draft['scope'] : Scope::UNSCOPED;

        $temp = $this->tempFile();
        $fp = fopen($temp, 'wb');
        if ($fp === false) {
            throw new PackageException('Cannot open temp file', 'package_write');
        }
        $hash = hash_init('sha256');
        $seq = 0;
        $offset = 0;
        $previous = ini_get('serialize_precision');
        ini_set('serialize_precision', '-1');
        try {
            foreach ($rows as $row) {
                if (! is_array($row)) {
                    throw new PackageException('Row '.$seq.' of stream '.$stream.' is not an object', 'row_invalid');
                }
                if ($stream === 'inspection_reports' && ($row['content_base64'] ?? null) !== null) {
                    throw new PackageException('inspection_reports row '.$seq.' carries content_base64; media travels under files/<sha256>', 'row_invalid');
                }
                if ($this->stampScope && ! array_key_exists('scope', $row)) {
                    $row['scope'] = $scope;
                }
                $line = json_encode($row, self::ROW_FLAGS);
                $bytes = strlen($line);
                if ($bytes > Contract::MAX_ROW_BYTES) {
                    throw new RowTooLargeException($stream, $seq, $bytes, Contract::MAX_ROW_BYTES);
                }
                if ($onOffset !== null && $seq % self::OFFSET_EVERY === 0) {
                    $onOffset($seq, $offset);
                }
                $line .= "\n";
                fwrite($fp, $line);
                hash_update($hash, $line);
                $offset += $bytes + 1;
                $seq++;
            }
        } finally {
            ini_set('serialize_precision', $previous === false ? '-1' : $previous);
            fclose($fp);
        }
        $entry = Contract::streamEntry($stream);
        $this->zip->addFile($temp, $entry);
        $this->zip->setCompressionName($entry, \ZipArchive::CM_DEFLATE);

        return $this->files[$stream] = new StreamFile($entry, $stream, $schema, $seq, hash_final($hash), $offset, $mode, $setChecksum, $setTotal);
    }

    /**
     * Include a file under files/<sha256>. The hash is recomputed from the bytes; a mismatch with the
     * caller's `$sha256` throws {@see BlobHashException}. Adding the same blob twice is a no-op.
     *
     * @param  string|resource  $source  a path or an open readable stream
     */
    public function addBlob(string $sha256, mixed $source, string $mime): Blob
    {
        $this->assertOpen();
        $sha256 = strtolower($sha256);
        if (is_resource($source)) {
            $path = $this->tempFile();
            $out = fopen($path, 'wb');
            stream_copy_to_stream($source, $out);
            fclose($out);
        } elseif (is_string($source) && is_file($source)) {
            $path = $source;
        } else {
            throw new PackageException('Blob source must be a readable file or stream', 'blob_invalid');
        }
        $actual = hash_file('sha256', $path);
        if ($actual === false || ! hash_equals($actual, $sha256)) {
            throw new BlobHashException($sha256, (string) $actual);
        }
        if (isset($this->blobs[$actual])) {
            return $this->blobs[$actual];
        }
        $entry = Contract::blobEntry($actual);
        $this->zip->addFile($path, $entry);
        $this->zip->setCompressionName($entry, \ZipArchive::CM_STORE);

        return $this->blobs[$actual] = new Blob($actual, (int) filesize($path), $mime, $entry);
    }

    /** @param list<Receipt> $receipts */
    public function addReceipts(array $receipts): void
    {
        $this->assertOpen();
        if ($this->receipts !== []) {
            throw new PackageException('Receipts already added', 'duplicate_receipts');
        }
        if ($receipts === []) {
            return;
        }
        $packageIds = [];
        $longest = 0;
        foreach ($receipts as $receipt) {
            if (! $receipt instanceof Receipt) {
                throw new PackageException('addReceipts() takes Receipt DTOs', 'receipt_invalid');
            }
            $packageIds[] = $receipt->packageId;
            $longest = max($longest, $receipt->longestRejectList());
        }
        // A reader refuses a receipt.json over MAX_MANIFEST_BYTES (and with it the whole package), so an
        // oversize document lists fewer rejected rows per stream — halving until it fits — and counts the
        // rest in rejected_rows_omitted. The counts, and so what the producer settles, never change.
        $cap = null;
        while (true) {
            $document = ['format' => Contract::RECEIPT_FORMAT, 'version' => Contract::MAJOR, 'receipts' => []];
            foreach ($receipts as $receipt) {
                $document['receipts'][] = ($cap === null ? $receipt : $receipt->withListedRejects($cap))->toArray();
            }
            $bytes = json_encode($document, self::ROW_FLAGS);
            if (strlen($bytes) <= Contract::MAX_MANIFEST_BYTES) {
                break;
            }
            if ($cap === 0 || $longest === 0) {
                throw new PackageException(sprintf('receipt.json is %d bytes even with no rejected rows listed (limit %d); carry fewer receipts', strlen($bytes), Contract::MAX_MANIFEST_BYTES), 'receipt_too_large');
            }
            $cap = intdiv($cap ?? $longest, 2);
        }
        // receipt.json is bound into the signed manifest by its hash and length, like a stream file.
        $sha = hash('sha256', $bytes);
        foreach ($packageIds as $packageId) {
            $this->receipts[] = new ReceiptRef($packageId, Contract::RECEIPT_ENTRY, $sha, strlen($bytes));
        }
        $this->zip->addFromString(Contract::RECEIPT_ENTRY, $bytes);
    }

    /** Signed finalize: composes the manifest, signs it with `$keyId` from the ring, writes manifest.json last. */
    public function finalize(Signer $signer, KeyRing $ring, string $keyId, array $extras = []): Manifest
    {
        $this->assertOpen();
        $manifest = $signer->signManifest($this->compose($extras), $ring, $keyId);

        return $this->write($manifest);
    }

    /** Unsigned finalize (`signature: null`): permitted ONLY when the draft kind is `rows`. */
    public function finalizeUnsigned(array $extras = []): Manifest
    {
        $this->assertOpen();
        if (($this->draft['kind'] ?? Contract::KIND_PACKAGE) !== Contract::KIND_ROWS) {
            throw new UnsignedPackageException((string) ($this->draft['kind'] ?? Contract::KIND_PACKAGE));
        }
        $manifest = $this->compose($extras);
        $manifest['signature'] = null;

        return $this->write($manifest);
    }

    /** verbatim mode: copy a pre-built entry (a fixture's stream file or blob) without re-hashing. */
    public function addVerbatimEntry(string $entry, string $sourcePath): void
    {
        $this->assertVerbatim();
        if (! is_file($sourcePath)) {
            throw new PackageException('Verbatim source missing: '.$sourcePath, 'package_write');
        }
        $this->zip->addFile($sourcePath, $entry);
    }

    public function addVerbatimString(string $entry, string $contents): void
    {
        $this->assertVerbatim();
        $this->zip->addFromString($entry, $contents);
    }

    /** verbatim mode: write the given manifest bytes (or array) as manifest.json, unsigned and unchecked. */
    public function finalizeVerbatim(array|string $manifest): void
    {
        $this->assertVerbatim();
        $bytes = is_array($manifest) ? json_encode($manifest, self::ROW_FLAGS | JSON_PRETTY_PRINT) : $manifest;
        $this->zip->addFromString(Contract::MANIFEST_ENTRY, $bytes);
        $this->close();
    }

    /** @return array<string, mixed> */
    private function compose(array $extras): array
    {
        $manifest = $this->draft;
        $manifest['files'] = array_values(array_map(static fn (StreamFile $f) => $f->toArray(), $this->files));
        $manifest['blobs'] = array_values(array_map(static fn (Blob $b) => $b->toArray(), $this->blobs));
        $manifest['receipts'] = array_map(static fn (ReceiptRef $r) => $r->toArray(), $this->receipts);
        $manifest['extras'] = $extras === [] ? new \stdClass : $extras;
        if (($manifest['cursors'] ?? null) === []) {
            $manifest['cursors'] = new \stdClass;
        }
        unset($manifest['signature']);

        return $manifest;
    }

    private function write(array $manifest): Manifest
    {
        $this->zip->addFromString(Contract::MANIFEST_ENTRY, json_encode($manifest, self::ROW_FLAGS | JSON_PRETTY_PRINT));
        $this->close();

        return Manifest::fromArray(json_decode(json_encode($manifest, self::ROW_FLAGS), true));
    }

    private function close(): void
    {
        $this->closed = true;
        try {
            if ($this->zip->close() !== true) {
                throw new PackageException('Cannot write zip at '.$this->path, 'package_write');
            }
        } finally {
            foreach ($this->tempFiles as $temp) {
                @unlink($temp);
            }
            $this->tempFiles = [];
        }
    }

    private function tempFile(): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'txx');
        if ($temp === false) {
            throw new PackageException('Cannot create temp file', 'package_write');
        }

        return $this->tempFiles[] = $temp;
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new PackageException('Package already finalized', 'package_write');
        }
        if ($this->verbatim) {
            throw new PackageException('A verbatim builder only takes verbatim entries', 'package_write');
        }
    }

    private function assertVerbatim(): void
    {
        if ($this->closed) {
            throw new PackageException('Package already finalized', 'package_write');
        }
        if (! $this->verbatim) {
            throw new PackageException('Not a verbatim builder', 'package_write');
        }
    }

    public function __destruct()
    {
        if (! $this->closed) {
            @$this->zip->close();
            foreach ($this->tempFiles as $temp) {
                @unlink($temp);
            }
        }
    }
}
