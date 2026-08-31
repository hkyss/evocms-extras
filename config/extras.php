<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Catalog sources
    |--------------------------------------------------------------------------
    |
    | Order matters: the first source holding a coordinate wins. The snapshot comes
    | first because compatibility statuses live nowhere else.
    |
    | Drivers: snapshot | packagist | github-org
    |
    */
    'sources' => [

        [
            'driver' => 'snapshot',
            'name' => 'Bundled snapshot',
            'path' => null,
        ],

        [
            'driver' => 'packagist',
            'name' => 'Packagist',
            'types' => [
                'evolutioncms-plugin',
                'evolutioncms-snippet',
                'evolutioncms-module',
                'evolutioncms-package',
            ],
        ],

        [
            'driver' => 'github-org',
            'name' => 'Extras-Evolution',
            'organization' => 'extras-evolution',
        ],

        [
            'driver' => 'github-org',
            'name' => 'EvoCMS Community',
            'organization' => 'evocms-community',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | GitHub
    |--------------------------------------------------------------------------
    |
    | Anonymous requests are capped at 60 per hour, 5000 with a token. The core reads
    | the same variable, so an existing GITHUB_PAT works as is.
    |
    */
    'github' => [
        'token' => env('GITHUB_PAT'),
        'timeout' => env('EXTRAS_HTTP_TIMEOUT', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Catalog cache
    |--------------------------------------------------------------------------
    |
    | A null path resolves to core/custom/cache/extras.
    |
    */
    'cache' => [
        'enabled' => env('EXTRAS_CACHE_ENABLED', true),
        'ttl' => env('EXTRAS_CACHE_TTL', 3600),
        'path' => env('EXTRAS_CACHE_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Composer
    |--------------------------------------------------------------------------
    |
    | The manifest is core/custom/composer.json, which composer-merge-plugin merges
    | into the core one. Null values resolve from EVO_CORE_PATH.
    |
    */
    'composer' => [
        'manifest' => null,
        'home' => null,
        'memory_limit' => env('EXTRAS_COMPOSER_MEMORY_LIMIT', '-1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Legacy extras
    |--------------------------------------------------------------------------
    |
    | The .old suffix comes from the format specification. Statuses listed under
    | require_confirmation need an explicit --force to install.
    |
    */
    'legacy' => [
        'backup_suffix' => '.old',
        'require_confirmation' => ['unknown', 'incompatible'],
        'install_set' => 'base',
    ],

    /*
    |--------------------------------------------------------------------------
    | Takeover
    |--------------------------------------------------------------------------
    |
    | What `extra:takeover` does with the elements the legacy manager left behind.
    | An element is a candidate only where the catalog can name it and its
    | description carries a version, which is what an installer of the legacy
    | format writes and a hand-written element does not.
    |
    | Replacements name the ones the site does not call by the catalog's name;
    | ignored ones are left exactly as they are, whatever the catalog says.
    |
    */
    'takeover' => [
        'replacements' => [
            'CodeMirror' => 'evolution-cms/ecodemirror',
        ],

        /*
         * Updater: extras-evolution/Updater publishes no tag at all, so a takeover would
         * install its default branch over the release Evolution ships — and the element that
         * arrives carries no version in its description, which is where the core's own
         * OutdatedExtrasCheck reads one. The manager then warns, in every page of it, about a
         * plugin this tool had just installed.
         */
        'ignore' => ['Updater'],
    ],

];
