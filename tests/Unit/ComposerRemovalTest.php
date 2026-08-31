<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Installer\ComposerInstaller;
use hkyss\Extras\Installer\ComposerManifest;
use hkyss\Extras\Installer\ComposerResult;
use hkyss\Extras\Installer\ComposerRunner;
use hkyss\Extras\Installer\InstallPlan;
use hkyss\Extras\Installer\Intent;
use hkyss\Extras\Installer\StepKind;
use hkyss\Extras\Support\Paths;
use hkyss\Extras\Tests\Support\TempTree;
use PHPUnit\Framework\TestCase;

/** A runner that answers with the code it was given and records the request and the tree it saw. */
class RecordingRunner extends ComposerRunner
{
    public bool $providerWasThere = true;

    public bool $compiledListWasThere = true;

    /** @var array<string,mixed> */
    public array $arguments = [];

    private int $exitCode;

    private string $provider;

    private string $compiled;

    public function __construct(Paths $paths, int $exitCode, string $provider, string $compiled)
    {
        parent::__construct($paths);

        $this->exitCode = $exitCode;
        $this->provider = $provider;
        $this->compiled = $compiled;
    }

    /** @param array<string,mixed> $arguments */
    public function run(array $arguments): ComposerResult
    {
        $this->arguments = $arguments;
        $this->providerWasThere = is_file($this->provider);
        $this->compiledListWasThere = is_file($this->compiled);

        return new ComposerResult($this->exitCode, ['output']);
    }
}

class ComposerRemovalTest extends TestCase
{
    private ?TempTree $tree = null;

    protected function tearDown(): void
    {
        $this->tree?->remove();
        $this->tree = null;
    }

    public function testItUnregistersTheProviderBeforeComposerRuns(): void
    {
        [$installer, $runner, $paths, $plan] = $this->removal(0);

        $outcome = $installer->apply($plan);

        $this->assertFalse($runner->providerWasThere);
        $this->assertFalse($runner->compiledListWasThere);
        $this->assertTrue($outcome->isSuccessful());
        $this->assertFileDoesNotExist($paths->providersDir() . 'DemoServiceProvider.php');
    }

    public function testARemovalAsksComposerForThePackageAloneRatherThanTheWholeTree(): void
    {
        [$installer, $runner, , $plan] = $this->removal(0);

        $installer->apply($plan);

        $this->assertSame(['vendor/package'], $runner->arguments['packages'] ?? []);
    }

    public function testAFailedComposerRunPutsTheProviderBack(): void
    {
        [$installer, , $paths, $plan] = $this->removal(1);

        $outcome = $installer->apply($plan);

        $manifest = json_decode((string) file_get_contents($paths->customManifest()), true);

        $this->assertFalse($outcome->isSuccessful());
        $this->assertFileExists($paths->providersDir() . 'DemoServiceProvider.php');
        $this->assertArrayHasKey('vendor/package', $manifest['require'] ?? []);
    }

    public function testItTakesTheDirectoriesItEmptiedWithTheFiles(): void
    {
        [$installer, , $paths, $plan] = $this->removal(0);

        $installer->apply($plan);

        $this->assertFileDoesNotExist($paths->base() . 'assets/plugins/demo/demo.js');
        $this->assertDirectoryDoesNotExist($paths->base() . 'assets/plugins/demo');
        $this->assertDirectoryExists($paths->base() . 'assets/plugins');
    }

    /** @return array{0: ComposerInstaller, 1: RecordingRunner, 2: Paths, 3: InstallPlan} */
    private function removal(int $exitCode): array
    {
        $this->tree = $tree = TempTree::make('extras-composer-removal');

        $tree->put('core/custom/composer.json', (string) json_encode([
            'name' => 'evolutioncms/custom',
            'require' => ['vendor/package' => '1.0'],
        ]));
        $tree->put('core/custom/config/app/providers/DemoServiceProvider.php', "<?php return \\Demo::class;\n");
        $tree->put('core/storage/bootstrap/services.php', "<?php return [];\n");
        $tree->put('assets/plugins/demo/demo.js', 'demo');
        $tree->directory('assets/plugins/other');

        $paths = new Paths($tree->path('core'), $tree->path());
        $provider = $paths->providersDir() . 'DemoServiceProvider.php';

        $runner = new RecordingRunner($paths, $exitCode, $provider, $paths->compiledProviders());

        $plan = new InstallPlan(
            Coordinate::parse('vendor/package'),
            ExtraFormat::Composer,
            Intent::Remove
        );
        $plan->step(StepKind::ProviderConfigDelete, 'unregister Demo', ['path' => $provider]);
        $plan->step(StepKind::FileDelete, 'delete demo.js', [
            'path' => $paths->base() . 'assets/plugins/demo/demo.js',
        ]);

        return [
            new ComposerInstaller($paths, new ComposerManifest($paths->customManifest()), $runner),
            $runner,
            $paths,
            $plan,
        ];
    }
}
