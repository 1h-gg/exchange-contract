<?php

declare(strict_types=1);

namespace Texstar\Exchange\Cli;

use Texstar\Exchange\Client\RunnerClient;

/**
 * `exchange sync --from=nesc --to=pmo …`: build "everything not yet receipted" on the FROM app, wait,
 * download the signed zip, upload it to the TO app with auto_apply, wait, print the receipt summary.
 * `--both` then runs the opposite direction, which carries the receipt back. The runner never needs
 * one app to reach the other: it talks to both /api/v1/exchange surfaces itself.
 */
final class SyncCommand
{
    private const APPS = ['nesc', 'pmo'];

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

        $source = ($this->clientFactory)($fromUrl, $fromToken, $insecure);
        $target = ($this->clientFactory)($toUrl, $toToken, $insecure);
        $code = $this->direction($from, $to, $source, $target, $includeBlobs, $program, $timeout, $interval, $keep);
        if (($options['both'] ?? false) === true) {
            $code = max($code, $this->direction($to, $from, $target, $source, $includeBlobs, $program, $timeout, $interval, $keep));
        }

        return $code;
    }

    private function direction(string $from, string $to, RunnerClient $source, RunnerClient $target, bool $includeBlobs, ?string $program, float $timeout, float $interval, bool $keep): int
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
