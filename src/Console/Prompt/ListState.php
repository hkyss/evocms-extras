<?php

namespace hkyss\Extras\Console\Prompt;

/** @phpstan-type Option array{value:string,label:string,hint:string,search:string} */
final class ListState
{
    /** @var list<Option> */
    private array $options;

    /** @var list<int> indexes into $options, in display order */
    private array $visible;

    private string $filter = '';

    private int $cursor = 0;

    private int $offset = 0;

    private int $height;

    /** @var array<string, true> */
    private array $selected = [];

    /** @param list<Option> $options */
    public function __construct(array $options, int $height = 10)
    {
        $this->options = $options;
        $this->height = max(1, $height);
        $this->visible = array_keys($options);
    }

    /** @param list<array{value:string,label?:string,hint?:string,search?:string}> $rows */
    public static function of(array $rows, int $height = 10): self
    {
        $options = [];

        foreach ($rows as $row) {
            $label = $row['label'] ?? $row['value'];

            $options[] = [
                'value' => $row['value'],
                'label' => $label,
                'hint' => $row['hint'] ?? '',
                'search' => mb_strtolower($row['search'] ?? ($row['value'] . ' ' . $label . ' ' . ($row['hint'] ?? ''))),
            ];
        }

        return new self($options, $height);
    }

    public function filter(): string
    {
        return $this->filter;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function total(): int
    {
        return count($this->options);
    }

    public function matches(): int
    {
        return count($this->visible);
    }

    public function isEmpty(): bool
    {
        return $this->visible === [];
    }

    /** @return Option|null */
    public function current(): ?array
    {
        $index = $this->visible[$this->cursor] ?? null;

        return $index === null ? null : $this->options[$index];
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    public function offset(): int
    {
        return $this->offset;
    }

    /** @return list<array{option:Option,active:bool,selected:bool}> */
    public function window(): array
    {
        $rows = [];
        $slice = array_slice($this->visible, $this->offset, $this->height, true);

        foreach ($slice as $position => $index) {
            $option = $this->options[$index];

            $rows[] = [
                'option' => $option,
                'active' => $position === $this->cursor,
                'selected' => isset($this->selected[$option['value']]),
            ];
        }

        return $rows;
    }

    public function moveDown(): void
    {
        if ($this->visible === []) {
            return;
        }

        $this->cursor = ($this->cursor + 1) % count($this->visible);
        $this->clampOffset();
    }

    public function moveUp(): void
    {
        if ($this->visible === []) {
            return;
        }

        $this->cursor = ($this->cursor - 1 + count($this->visible)) % count($this->visible);
        $this->clampOffset();
    }

    public function pageDown(): void
    {
        if ($this->visible === []) {
            return;
        }

        $this->cursor = min($this->cursor + $this->height, count($this->visible) - 1);
        $this->clampOffset();
    }

    public function pageUp(): void
    {
        $this->cursor = max($this->cursor - $this->height, 0);
        $this->clampOffset();
    }

    public function type(string $characters): void
    {
        $this->applyFilter($this->filter . $characters);
    }

    public function backspace(): void
    {
        if ($this->filter === '') {
            return;
        }

        $this->applyFilter(mb_substr($this->filter, 0, -1));
    }

    public function clearFilter(): void
    {
        $this->applyFilter('');
    }

    public function toggle(): void
    {
        $current = $this->current();

        if ($current === null) {
            return;
        }

        if (isset($this->selected[$current['value']])) {
            unset($this->selected[$current['value']]);

            return;
        }

        $this->selected[$current['value']] = true;
    }

    public function select(string $value): void
    {
        $this->selected[$value] = true;
    }

    public function selectAllVisible(): void
    {
        foreach ($this->visible as $index) {
            $this->selected[$this->options[$index]['value']] = true;
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function selectedCount(): int
    {
        return count($this->selected);
    }

    /** @return list<string> */
    public function selectedValues(): array
    {
        $values = [];

        foreach ($this->options as $option) {
            if (isset($this->selected[$option['value']])) {
                $values[] = $option['value'];
            }
        }

        return $values;
    }

    private function applyFilter(string $filter): void
    {
        $previous = $this->current();

        $this->filter = $filter;
        $needle = mb_strtolower(trim($filter));
        $this->visible = [];

        foreach ($this->options as $index => $option) {
            if ($needle === '' || str_contains($option['search'], $needle)) {
                $this->visible[] = $index;
            }
        }

        $this->cursor = 0;

        if ($previous !== null) {
            foreach ($this->visible as $position => $index) {
                if ($this->options[$index]['value'] === $previous['value']) {
                    $this->cursor = $position;

                    break;
                }
            }
        }

        $this->offset = 0;
        $this->clampOffset();
    }

    private function clampOffset(): void
    {
        if ($this->cursor < $this->offset) {
            $this->offset = $this->cursor;

            return;
        }

        if ($this->cursor >= $this->offset + $this->height) {
            $this->offset = $this->cursor - $this->height + 1;
        }

        $this->offset = max(0, min($this->offset, max(0, count($this->visible) - $this->height)));
    }
}
