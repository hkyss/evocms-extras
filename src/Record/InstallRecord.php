<?php

namespace hkyss\Extras\Record;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $coordinate
 * @property string $format
 * @property string $version
 * @property string $title
 * @property array<string,string>        $files
 * @property array<string,string>        $backups
 * @property list<array<string,mixed>>   $elements
 * @property list<string>                $sql_hashes
 */
class InstallRecord extends Model
{
    public const CREATED_AT = 'installed_at';
    public const UPDATED_AT = 'updated_at';

    protected $table = 'extras_install_records';

    protected $fillable = [
        'coordinate',
        'format',
        'version',
        'title',
        'files',
        'backups',
        'elements',
        'sql_hashes',
    ];

    /** @var array<string,string> */
    protected $casts = [
        'files' => 'array',
        'backups' => 'array',
        'elements' => 'array',
        'sql_hashes' => 'array',
        'installed_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** @return array<string,string> Relative path to its sha256 at install time. */
    public function fileList(): array
    {
        return array_filter((array) ($this->files ?? []), 'is_string');
    }

    /** @return array<string,string> Site path to the path of its backup. */
    public function backupMap(): array
    {
        return array_filter((array) ($this->backups ?? []), 'is_string');
    }

    /** @return list<array<string,mixed>> */
    public function elementList(): array
    {
        return array_values(array_filter((array) ($this->elements ?? []), 'is_array'));
    }

    /** @return list<string> */
    public function appliedSqlHashes(): array
    {
        return array_values(array_filter((array) ($this->sql_hashes ?? []), 'is_string'));
    }
}
