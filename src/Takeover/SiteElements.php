<?php

namespace hkyss\Extras\Takeover;

use hkyss\Extras\Legacy\ElementType;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class SiteElements
{
    /** What every element table gives a name, and what a set-aside one has to fit into. */
    private const NAME_LENGTH = 50;

    private ?ConnectionInterface $connection;

    public function __construct(?ConnectionInterface $connection = null)
    {
        $this->connection = $connection;
    }

    /** @return list<SiteElement> */
    public function listing(ElementType $type): array
    {
        return $this->read($type, null);
    }

    /**
     * The only way to recognise an element that has been renamed, which the legacy manager's own
     * module is on every site whose manager speaks something other than English.
     *
     * @return list<SiteElement>
     */
    public function including(ElementType $type, string $needle): array
    {
        return $this->read($type, $needle);
    }

    public function disable(SiteElement $element): bool
    {
        return $this->write($element->type(), $element->id(), ['disabled' => 1]);
    }

    /**
     * An installer of the legacy format finds its elements by name and would write straight over
     * this row, so setting it aside is what leaves the site with both copies.
     */
    public function setAside(SiteElement $element, string $suffix): bool
    {
        $name = $this->asideName($element->name(), $suffix);

        if ($name === '' || $this->taken($element->type(), $name, $element->id())) {
            return false;
        }

        return $this->write($element->type(), $element->id(), ['name' => $name, 'disabled' => 1]);
    }

    /** Back under its own name and on again, whichever of the two a takeover had changed. */
    public function restore(ElementType $type, int $id, string $name): bool
    {
        return $this->write($type, $id, ['name' => $name, 'disabled' => 0]);
    }

    /** @return list<SiteElement> */
    private function read(ElementType $type, ?string $needle): array
    {
        $query = $this->db()->table($type->table())
            ->select('id', 'name', 'description', 'disabled')
            ->orderBy('id');

        if ($needle !== null) {
            $query->where($type->codeColumn(), 'like', '%' . $needle . '%');
        }

        $elements = [];

        foreach ($query->get() as $row) {
            $elements[] = new SiteElement(
                $type,
                (int) $row->id,
                (string) $row->name,
                SiteElement::versionIn((string) ($row->description ?? '')),
                (bool) ($row->disabled ?? false)
            );
        }

        return $elements;
    }

    /** @param array<string,mixed> $columns */
    private function write(ElementType $type, int $id, array $columns): bool
    {
        if (!$this->db()->table($type->table())->where('id', $id)->exists()) {
            return false;
        }

        $this->db()->table($type->table())->where('id', $id)->update($columns);

        return true;
    }

    /** The column holds 50 characters, and the suffix is the half that has to survive. */
    private function asideName(string $name, string $suffix): string
    {
        $room = self::NAME_LENGTH - mb_strlen($suffix);

        return $room < 1 ? '' : mb_substr($name, 0, $room) . $suffix;
    }

    private function taken(ElementType $type, string $name, int $except): bool
    {
        return $this->db()->table($type->table())
            ->where('name', $name)
            ->where('id', '!=', $except)
            ->exists();
    }

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
