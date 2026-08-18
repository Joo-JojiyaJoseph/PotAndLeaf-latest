<?php

namespace App\Support\Lookup;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Drives the entire CRUD for a simple lookup master. A concrete child only
 * declares what makes it unique: its model, the Inertia page prefix, its
 * permission prefix, validation rules, and how a row is shaped for the client.
 *
 * Simple masters render an index page with an inline modal form, so there are
 * no create/edit pages — everything posts back to store/update.
 */
abstract class LookupController extends Controller
{
    /** @return class-string<Model> */
    abstract protected function model(): string;

    /** Inertia page folder + route name prefix, e.g. "categories". */
    abstract protected function key(): string;

    /** Permission prefix, e.g. "categories" → categories.view / .create … */
    abstract protected function permission(): string;

    /** @return array<string,mixed> */
    abstract protected function rules(Request $request, ?string $id): array;

    /** Shape a model for the table/form. @return array<string,mixed> */
    abstract protected function transform(Model $model): array;

    protected function service(): LookupService
    {
        return new LookupService(new LookupRepository($this->model()));
    }

    public function index(Request $request, Team $current_team): Response
    {
        $this->allow('view');

        $filters = $request->only(['search', 'status', 'sort', 'dir', 'per_page']);
        $paginator = $this->service()->list($current_team->id, $filters);

        return Inertia::render("{$this->key()}/index", [
            'team'    => $current_team->slug,
            'records' => [
                'data' => collect($paginator->items())->map(fn ($m) => $this->transform($m))->all(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                    'total'        => $paginator->total(),
                ],
            ],
            'filters' => $filters,
            'can'     => [
                'create' => $request->user()->hasPermission("{$this->permission()}.create"),
                'update' => $request->user()->hasPermission("{$this->permission()}.update"),
                'delete' => $request->user()->hasPermission("{$this->permission()}.delete"),
            ],
        ]);
    }

    public function store(Request $request, Team $current_team): RedirectResponse
    {
        $this->allow('create');
        $data = $request->validate($this->rules($request, null));
        $this->service()->create($current_team->id, $data);

        return back()->with('success', 'Created.');
    }

    public function update(Request $request, Team $current_team, string $record): RedirectResponse
    {
        $this->allow('update');
        $model = ($this->model())::where('team_id', $current_team->id)->findOrFail($record);
        $data = $request->validate($this->rules($request, $record));
        $this->service()->update($model, $data);

        return back()->with('success', 'Updated.');
    }

    public function destroy(Team $current_team, string $record): RedirectResponse
    {
        $this->allow('delete');
        $model = ($this->model())::where('team_id', $current_team->id)->findOrFail($record);
        $this->service()->delete($model);

        return back()->with('success', 'Moved to trash.');
    }

    public function restore(Team $current_team, string $record): RedirectResponse
    {
        $this->allow('delete');
        $this->service()->restore($current_team->id, $record);

        return back()->with('success', 'Restored.');
    }

    private function allow(string $ability): void
    {
        abort_unless(
            request()->user()?->hasPermission("{$this->permission()}.{$ability}"),
            403,
        );
    }
}
