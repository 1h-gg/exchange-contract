<?php

declare(strict_types=1);

namespace Texstar\Exchange\Cli;

use Texstar\Exchange\Client\RunnerClient;
use Texstar\Exchange\Package\PackageReader;
use Texstar\Exchange\Schema\KeywordAudit;
use Texstar\Exchange\Schema\SchemaRegistry;
use Texstar\Exchange\Signing\KeyRing;
use Texstar\Exchange\Testing\ContractSuite;
use Texstar\Exchange\Testing\FixtureSigner;

/**
 * The reference CLI (plain argv, no framework). `bin/exchange` is a thin wrapper around run().
 *
 *   exchange sync --from=nesc --to=pmo --from-url=… --to-url=… [--from-token=… --to-token=…] [--both] [--include-blobs] [--no-files] [--program=…] [--timeout=600] [--keep]
 *   exchange push-updates <file.xlsx> --url=… [--token=…] [--dry-run] [--create]
 *   exchange fixtures:sign [--fixture=<name>]         rewrite fixture manifests (hashes + signatures) from expectations.build
 *   exchange fixtures:build <name> [--out=<zip>]      zip one fixture tree verbatim
 *   exchange suite:run                                run the ContractSuite against this package's reader
 *   exchange schemas:audit                            keyword audit of schemas/v1
 *   exchange package:inspect <zip> [--keys=id=secret,…] [--max-unpacked-mb=N]
 *
 * Tokens come, best first, from `--<flag>-file=PATH`, the environment (EXCHANGE_FROM_TOKEN,
 * EXCHANGE_TO_TOKEN, EXCHANGE_TOKEN) or the flag itself, which is warned about because it shows in `ps`
 * output and shell history. Base URLs must be https unless the host is localhost / 127.0.0.1 / ::1 or
 * `--insecure` is given.
 */
final class App
{
    /** @var callable(string): void */
    private $out;

    /** @var callable(string): void */
    private $err;

    /** @var callable(string, string): RunnerClient */
    private $clientFactory;

    /** @var array<string, string> */
    private array $env;

    /**
     * @param  null|callable(string): void  $out
     * @param  null|callable(string): void  $err
     * @param  null|callable(string, string): RunnerClient  $clientFactory  (baseUrl, token) => client; tests inject a fake transport here
     * @param  array<string, string>|null  $env
     */
    public function __construct(?callable $out = null, ?callable $err = null, ?callable $clientFactory = null, ?array $env = null)
    {
        $this->out = $out ?? static function (string $line): void {
            fwrite(STDOUT, $line."\n");
        };
        $this->err = $err ?? static function (string $line): void {
            fwrite(STDERR, $line."\n");
        };
        $this->clientFactory = $clientFactory ?? static fn (string $url, string $token, bool $insecure = false): RunnerClient => new RunnerClient($url, $token, allowInsecure: $insecure);
        $this->env = $env ?? array_filter(getenv(), 'is_string');
    }

    /** @param list<string> $argv without the script name */
    public function run(array $argv): int
    {
        $command = array_shift($argv) ?? 'help';
        [$options, $positional] = self::parse($argv);
        try {
            return match ($command) {
                'sync' => (new SyncCommand($this->out, $this->err, $this->clientFactory, $this->env))->run($options),
                'push-updates' => (new PushUpdatesCommand($this->out, $this->err, $this->clientFactory, $this->env))->run($options, $positional),
                'fixtures:sign' => $this->fixturesSign($options),
                'fixtures:build' => $this->fixturesBuild($options, $positional),
                'suite:run' => $this->suiteRun(),
                'schemas:audit' => $this->schemasAudit(),
                'package:inspect' => $this->packageInspect($options, $positional),
                'help', '--help', '-h' => $this->help(0),
                default => $this->help(2, 'unknown command '.$command),
            };
        } catch (\Throwable $e) {
            ($this->err)(get_debug_type($e).': '.$e->getMessage());

            return 1;
        }
    }

