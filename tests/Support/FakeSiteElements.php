<?php

namespace hkyss\Extras\Tests\Support;

use hkyss\Extras\Legacy\ElementType;
use hkyss\Extras\Takeover\SiteElement;
use hkyss\Extras\Takeover\SiteElements;

/** The element tables as a set of rows, for the tests that are about deciding, not about SQL. */
class FakeSiteElements extends SiteElements
{
    /** @var list<string> */
    public array $switchedOff = [];
    /** @var list<string> */
    public array $switchedOn = [];
    /** @var list<string> */
    public array $aside = [];
    /** @var list<string> */
    public array $named = [];

    /** @var array<string, list<SiteElement>> */
    private array $rows = [];
    /** @var list<SiteElement> */
    private array $managers;
    /** @var list<int> */
    private array $missing;

    /**
     * @param list<SiteElement> $rows
     * @param list<SiteElement> $managers
     * @param list<int>         $missing
     */
    public function __construct(array $rows = [], array $managers = [], array $missing = [])
    {
        parent::__construct();

        foreach ($rows as $row) {
            $this->rows[$row->type()->value][] = $row;
        }

        $this->managers = $managers;
        $this->missing = $missing;
    }

    /** @return list<SiteElement> */
    public function listing(ElementType $type): array
    {
        return $this->rows[$type->value] ?? [];
    }

    /** @return list<SiteElement> */
    public function including(ElementType $type, string $needle): array
    {
        return $type === ElementType::Module ? $this->managers : [];
    }

    public function disable(SiteElement $element): bool
    {
        if (in_array($element->id(), $this->missing, true)) {
            return false;
        }

        $this->switchedOff[] = $element->key();

        return true;
    }

    public function setAside(SiteElement $element, string $suffix): bool
    {
        if (!$this->disable($element)) {
            return false;
        }

        $this->aside[] = $element->name() . $suffix;

        return true;
    }

    public function restore(ElementType $type, int $id, string $name): bool
    {
        if (in_array($id, $this->missing, true)) {
            return false;
        }

        $this->switchedOn[] = $type->value . '/' . $id;
        $this->named[] = $name;

        return true;
    }
}
