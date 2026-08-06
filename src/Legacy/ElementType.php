<?php

namespace hkyss\Extras\Legacy;

enum ElementType: string
{
    case Snippet = 'snippets';
    case Plugin = 'plugins';
    case Chunk = 'chunks';
    case Module = 'modules';
    case Template = 'templates';
    case Tv = 'tvs';

    /** The format carries no type field; the directory name under install/assets is it. */
    public static function fromDirectory(string $directory): ?self
    {
        return self::tryFrom(strtolower(trim($directory, '/\\')));
    }

    public function table(): string
    {
        return match ($this) {
            self::Snippet => 'site_snippets',
            self::Plugin => 'site_plugins',
            self::Chunk => 'site_htmlsnippets',
            self::Module => 'site_modules',
            self::Template => 'site_templates',
            self::Tv => 'site_tmplvars',
        };
    }

    public function codeColumn(): string
    {
        return match ($this) {
            self::Snippet, self::Chunk => 'snippet',
            self::Plugin => 'plugincode',
            self::Module => 'modulecode',
            self::Template => 'content',
            self::Tv => 'default_text',
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Snippet => 'snippet',
            self::Plugin => 'plugin',
            self::Chunk => 'chunk',
            self::Module => 'module',
            self::Template => 'template',
            self::Tv => 'template variable',
        };
    }

    public function hasEvents(): bool
    {
        return $this === self::Plugin;
    }
}
