<?php

namespace hkyss\Extras\Record;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Takeover\SiteElement;
use hkyss\Extras\Takeover\TakeoverAction;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $coordinate
 * @property string $format
 * @property string $action
 * @property list<array<string,mixed>> $elements
 * @property \Illuminate\Support\Carbon|null $taken_at
 */
class TakeoverRecord extends Model
{
    public const CREATED_AT = 'taken_at';
    public const UPDATED_AT = null;

    protected $table = 'extras_takeover_records';

    protected $fillable = [
        'coordinate',
        'format',
        'action',
        'elements',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'elements' => 'array',
        'taken_at' => 'datetime',
    ];

    /** @return list<SiteElement> */
    public function elementList(): array
    {
        $elements = [];

        foreach (array_filter((array) ($this->elements ?? []), 'is_array') as $row) {
            $element = SiteElement::fromArray($row);

            if ($element !== null) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /** Null where the takeover installed nothing, which is how the legacy manager is recorded. */
    public function installed(): ?Coordinate
    {
        return trim((string) $this->coordinate) === ''
            ? null
            : Coordinate::tryParse((string) $this->coordinate);
    }

    public function format(): ?ExtraFormat
    {
        return ExtraFormat::tryFrom((string) $this->format);
    }

    public function action(): ?TakeoverAction
    {
        return TakeoverAction::tryFrom((string) $this->action);
    }
}
