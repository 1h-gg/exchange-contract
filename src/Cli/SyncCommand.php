<?php

declare(strict_types=1);

namespace Texstar\Exchange\Cli;

use Texstar\Exchange\Client\RunnerClient;
use Texstar\Exchange\Client\RunnerClientException;
use Texstar\Exchange\Dto\StreamFile;
use Texstar\Exchange\Package\PackageReader;

/**
 * `exchange sync --from=nesc --to=pmo …`: build "everything not yet receipted" on the FROM app, wait,
 * download the signed zip, upload it to the TO app with auto_apply, wait, print the receipt summary.
 * `--both` then runs the opposite direction, which carries the receipt back. The runner never needs
 * one app to reach the other: it talks to both /api/v1/exchange surfaces itself.
 *
 * Between download and upload the runner shuttles the files the package references by sha256 but
 * does not carry (a build without include_blobs): HEAD on the TO app, and only when it lacks the file,
 * GET from the FROM app and PUT to the TO app. Without this step every report row is rejected
 * `blob_missing`. `--no-files` skips it; a failed copy is reported and the upload still goes ahead
 * (the TO app then rejects just those rows).
 */
final class SyncCommand
{
    private const APPS = ['nesc', 'pmo'];

    /** Streams whose rows reference a file by a top-level sha256 the consumer resolves (a missing one is the row reject blob_missing). */
    public const FILE_REF_STREAMS = ['inspection_reports' => 'sha256'];

    /** The scan of a downloaded package never inflates more than this (the contract's max_unpacked_mb default). */
    private const SCAN_MAX_INFLATED_BYTES = 2048 * 1024 * 1024;

    /** @var callable(string): void */
    private $out;

    /** @var callable(string): void */
    private $err;

    /** @var callable(string, string, bool): RunnerClient */
    private $clientFactory;

    /** @param callable(string): void $out @param callable(string): void $err @param callable(string, string): RunnerClient $clientFactory @param array<string, string> $env */
    public function __construct(callable $out, callable $err, callable $clientFactory, private readonly array $env = [])
    {
        $this->out = $out;
        $this->err = $err;
        $this->clientFactory = $clientFactory;
    }

    /** @param array<string, string|true> $options */
    public function run(array $options): int
    {
        $from = $this->appName($options, 'from');
        $to = $this->appName($options, 'to');
        if ($from === $to) {
            throw new \InvalidArgumentException('--from and --to must differ');
        }
        $fromUrl = $this->string($options, 'from-url') ?? throw new \InvalidArgumentException('--from-url is required');
        $toUrl = $this->string($options, 'to-url') ?? throw new \InvalidArgumentException('--to-url is required');
        $fromToken = Tokens::resolve($options, 'from-token', ['EXCHANGE_FROM_TOKEN', 'EXCHANGE_TOKEN'], $this->env, $this->err) ?? throw new \InvalidArgumentException('EXCHANGE_FROM_TOKEN, --from-token-file or --from-token is required');
        $toToken = Tokens::resolve($options, 'to-token', ['EXCHANGE_TO_TOKEN', 'EXCHANGE_TOKEN'], $this->env, $this->err) ?? throw new \InvalidArgumentException('EXCHANGE_TO_TOKEN, --to-token-file or --to-token is required');
        $insecure = ($options['insecure'] ?? false) === true;
        $timeout = (float) ($this->string($options, 'timeout') ?? 600);
        $interval = (float) ($this->string($options, 'interval') ?? 5);
        $includeBlobs = ($options['include-blobs'] ?? false) === true;
        $program = $this->string($options, 'program');
        $keep = ($options['keep'] ?? false) === true;
        $shuttle = ($options['no-files'] ?? false) !== true;

        $source = ($this->clientFactory)($fromUrl, $fromToken, $insecure);
        $target = ($this->clientFactory)($toUrl, $toToken, $insecure);
        $code = $this->direction($from, $to, $source, $target, $includeBlobs, $program, $timeout, $interval, $keep, $shuttle);
        if (($options['both'] ?? false) === true) {
            $code = max($code, $this->direction($to, $from, $target, $source, $includeBlobs, $program, $timeout, $interval, $keep, $shuttle));
        }

        return $code;
    }

