<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Catalog\CatalogSource;
use hkyss\Extras\Console\Commands\AbstractExtraCommand;
use hkyss\Extras\Console\Commands\InstallCommand;
use hkyss\Extras\Console\Commands\RemoveCommand;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Tests\Support\UnpackingInstaller;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CommandArchiveCleanupTest extends TestCase
{
    private UnpackingInstaller $installer;

    protected function setUp(): void
    {
        $this->installer = new UnpackingInstaller();
    }

    protected function tearDown(): void
    {
        $this->installer->remove();
    }

    public function testADryRunDiscardsWhatPlanningUnpacked(): void
    {
        $status = $this->install(['--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $this->installer->discarded);
        self::assertSame(0, $this->installer->applied);
        self::assertSame([], $this->installer->leftovers());
    }

    public function testABlockedPlanDiscardsWhatPlanningUnpacked(): void
    {
        $this->installer->blockWith('compatibility with Evolution CMS 3 is unknown');

        $status = $this->install();

        self::assertSame(Command::FAILURE, $status);
        self::assertSame(0, $this->installer->applied);
        self::assertSame([], $this->installer->leftovers());
    }

    public function testPlanningThatFailsHalfwayDiscardsWhatItAlreadyUnpacked(): void
    {
        $this->installer->failWhilePlanning();

        $status = $this->install();

        self::assertSame(Command::FAILURE, $status);
        self::assertSame([], $this->installer->leftovers());
    }

    public function testAPlanWithNothingToDoDiscardsWhatPlanningUnpacked(): void
    {
        $this->installer->planNothing();

        $status = $this->install();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(0, $this->installer->applied);
        self::assertSame([], $this->installer->leftovers());
    }

    public function testAnAppliedPlanDiscardsWhatPlanningUnpacked(): void
    {
        $status = $this->install();

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $this->installer->applied);
        self::assertSame([], $this->installer->leftovers());
    }

    public function testRemovalTakesTheSameRoute(): void
    {
        $status = $this->execute(
            new RemoveCommand($this->catalog(), new InstallerRegistry([$this->installer])),
            ['coordinates' => ['org/thing'], '--dry-run' => true]
        );

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(1, $this->installer->discarded);
        self::assertSame([], $this->installer->leftovers());
    }

    /** runOne() is the one place that discards what planning unpacked, so a command that takes an installer of its own leaks again. */
    public function testCommandsPlanOnlyThroughRunOne(): void
    {
        foreach ((array) glob(dirname(__DIR__, 2) . '/src/Console/Commands/*.php') as $file) {
            if (basename((string) $file) === 'AbstractExtraCommand.php') {
                continue;
            }

            self::assertStringNotContainsString(
                'installers->for(',
                (string) file_get_contents((string) $file),
                basename((string) $file) . ' builds a plan of its own instead of going through runOne()'
            );
        }
    }

    /** @param array<string, mixed> $parameters */
    private function install(array $parameters = []): int
    {
        return $this->execute(
            new InstallCommand($this->catalog(), new InstallerRegistry([$this->installer])),
            ['coordinates' => ['org/thing']] + $parameters
        );
    }

    /** @param array<string, mixed> $parameters */
    private function execute(AbstractExtraCommand $command, array $parameters): int
    {
        $command->setLaravel(new class () extends Container {
            public function runningUnitTests(): bool
            {
                return true;
            }
        });

        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->add($command);

        $input = new ArrayInput(['command' => (string) $command->getName()] + $parameters);
        $input->setInteractive(false);

        return $application->run($input, new BufferedOutput());
    }

    private function catalog(): Catalog
    {
        $extra = new Extra(Coordinate::parse('org/thing'), ExtraFormat::Legacy, '', '', '', [], 'master');

        return new Catalog([new class ($extra) implements CatalogSource {
            public function __construct(private Extra $extra)
            {
            }

            public function name(): string
            {
                return 'test';
            }

            public function all(): array
            {
                return [$this->extra];
            }

            public function find(Coordinate $coordinate): ?Extra
            {
                return $this->extra->coordinate()->equals($coordinate) ? $this->extra : null;
            }

            public function unavailableReason(): ?string
            {
                return null;
            }
        }]);
    }
}
