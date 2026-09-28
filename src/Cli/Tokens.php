<?php

declare(strict_types=1);

namespace Texstar\Exchange\Cli;

/**
 * Where the CLI takes a bearer token from, best first: `--<flag>-file=PATH` (the file's trimmed
 * contents), the environment, then `--<flag>=T` — accepted, but warned about, because a token on the
 * command line shows in `ps` output and shell history.
 */
final class Tokens
{
    private function __construct() {}

    /**
     * @param  array<string, string|true>  $options
     * @param  list<string>  $envKeys
     * @param  array<string, string>  $env
     * @param  callable(string): void  $err
     */
    public static function resolve(array $options, string $flag, array $envKeys, array $env, callable $err): ?string
    {
        $file = $options[$flag.'-file'] ?? null;
        if (is_string($file) && $file !== '') {
            if (! is_file($file) || ! is_readable($file)) {
                throw new \InvalidArgumentException('--'.$flag.'-file is not a readable file');
            }
            $token = trim((string) file_get_contents($file));
            if ($token === '') {
                throw new \InvalidArgumentException('--'.$flag.'-file is empty');
            }

            return $token;
        }
        foreach ($envKeys as $key) {
            if (isset($env[$key]) && $env[$key] !== '') {
                return $env[$key];
            }
        }
        $value = $options[$flag] ?? null;
        if (is_string($value) && $value !== '') {
            $err('warning: --'.$flag.' puts the token in ps output and shell history; prefer '.($envKeys[0] ?? 'the environment').' or --'.$flag.'-file=PATH');

            return $value;
        }

        return null;
    }
}
