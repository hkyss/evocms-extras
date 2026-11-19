<?php

namespace hkyss\Extras\Installer;

use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Domain\InstalledState;
use hkyss\Extras\Exceptions\ExtrasException;
use hkyss\Extras\Legacy\ElementType;
use hkyss\Extras\Legacy\ElementWriter;
use hkyss\Extras\Legacy\LegacyArchive;
use hkyss\Extras\Record\InstallRecordStore;
use hkyss\Extras\Support\Http;
use hkyss\Extras\Support\Paths;
use hkyss\Extras\Support\SiteCache;

/** Handles legacy extras, whose format supports no removal and so needs an install record. */
class LegacyInstaller implements Installer, EnumeratesInstalled, HoldsArchives
{
    private const PREVIOUS = ['previous_code', 'previous_description', 'previous_properties', 'previous_category', 'previous_events'];

    private Paths $paths;
    private Http $http;
    private InstallRecordStore $records;
    private ElementWriter $elements;
    private string $installSet;
    /** @var list<string> */
    private array $requireConfirmation;
    private string $tablePrefix;
    private SiteCache $cache;
    /** @var array<string,LegacyArchive> */
    private array $openArchives = [];

    /** @param list<string> $requireConfirmation */
    public function __construct(
        Paths $paths,
        Http $http,
        InstallRecordStore $records,
        ElementWriter $elements,
        string $tablePrefix = '',
        string $installSet = 'base',
        array $requireConfirmation = ['unknown', 'incompatible'],
        ?SiteCache $cache = null
    ) {
        $this->paths = $paths;
        $this->http = $http;
        $this->records = $records;
        $this->elements = $elements;
        $this->tablePrefix = $tablePrefix;
        $this->installSet = $installSet;
        $this->requireConfirmation = $requireConfirmation;
        $this->cache = $cache ?? new SiteCache();
    }

    public function supports(Extra $extra): bool
    {
        return $extra->format() === ExtraFormat::Legacy;
    }

    public function plan(Extra $extra, Intent $intent, string $version = ''): InstallPlan
    {
        if ($intent === Intent::Remove) {
            return $this->planRemoval($extra);
        }

        if (!$this->records->isAvailable()) {
            throw new ExtrasException(
                'The install record table is missing; run "php artisan migrate" first. '
                . 'Without it a legacy extra cannot be removed later.'
            );
        }

        $ref = trim($version) !== '' ? trim($version) : $extra->defaultVersion();
        $state = $this->installedState($extra);

        $plan = new InstallPlan($extra->coordinate(), ExtraFormat::Legacy, $intent, $ref, $state->version());

        $this->guardCompatibility($plan, $extra);

        $archive = $this->archive($extra, $ref);

        if (!$archive->isUsable()) {
            $plan->block(sprintf(
                "'%s'@%s is not a MODX Evolution Package: neither assets/ nor install/assets/ was found",
                $extra->coordinate(),
                $ref
            ));

            return $plan;
        }

        $plan->step(StepKind::RecordWrite, 'archive root: ' . $archive->root(), [
            'archive_root' => $archive->root(),
            'ref' => $ref,
        ]);

        $this->planAssets($plan, $archive, $this->records->find($extra->coordinate())?->fileList() ?? []);
        $this->planElements($plan, $archive);
        $this->planSql($plan, $archive, $extra);

        return $plan;
    }

    public function apply(InstallPlan $plan): Outcome
    {
        return $plan->intent() === Intent::Remove
            ? $this->applyRemoval($plan)
            : $this->applyInstall($plan);
    }

    public function format(): ExtraFormat
    {
        return ExtraFormat::Legacy;
    }

    /** @return array<string, InstalledState> */
    public function installed(): array
    {
        $installed = [];

        foreach ($this->records->all() as $record) {
            $installed[(string) $record->coordinate] = InstalledState::present((string) $record->version);
        }

        return $installed;
    }

    public function installedState(Extra $extra): InstalledState
    {
        return $this->records->stateOf($extra->coordinate());
    }

    public function discardArchives(): void
    {
        foreach ($this->openArchives as $archive) {
            $archive->discard();
        }

        $this->openArchives = [];
    }

