<?php

declare(strict_types=1);

namespace Texstar\Exchange\Dto;

/** Shared helpers for the readonly DTOs: unknown keys survive a fromArray()/toArray() round trip in `$extra`. */
trait Extras
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $known
     * @return array<string, mixed>
     */
    private static function unknown(array $data, array $known): array
    {
        return array_diff_key($data, array_flip($known));
    }

    /**
     * @param  array<string, mixed>  $known
     * @return array<string, mixed>
     */
    private function merged(array $known): array
    {
        return $known + $this->extra;
    }
}