    private function direction(string $from, string $to, RunnerClient $source, RunnerClient $target, bool $includeBlobs, ?string $program, float $timeout, float $interval, bool $keep, bool $shuttle = true): int
    {
        ($this->out)(sprintf('[%s -> %s] building outbound package on %s', $from, $to, $source->baseUrl()));
        $build = $source->buildOutbound(array_filter(['include_blobs' => $includeBlobs, 'program' => $program], static fn ($v) => $v !== null && $v !== false));
        $packageId = (string) ($build['package_id'] ?? '');
        if ($packageId === '') {
            ($this->err)('build returned no package_id: '.json_encode($build));

            return 1;
        }
        $state = $source->waitOutbound($packageId, $timeout, $interval);
        if (($state['status'] ?? null) === 'failed') {
            ($this->err)(sprintf('[%s -> %s] build failed: %s', $from, $to, (string) ($state['error'] ?? 'unknown')));

            return 1;
        }
        $counts = is_array($state['counts'] ?? null) ? $state['counts'] : [];
        ($this->out)(sprintf('[%s -> %s] package %s ready: %s', $from, $to, $packageId, $this->summarizeCounts($counts)));

        $path = rtrim(sys_get_temp_dir(), '/').'/'.$from.'-'.$to.'-'.substr($packageId, 0, 8).'.txx.zip';
        $sha = $source->download($packageId, $path);
        ($this->out)(sprintf('[%s -> %s] downloaded %s (%d bytes, sha256 %s)', $from, $to, $path, (int) filesize($path), substr($sha, 0, 12).'…'));

        try {
            if ($shuttle) {
                $this->shuttleFiles($from, $to, $path, $source, $target);
            }
            $upload = $target->uploadInbound($path, autoApply: true);
            $importId = (string) ($upload['import_id'] ?? '');
            if ($importId === '') {
                ($this->err)('upload returned no import_id: '.json_encode($upload));

                return 1;
            }
            $result = $target->waitInbound($importId, $timeout, $interval);
            $status = (string) ($result['status'] ?? '');
            ($this->out)(sprintf('[%s -> %s] import %s %s', $from, $to, $importId, $status));
            if ($status === 'previewed') {
                $target->apply($importId);
                $result = $target->waitInbound($importId, $timeout, $interval);
                $status = (string) ($result['status'] ?? '');
                ($this->out)(sprintf('[%s -> %s] import %s %s', $from, $to, $importId, $status));
            }
            if ($status !== 'applied') {
                ($this->err)(sprintf('[%s -> %s] import %s: %s', $from, $to, $status, (string) ($result['rejected_code'] ?? $result['error'] ?? 'see GET /inbound/'.$importId)));
                foreach (array_slice(is_array($result['rejects'] ?? null) ? $result['rejects'] : [], 0, 20) as $reject) {
                    ($this->err)('  '.json_encode($reject, JSON_UNESCAPED_SLASHES));
                }

                return 1;
            }
            $receipt = $target->receipt($importId);
            foreach (is_array($receipt['streams'] ?? null) ? $receipt['streams'] : [] as $stream => $s) {
                ($this->out)(sprintf('  %-24s received=%d accepted=%d rejected=%d', $stream, (int) ($s['received'] ?? 0), (int) ($s['accepted'] ?? 0), (int) ($s['rejected'] ?? 0)));
                foreach (array_slice(is_array($s['rejected_rows'] ?? null) ? $s['rejected_rows'] : [], 0, 20) as $r) {
                    ($this->err)(sprintf('    seq %d %s: %s %s', (int) ($r['seq'] ?? 0), (string) ($r['key'] ?? ''), (string) ($r['code'] ?? ''), (string) ($r['message'] ?? '')));
                }
            }
            ($this->out)(sprintf('[%s -> %s] receipt %s (%s)', $from, $to, (string) ($receipt['receipt_id'] ?? '-'), (string) ($receipt['status'] ?? '-')));

            return 0;
        } finally {
            if (! $keep) {
                @unlink($path);
            }
        }
    }

