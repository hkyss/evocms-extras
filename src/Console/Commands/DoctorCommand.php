<?php

namespace hkyss\Extras\Console\Commands;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Installer\ComposerManifest;
use hkyss\Extras\Installer\ComposerRunner;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Record\InstallRecordStore;
use hkyss\Extras\Support\Http;
use hkyss\Extras\Support\Paths;

/**
 * Checks what Composer cannot: the CMS itself is not expressible as a dependency, so the
 * platform has to be verified at runtime.
 */
class DoctorCommand extends AbstractExtraCommand
{
    protected $signature = 'extra:doctor';

    protected $description = 'Check that this installation can install extras';

    private ?Paths $paths;
    private ComposerManifest $manifest;
    private ComposerRunner $runner;
    private InstallRecordStore $records;
    private Http $http;

    public function __construct(
        Catalog $catalog,
        InstallerRegistry $installers,
        ?Paths $paths,
        ComposerManifest $manifest,
        ComposerRunner $runner,
        InstallRecordStore $records,
        Http $http
    ) {
        parent::__construct($catalog, $installers);
        $this->paths = $paths;
        $this->manifest = $manifest;
        $this->runner = $runner;
        $this->records = $records;
        $this->http = $http;
    }

    public function handle(): int
    {
        $checks = array_merge(
            $this->platformChecks(),
            $this->composerChecks(),
            $this->recordChecks(),
            $this->catalogChecks()
        );

        $this->newLine();
        $this->table(
            ['', 'Check', 'Detail'],
            array_map(static fn (array $c) => [
                $c['ok'] ? '<fg=green>ok</>' : ($c['fatal'] ? '<fg=red>fail</>' : '<fg=yellow>warn</>'),
                $c['name'],
                $c['detail'],
            ], $checks)
        );

        $failures = array_filter($checks, static fn (array $c) => !$c['ok'] && $c['fatal']);
        $warnings = array_filter($checks, static fn (array $c) => !$c['ok'] && !$c['fatal']);

        $this->newLine();

        if ($failures !== []) {
            $this->error(sprintf('%d blocking problem(s).', count($failures)));

            return self::FAILURE;
        }

        if ($warnings !== []) {
            $this->warn(sprintf('%d thing(s) worth fixing, nothing blocking.', count($warnings)));

            return self::SUCCESS;
        }

        $this->info('Everything checks out.');

        return self::SUCCESS;
    }

    /** @return list<array{name:string,ok:bool,fatal:bool,detail:string}> */
    private function platformChecks(): array
    {
        $checks = [[
            'name' => 'PHP version',
            'ok' => PHP_VERSION_ID >= 80200,
            'fatal' => true,
            'detail' => PHP_VERSION . ' (needs 8.2+)',
        ]];

        foreach (['zip', 'json'] as $extension) {
            $checks[] = [
                'name' => "ext-{$extension}",
                'ok' => extension_loaded($extension),
                'fatal' => true,
                'detail' => extension_loaded($extension) ? 'loaded' : 'missing; legacy archives cannot be opened',
            ];
        }

        $checks[] = [
            'name' => 'EVO_CORE_PATH',
            'ok' => $this->paths !== null,
            'fatal' => true,
            'detail' => $this->paths !== null
                ? $this->paths->core()
                : 'not defined; this package must run inside an Evolution CMS installation',
        ];

        return $checks;
    }

