<?php

/** The manager evals this file, so __DIR__ is core/functions and the page is reached through the container. */

if (!defined('IN_MANAGER_MODE')) {
    return;
}

return view('extras::module')->render();
