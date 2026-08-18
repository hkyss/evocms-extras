<?php

namespace hkyss\Extras\Tests\Unit;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Console\Commands\InfoCommand;
use hkyss\Extras\Console\Commands\InstallCommand;
use hkyss\Extras\Console\Commands\ListCommand;
use hkyss\Extras\Console\Commands\RemoveCommand;
use hkyss\Extras\Console\Commands\UpdateCommand;
use hkyss\Extras\Installer\InstallerRegistry;
use Illuminate\Console\Parser;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

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

    /** @dataProvider optionalSubjects */
    public function testInteractiveCommandsDoNotRequireTheirSubject(string $class, string $argument): void
    {
        [, $arguments] = Parser::parse(self::signatureOf($class));

        foreach ($arguments as $definition) {
            if ($definition->getName() === $argument) {
                self::assertFalse(
                    $definition->isRequired(),
                    sprintf('%s must be optional so the command can offer a picker instead', $argument)
                );

                return;
            }
        }

        self::fail(sprintf('%s declares no argument named %s', $class, $argument));
    }

    /** @return array<string, array{class-string, string}> */
    public static function optionalSubjects(): array
    {
        return [
            'extra:info' => [InfoCommand::class, 'coordinate'],
            'extra:install' => [InstallCommand::class, 'coordinates'],
            'extra:remove' => [RemoveCommand::class, 'coordinates'],
            'extra:update' => [UpdateCommand::class, 'coordinates'],
        ];
    }

    /** @dataProvider formats */
    public function testListFormatResolution(string $requested, bool $interactive, string $expected): void
    {
        self::assertSame($expected, ListCommand::resolveFormat($requested, $interactive));
    }

    /** @return array<string, array{string, bool, string}> */
    public static function formats(): array
    {
        return [
            'default in a terminal browses' => ['auto', true, 'list'],
            'default in a pipe prints the table' => ['auto', false, 'table'],
            'table is honoured in a terminal' => ['table', true, 'table'],
            'list degrades to the table in a pipe' => ['list', false, 'table'],
            'json wins everywhere' => ['json', true, 'json'],
            'json in a pipe' => ['json', false, 'json'],
            'anything unknown falls back to the table' => ['nonsense', true, 'table'],
        ];
    }

    /** @param class-string $class */
    private static function signatureOf(string $class): string
    {
        $signature = (new ReflectionClass($class))->getDefaultProperties()['signature'] ?? null;

        self::assertIsString($signature, $class . ' has no $signature');

        return $signature;
    }
}
