<?php

namespace hkyss\Extras\Legacy;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/** The previous code is handed back to the caller instead of being overwritten for good. */
class ElementWriter
{
    private ?ConnectionInterface $connection;
    private PropertyMerger $merger;
    /** @var array<string,bool> */
    private array $columnCache = [];

    public function __construct(?ConnectionInterface $connection = null, ?PropertyMerger $merger = null)
    {
        $this->connection = $connection;
        $this->merger = $merger ?? new PropertyMerger();
    }

    /**
     * @return array{action:string,id:int|null,previous_code:string|null,previous_description:string|null,reason:string}
     */
    public function preview(ElementDescriptor $descriptor): array
    {
        $existing = $this->findByName($descriptor);
        $empty = ['id' => null, 'previous_code' => null, 'previous_description' => null, 'reason' => ''];

        if ($existing === null) {
            return ['action' => 'insert'] + $empty;
        }

        if (!$descriptor->mayOverwrite()) {
            return ['action' => 'skip', 'reason' => 'the descriptor declares @overwrite false']
                + $empty
                + ['id' => (int) $existing->id];
        }

        return [
            'action' => 'update',
            'id' => (int) $existing->id,
            'previous_code' => (string) ($existing->{$descriptor->type()->codeColumn()} ?? ''),
            'previous_description' => (string) ($existing->description ?? ''),
            'reason' => '',
        ];
    }

    /** @return array{action:string,id:int|null,previous_code:string|null,previous_description:string|null} */
    public function write(ElementDescriptor $descriptor): array
    {
        $preview = $this->preview($descriptor);

        if ($preview['action'] === 'skip') {
            return [
                'action' => 'skip',
                'id' => $preview['id'],
                'previous_code' => null,
                'previous_description' => null,
            ];
        }

        $categoryId = $this->resolveCategory($descriptor->category());
        $table = $descriptor->type()->table();
        $column = $descriptor->type()->codeColumn();

        $this->disableConflicts($descriptor, $preview['id']);

        if ($preview['action'] === 'update') {
            $existing = $this->findByName($descriptor);

            $this->db()->table($table)->where('id', $preview['id'])->update([
                'description' => $descriptor->description(),
                $column => $this->body($descriptor),
                'properties' => $this->merger->merge(
                    $descriptor->properties(),
                    (string) ($existing->properties ?? '')
                ),
                'category' => $categoryId,
            ]);

            $this->syncEvents($descriptor, (int) $preview['id']);

            unset($preview['reason']);

            return $preview;
        }

        $row = [
            'name' => $descriptor->name(),
            'description' => $descriptor->description(),
            $column => $this->body($descriptor),
            'properties' => $descriptor->properties(),
            'category' => $categoryId,
        ];

        if ($this->hasColumn($table, 'disabled')) {
            $row['disabled'] = $descriptor->isDisabled() ? 1 : 0;
        }

        if ($descriptor->type() === ElementType::Tv) {
            $row += $this->tvColumns($descriptor);
        }

        $id = (int) $this->db()->table($table)->insertGetId($row);
        $this->syncEvents($descriptor, $id);

        return ['action' => 'insert', 'id' => $id, 'previous_code' => null, 'previous_description' => null];
    }

    /** Failures are not swallowed; the caller decides whether the statement counts as applied. */
    public function runStatement(string $sql): void
    {
        $this->db()->statement($sql);
    }

    /** The description goes back with the code: it is where the legacy format keeps the version. */
    public function restore(ElementType $type, int $id, string $code, ?string $description = null): bool
    {
        $columns = [$type->codeColumn() => $code];

        if ($description !== null) {
            $columns['description'] = $description;
        }

        return $this->db()->table($type->table())->where('id', $id)->update($columns) > 0;
    }

    public function delete(ElementType $type, int $id): bool
    {
        if ($type->hasEvents()) {
            $this->db()->table('site_plugin_events')->where('pluginid', $id)->delete();
        }

        return $this->db()->table($type->table())->where('id', $id)->delete() > 0;
    }

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    /** @return object|null */
    private function findByName(ElementDescriptor $descriptor)
    {
        return $this->db()->table($descriptor->type()->table())
            ->where('name', $descriptor->name())
            ->orderBy('id')
            ->first();
    }

    /**
     * A version bump changes the description, so sweeping the row this write is for would switch
     * off the very element that had just been installed.
     */
    private function disableConflicts(ElementDescriptor $descriptor, ?int $written): void
    {
        $table = $descriptor->type()->table();

        if (!$this->hasColumn($table, 'disabled')) {
            return;
        }

        $legacyNames = $descriptor->legacyNames();

        if ($legacyNames !== []) {
            $this->db()->table($table)->whereIn('name', $legacyNames)->update(['disabled' => 1]);
        }

        $conflicts = $this->db()->table($table)
            ->where('name', $descriptor->name())
            ->where('description', '!=', $descriptor->description());

        if ($written !== null) {
            $conflicts->where('id', '!=', $written);
        }

        $conflicts->update(['disabled' => 1]);
    }

    /** Unknown event names are skipped: they belong to a newer core or are a typo. */
    private function syncEvents(ElementDescriptor $descriptor, int $elementId): void
    {
        if (!$descriptor->type()->hasEvents() || $elementId <= 0) {
            return;
        }

        $events = $descriptor->events();

        if ($events === []) {
            return;
        }

        $known = $this->db()->table('system_eventnames')->whereIn('name', $events)->pluck('id', 'name');

        $this->db()->table('site_plugin_events')->where('pluginid', $elementId)->delete();

        foreach ($known as $eventId) {
            $this->db()->table('site_plugin_events')->insert([
                'pluginid' => $elementId,
                'evtid' => (int) $eventId,
                'priority' => 0,
            ]);
        }
    }

    private function resolveCategory(string $category): int
    {
        $category = trim($category);

        if ($category === '') {
            return 0;
        }

        $existing = $this->db()->table('categories')->where('category', $category)->first();

        if ($existing !== null) {
            return (int) $existing->id;
        }

        return (int) $this->db()->table('categories')->insertGetId(['category' => $category]);
    }

    /** Template variables carry no body; their default comes from @input_default. */
    private function body(ElementDescriptor $descriptor): string
    {
        return $descriptor->type() === ElementType::Tv
            ? $descriptor->tag('input_default')
            : $descriptor->code();
    }

    /** @return array<string,mixed> */
    private function tvColumns(ElementDescriptor $descriptor): array
    {
        return [
            'caption' => $descriptor->tag('caption', $descriptor->name()),
            'type' => $descriptor->tag('input_type', 'text'),
            'elements' => $descriptor->tag('input_options'),
            'display' => $descriptor->tag('output_widget'),
            'display_params' => $descriptor->tag('output_widget_params'),
            'locked' => $descriptor->tag('lock_tv') === '1' ? 1 : 0,
        ];
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table . '.' . $column;

        if (!isset($this->columnCache[$key])) {
            $connection = $this->db();

            try {
                $this->columnCache[$key] = $connection instanceof Connection
                    && $connection->getSchemaBuilder()->hasColumn($table, $column);
            } catch (\Throwable) {
                $this->columnCache[$key] = false;
            }
        }

        return $this->columnCache[$key];
    }
}
