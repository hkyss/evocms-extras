<?php

namespace hkyss\Extras\Manager;

use hkyss\Extras\Domain\Coordinate;

/** The page the manager runs, and the entry in the header that reaches it. */
final class ManagerModule
{
    public const NAME = 'Extras';

    public const ICON = 'fa fa-cubes';

    public const PACKAGE = 'hkyss/evocms-extras';

    /** Modules sit at 30 and Users at 40, so the entry lands beside the tab it left. */
    private const RANK = 31;

    private const KEY = 'extras';

    public static function id(): string
    {
        return md5(self::NAME);
    }

    /** The page does not install or remove the package it is part of. */
    public static function isSelf(Coordinate $coordinate): bool
    {
        return $coordinate->key() === self::PACKAGE;
    }

    public static function file(): string
    {
        return self::packagePath() . '/resources/module.php';
    }

    public static function assetsPath(): string
    {
        return self::packagePath() . '/assets';
    }

    public static function url(): string
    {
        return 'index.php?a=112&id=' . self::id();
    }

    /** Cache key for the published copies of the two files below; they are copied byte for byte. */
    public static function assetsVersion(): string
    {
        $newest = 0;

        foreach (['css/module.css', 'js/module.js'] as $asset) {
            $newest = max($newest, (int) @filemtime(self::assetsPath() . '/modules/Extras/' . $asset));
        }

        return $newest > 0 ? (string) $newest : '1';
    }

    /**
     * @param array<string,mixed> $menu
     * @return array<string,mixed>
     */
    public static function promote(array $menu): array
    {
        $menu[self::KEY] = [
            self::KEY,
            'main',
            '<i class="' . self::ICON . '"></i><span class="menu-item-text">' . self::NAME . '</span>',
            self::url(),
            self::NAME,
            '',
            '',
            'main',
            0,
            self::RANK,
            '',
        ];

        return $menu;
    }

    private static function packagePath(): string
    {
        return dirname(__DIR__, 2);
    }
}