    private function guardCompatibility(InstallPlan $plan, Extra $extra): void
    {
        $status = $extra->compatibility();

        if (in_array($status->value, $this->requireConfirmation, true)) {
            $plan->block(sprintf(
                "compatibility of '%s' with Evolution CMS 3 is %s — %s",
                $extra->coordinate(),
                $status->label(),
                $status->explain()
            ));
        }
    }

    /** @param array<string,string> $owned */
    private function planAssets(InstallPlan $plan, LegacyArchive $archive, array $owned): void
    {
        if (!$archive->hasAssets()) {
            return;
        }

        $source = $archive->assetsDir();
        $destination = rtrim($this->paths->base(), '/\\') . DIRECTORY_SEPARATOR . 'assets';
        $backups = $this->paths->backupDir($plan->coordinate());

        foreach ($archive->assetFiles() as $file) {
            $relative = 'assets/' . $file;
            $from = $source . DIRECTORY_SEPARATOR . $file;
            $to = $destination . DIRECTORY_SEPARATOR . $file;
            $hash = (string) hash_file('sha256', $from);
            $copy = ['from' => $from, 'to' => $to, 'relative' => $relative, 'hash' => $hash];

            if (!is_file($to)) {
                $plan->step(StepKind::FileCopy, 'create ' . $relative, $copy);

                continue;
            }

            $current = (string) hash_file('sha256', $to);

            if ($current === $hash) {
                $plan->add($this->keep($relative, $to, $hash, $owned));

                continue;
            }

            if (isset($owned[$relative]) && $owned[$relative] === $current) {
                $plan->step(StepKind::FileCopy, 'update ' . $relative, $copy);

                continue;
            }

            if (isset($owned[$relative])) {
                $plan->step(StepKind::FileKeep, 'keep (modified) ' . $relative, [
                    'to' => $to,
                    'relative' => $relative,
                    'hash' => $owned[$relative],
                ]);
                $plan->warn(sprintf('%s was changed on the site after it was installed and is left as it is', $relative));

                continue;
            }

            $plan->step(StepKind::FileBackup, 'back up ' . $relative, [
                'to' => $to,
                'backup' => $backups . $relative,
                'relative' => $relative,
            ]);

            $plan->step(StepKind::FileCopy, 'overwrite ' . $relative, $copy);
        }
    }

    /** @param array<string,string> $owned */
    private function keep(string $relative, string $to, string $hash, array $owned): PlanStep
    {
        if (!isset($owned[$relative])) {
            return new PlanStep(StepKind::FileKeep, 'already on the site ' . $relative, ['to' => $to, 'relative' => $relative]);
        }

        return new PlanStep(StepKind::FileKeep, 'unchanged ' . $relative, ['to' => $to, 'relative' => $relative, 'hash' => $hash]);
    }

    private function planElements(InstallPlan $plan, LegacyArchive $archive): void
    {
        foreach ($archive->descriptors() as $descriptor) {
            if (!$descriptor->belongsToInstallSet($this->installSet)) {
                continue;
            }

            $preview = $this->elements->preview($descriptor);
            $label = $descriptor->type()->singular() . ' ' . $descriptor->name();

            if ($preview['action'] === 'skip') {
                $plan->step(StepKind::ElementSkip, "keep {$label} ({$preview['reason']})", [
                    'type' => $descriptor->type()->value,
                    'name' => $descriptor->name(),
                    'reason' => $preview['reason'],
                ]);

                $plan->warn(sprintf('%s is left untouched: %s', $label, $preview['reason']));

                continue;
            }

            $plan->step(
                StepKind::ElementUpsert,
                ($preview['action'] === 'insert' ? 'create ' : 'update ') . $label,
                [
                    'type' => $descriptor->type()->value,
                    'name' => $descriptor->name(),
                    'action' => $preview['action'],
                    'events' => $descriptor->events(),
                ]
            );
        }
    }

    private function planSql(InstallPlan $plan, LegacyArchive $archive, Extra $extra): void
    {
        $script = $archive->sqlScript($this->tablePrefix);

        if ($script === null || $script->isEmpty()) {
            return;
        }

        $applied = $this->records->find($extra->coordinate())?->appliedSqlHashes() ?? [];
        $pending = $script->pending($applied);

        if ($pending === [] && $applied !== []) {
            $plan->warn(sprintf(
                'setup.data.sql holds %d statement(s), all already applied',
                count($script->statements())
            ));

            return;
        }

        foreach ($pending as $statement) {
            $plan->step(StepKind::SqlApply, 'sql: ' . $statement->summary(), [
                'sql' => $statement->sql(),
                'hash' => $statement->hash(),
            ]);
        }
    }

