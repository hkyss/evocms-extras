<?php

namespace hkyss\Extras\Domain;

/**
 * Composer records the url it clones from and GitHub keeps a homepage as it was typed, and
 * neither is always one a browser can follow.
 */
final class WebUrl
{
    public static function from(mixed $url): string
    {
        if (!is_string($url)) {
            return '';
        }

        $url = trim($url);

        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
            return '';
        }

        return preg_replace('~\.git$~', '', $url) ?? '';
    }
}