    /**
     * `--key=value` and `--flag` become options (a repeated key keeps the last value); the rest is positional.
     *
     * @param  list<string>  $argv
     * @return array{0: array<string, string|true>, 1: list<string>}
     */
    public static function parse(array $argv): array
    {
        $options = [];
        $positional = [];
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--')) {
                $pair = substr($arg, 2);
                if (str_contains($pair, '=')) {
                    [$k, $v] = explode('=', $pair, 2);
                    $options[$k] = $v;
                } else {
                    $options[$pair] = true;
                }
            } else {
                $positional[] = $arg;
            }
        }

        return [$options, $positional];
    }

    private function help(int $code, ?string $message = null): int
    {
        if ($message !== null) {
            ($this->err)($message);
        }
        ($this->out)(implode("\n", [
            'usage: exchange <command> [options]',
            '  sync --from=nesc --to=pmo --from-url=URL --to-url=URL [--from-token=T --to-token=T] [--both] [--include-blobs] [--no-files] [--program=P] [--timeout=600] [--keep]',
            '  push-updates <file.xlsx> --url=URL [--token=T] [--dry-run] [--create]',
            '  fixtures:sign [--fixture=NAME]     fixtures:build NAME [--out=ZIP]     suite:run',
            '  schemas:audit     package:inspect <zip> [--keys=id=secret,...] [--max-unpacked-mb=N]',
            'tokens: EXCHANGE_FROM_TOKEN / EXCHANGE_TO_TOKEN / EXCHANGE_TOKEN, or --<flag>-file=PATH; a --token flag works but is warned about (ps, history)',
            'urls: https only, except localhost / 127.0.0.1 / ::1 or with --insecure',
        ]));

        return $code;
    }

    /** @param array<string, string|true> $options */
    private function fixturesSign(array $options): int
    {
        $only = isset($options['fixture']) && is_string($options['fixture']) ? $options['fixture'] : null;
        foreach (FixtureSigner::signAll($only) as $name => $result) {
            ($this->out)(sprintf('%-20s %s', $name, $result));
        }

        return 0;
    }

    /** @param array<string, string|true> $options @param list<string> $positional */
    private function fixturesBuild(array $options, array $positional): int
    {
        $name = $positional[0] ?? throw new \InvalidArgumentException('usage: exchange fixtures:build <name> [--out=zip]');
        $out = isset($options['out']) && is_string($options['out']) ? $options['out'] : null;
        ($this->out)(ContractSuite::build($name, $out));

        return 0;
    }

    private function suiteRun(): int
    {
        $failures = 0;
        foreach (ContractSuite::cases() as $case) {
            $problems = ContractSuite::check(PackageReader::open($case['path'], 64 * 1024 * 1024), $case['expected'], $case['keyRing']);
            ($this->out)(sprintf('%-20s %s', $case['name'], $problems === [] ? 'ok' : 'FAIL'));
            foreach ($problems as $p) {
                ($this->err)('  '.$case['name'].': '.$p);
            }
            $failures += $problems === [] ? 0 : 1;
        }
        ContractSuite::cleanup();

        return $failures === 0 ? 0 : 1;
    }

    private function schemasAudit(): int
    {
        $problems = (new KeywordAudit(new SchemaRegistry))->run();
        foreach ($problems as $p) {
            ($this->err)($p);
        }
        ($this->out)($problems === [] ? 'schemas/v1: clean' : count($problems).' problem(s)');

        return $problems === [] ? 0 : 1;
    }

    /** @param array<string, string|true> $options @param list<string> $positional */
    private function packageInspect(array $options, array $positional): int
    {
        $path = $positional[0] ?? throw new \InvalidArgumentException('usage: exchange package:inspect <zip> [--keys=id=secret,...] [--max-unpacked-mb=N]');
        $cap = isset($options['max-unpacked-mb']) && is_string($options['max-unpacked-mb']) ? (int) $options['max-unpacked-mb'] * 1024 * 1024 : PHP_INT_MAX;
        $reader = PackageReader::open($path, $cap);
        $m = $reader->manifestDto();
        ($this->out)(sprintf('package %s  kind=%s  direction=%s  producer=%s  created=%s', $m->packageId, $m->kind, $m->direction, $m->producer->app, $m->createdAt));
        ($this->out)('scope     '.json_encode($m->resolvedScope()->toArray()));
        ($this->out)('declared  '.$reader->declaredSize().' bytes uncompressed');
        $violations = $reader->validateManifest();
        foreach ($violations as $v) {
            ($this->err)('manifest: '.$v);
        }
        foreach ($reader->negotiation()->warnings() as $w) {
            ($this->err)('warning: '.$w);
        }
        foreach ($m->files as $f) {
            ($this->out)(sprintf('  stream %-24s %-6s rows=%-7d sha256=%s', $f->stream, $f->mode, $f->rows, substr($f->sha256, 0, 12).'…'));
        }
        foreach ($m->blobs as $b) {
            ($this->out)(sprintf('  blob   %s %d bytes %s', substr($b->sha256, 0, 12).'…', $b->bytes, $b->mime));
        }
        if (isset($options['keys']) && is_string($options['keys'])) {
            $reader->verify(KeyRing::fromEnvString($options['keys']));
            ($this->out)('signature verified ('.$m->signature['key_id'].')');
        } else {
            ($this->out)($m->isSigned() ? 'signature present, not verified (pass --keys)' : 'signature: none (kind=rows only)');
        }
        $reader->checkHashes();
        ($this->out)('hashes ok ('.$reader->inflatedBytes().' bytes inflated)');

        return $violations === [] ? 0 : 1;
    }
}
