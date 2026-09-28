<?php

declare(strict_types=1);

namespace Texstar\Exchange;

/**
 * Wire constants of Texstar Exchange v1.
 *
 * A reader accepts a manifest whose `version` equals its own MAJOR. `VERSION` is the
 * informational semver of the writing package (additive changes bump minor).
 */
final class Contract
{
    public const FORMAT = 'texstar-exchange';

    public const RECEIPT_FORMAT = 'texstar-exchange-receipt';

    public const VERSION = '1.1.0';

    public const MAJOR = 1;

    /** Longest NDJSON line (bytes, without the newline) a builder writes or a reader parses. */
    public const MAX_ROW_BYTES = 1048576;

    public const DIRECTION_NESC_TO_PMO = 'nesc_to_pmo';

    public const DIRECTION_PMO_TO_NESC = 'pmo_to_nesc';

    /** @var list<string> */
    public const DIRECTIONS = [self::DIRECTION_NESC_TO_PMO, self::DIRECTION_PMO_TO_NESC];

    /** A signed zip exchanged between the apps. */
    public const KIND_PACKAGE = 'package';

    /** An unsigned mini-package built server-side from API rows; never accepted through the inbound upload. */
    public const KIND_ROWS = 'rows';

    /** @var list<string> */
    public const KINDS = [self::KIND_PACKAGE, self::KIND_ROWS];

    public const SIGNATURE_ALG = 'HMAC-SHA256';

    public const MANIFEST_ENTRY = 'manifest.json';

    public const RECEIPT_ENTRY = 'receipt.json';

    public const STREAM_ENTRY_PATTERN = '~^streams/([a-z0-9_]{1,40})\.ndjson$~';

    public const BLOB_ENTRY_PATTERN = '~^files/([a-f0-9]{64})$~';

    public const STREAM_NAME_PATTERN = '~^[a-z0-9_]{1,40}$~';

    public const SHA256_PATTERN = '~^[a-f0-9]{64}$~';

    /** Largest manifest.json / receipt.json a reader will parse. */
    public const MAX_MANIFEST_BYTES = 4194304;

    private function __construct() {}

    public static function streamEntry(string $stream): string
    {
        return 'streams/'.$stream.'.ndjson';
    }

    public static function blobEntry(string $sha256): string
    {
        return 'files/'.$sha256;
    }
}
