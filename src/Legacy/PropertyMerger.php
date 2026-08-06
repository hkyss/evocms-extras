<?php

namespace hkyss\Extras\Legacy;

/**
 * Merges element property strings on update: the author owns the definition, the user
 * owns the configured value.
 */
class PropertyMerger
{
    public function merge(string $incoming, string $existing): string
    {
        if (trim($existing) === '') {
            return $incoming;
        }

        if (trim($incoming) === '') {
            return $existing;
        }

        $incomingJson = json_decode($incoming, true);
        $existingJson = json_decode($existing, true);

        if (is_array($incomingJson) && is_array($existingJson)) {
            return (string) json_encode(
                $this->mergeJson($incomingJson, $existingJson),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }

        $existingValues = $this->values($existing);
        $result = '';

        foreach ($this->entries($incoming) as $name => $segments) {
            if (isset($existingValues[$name]) && $existingValues[$name] !== '' && count($segments) >= 3) {
                $segments[count($segments) - 1] = $existingValues[$name];
            }

            $result .= '&' . $name . '=' . implode(';', $segments) . ' ';
        }

        return trim($result);
    }

    /**
     * @param array<mixed> $incoming
     * @param array<mixed> $existing
     * @return array<mixed>
     */
    private function mergeJson(array $incoming, array $existing): array
    {
        foreach ($incoming as $key => $value) {
            if (!array_key_exists($key, $existing)) {
                continue;
            }

            if (is_array($value) && is_array($existing[$key])) {
                $incoming[$key] = $this->mergeJson($value, $existing[$key]);
                continue;
            }

            if (!is_array($value) && !is_array($existing[$key])) {
                $incoming[$key] = $existing[$key];
            }
        }

        return $incoming;
    }

    /** @return array<string,list<string>> */
    public function entries(string $properties): array
    {
        $entries = [];

        foreach ($this->chunks($properties) as $chunk) {
            $position = strpos($chunk, '=');

            if ($position === false) {
                continue;
            }

            $name = trim(substr($chunk, 0, $position));

            if ($name === '') {
                continue;
            }

            $entries[$name] = array_map('trim', explode(';', substr($chunk, $position + 1)));
        }

        return $entries;
    }

    /**
     * The value is the last segment: text fields have three, menus have four.
     *
     * @return array<string,string>
     */
    public function values(string $properties): array
    {
        $values = [];

        foreach ($this->entries($properties) as $name => $segments) {
            $values[$name] = count($segments) >= 3 ? (string) end($segments) : '';
        }

        return $values;
    }

    /**
     * Splits on `&` while leaving HTML entities in captions intact.
     *
     * @return list<string>
     */
    private function chunks(string $properties): array
    {
        $parts = preg_split('~&(?![a-z]+;|#\d+;)~i', trim($properties)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($p) => $p !== ''));
    }
}
