<?php

namespace hkyss\Extras\Tests\Support;

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/** The element tables on an in-memory database, so what writes SQL is tested by running it. */
class SiteTables
{
    private Manager $capsule;

    public function __construct()
    {
        $this->capsule = new Manager();
        $this->capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        foreach (['site_plugins' => 'plugincode', 'site_modules' => 'modulecode', 'site_snippets' => 'snippet'] as $table => $column) {
            $this->schema()->create($table, static function (Blueprint $blueprint) use ($column) {
                $blueprint->increments('id');
                $blueprint->string('name');
                $blueprint->string('description')->default('');
                $blueprint->text($column)->nullable();
                $blueprint->integer('category')->default(0);
                $blueprint->text('properties')->nullable();
                $blueprint->boolean('disabled')->default(false);
            });
        }

        $this->schema()->create('categories', static function (Blueprint $blueprint) {
            $blueprint->increments('id');
            $blueprint->string('category');
        });
    }

    public function connection(): ConnectionInterface
    {
        return $this->capsule->getConnection();
    }

    /** @param array<string,mixed> $row */
    public function insert(string $table, array $row): int
    {
        return (int) $this->connection()->table($table)->insertGetId($row);
    }

    /** @return array<string,mixed> */
    public function row(string $table, int $id): array
    {
        return (array) $this->connection()->table($table)->where('id', $id)->first();
    }

    private function schema(): Builder
    {
        return $this->capsule->getConnection()->getSchemaBuilder();
    }
}
