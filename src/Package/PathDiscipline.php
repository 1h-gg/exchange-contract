<?php

declare(strict_types=1);

namespace Texstar\Exchange\Package;

use Texstar\Exchange\Contract;
use Texstar\Exchange\Schema\Violation;

/**
 * The closed zip layout: manifest.json, streams/<stream>.ndjson, files/<sha256>, receipt.json.
 * Manifest paths are checked against that layout BEFORE any entry is read, and entry names are never
 * used as filesystem path components (the reader only opens entries by their validated names).
 */
final class PathDiscipline
{
    /** Directory placeholders some zip writers add; tolerated, never read. */
    private const TOLERATED_DIRS = ['streams/', 'files/'];

    private function __construct() {}

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $entries  every entry name in the zip
     * @return list<Violation>
     */
    public static function check(array $manifest, array $entries): array
    {
        $out = [];
        $expected = [Contract::MANIFEST_ENTRY => true];
        foreach (is_array($manifest['files'] ?? null) ? $manifest['files'] : [] as $i => $file) {
            $path = is_array($file) ? (string) ($file['path'] ?? '') : '';
            $stream = is_array($file) ? (string) ($file['stream'] ?? '') : '';
            if (! preg_match(Contract::STREAM_NAME_PATTERN, $stream) || $path !== Contract::streamEntry($stream)) {
                $out[] = new Violation('$.files['.$i.'].path', 'bad_path', 'must be streams/<stream>.ndjson for stream '.json_encode($stream, JSON_UNESCAPED_SLASHES));

                continue;
            }
            $expected[$path] = true;
        }
        foreach (is_array($manifest['blobs'] ?? null) ? $manifest['blobs'] : [] as $i => $blob) {
            $path = is_array($blob) ? (string) ($blob['path'] ?? '') : '';
            $sha = is_array($blob) ? (string) ($blob['sha256'] ?? '') : '';
            if (! preg_match(Contract::SHA256_PATTERN, $sha) || $path !== Contract::blobEntry($sha)) {
                $out[] = new Violation('$.blobs['.$i.'].path', 'bad_path', 'must be files/<sha256> equal to blobs[].sha256');

                continue;
            }
            $expected[$path] = true;
        }
        foreach (is_array($manifest['receipts'] ?? null) ? $manifest['receipts'] : [] as $i => $receipt) {
            $path = is_array($receipt) ? (string) ($receipt['path'] ?? '') : '';
            if ($path !== Contract::RECEIPT_ENTRY) {
                $out[] = new Violation('$.receipts['.$i.'].path', 'bad_path', 'must be receipt.json');

                continue;
            }
            $expected[$path] = true;
        }
        foreach ($entries as $entry) {
            if (isset($expected[$entry]) || in_array($entry, self::TOLERATED_DIRS, true)) {
                continue;
            }
            $out[] = new Violation('$', 'unexpected_entry', 'zip entry '.json_encode($entry, JSON_UNESCAPED_SLASHES).' is not declared by the manifest');
        }
        foreach (array_keys($expected) as $entry) {
            if (! in_array($entry, $entries, true)) {
                $out[] = new Violation('$', 'missing_entry', 'manifest declares '.json_encode($entry, JSON_UNESCAPED_SLASHES).' but the zip has no such entry');
            }
        }

        return $out;
    }
}
