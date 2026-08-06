<?php

namespace hkyss\Extras;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Catalog\CatalogCache;
use hkyss\Extras\Catalog\CatalogSource;
use hkyss\Extras\Catalog\GitHubOrgSource;
use hkyss\Extras\Catalog\PackagistSource;
use hkyss\Extras\Catalog\SnapshotSource;
use hkyss\Extras\Console\Commands\CacheCommand;
use hkyss\Extras\Console\Commands\DoctorCommand;
use hkyss\Extras\Console\Commands\InfoCommand;
use hkyss\Extras\Console\Commands\InstallCommand;
use hkyss\Extras\Console\Commands\ListCommand;
use hkyss\Extras\Console\Commands\RemoveCommand;
use hkyss\Extras\Console\Commands\UpdateCommand;
use hkyss\Extras\Installer\ComposerInstaller;
use hkyss\Extras\Installer\ComposerManifest;
use hkyss\Extras\Installer\ComposerRunner;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Installer\LegacyInstaller;
use hkyss\Extras\Legacy\ElementWriter;
use hkyss\Extras\Record\InstallRecordStore;
use hkyss\Extras\Support\Http;
use hkyss\Extras\Support\Paths;
use Illuminate\Support\ServiceProvider;

class ExtrasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/extras.php', 'extras');

        $this->registerFoundation();
        $this->registerCatalog();
        $this->registerInstallers();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([
            __DIR__ . '/../config/extras.php' => $this->configTarget(),
        ], 'extras-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                ListCommand::class,
                InstallCommand::class,
                UpdateCommand::class,
                RemoveCommand::class,
                InfoCommand::class,
                CacheCommand::class,
                DoctorCommand::class,
            ]);
        }
    }

    private function registerFoundation(): void
    {
        $this->app->singleton(Paths::class, static fn () => new Paths());

        $this->app->singleton(Http::class, fn () => new Http(
            null,
            $this->config('extras.github.token'),
            (int) $this->config('extras.github.timeout', 30)
        ));

        $this->app->singleton(CatalogCache::class, fn () => new CatalogCache(
            $this->app->make(Paths::class),
            (int) $this->config('extras.cache.ttl', 3600),
            (bool) $this->config('extras.cache.enabled', true),
            $this->config('extras.cache.path')
        ));

        $this->app->singleton(InstallRecordStore::class, static fn () => new InstallRecordStore());
    }

    private function registerCatalog(): void
    {
        $this->app->singleton(Catalog::class, function (): Catalog {
            $http = $this->app->make(Http::class);
            $cache = $this->app->make(CatalogCache::class);
            $catalog = new Catalog();

            foreach ((array) $this->config('extras.sources', []) as $definition) {
                if (!is_array($definition)) {
                    continue;
                }

                $source = $this->makeSource($definition, $http, $cache);

                if ($source !== null) {
                    $catalog->addSource($source);
                }
            }

            return $catalog;
        });
    }

    /** @param array<string,mixed> $definition */
    private function makeSource(array $definition, Http $http, CatalogCache $cache): ?CatalogSource
    {
        $name = (string) ($definition['name'] ?? '');

        switch ((string) ($definition['driver'] ?? '')) {
            case 'snapshot':
                $path = $definition['path'] ?? null;

                return new SnapshotSource(
                    $name !== '' ? $name : 'Bundled snapshot',
                    is_string($path) && $path !== '' ? $path : null
                );

            case 'packagist':
                return new PackagistSource(
                    $http,
                    $cache,
                    array_values(array_filter((array) ($definition['types'] ?? []), 'is_string')),
                    $name !== '' ? $name : 'Packagist'
                );

            case 'github-org':
                $organization = (string) ($definition['organization'] ?? '');

                return $organization !== '' ? new GitHubOrgSource($http, $cache, $organization, $name) : null;
        }

        return null;
    }

    private function registerInstallers(): void
    {
        $this->app->singleton(ComposerManifest::class, function (): ComposerManifest {
            $configured = $this->config('extras.composer.manifest');

            return new ComposerManifest(
                is_string($configured) && $configured !== ''
                    ? $configured
                    : $this->app->make(Paths::class)->customManifest()
            );
        });

        $this->app->singleton(ComposerRunner::class, fn () => new ComposerRunner(
            $this->app->make(Paths::class),
            (string) $this->config('extras.composer.memory_limit', '-1')
        ));

        $this->app->singleton(ElementWriter::class, static fn () => new ElementWriter());

        $this->app->singleton(InstallerRegistry::class, function (): InstallerRegistry {
            $paths = $this->app->make(Paths::class);

            return new InstallerRegistry([
                new ComposerInstaller(
                    $paths,
                    $this->app->make(ComposerManifest::class),
                    $this->app->make(ComposerRunner::class)
                ),
                new LegacyInstaller(
                    $paths,
                    $this->app->make(Http::class),
                    $this->app->make(InstallRecordStore::class),
                    $this->app->make(ElementWriter::class),
                    $this->tablePrefix(),
                    (string) $this->config('extras.legacy.backup_suffix', '.old'),
                    (string) $this->config('extras.legacy.install_set', 'base'),
                    array_values(array_filter(
                        (array) $this->config('extras.legacy.require_confirmation', ['unknown', 'incompatible']),
                        'is_string'
                    ))
                ),
            ]);
        });

        $this->app->singleton(DoctorCommand::class, fn () => new DoctorCommand(
            $this->app->make(Catalog::class),
            $this->app->make(InstallerRegistry::class),
            Paths::isAvailable() ? $this->app->make(Paths::class) : null,
            $this->app->make(ComposerManifest::class),
            $this->app->make(ComposerRunner::class),
            $this->app->make(InstallRecordStore::class),
            $this->app->make(Http::class)
        ));
    }

    private function tablePrefix(): string
    {
        try {
            return (string) $this->app->make('db')->connection()->getTablePrefix();
        } catch (\Throwable) {
            return (string) $this->config('database.connections.default.prefix', '');
        }
    }

    /** @return mixed */
    private function config(string $key, $default = null)
    {
        try {
            return $this->app->make('config')->get($key, $default);
        } catch (\Throwable) {
            return $default;
        }
    }

    /** Evolution's config_path() takes a second argument that targets core/custom/config. */
    private function configTarget(): string
    {
        if (!function_exists('config_path')) {
            return 'extras.php';
        }

        try {
            if ((new \ReflectionFunction('config_path'))->getNumberOfParameters() >= 2) {
                return config_path('extras.php', true);
            }
        } catch (\ReflectionException) {
            return config_path('extras.php');
        }

        return config_path('extras.php');
    }
}
