<?php

namespace hkyss\Extras\Takeover;

use hkyss\Extras\Catalog\Catalog;
use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Domain\ExtraFormat;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Installer\PlatformCheck;
use hkyss\Extras\Legacy\ElementType;
use hkyss\Extras\Record\InstallRecordStore;

/**
 * What the site is running that this package does not manage yet, and what to do about each of
 * them. Reads the site and the catalog; changes neither.
 */
class TakeoverPlanner
{
    /** The legacy manager is renamed on every site whose manager speaks something else. */
    private const LEGACY_MANAGER = 'assets/modules/store/core.php';

    /** The three that execute code. A template or a chunk switched off is a page that stops working. */
    private const SCOPE = [ElementType::Plugin, ElementType::Module, ElementType::Snippet];

    private Catalog $catalog;
    private SiteElements $site;
    private InstallRecordStore $records;
    private InstallerRegistry $installers;
    /** @var array<string,string> */
    private array $replacements;
    /** @var list<string> */
    private array $ignored;
    /** @var array<string,Extra>|null */
    private ?array $index = null;

    /**
     * @param array<string,string> $replacements element name to the coordinate that supersedes it
     * @param list<string>         $ignored      element names a takeover leaves alone
     */
    public function __construct(
        Catalog $catalog,
        SiteElements $site,
        InstallRecordStore $records,
        InstallerRegistry $installers,
        array $replacements = [],
        array $ignored = []
    ) {
        $this->catalog = $catalog;
        $this->site = $site;
        $this->records = $records;
        $this->installers = $installers;
        $this->replacements = $replacements;
        $this->ignored = $ignored;
    }

    public function plan(): TakeoverPlan
    {
        $steps = [];
        $skipped = [];
        $candidates = [];
        $manager = $this->legacyManager();

        foreach ($manager as $module) {
            $steps[] = TakeoverStep::retire($module, 'this package is the manager now');
        }

        foreach ($this->loose($this->keysOf($manager)) as $element) {
            $refusal = $this->refuse($element);

            if ($refusal !== null) {
                $skipped[] = TakeoverStep::skip($element, $refusal);

                continue;
            }

            $extra = $this->match($element);

            if ($extra === null) {
                $skipped[] = TakeoverStep::skip($element, 'nothing in the catalog answers to the name');

                continue;
            }

            $key = $extra->coordinate()->key();
            $candidates[$key]['extra'] = $extra;
            $candidates[$key]['elements'][] = $element;
        }

        ksort($candidates);

        foreach ($candidates as $candidate) {
            $steps[] = $this->step($candidate['extra'], $candidate['elements']);
        }

        return new TakeoverPlan(array_merge($steps, $skipped));
    }

    /** @return list<SiteElement> The rows the legacy manager runs from, disabled ones aside. */
    private function legacyManager(): array
    {
        return array_values(array_filter(
            $this->site->including(ElementType::Module, self::LEGACY_MANAGER),
            static fn (SiteElement $module) => !$module->isDisabled()
        ));
    }

    /**
     * Rows nothing here owns yet. A disabled one is left where it is: somebody switched it off,
     * and a takeover is not the place to decide it should come back.
     *
     * @param array<string,bool> $spokenFor
     * @return list<SiteElement>
     */
    private function loose(array $spokenFor): array
    {
        $owned = $this->owned();
        $loose = [];

        foreach (self::SCOPE as $type) {
            foreach ($this->site->listing($type) as $element) {
                $key = $element->key();

                if ($element->isDisabled() || isset($spokenFor[$key]) || isset($owned[$key])) {
                    continue;
                }

                $loose[] = $element;
            }
        }

        return $loose;
    }

    /** Why this row is not a candidate, or null when it is one. */
    private function refuse(SiteElement $element): ?string
    {
        foreach ($this->ignored as $name) {
            if (mb_strtolower($name) === mb_strtolower($element->name())) {
                return 'the configuration keeps it out of a takeover';
            }
        }

        // Every installer of the legacy format writes the version in bold ahead of the
        // description; an element typed into the manager by hand carries none.
        return $element->version() === ''
            ? 'no package wrote it: its description carries no version'
            : null;
    }

    /** @param list<SiteElement> $elements */
    private function step(Extra $extra, array $elements): TakeoverStep
    {
        if ($this->installers->stateOf($extra)->isInstalled()) {
            return TakeoverStep::retire(
                $elements[0],
                sprintf('%s is installed and does its job', $extra->coordinate())
            );
        }

        $unmet = $this->unmet($extra);

        if ($unmet !== null) {
            return TakeoverStep::skip($elements[0], $unmet);
        }

        return $extra->format() === ExtraFormat::Legacy
            ? TakeoverStep::adopt($extra, $elements, $this->versionOn($extra, $elements))
            : TakeoverStep::replace($extra, $elements);
    }

    /**
     * A takeover is one thing that has to work end to end, so a replacement this installation
     * could not install is left out of the plan rather than failing it: Composer would refuse
     * for the same reason, and the row it would have replaced is better off running.
     */
    private function unmet(Extra $extra): ?string
    {
        $php = PlatformCheck::unmetPhpRequirement($extra->requires());

        if ($php !== null) {
            return sprintf('%s %s', $extra->coordinate(), $php);
        }

        foreach (PlatformCheck::missingExtensions($extra->requires()) as $extension) {
            return sprintf('%s needs the %s extension, which this PHP does not load', $extra->coordinate(), $extension);
        }

        return null;
    }

    /**
     * A legacy extra is adopted at the version the site is already running, where the catalog
     * publishes that one: taking a site off the version it chose is an update, not a takeover.
     *
     * @param list<SiteElement> $elements
     */
    private function versionOn(Extra $extra, array $elements): string
    {
        foreach ($elements as $element) {
            if (in_array($element->version(), $extra->versions(), true)) {
                return $element->version();
            }
        }

        return '';
    }

    private function match(SiteElement $element): ?Extra
    {
        $name = mb_strtolower($element->name());

        foreach ($this->replacements as $from => $coordinate) {
            if (mb_strtolower((string) $from) !== $name) {
                continue;
            }

            $parsed = Coordinate::tryParse((string) $coordinate);

            return $parsed === null ? null : $this->catalog->find($parsed);
        }

        return $this->index()[$name] ?? null;
    }

    /**
     * The catalog by every name a site could call an extra: its title, and the second half of
     * its coordinate. Source order decides a collision, the way the catalog itself does.
     *
     * @return array<string,Extra>
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $index = [];

        foreach ($this->catalog->all() as $extra) {
            foreach ([$extra->title(), $extra->coordinate()->name()] as $name) {
                $key = mb_strtolower(trim($name));

                if ($key !== '' && !isset($index[$key])) {
                    $index[$key] = $extra;
                }
            }
        }

        return $this->index = $index;
    }

    /**
     * Elements written by an install this package has a record of. They are already managed;
     * a takeover has nothing to add to them.
     *
     * @return array<string,bool>
     */
    private function owned(): array
    {
        $owned = [];

        foreach ($this->records->all() as $record) {
            foreach ($record->elementList() as $element) {
                $type = ElementType::tryFrom((string) ($element['type'] ?? ''));

                if ($type !== null) {
                    $owned[$type->value . '/' . mb_strtolower((string) ($element['name'] ?? ''))] = true;
                }
            }
        }

        return $owned;
    }

    /**
     * @param list<SiteElement> $elements
     * @return array<string,bool>
     */
    private function keysOf(array $elements): array
    {
        $keys = [];

        foreach ($elements as $element) {
            $keys[$element->key()] = true;
        }

        return $keys;
    }
}
