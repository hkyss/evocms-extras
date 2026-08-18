<?php

namespace hkyss\Extras\Installer;

use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Support\Paths;
use hkyss\Extras\Support\PublishedAssets;

class ComposerInstaller implements Installer
{
    private Paths $paths;
    private ComposerManifest $manifest;
    private ComposerRunner $runner;

    public function __construct(Paths $paths, ComposerManifest $manifest, ComposerRunner $runner)
    {
        $this->paths = $paths;
        $this->manifest = $manifest;
        $this->runner = $runner;
    }

    public function supports(Extra $extra): bool
    {
        return $extra->format() === ExtraFormat::Composer;
    }

    public function plan(Extra $extra, Intent $intent, string $version = ''): InstallPlan
    {
        $coordinate = (string) $extra->coordinate();
        $state = $this->installedState($extra);

        if ($intent === Intent::Remove) {
            return $this->planRemoval($extra, $state);
        }

        $constraint = $this->constraint($extra, $version);

        $plan = new InstallPlan(
            $extra->coordinate(),
            ExtraFormat::Composer,
            $intent,
            $constraint,
            $state->constraint()
        );

        if ($intent === Intent::Update && !$state->isInstalled()) {
            $plan->block("'{$coordinate}' is not installed; nothing to update");

            return $plan;
        }

        if ($state->isInstalled() && $state->constraint() === $constraint && $intent === Intent::Install) {
            $plan->warn("'{$coordinate}' is already required as '{$constraint}'");
        }

        $unmet = PlatformCheck::unmetPhpRequirement($extra->requires());

        if ($unmet !== null) {
            $plan->forbid("'{$coordinate}' {$unmet}");
        }

        foreach (PlatformCheck::missingExtensions($extra->requires()) as $extension) {
            $plan->forbid("'{$coordinate}' needs the {$extension} extension, which this PHP does not load");
        }

        $plan->step(
            StepKind::ManifestRequire,
            "require {$coordinate}:{$constraint} in " . $this->relative($this->manifest->path()),
            ['coordinate' => $coordinate, 'constraint' => $constraint]
        );

        $plan->step(
            StepKind::ComposerRun,
            "composer update {$coordinate} --with-dependencies",
            ['coordinate' => $coordinate, 'mode' => 'package']
        );

        return $plan;
    }

    public function apply(InstallPlan $plan): Outcome
    {
        return $plan->intent() === Intent::Remove
            ? $this->applyRemoval($plan)
            : $this->applyInstall($plan);
    }

    public function format(): ExtraFormat
    {
        return ExtraFormat::Composer;
    }

    /** @return array<string, InstalledState> */
    public function installed(): array
    {
        $installed = [];

        foreach ($this->manifest->requirements() as $coordinate => $constraint) {
            $installed[$coordinate] = InstalledState::present(
                $this->installedVersion($coordinate) ?? $constraint,
                $constraint
            );
        }

        return $installed;
    }

    public function installedState(Extra $extra): InstalledState
    {
        $coordinate = (string) $extra->coordinate();
        $constraint = $this->manifest->constraintFor($coordinate);

        if ($constraint === null) {
            return InstalledState::absent();
        }

        return InstalledState::present($this->installedVersion($coordinate) ?? $constraint, $constraint);
    }

    private function planRemoval(Extra $extra, InstalledState $state): InstallPlan
    {
        $coordinate = (string) $extra->coordinate();

        $plan = new InstallPlan(
            $extra->coordinate(),
            ExtraFormat::Composer,
            Intent::Remove,
            '',
            $state->constraint()
        );

        if (!$state->isInstalled()) {
            $plan->block("'{$coordinate}' is not required in " . $this->relative($this->manifest->path()));

            return $plan;
        }

        $packageComposer = $this->packageComposer($coordinate);

        $this->planAssetRemoval($plan, $coordinate, $packageComposer);
        $this->planProviderRemoval($plan, $packageComposer);

        $plan->step(
            StepKind::ManifestRemove,
            "drop {$coordinate} from " . $this->relative($this->manifest->path()),
            ['coordinate' => $coordinate]
        );

        $plan->step(
            StepKind::ComposerRun,
            'composer update (resolve the tree without ' . $coordinate . ')',
            ['coordinate' => $coordinate, 'mode' => 'all']
        );

        $plan->step(StepKind::RecordDelete, "forget {$coordinate}", ['coordinate' => $coordinate]);

        return $plan;
    }