    /** @return list<array{name:string,ok:bool,fatal:bool,detail:string}> */
    private function composerChecks(): array
    {
        $checks = [[
            'name' => 'composer/composer',
            'ok' => $this->runner->isAvailable(),
            'fatal' => true,
            'detail' => $this->runner->isAvailable()
                ? 'available in-process'
                : 'not installed; composer extras cannot be installed',
        ]];

        $manifestPath = $this->manifest->path();
        $directory = dirname($manifestPath);
        $writable = $this->manifest->exists() ? is_writable($manifestPath) : is_writable($directory);

        $checks[] = [
            'name' => 'custom/composer.json',
            'ok' => $writable,
            'fatal' => true,
            'detail' => $this->manifest->exists()
                ? ($writable ? $manifestPath : "not writable: {$manifestPath}")
                : ($writable ? "will be created at {$manifestPath}" : "directory not writable: {$directory}"),
        ];

        $checks[] = $this->mergePluginCheck();

        if ($this->paths !== null) {
            $providers = $this->paths->providersDir();
            $ok = is_dir($providers) ? is_writable($providers) : is_writable(dirname(rtrim($providers, '/\\')));

            $checks[] = [
                'name' => 'custom/config/app/providers',
                'ok' => $ok,
                'fatal' => false,
                'detail' => $ok ? $providers : "not writable: {$providers}",
            ];
        }

        return $checks;
    }

    /**
     * Without the merge plugin our requirements never reach the core manifest.
     *
     * @return array{name:string,ok:bool,fatal:bool,detail:string}
     */
    private function mergePluginCheck(): array
    {
        if ($this->paths === null) {
            return ['name' => 'composer-merge-plugin', 'ok' => false, 'fatal' => true, 'detail' => 'core path unknown'];
        }

        $coreManifest = $this->paths->core() . 'composer.json';

        if (!is_file($coreManifest)) {
            return [
                'name' => 'composer-merge-plugin',
                'ok' => false,
                'fatal' => true,
                'detail' => "core composer.json not found at {$coreManifest}",
            ];
        }

        $data = json_decode((string) @file_get_contents($coreManifest), true);
        $includes = $data['extra']['merge-plugin']['include'] ?? [];
        $allowed = $data['config']['allow-plugins']['wikimedia/composer-merge-plugin'] ?? null;

        if (!is_array($includes) || !in_array('custom/composer.json', $includes, true)) {
            return [
                'name' => 'composer-merge-plugin',
                'ok' => false,
                'fatal' => true,
                'detail' => 'core composer.json does not merge custom/composer.json',
            ];
        }

        if ($allowed !== true) {
            return [
                'name' => 'composer-merge-plugin',
                'ok' => false,
                'fatal' => true,
                'detail' => 'not listed under config.allow-plugins; Composer will silently ignore it',
            ];
        }

        return [
            'name' => 'composer-merge-plugin',
            'ok' => true,
            'fatal' => false,
            'detail' => 'merges custom/composer.json and is allowed',
        ];
    }

    /** @return list<array{name:string,ok:bool,fatal:bool,detail:string}> */
    private function recordChecks(): array
    {
        $available = $this->records->isAvailable();

        return [[
            'name' => 'install record table',
            'ok' => $available,
            'fatal' => false,
            'detail' => $available
                ? sprintf('%d record(s)', count($this->records->all()))
                : 'missing; run "php artisan migrate". Legacy extras cannot be installed without it',
        ]];
    }

    /** @return list<array{name:string,ok:bool,fatal:bool,detail:string}> */
    private function catalogChecks(): array
    {
        $checks = [[
            'name' => 'GITHUB_PAT',
            'ok' => $this->http->hasGithubToken(),
            'fatal' => false,
            'detail' => $this->http->hasGithubToken()
                ? 'set; GitHub allows 5000 requests/hour'
                : 'not set; anonymous GitHub is capped at 60 requests/hour, '
                    . 'which is not enough to walk the legacy organisations',
        ]];

        $checks[] = [
            'name' => 'catalog',
            'ok' => count($this->catalog->all()) > 0,
            'fatal' => false,
            'detail' => sprintf(
                '%d extra(s) from %d source(s)',
                count($this->catalog->all()),
                count($this->catalog->sources())
            ),
        ];

        foreach ($this->catalog->problems() as $source => $reason) {
            $checks[] = [
                'name' => 'source: ' . $source,
                'ok' => false,
                'fatal' => false,
                'detail' => $reason,
            ];
        }

        return $checks;
    }
}
