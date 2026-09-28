<?php

declare(strict_types=1);

namespace Texstar\Exchange\Tests;

use PHPUnit\Framework\TestCase;

/** The core is plain PHP: no Laravel, no framework, no runtime dependency beyond ext-json/zip/fileinfo. */
final class ArchitectureTest extends TestCase
{
    public function test_src_never_references_laravel(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            $code = (string) file_get_contents($file->getPathname());
            if (preg_match('~Illuminate\\\\|Laravel\\\\|\bapp\(|\bconfig\(|\bnow\(\)~', $code)) {
                $offenders[] = substr($file->getPathname(), strlen(__DIR__.'/../'));
            }
        }
        $this->assertSame([], $offenders);
    }

    public function test_composer_has_no_runtime_package_dependencies(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);
        $this->assertSame(['php', 'ext-json', 'ext-zip', 'ext-fileinfo'], array_keys($composer['require']));
        $this->assertSame('texstar/exchange-contract', $composer['name']);
    }
}
