<?php

namespace hkyss\Extras\Installer;

use Composer\Console\Application;
use hkyss\Extras\Support\Paths;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/** Runs Composer in-process and keeps the exit code, which is what makes a rollback possible. */
class ComposerRunner
{
    private Paths $paths;
    private string $memoryLimit;

    public function __construct(Paths $paths, string $memoryLimit = '-1')
    {
        $this->paths = $paths;
        $this->memoryLimit = $memoryLimit;
    }

    public function isAvailable(): bool
    {
        return class_exists(Application::class);
    }

    /** Composer is itself a dependency of the site it manages, so an update left unfiltered replaces the files this process runs from. */
    public function updatePackage(string $coordinate): ComposerResult
    {
        return $this->run([
            'command' => 'update',
            'packages' => [$coordinate],
            '--with-dependencies' => true,
            '--no-interaction' => true,
            '--no-dev' => true,
            '--optimize-autoloader' => true,
        ]);
    }

    /** @param array<string,mixed> $arguments */
    public function run(array $arguments): ComposerResult
    {
        if (!$this->isAvailable()) {
            return new ComposerResult(1, ['composer/composer is not available in this installation']);
        }

        $previousHome = getenv('COMPOSER_HOME');
        $previousMemory = getenv('COMPOSER_MEMORY_LIMIT');
        $previousCwd = getcwd();

        putenv('COMPOSER_HOME=' . $this->paths->composerHome());
        putenv('COMPOSER_MEMORY_LIMIT=' . $this->memoryLimit);
        @chdir($this->paths->core());

        $output = new BufferedOutput();

        try {
            $application = new Application();
            $application->setAutoExit(false);
            $application->setCatchExceptions(true);

            $exitCode = (int) $application->run(new ArrayInput($arguments), $output);
        } catch (\Throwable $e) {
            return new ComposerResult(1, [$e->getMessage()]);
        } finally {
            if (is_string($previousCwd)) {
                @chdir($previousCwd);
            }

            $this->restoreEnv('COMPOSER_HOME', $previousHome);
            $this->restoreEnv('COMPOSER_MEMORY_LIMIT', $previousMemory);
        }

        return new ComposerResult($exitCode, $this->lines($output->fetch()));
    }

    /** @param string|false $previous */
    private function restoreEnv(string $name, $previous): void
    {
        if (is_string($previous) && $previous !== '') {
            putenv($name . '=' . $previous);

            return;
        }

        putenv($name);
    }

    /** @return list<string> */
    private function lines(string $raw): array
    {
        $lines = preg_split('~\R~', trim($raw)) ?: [];

        return array_values(array_filter($lines, static fn ($l) => trim((string) $l) !== ''));
    }
}
