<?php

/**
 * What this package reaches for in an Evolution CMS installation. The CMS cannot be declared
 * as a Composer dependency, so static analysis is given the surface instead.
 */

namespace EvolutionCMS {
    class Core
    {
        /** @var array<string, array<string, mixed>> */
        public $modulesFromFile = [];

        /** @return mixed */
        public function getConfig(string $name = '', mixed $default = null)
        {
        }

        /** @return int|string */
        public function getLoginUserID(string $context = '')
        {
        }

        public function hasPermission(string $permission): bool
        {
        }

        /**
         * @param array<string, mixed> $params
         * @return string|false
         */
        public function registerModule(string $name, string $file, string $icon = 'fa fa-cube', array $params = [])
        {
        }

        /**
         * @param string|array<int, string> $type
         * @return mixed
         */
        public function clearCache($type = '', bool $report = false)
        {
        }
    }
}

namespace {
    function evolutionCMS(): \EvolutionCMS\Core
    {
    }

    function evo(): \EvolutionCMS\Core
    {
    }

    /** @param array<string, string> $headers */
    function response(string $content = '', int $status = 200, array $headers = []): \Illuminate\Contracts\Routing\ResponseFactory
    {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $mergeData
     */
    function view(?string $view = null, array $data = [], array $mergeData = []): \Illuminate\Contracts\View\View
    {
    }

    function base_path(string $path = ''): string
    {
    }
}
