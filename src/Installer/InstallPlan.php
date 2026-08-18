<?php

namespace hkyss\Extras\Installer;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\ExtraFormat;

/** Everything that will happen to the site, assembled before anything changes. */
final class InstallPlan
{
    private Coordinate $coordinate;
    private ExtraFormat $format;
    private Intent $intent;
    private string $fromVersion;
    private string $toVersion;
    /** @var list<PlanStep> */
    private array $steps = [];
    /** @var list<string> */
    private array $warnings = [];
    /** @var list<string> */
    private array $blockers = [];
    /** @var list<string> */
    private array $forbidden = [];

    public function __construct(
        Coordinate $coordinate,
        ExtraFormat $format,
        Intent $intent,
        string $toVersion = '',
        string $fromVersion = ''
    ) {
        $this->coordinate = $coordinate;
        $this->format = $format;
        $this->intent = $intent;
        $this->toVersion = $toVersion;
        $this->fromVersion = $fromVersion;
    }

    public function add(PlanStep $step): self
    {
        $this->steps[] = $step;

        return $this;
    }

    /** @param array<string,mixed> $data */
    public function step(StepKind $kind, string $summary, array $data = []): self
    {
        return $this->add(new PlanStep($kind, $summary, $data));
    }

    public function warn(string $message): self
    {
        if (!in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }

        return $this;
    }

    public function block(string $reason): self
    {
        if (!in_array($reason, $this->blockers, true)) {
            $this->blockers[] = $reason;
        }

        return $this;
    }

    public function forbid(string $reason): self
    {
        if (!in_array($reason, $this->forbidden, true)) {
            $this->forbidden[] = $reason;
        }

        return $this;
    }

    public function coordinate(): Coordinate
    {
        return $this->coordinate;
    }

    public function format(): ExtraFormat
    {
        return $this->format;
    }

    public function intent(): Intent
    {
        return $this->intent;
    }

    public function fromVersion(): string
    {
        return $this->fromVersion;
    }

    public function toVersion(): string
    {
        return $this->toVersion;
    }

    /** @return list<PlanStep> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return list<PlanStep> */
    public function stepsOf(StepKind ...$kinds): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn (PlanStep $s) => in_array($s->kind(), $kinds, true)
        ));
    }

    /** @return list<string> */
    public function warnings(): array
    {
        return $this->warnings;
    }

    /** @return list<string> */
    public function blockers(): array
    {
        return $this->blockers;
    }

    public function isBlocked(): bool
    {
        return $this->blockers !== [] || $this->forbidden !== [];
    }

    /** @return list<string> */
    public function forbidden(): array
    {
        return $this->forbidden;
    }

    public function isForbidden(): bool
    {
        return $this->forbidden !== [];
    }

    public function isEmpty(): bool
    {
        foreach ($this->steps as $step) {
            if ($step->kind()->mutates()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,int> */
    public function tally(): array
    {
        $tally = [];

        foreach ($this->steps as $step) {
            $key = $step->kind()->value;
            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }

        return $tally;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'coordinate' => (string) $this->coordinate,
            'format' => $this->format->value,
            'intent' => $this->intent->value,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'steps' => array_map(static fn (PlanStep $s) => $s->toArray(), $this->steps),
            'warnings' => $this->warnings,
            'blockers' => $this->blockers,
            'forbidden' => $this->forbidden,
        ];
    }
}
