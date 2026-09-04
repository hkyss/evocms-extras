<?php

namespace hkyss\Extras\Manager;

class MenuListener
{
    /**
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
