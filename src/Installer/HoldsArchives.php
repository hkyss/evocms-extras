<?php

namespace hkyss\Extras\Installer;

/** An installer that unpacks something to build a plan; whoever asked for the plan releases it, applied or not. */
interface HoldsArchives
{
    public function discardArchives(): void;
}
