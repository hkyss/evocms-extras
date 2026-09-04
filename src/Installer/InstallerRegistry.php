<?php

namespace hkyss\Extras\Installer;

use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Exceptions\ExtrasException;

class InstallerRegistry
{
    /** @var list<Installer> */
    private array $installers;

    /** @param list<Installer> $installers */
    public function __construct(array $installers = [])
    {
        $this->installers = $installers;
    }

    public function add(Installer $installer): void
    {
        $this->installers[] = $installer;
    }

    public function for(Extra $extra): Installer
    {
        foreach ($this->installers as $installer) {
            if ($installer->supports($extra)) {
                return $installer;
            }
        }

        throw new ExtrasException(sprintf(
            "No installer handles format '%s' required by '%s'",
            $extra->format()->value,
            $extra->coordinate()
        ));
    }

    /**
     * @return array<string, array{state:InstalledState,format:ExtraFormat}>
     */
    public function installed(): array
    {
        $installed = [];

        foreach ($this->installers as $installer) {
            if (!$installer instanceof EnumeratesInstalled) {
                continue;
            }

            foreach ($installer->installed() as $coordinate => $state) {
                $installed[$coordinate] = ['state' => $state, 'format' => $installer->format()];
            }
        }

        ksort($installed);

        return $installed;
    }

    public function stateOf(Extra $extra): InstalledState
    {
        try {
            return $this->for($extra)->installedState($extra);
        } catch (ExtrasException) {
            return InstalledState::absent();
        }
    }
}