    /**
     * Copy every file the package references (FILE_REF_STREAMS) but does not carry in files/<sha256>
     * from the FROM app to the TO app, skipping the ones the TO app already holds (HEAD).
     *
     * @return array{referenced: int, in_package: int, present: int, copied: int, failed: int}
     */
    public function shuttleFiles(string $from, string $to, string $path, RunnerClient $source, RunnerClient $target): array
    {
        $tally = ['referenced' => 0, 'in_package' => 0, 'present' => 0, 'copied' => 0, 'failed' => 0];
        try {
            $reader = PackageReader::open($path, self::SCAN_MAX_INFLATED_BYTES);
        } catch (\Throwable $e) {
            ($this->err)(sprintf('[%s -> %s] files: package not scanned (%s); uploading without copying files', $from, $to, $e->getMessage()));

            return $tally;
        }
        try {
            $carried = [];
            foreach ($reader->manifestDto()->blobs as $blob) {
                $carried[strtolower($blob->sha256)] = true;
            }
            $wanted = [];
            $streams = $reader->manifestDto()->files;
            foreach (self::FILE_REF_STREAMS as $stream => $field) {
                if (! $this->hasStream($streams, $stream)) {
                    continue;
                }
                foreach ($reader->rows($stream) as $row) {
                    $sha = is_array($row) && is_string($row[$field] ?? null) ? strtolower($row[$field]) : '';
                    if (preg_match('~^[a-f0-9]{64}$~', $sha) === 1) {
                        $wanted[$sha] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            ($this->err)(sprintf('[%s -> %s] files: package not scanned (%s); uploading without copying files', $from, $to, $e->getMessage()));

            return $tally;
        } finally {
            $reader->close();
        }
        $tally['referenced'] = count($wanted);
        foreach (array_keys($wanted) as $sha) {
            if (isset($carried[$sha])) {
                $tally['in_package']++;

                continue;
            }
            $tmp = rtrim(sys_get_temp_dir(), '/').'/txx-file-'.bin2hex(random_bytes(6));
            try {
                if ($target->headFile($sha) !== null) {
                    $tally['present']++;

                    continue;
                }
                $source->getFile($sha, $tmp);
                $target->putFile($sha, $tmp);
                $tally['copied']++;
            } catch (RunnerClientException $e) {
                $tally['failed']++;
                ($this->err)(sprintf('[%s -> %s] file %s not copied: %s %s', $from, $to, $sha, $e->wireCode(), $e->getMessage()));
            } finally {
                @unlink($tmp);
            }
        }
        ($this->out)(sprintf('[%s -> %s] files: %d referenced, %d in package, %d already on %s, %d copied, %d failed', $from, $to, $tally['referenced'], $tally['in_package'], $tally['present'], $to, $tally['copied'], $tally['failed']));

        return $tally;
    }

    /** @param list<StreamFile> $files manifest files[] */
    private function hasStream(array $files, string $stream): bool
    {
        foreach ($files as $file) {
            if ($file->stream === $stream) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $counts */
    private function summarizeCounts(array $counts): string
    {
        $parts = [];
        foreach ($counts as $stream => $c) {
            $parts[] = $stream.'='.(is_array($c) ? (int) ($c['rows'] ?? 0) : (int) $c);
        }

        return $parts === [] ? 'no rows' : implode(' ', $parts);
    }

    /** @param array<string, string|true> $options */
    private function appName(array $options, string $key): string
    {
        $app = $this->string($options, $key) ?? throw new \InvalidArgumentException('--'.$key.' is required (nesc|pmo)');
        if (! in_array($app, self::APPS, true)) {
            throw new \InvalidArgumentException('--'.$key.' must be nesc or pmo');
        }

        return $app;
    }

    /** @param array<string, string|true> $options */
    private function string(array $options, string $key): ?string
    {
        $v = $options[$key] ?? null;

        return is_string($v) && $v !== '' ? $v : null;
    }
}
