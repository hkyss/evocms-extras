<?php

namespace hkyss\Extras\Support;

use Throwable;

/**
 * Evolution evaluates plugins, snippets and modules out of a compiled cache rather than out of
 * the tables, so an element a removal deleted keeps firing until the cache is written again —
 * and one whose files went with it takes the manager down with a fatal.
 */
class SiteCache
{
    public function clear(): void
    {
        if (!function_exists('evolutionCMS')) {
            return;
        }

        try {
            evolutionCMS()->clearCache('full');
        } catch (Throwable) {
            // A cache that could not be written is the CMS's to report; the plan is applied.
        }
    }
}