    private function planRemoval(Extra $extra): InstallPlan
    {
        $coordinate = $extra->coordinate();
        $record = $this->records->find($coordinate);

        $plan = new InstallPlan(
            $coordinate,
            ExtraFormat::Legacy,
            Intent::Remove,
            '',
            $record !== null ? (string) $record->version : ''
        );

        if ($record === null) {
            $plan->block(sprintf(
                "no install record for '%s'; it was not installed by this tool and cannot be removed safely",
                $coordinate
            ));

            return $plan;
        }

        $base = rtrim($this->paths->base(), '/\\') . DIRECTORY_SEPARATOR;
        $backups = $record->backupMap();
        $kept = 0;

        foreach ($record->fileList() as $relative => $hash) {
            $path = $base . $relative;

            if (!is_file($path)) {
                continue;
            }

            if (hash_file('sha256', $path) !== $hash) {
                $plan->step(StepKind::FileKeep, 'keep (modified) ' . $relative, ['path' => $path]);
                $kept++;

                continue;
            }

            $plan->step(StepKind::FileDelete, 'delete ' . $relative, [
                'path' => $path,
                'restore' => $backups[$relative] ?? null,
            ]);
        }

        if ($kept > 0) {
            $plan->warn(sprintf('%d file(s) differ from what was installed and will be left in place', $kept));
        }

        foreach ($record->elementList() as $element) {
            $type = ElementType::tryFrom((string) ($element['type'] ?? ''));
            $id = (int) ($element['id'] ?? 0);

            if ($type === null || $id <= 0) {
                continue;
            }

            $label = $type->singular() . ' ' . (string) ($element['name'] ?? '');

            if (($element['action'] ?? '') === 'insert') {
                $plan->step(StepKind::ElementDelete, 'delete ' . $label, [
                    'type' => $type->value,
                    'id' => $id,
                    'name' => (string) ($element['name'] ?? ''),
                ]);

                continue;
            }

            $plan->step(StepKind::ElementUpsert, 'restore ' . $label . ' to what it was', [
                'type' => $type->value,
                'id' => $id,
                'name' => (string) ($element['name'] ?? ''),
                'action' => 'restore',
                'previous_code' => (string) ($element['previous_code'] ?? ''),
                'previous_description' => $element['previous_description'] ?? null,
                'previous_properties' => $element['previous_properties'] ?? null,
                'previous_category' => $element['previous_category'] ?? null,
                'previous_events' => $element['previous_events'] ?? null,
            ]);
        }

        if ($record->appliedSqlHashes() !== []) {
            $plan->warn(sprintf(
                '%d SQL statement(s) were applied at install time and cannot be reverted; '
                . 'tables and columns created by this extra stay in the database',
                count($record->appliedSqlHashes())
            ));
        }

        $plan->step(StepKind::RecordDelete, 'forget ' . $coordinate, ['coordinate' => (string) $coordinate]);

        return $plan;
    }

    /**
     * A step that fails stops the install, and what had already landed is still recorded, so that
     * a removal can take it back.
     */
    private function applyInstall(InstallPlan $plan): Outcome
    {
        $files = [];
        $backups = [];
        $elements = [];
        $sqlHashes = [];
        $failure = null;

        foreach ($plan->steps() as $step) {
            $failure = match ($step->kind()) {
                StepKind::FileBackup => $this->backUp($step, $backups),
                StepKind::FileCopy => $this->copy($step, $files),
                StepKind::FileKeep => $this->own($step, $files),
                StepKind::ElementUpsert => $this->upsert($step, $plan, $elements),
                StepKind::SqlApply => $this->execute($step, $sqlHashes),
                default => null,
            };

            if ($failure !== null) {
                break;
            }
        }

        $previous = $this->records->find($plan->coordinate());

        $this->records->put($plan->coordinate(), ExtraFormat::Legacy->value, [
            'version' => $failure !== null && $previous !== null ? (string) $previous->version : $plan->toVersion(),
            'files' => $files + ($previous?->fileList() ?? []),
            'backups' => ($previous?->backupMap() ?? []) + $backups,
            'elements' => $this->mergeElements($plan, $elements),
            'sql_hashes' => $this->mergeSqlHashes($plan, $sqlHashes),
        ]);

        $this->discardArchives();
        $this->cache->clear();

        $done = sprintf(
            '%d file(s), %d element(s), %d statement(s)',
            count($files),
            count($elements),
            count($sqlHashes)
        );

        if ($failure !== null) {
            return Outcome::failure(
                sprintf('%s stopped: %s', $plan->coordinate(), $failure),
                [sprintf('Applied before it stopped, and recorded so that a removal takes it back: %s', $done)]
            );
        }

        return Outcome::success(sprintf(
            '%s %s: %s',
            $plan->coordinate(),
            $plan->intent() === Intent::Update ? 'updated' : 'installed',
            $done
        ));
    }

