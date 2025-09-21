<?php
declare(strict_types=1);

namespace MagicSunday\Memories\Service\Metadata\Support;

use MagicSunday\Memories\Entity\Media;

trait PathTokensTrait
{
    /** @return list<string> */
    private function tokenizePath(string $path): array
    {
        $lower = \strtolower($path);
        $delims = ['/', '\\', '-', '_', '.', ' '];
        $tmp = \str_replace($delims, ' ', $lower);
        $parts = \preg_split('~\s+~', $tmp) ?: [];
        /** @var list<string> $out */
        $out = [];
        foreach ($parts as $p) {
            if ($p !== '' && \strlen($p) <= 64) {
                $out[] = $p;
            }
        }
        return $out;
    }
}
