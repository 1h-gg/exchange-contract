<?php

declare(strict_types=1);

namespace Texstar\Exchange\Cli;

use Texstar\Exchange\Client\RunnerClient;

/** `exchange push-updates <file.xlsx> --url=… [--token=…] [--dry-run] [--create]`: the bulk-updates workbook through POST /updates. */
final class PushUpdatesCommand
{
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

    /** @param array<string, string|true> $options @param list<string> $positional */
    public function run(array $options, array $positional): int
    {
        $file = $positional[0] ?? throw new \InvalidArgumentException('usage: exchange push-updates <file.xlsx> --url=URL [--token=T] [--dry-run] [--create]');
        if (! is_file($file)) {
            throw new \InvalidArgumentException('Workbook not found: '.$file);
        }
        $url = is_string($options['url'] ?? null) ? $options['url'] : throw new \InvalidArgumentException('--url is required');
        $token = Tokens::resolve($options, 'token', ['EXCHANGE_TOKEN'], $this->env, $this->err) ?? throw new \InvalidArgumentException('EXCHANGE_TOKEN, --token-file or --token is required');
        $dryRun = ($options['dry-run'] ?? false) === true;
        $create = ($options['create'] ?? false) === true;

        $client = ($this->clientFactory)($url, $token, ($options['insecure'] ?? false) === true);
        $result = $client->pushUpdatesWorkbook($file, $dryRun, $create);
        if (isset($result['status_url'])) {
            ($this->out)(sprintf('batch %s queued (%d ops): poll %s', (string) ($result['batch_id'] ?? '-'), (int) ($result['ops'] ?? 0), (string) $result['status_url']));

            return 0;
        }
        $summary = is_array($result['summary'] ?? null) ? $result['summary'] : [];
        ($this->out)(sprintf('batch %s%s: %s', (string) ($result['batch_id'] ?? '-'), $dryRun ? ' (dry run)' : '', $summary === [] ? 'no summary' : http_build_query($summary, '', ' ')));
        $failed = 0;
        foreach (is_array($result['verdicts'] ?? null) ? $result['verdicts'] : [] as $v) {
            $outcome = (string) ($v['outcome'] ?? '');
            if (! in_array($outcome, ['applied', 'unchanged'], true)) {
                $failed++;
                ($this->err)(sprintf('  %s %s: %s %s', (string) ($v['op_id'] ?? ''), (string) ($v['key'] ?? ''), $outcome, (string) ($v['message'] ?? '')));
            }
        }

        return $failed === 0 ? 0 : 1;
    }
}
