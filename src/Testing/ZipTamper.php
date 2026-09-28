<?php

declare(strict_types=1);

namespace Texstar\Exchange\Testing;

/**
 * TEST-ONLY zip surgery. `declareSize()` patches the central-directory uncompressed-size field of one
 * entry so `PackageReader::declaredSize()` and `statIndex()` lie about it (the inflate-cap tests).
 * Never ships inside a fixture; never used at runtime.
 */
final class ZipTamper
{
    private const EOCD_SIG = "PK\x05\x06";

    private const CDIR_SIG = "PK\x01\x02";

    private function __construct() {}

    public static function declareSize(string $zipPath, string $entry, int $declared): void
    {
        $data = file_get_contents($zipPath);
        if ($data === false) {
            throw new \RuntimeException('Cannot read '.$zipPath);
        }
        $eocd = strrpos($data, self::EOCD_SIG);
        if ($eocd === false) {
            throw new \RuntimeException('No end-of-central-directory record');
        }
        $count = unpack('v', substr($data, $eocd + 10, 2))[1];
        $offset = unpack('V', substr($data, $eocd + 16, 4))[1];
        $patched = false;
        for ($i = 0; $i < $count; $i++) {
            if (substr($data, $offset, 4) !== self::CDIR_SIG) {
                throw new \RuntimeException('Central directory is corrupt at '.$offset);
            }
            $nameLen = unpack('v', substr($data, $offset + 28, 2))[1];
            $extraLen = unpack('v', substr($data, $offset + 30, 2))[1];
            $commentLen = unpack('v', substr($data, $offset + 32, 2))[1];
            $name = substr($data, $offset + 46, $nameLen);
            if ($name === $entry) {
                $data = substr_replace($data, pack('V', $declared), $offset + 24, 4);
                $patched = true;
            }
            $offset += 46 + $nameLen + $extraLen + $commentLen;
        }
        if (! $patched) {
            throw new \RuntimeException('Entry not found: '.$entry);
        }
        file_put_contents($zipPath, $data);
    }

    /** Flip one byte inside a STORED entry's data (streams are deflated, so use it on blobs or rewrite the zip instead). */
    public static function flipStoredByte(string $zipPath, string $entry, int $at = 0): void
    {
        $data = file_get_contents($zipPath);
        $eocd = strrpos($data, self::EOCD_SIG);
        $count = unpack('v', substr($data, $eocd + 10, 2))[1];
        $offset = unpack('V', substr($data, $eocd + 16, 4))[1];
        for ($i = 0; $i < $count; $i++) {
            $nameLen = unpack('v', substr($data, $offset + 28, 2))[1];
            $extraLen = unpack('v', substr($data, $offset + 30, 2))[1];
            $commentLen = unpack('v', substr($data, $offset + 32, 2))[1];
            $name = substr($data, $offset + 46, $nameLen);
            if ($name === $entry) {
                $local = unpack('V', substr($data, $offset + 42, 4))[1];
                $lNameLen = unpack('v', substr($data, $local + 26, 2))[1];
                $lExtraLen = unpack('v', substr($data, $local + 28, 2))[1];
                $pos = $local + 30 + $lNameLen + $lExtraLen + $at;
                $data[$pos] = chr(ord($data[$pos]) ^ 0x01);
                file_put_contents($zipPath, $data);

                return;
            }
            $offset += 46 + $nameLen + $extraLen + $commentLen;
        }
        throw new \RuntimeException('Entry not found: '.$entry);
    }
}
