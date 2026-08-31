<?php

namespace hkyss\Extras\Takeover;

use hkyss\Extras\Legacy\ElementType;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * The element tables as a takeover needs them: which rows are there, and one switch per row.
 * Writing an element from a descriptor stays ElementWriter's; this only flips `disabled`.
 */
class SiteElements
{
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
     * Rows whose body mentions the needle. The only way to recognise an element that has been
     * renamed — and the legacy manager's own module is renamed on every site whose manager
     * speaks something other than English.
     *
     * @return list<SiteElement>
     */
    public function including(ElementType $type, string $needle): array
    {
        return $this->read($type, $needle);
    }

    public function disable(SiteElement $element): bool
    {
        return $this->setDisabled($element->type(), $element->id(), true);
    }

    public function enable(ElementType $type, int $id): bool
    {
        return $this->setDisabled($type, $id, false);
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

    private function setDisabled(ElementType $type, int $id, bool $disabled): bool
    {
        if (!$this->db()->table($type->table())->where('id', $id)->exists()) {
            return false;
        }

        $this->db()->table($type->table())
            ->where('id', $id)
            ->update(['disabled' => $disabled ? 1 : 0]);

        return true;
    }

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }
}
