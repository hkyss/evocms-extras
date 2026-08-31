<?php

namespace hkyss\Extras\Takeover;

/** Everything a takeover would do to the site, assembled before anything changes. */
final class TakeoverPlan
{
    /** @var list<TakeoverStep> */
    private array $steps;

    /** @param list<TakeoverStep> $steps */
    public function __construct(array $steps = [])
    {
        $this->steps = $steps;
    }

    /** @return list<TakeoverStep> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @return list<TakeoverStep> */
    public function of(TakeoverAction ...$actions): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn (TakeoverStep $step) => in_array($step->action(), $actions, true)
        ));
    }

    /** @return list<TakeoverStep> */
    public function actionable(): array
    {
        return $this->of(TakeoverAction::Retire, TakeoverAction::Replace, TakeoverAction::Adopt);
    }

    public function isEmpty(): bool
    {
        return $this->actionable() === [];
    }
}
