<?php

namespace hkyss\Extras\Http\Controllers;

use hkyss\Extras\Domain\Coordinate;
use hkyss\Extras\Domain\Extra;
use hkyss\Extras\Exceptions\ExtrasException;
use hkyss\Extras\Installer\Installer;
use hkyss\Extras\Installer\InstallerRegistry;
use hkyss\Extras\Installer\InstallPlan;
use hkyss\Extras\Installer\Intent;
use hkyss\Extras\Installer\LegacyInstaller;
use hkyss\Extras\Installer\PlanStep;
use hkyss\Extras\Manager\CatalogListing;
use hkyss\Extras\Manager\InstalledExtras;
use hkyss\Extras\Manager\ManagerModule;
use hkyss\Extras\Manager\Mutex;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackagesController
{
    private CatalogListing $listing;
    private InstalledExtras $installed;
    private InstallerRegistry $installers;
    private Mutex $mutex;

    public function __construct(
        CatalogListing $listing,
        InstalledExtras $installed,
        InstallerRegistry $installers,
        Mutex $mutex
    ) {
        $this->listing = $listing;
        $this->installed = $installed;
        $this->installers = $installers;
        $this->mutex = $mutex;
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => ['extras' => $this->installed->all()],
        ]);
    }

    /** The one endpoint that waits on the network; the catalog cache carries the next hour. */
    public function catalog(): JsonResponse
    {
        @set_time_limit(0);

        return response()->json(['success' => true, 'data' => $this->listing->all()]);
    }

    public function plan(Request $request, string $vendor, string $package): JsonResponse
    {
        $intent = $this->intent($request);
        $extra = $this->resolve($intent, $vendor, $package);

        if ($extra === null) {
            return $this->unknown($intent);
        }

        $installer = null;

        try {
            $installer = $this->installers->for($extra);
            $plan = $installer->plan($extra, $intent, (string) $request->query('version', ''));
        } catch (ExtrasException $e) {
            return $this->refuse($e->getMessage(), 422);
        } finally {
            $this->discard($installer);
        }

        return response()->json(['success' => true, 'data' => $this->present($plan, $extra)]);
    }

    public function store(Request $request, string $vendor, string $package): JsonResponse
    {
        return $this->apply(
            Intent::Install,
            $vendor,
            $package,
            (string) $request->input('version', ''),
            (bool) $request->input('force', false)
        );
    }

    public function destroy(string $vendor, string $package): JsonResponse
    {
        return $this->apply(Intent::Remove, $vendor, $package, '', false);
    }

    private function apply(Intent $intent, string $vendor, string $package, string $version, bool $force): JsonResponse
    {
        $extra = $this->resolve($intent, $vendor, $package);

        if ($extra === null) {
            return $this->unknown($intent);
        }

        if (!$this->mutex->acquire()) {
            return $this->refuse('Другая установка ещё идёт. Дождитесь, пока она закончится.', 409);
        }

        /** Composer resolves the whole tree, which outlasts the default time limit. */
        @set_time_limit(0);
        ignore_user_abort(true);

        $installer = null;

        try {
            $installer = $this->installers->for($extra);
            $plan = $installer->plan($extra, $intent, $version);

            if ($plan->isForbidden()) {
                return $this->refuse(implode(' ', $plan->forbidden()), 422);
            }

            if ($plan->blockers() !== [] && !$force) {
                return $this->refuse(implode(' ', $plan->blockers()), 422);
            }

            if ($plan->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => ['message' => 'Делать нечего.', 'notes' => [], 'output' => []],
                ]);
            }

            $outcome = $installer->apply($plan);
        } catch (ExtrasException $e) {
            return $this->refuse($e->getMessage(), 422);
        } finally {
            $this->discard($installer);
            $this->mutex->release();
        }

        return response()->json([
            'success' => $outcome->isSuccessful(),
            'data' => [
                'message' => $outcome->message(),
                'notes' => $outcome->notes(),
                'output' => $outcome->output(),
            ],
            'errors' => $outcome->isSuccessful() ? [] : ['message' => $outcome->message()],
        ], $outcome->isSuccessful() ? 200 : 422);
    }

    private function intent(Request $request): Intent
    {
        return $request->query('intent') === Intent::Remove->value ? Intent::Remove : Intent::Install;
    }

    private function resolve(Intent $intent, string $vendor, string $package): ?Extra
    {
        $coordinate = Coordinate::tryParse($vendor . '/' . $package);

        if ($coordinate === null || ManagerModule::isSelf($coordinate)) {
            return null;
        }

        return $intent === Intent::Remove
            ? $this->installed->extraFor($coordinate)
            : $this->listing->installable($coordinate);
    }

    /** A legacy plan downloads and unpacks the archive; a plan nobody applies still has to go. */
    private function discard(?Installer $installer): void
    {
        if ($installer instanceof LegacyInstaller) {
            $installer->discardArchives();
        }
    }

    private function unknown(Intent $intent): JsonResponse
    {
        return $this->refuse(
            $intent === Intent::Remove
                ? 'Пакет не установлен или установлен не этим модулем.'
                : 'Пакета нет в каталоге.',
            404
        );
    }

    /** @return array<string,mixed> */
    private function present(InstallPlan $plan, Extra $extra): array
    {
        return [
            'coordinate' => (string) $plan->coordinate(),
            'format' => $plan->format()->value,
            'intent' => $plan->intent()->value,
            'from' => $plan->fromVersion(),
            'to' => $plan->toVersion(),
            'versions' => $extra->versions(),
            'steps' => array_map(static fn (PlanStep $step) => [
                'kind' => $step->kind()->value,
                'group' => $step->kind()->group(),
                'summary' => $step->summary(),
                'mutates' => $step->kind()->mutates(),
            ], $plan->steps()),
            'warnings' => $plan->warnings(),
            'blockers' => $plan->blockers(),
            'forbidden' => $plan->forbidden(),
            'empty' => $plan->isEmpty(),
        ];
    }

    private function refuse(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'errors' => ['message' => $message],
        ], $status);
    }
}
