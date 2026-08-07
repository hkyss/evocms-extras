<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Console\Commands\InstallCommand;
use hkyss\Extras\Console\Commands\UpdateCommand;
use hkyss\Extras\Installer\InstallerRegistry;
use Illuminate\Console\Parser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Symfony defines its own options on the Application (--help, --version,
 * --quiet, ...). Command::run() merges that definition into the command's own
 * before it looks at a single argument, and InputDefinition::addOption() throws
 * on a duplicate name. A command that declares one of those names is therefore
 * unreachable — with or without the flag — which is exactly how `extra:install`
 * and `extra:update` were lost to their own `--version`.
 */
class CommandDefinitionTest extends TestCase
{
    /** @return array<string, array{class-string}> */
    public static function commandClasses(): array
    {
        $classes = [];

        foreach ((array) glob(__DIR__ . '/../../src/Console/Commands/*.php') as $file) {
            $class = 'hkyss\\Extras\\Console\\Commands\\' . basename((string) $file, '.php');

            if (!class_exists($class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $classes[basename((string) $file, '.php')] = [$class];
        }

        return $classes;
    }

    /**
     * @param class-string $class
     *
     * @dataProvider commandClasses
     */
    public function testSignatureAvoidsNamesSymfonyReserves(string $class): void
    {
        $reserved = array_keys((new Application())->getDefinition()->getOptions());

        [$name, , $options] = Parser::parse(self::signatureOf($class));

        foreach ($options as $option) {
            self::assertNotContains(
                $option->getName(),
                $reserved,
                sprintf(
                    '%s declares --%s, which Symfony already defines on the Application. '
                    . 'mergeApplicationDefinition() would throw and the command could never run.',
                    $name,
                    $option->getName()
                )
            );
        }
    }

    /**
     * The guard above reads the signature; this one walks the real path that
     * broke — registering the command on an Application and describing it,
     * which is what merges the two definitions.
     */
    public function testCommandsSurviveBeingRegisteredOnAnApplication(): void
    {
        $commands = [
            new InstallCommand(new Catalog(), new InstallerRegistry()),
            new UpdateCommand(new Catalog(), new InstallerRegistry()),
        ];

        foreach ($commands as $command) {
            $application = new Application();
            $application->setAutoExit(false);
            $application->setCatchExceptions(false);
            $application->add($command);

            $status = $application->run(
                new ArrayInput(['command' => (string) $command->getName(), '--help' => true]),
                new NullOutput()
            );

            self::assertSame(Command::SUCCESS, $status, (string) $command->getName() . ' could not be described');
        }
    }

    /** @param class-string $class */
    private static function signatureOf(string $class): string
    {
        $signature = (new ReflectionClass($class))->getDefaultProperties()['signature'] ?? null;

        self::assertIsString($signature, $class . ' has no $signature');

        return $signature;
    }
}
