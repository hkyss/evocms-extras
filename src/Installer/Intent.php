<?php

namespace hkyss\Extras\Installer;

enum Intent: string
{
    case Install = 'install';
    case Update = 'update';
    case Remove = 'remove';

    public function verb(): string
    {
        return $this->value;
    }
}
