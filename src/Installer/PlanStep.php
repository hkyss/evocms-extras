<?php

namespace hkyss\Extras\Installer;

final class PlanStep
{
    private StepKind $kind;
    private string $summary;
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(StepKind $kind, string $summary, array $data = [])
    {
        $this->kind = $kind;
        $this->summary = $summary;
        $this->data = $data;
    }

    public function kind(): StepKind
    {
        return $this->kind;
    }

    public function summary(): string
    {
        return $this->summary;
    }

    /** @return array<string,mixed> */
    public function data(): array
    {
        return $this->data;
    }

    /** @return mixed */
    public function get(string $key, mixed $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'summary' => $this->summary,
            'data' => $this->data,
        ];
    }
}
