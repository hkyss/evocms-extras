<?php

namespace hkyss\Extras\Manager;

/**
 * Puts the module in the header instead of the Modules tab, the way the rest of the
 * everyday modules in this stack sit.
 */
class MenuListener
{
    /**
     * Frame::makeMenu() replaces the menu with the union of every answer rather than
     * merging what each one added, so the whole thing goes back, serialized.
     *
     * @param array<string,mixed> $params
     */
    public function handle(array $params): ?string
    {
        $menu = $params['menu'] ?? null;

        if (!is_array($menu) || $menu === [] || !$this->isReachable()) {
            return null;
        }

        return serialize(ManagerModule::promote($menu));
    }

    protected function isReachable(): bool
    {
        if (!function_exists('evolutionCMS')) {
            return false;
        }

        $core = evolutionCMS();

        return $core->hasPermission('exec_module')
            && isset($core->modulesFromFile[ManagerModule::id()]);
    }
}
