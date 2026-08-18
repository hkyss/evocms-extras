<?php

namespace hkyss\Extras\Installer;

use Composer\Semver\Semver;
use Throwable;

final class PlatformCheck
{
    /**
     * @param array<string, string> $requires
     */
    public static function unmetPhpRequirement(array $requires, string $running = PHP_VERSION): ?string
    {
        $constraint = $requires['php'] ?? '';

        if ($constraint === '' || !class_exists(Semver::class)) {
            return null;
        }

        try {
            if (Semver::satisfies(self::normalise($running), $constraint)) {
                return null;
            }
        } catch (Throwable) {
            return null;
        }

        return sprintf('needs PHP %s; this installation runs %s', $constraint, $running);
    }

    /**
     * @param array<string, string> $requires
     * @return list<string>
     */
    public static function missingExtensions(array $requires): array
    {
        $missing = [];

        foreach (array_keys($requires) as $name) {
            if (!str_starts_with($name, 'ext-')) {
                continue;
            }

            if (!extension_loaded(substr($name, 4))) {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    private static function normalise(string $version): string
    {
        return (string) preg_replace('~[-+].*$~', '', $version);
    }
}
