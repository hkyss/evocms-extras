<?php

namespace hkyss\Extras\Legacy;

/** Parses element files, which start with `//<?php`, a bare docblock or a BOM depending on type. */
class DocblockParser
{
    public function parse(ElementType $type, string $contents, string $filename = ''): ElementDescriptor
    {
        $contents = $this->stripBom($contents);
        $lines = $this->docblockLines($this->extractDocblock($contents));

        $tags = $this->parseTags($lines);
        [$name, $description] = $this->parseHeader($lines);

        if (isset($tags['name']) && trim($tags['name']) !== '') {
            $name = trim($tags['name']);
        }

        if ($name === '') {
            $name = $filename;
        }

        return new ElementDescriptor($type, $name, $description, $this->extractCode($contents), $tags);
    }

    private function stripBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }

    private function extractDocblock(string $contents): string
    {
        return preg_match('~/\*\*(.*?)\*/~s', $contents, $m) === 1 ? $m[1] : '';
    }

    /** Strips the php tag first, then the leading docblock, in that order. */
    private function extractCode(string $contents): string
    {
        $parts = preg_split('~(//)?\s*<\?php~', $contents, 2);
        $code = is_array($parts) ? (string) end($parts) : $contents;

        return trim((string) preg_replace('~^.*?/\*\*.*?\*/\s*~s', '', $code, 1));
    }

    /** @return list<string> */
    private function docblockLines(string $docblock): array
    {
        $lines = [];

        foreach (preg_split('~\R~', $docblock) ?: [] as $line) {
            $lines[] = rtrim((string) preg_replace('~^\s*\*[ \t]?~', '', $line));
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     * @return array{0:string,1:string}
     */
    private function parseHeader(array $lines): array
    {
        $name = '';
        $description = [];
        $seenName = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                continue;
            }

            if (str_starts_with($trimmed, '@')) {
                break;
            }

            if (!$seenName) {
                $name = $trimmed;
                $seenName = true;
                continue;
            }

            $description[] = $trimmed;
        }

        return [$name, implode(' ', $description)];
    }

    /**
     * @param list<string> $lines
     * @return array<string,string>
     */
    private function parseTags(array $lines): array
    {
        $tags = [];
        $current = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $current = null;
                continue;
            }

            $tag = $this->matchTag($trimmed);

            if ($tag !== null) {
                [$key, $value] = $tag;
                $tags[$key] = $value;
                $current = $key;
                continue;
            }

            if ($current !== null && !str_starts_with($trimmed, '@')) {
                $tags[$current] = trim($tags[$current] . ' ' . $trimmed);
            }
        }

        return $tags;
    }

    /** @return array{0:string,1:string}|null */
    private function matchTag(string $line): ?array
    {
        if (preg_match('~^@internal\s+@([a-z_]+)\b[ \t]*(.*)$~i', $line, $m) === 1) {
            return [strtolower($m[1]), trim($m[2])];
        }

        if (preg_match('~^@([a-z_]+)\b[ \t]*(.*)$~i', $line, $m) === 1) {
            $key = strtolower($m[1]);

            return $key === 'internal' ? null : [$key, trim($m[2])];
        }

        return null;
    }
}
