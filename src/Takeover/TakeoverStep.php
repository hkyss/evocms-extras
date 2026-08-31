<?php

namespace hkyss\Extras\Takeover;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;

/** One extra, and every row on the site that belongs to it. */
final class TakeoverStep
{
    private TakeoverAction $action;
    private ?Extra $extra;
    private string $version;
    /** @var list<SiteElement> */
    private array $elements;
    private string $reason;

    /** @param list<SiteElement> $elements */
    private function __construct(
        TakeoverAction $action,
        ?Extra $extra,
        array $elements,
        string $version = '',
        string $reason = ''
    ) {
        $this->action = $action;
        $this->extra = $extra;
        $this->elements = $elements;
        $this->version = $version;
        $this->reason = $reason;
    }

    public static function retire(SiteElement $element, string $reason): self
    {
        return new self(TakeoverAction::Retire, null, [$element], '', $reason);
    }

    /** @param list<SiteElement> $elements */
    public static function replace(Extra $extra, array $elements): self
    {
        return new self(TakeoverAction::Replace, $extra, $elements);
    }

    /** @param list<SiteElement> $elements */
    public static function adopt(Extra $extra, array $elements, string $version = ''): self
    {
        return new self(TakeoverAction::Adopt, $extra, $elements, $version);
    }

    public static function skip(SiteElement $element, string $reason): self
    {
        return new self(TakeoverAction::Skip, null, [$element], '', $reason);
    }

    public function action(): TakeoverAction
    {
        return $this->action;
    }

    public function extra(): ?Extra
    {
        return $this->extra;
    }

    public function coordinate(): ?Coordinate
    {
        return $this->extra?->coordinate();
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return list<SiteElement> */
    public function elements(): array
    {
        return $this->elements;
    }

    /** @return list<SiteElement> What a restore has to put back under its own name and switch on. */
    public function disabled(): array
    {
        return $this->action->disables() ? $this->elements : [];
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function summary(): string
    {
        $rows = implode(', ', array_map(static fn (SiteElement $e) => $e->label(), $this->elements));
        $coordinate = (string) $this->coordinate() . ($this->version !== '' ? '@' . $this->version : '');

        return match ($this->action) {
            TakeoverAction::Retire => 'switch off ' . $rows . ' — ' . $this->reason,
            TakeoverAction::Replace => 'switch off ' . $rows . ' and install ' . $coordinate,
            TakeoverAction::Adopt => 'set ' . $rows . ' aside and install ' . $coordinate,
            TakeoverAction::Skip => 'leave ' . $rows . ' alone — ' . $this->reason,
        };
    }
}