    /** @param array<string,string> $backups */
    private function backUp(PlanStep $step, array &$backups): ?string
    {
        $target = (string) $step->get('to');
        $backup = (string) $step->get('backup');

        if (!$this->makeDirectory(dirname($backup)) || !@copy($target, $backup)) {
            return "cannot back up '{$target}' to '{$backup}'";
        }

        $backups[(string) $step->get('relative')] = $backup;

        return null;
    }

    /** @param array<string,string> $files */
    private function copy(PlanStep $step, array &$files): ?string
    {
        $to = (string) $step->get('to');

        if (!$this->makeDirectory(dirname($to)) || !@copy((string) $step->get('from'), $to)) {
            return "cannot write '{$to}'";
        }

        $files[(string) $step->get('relative')] = (string) $step->get('hash');

        return null;
    }

    /** @param array<string,string> $files */
    private function own(PlanStep $step, array &$files): null
    {
        if ($step->get('hash') !== null) {
            $files[(string) $step->get('relative')] = (string) $step->get('hash');
        }

        return null;
    }

    /** @param list<array<string,mixed>> $elements */
    private function upsert(PlanStep $step, InstallPlan $plan, array &$elements): ?string
    {
        $label = (string) $step->get('type') . ' ' . (string) $step->get('name');

        try {
            $result = $this->writeElement($step, $plan);
        } catch (\Throwable $e) {
            return sprintf('cannot write %s: %s', $label, $e->getMessage());
        }

        if ($result === null) {
            return sprintf('cannot write %s: the archive no longer holds it', $label);
        }

        $elements[] = $result;

        return null;
    }

    /** @param list<string> $sqlHashes */
    private function execute(PlanStep $step, array &$sqlHashes): ?string
    {
        try {
            $this->elements->runStatement((string) $step->get('sql'));
        } catch (\Throwable $e) {
            return 'setup.data.sql failed: ' . $e->getMessage();
        }

        $sqlHashes[] = (string) $step->get('hash');

        return null;
    }

    private function makeDirectory(string $directory): bool
    {
        return is_dir($directory) || @mkdir($directory, 0775, true) || is_dir($directory);
    }

    private function applyRemoval(InstallPlan $plan): Outcome
    {
        $deleted = 0;
        $restored = 0;
        $pruned = 0;
        $notes = [];

        foreach ($plan->stepsOf(StepKind::FileDelete) as $step) {
            $path = (string) $step->get('path');
            $backup = $step->get('restore');

            if (!@unlink($path)) {
                $notes[] = 'Could not delete ' . $path;
                continue;
            }

            $deleted++;

            if (is_string($backup) && is_file($backup) && @copy($backup, $path)) {
                @unlink($backup);
                $this->paths->pruneEmptyDirectories($backup, $this->paths->core() . 'storage');
                $restored++;

                continue;
            }

            $pruned += $this->paths->pruneEmptyDirectories($path, $this->paths->base());
        }

        if ($pruned > 0) {
            $notes[] = sprintf('%d empty directory(s) removed with them', $pruned);
        }

        foreach ($plan->stepsOf(StepKind::ElementDelete) as $step) {
            $type = ElementType::tryFrom((string) $step->get('type'));

            if ($type !== null) {
                $this->elements->delete($type, (int) $step->get('id'));
            }
        }

        foreach ($plan->stepsOf(StepKind::ElementUpsert) as $step) {
            if ($step->get('action') !== 'restore') {
                continue;
            }

            $type = ElementType::tryFrom((string) $step->get('type'));

            if ($type !== null) {
                $description = $step->get('previous_description');
                $properties = $step->get('previous_properties');
                $category = $step->get('previous_category');

                $this->elements->restore(
                    $type,
                    (int) $step->get('id'),
                    (string) $step->get('previous_code'),
                    is_string($description) ? $description : null,
                    is_string($properties) ? $properties : null,
                    is_int($category) ? $category : null,
                    self::events($step->get('previous_events'))
                );
                $restored++;
            }
        }

        foreach ($plan->stepsOf(StepKind::FileKeep) as $step) {
            $notes[] = 'left in place: ' . (string) $step->get('path');
        }

        $this->records->forget($plan->coordinate());

        // The elements are gone from the tables and still in the compiled cache, where the
        // next request would evaluate one whose files left with it.
        $this->cache->clear();

        return Outcome::success(
            sprintf('%s removed (%d file(s) deleted, %d restored)', $plan->coordinate(), $deleted, $restored),
            $notes
        );
    }