    /** @param array<string,mixed> $packageComposer */
    private function planAssetRemoval(InstallPlan $plan, string $coordinate, array $packageComposer): void
    {
        $files = $packageComposer['extra']['laravel']['files'] ?? [];

        if (!is_array($files)) {
            return;
        }

        $packageDir = $this->paths->packageDir($coordinate);
        $publishBase = $this->paths->publishBase();

        foreach ($files as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $source = $packageDir . trim((string) ($entry['source'] ?? ''), '/\\');
            $destination = $publishBase . trim((string) ($entry['destination'] ?? ''), '/\\');

            $comparison = PublishedAssets::compare($source, $destination);

            foreach ($comparison->identical() as $relative) {
                $path = $destination . DIRECTORY_SEPARATOR . $relative;
                $plan->step(StepKind::FileDelete, 'delete ' . $this->relative($path), ['path' => $path]);
            }

            foreach ($comparison->modified() as $relative) {
                $path = $destination . DIRECTORY_SEPARATOR . $relative;
                $plan->step(StepKind::FileKeep, 'keep (modified) ' . $this->relative($path), ['path' => $path]);
            }

            if ($comparison->hasModified()) {
                $plan->warn(sprintf(
                    '%d published file(s) under %s differ from the package and will be left in place',
                    count($comparison->modified()),
                    $this->relative($destination)
                ));
            }
        }
    }

    /** @param array<string,mixed> $packageComposer */
    private function planProviderRemoval(InstallPlan $plan, array $packageComposer): void
    {
        $providers = $packageComposer['extra']['laravel']['providers'] ?? [];

        if (!is_array($providers)) {
            return;
        }

        foreach ($providers as $provider) {
            if (!is_string($provider) || trim($provider) === '') {
                continue;
            }

            $file = $this->paths->providersDir() . basename(str_replace('\\', '/', trim($provider))) . '.php';

            if (is_file($file)) {
                $plan->step(
                    StepKind::ProviderConfigDelete,
                    'unregister ' . $provider,
                    ['path' => $file, 'provider' => $provider]
                );
            }
        }
    }

    private function applyInstall(InstallPlan $plan): Outcome
    {
        $coordinate = (string) $plan->coordinate();
        $snapshot = $this->manifest->snapshot();

        foreach ($plan->stepsOf(StepKind::ManifestRequire) as $step) {
            $this->manifest->require((string) $step->get('coordinate'), (string) $step->get('constraint'));
        }

        $result = $this->runner->updatePackage($coordinate);

        if (!$result->isSuccessful()) {
            $this->manifest->restore($snapshot);

            return Outcome::failure(
                sprintf(
                    'Composer exited with code %d; %s has been rolled back',
                    $result->exitCode(),
                    $this->relative($this->manifest->path())
                ),
                ComposerDiagnosis::explain($result->tail()),
                $result->tail()
            );
        }

        return Outcome::success(
            sprintf('%s %s', $coordinate, $plan->intent() === Intent::Update ? 'updated' : 'installed'),
            [],
            $result->tail(4)
        );
    }

    private function applyRemoval(InstallPlan $plan): Outcome
    {
        $coordinate = (string) $plan->coordinate();
        $snapshot = $this->manifest->snapshot();

        $this->manifest->remove($coordinate);

        $result = $this->runner->updateAll();

        if (!$result->isSuccessful()) {
            $this->manifest->restore($snapshot);

            return Outcome::failure(
                sprintf(
                    'Composer exited with code %d; %s has been rolled back and nothing was deleted',
                    $result->exitCode(),
                    $this->relative($this->manifest->path())
                ),
                ComposerDiagnosis::explain($result->tail()),
                $result->tail()
            );
        }

        $notes = [];
        $deleted = 0;

        foreach ($plan->stepsOf(StepKind::ProviderConfigDelete, StepKind::FileDelete) as $step) {
            if (@unlink((string) $step->get('path'))) {
                $deleted++;
            }
        }

        $kept = $plan->stepsOf(StepKind::FileKeep);

        if ($kept !== []) {
            $notes[] = sprintf('%d modified file(s) left on disk:', count($kept));

            foreach (array_slice($kept, 0, 10) as $step) {
                $notes[] = '  ' . $this->relative((string) $step->get('path'));
            }

            if (count($kept) > 10) {
                $notes[] = sprintf('  … and %d more', count($kept) - 10);
            }
        }

        return Outcome::success(
            sprintf('%s removed (%d file(s) deleted)', $coordinate, $deleted),
            $notes,
            $result->tail(4)
        );
    }

    private function constraint(Extra $extra, string $version): string
    {
        $version = trim($version);

        if ($version === '' || strtolower($version) === 'latest') {
            $default = $extra->defaultVersion();

            return $default !== '' ? $default : '*';
        }

        return $version;
    }

    /** @return array<string,mixed> */
    private function packageComposer(string $coordinate): array
    {
        $file = $this->paths->packageDir($coordinate) . 'composer.json';

        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) @file_get_contents($file), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function installedVersion(string $coordinate): ?string
    {
        $file = $this->paths->installedJson();

        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($file), true);
        $packages = $decoded['packages'] ?? $decoded ?? [];

        if (!is_array($packages)) {
            return null;
        }

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            if (strcasecmp((string) ($package['name'] ?? ''), $coordinate) === 0) {
                $version = (string) ($package['version'] ?? '');

                return $version !== '' ? $version : null;
            }
        }

        return null;
    }

    private function relative(string $path): string
    {
        $base = $this->paths->base();

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
