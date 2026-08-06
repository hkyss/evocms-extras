<?php

namespace hkyss\Extras\Exceptions;

class ExtraNotFoundException extends ExtrasException
{
    public static function inCatalog(string $coordinate): self
    {
        return new self("Extra '{$coordinate}' not found in catalog");
    }

    public static function notInstalled(string $coordinate): self
    {
        return new self("Extra '{$coordinate}' is not installed");
    }
}