    /** @return list<array{evtid:int,priority:int}>|null */
    private static function events(mixed $recorded): ?array
    {
        if (!is_array($recorded)) {
            return null;
        }

        $events = [];

        foreach ($recorded as $event) {
            if (is_array($event) && isset($event['evtid'], $event['priority'])) {
                $events[] = ['evtid' => (int) $event['evtid'], 'priority' => (int) $event['priority']];
            }
        }

        return $events;
    }

    /** @return array<string,mixed>|null */
    private function writeElement(PlanStep $step, InstallPlan $plan): ?array
    {
        $archive = $this->archiveOf($plan);

        if ($archive === null) {
            return null;
        }

        foreach ($archive->descriptors() as $descriptor) {
            if ($descriptor->type()->value !== $step->get('type') || $descriptor->name() !== $step->get('name')) {
                continue;
            }

            $written = $this->elements->write($descriptor);

            return [
                'type' => $descriptor->type()->value,
                'name' => $descriptor->name(),
                'id' => $written['id'],
                'action' => $written['action'],
                'previous_code' => $written['previous_code'],
                'previous_description' => $written['previous_description'],
                'previous_properties' => $written['previous_properties'],
                'previous_category' => $written['previous_category'],
                'previous_events' => $written['previous_events'],
            ];
        }

        return null;
    }

    /**
     * @param list<array<string,mixed>> $fresh
     * @return list<array<string,mixed>>
     */
    private function mergeElements(InstallPlan $plan, array $fresh): array
    {
        $record = $this->records->find($plan->coordinate());
        $merged = [];

        foreach ($record?->elementList() ?? [] as $previous) {
            $merged[(string) ($previous['type'] ?? '') . '/' . (string) ($previous['name'] ?? '')] = $previous;
        }

        foreach ($fresh as $element) {
            $key = (string) $element['type'] . '/' . (string) $element['name'];

            foreach (self::PREVIOUS as $before) {
                if (isset($merged[$key]) && ($merged[$key][$before] ?? null) !== null) {
                    $element[$before] = $merged[$key][$before];
                }
            }

            if (isset($merged[$key]) && ($merged[$key]['action'] ?? '') === 'insert') {
                $element['action'] = 'insert';
            }

            $merged[$key] = $element;
        }

        return array_values($merged);
    }

    /**
     * @param list<string> $fresh
     * @return list<string>
     */
    private function mergeSqlHashes(InstallPlan $plan, array $fresh): array
    {
        $record = $this->records->find($plan->coordinate());

        return array_values(array_unique(array_merge($record?->appliedSqlHashes() ?? [], $fresh)));
    }

    private function archive(Extra $extra, string $ref): LegacyArchive
    {
        $key = $extra->coordinate()->key() . '@' . $ref;

        if (!isset($this->openArchives[$key])) {
            $this->openArchives[$key] = LegacyArchive::fetch($extra->coordinate(), $ref, $this->http);
        }

        return $this->openArchives[$key];
    }

    private function archiveOf(InstallPlan $plan): ?LegacyArchive
    {
        foreach ($plan->stepsOf(StepKind::RecordWrite) as $step) {
            $root = $step->get('archive_root');

            if (is_string($root) && is_dir($root)) {
                return LegacyArchive::opened($root);
            }
        }

        return null;
    }
}
