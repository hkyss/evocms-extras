<?php

namespace hkyss\Extras\Installer;

final class ComposerDiagnosis
{
    private const PHP_VERSION = '~^\s*-\s*(\S+/\S+) (\S+) requires php (\S+) -> your php version \(([^)]+)\)~mi';

    private const MISSING_EXTENSION = '~^\s*-\s*(\S+/\S+) (\S+) requires (ext-\S+)[^>]*-> it is missing~mi';

    private const NOT_FOUND = '~requires (\S+/\S+),? it could not be found~i';

    /**
     * @param list<string> $output
     * @return list<string>
     */
    public static function explain(array $output): array
    {
        $text = implode("\n", $output);
        $notes = [];

        if (preg_match_all(self::PHP_VERSION, $text, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as [, $package, $version, $constraint, $running]) {
                $notes[] = sprintf(
                    '%s %s needs PHP %s; this installation runs %s. '
                    . 'Either an older release of it supports your PHP, or it cannot be installed here.',
                    $package,
                    $version,
                    $constraint,
                    $running
                );
            }
        }

        if (preg_match_all(self::MISSING_EXTENSION, $text, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as [, $package, $version, $extension]) {
                $notes[] = sprintf('%s %s needs the %s extension, which this PHP does not load.', $package, $version, $extension);
            }
        }

        if (preg_match(self::NOT_FOUND, $text, $match) === 1) {
            $notes[] = sprintf(
                '%s was not found by Composer. The catalog lists it, but Packagist does not serve it '
                . 'under that name.',
                $match[1]
            );
        }

        return array_values(array_unique($notes));
    }
}
