<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Concerns\FiltersLists;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ArabicSearch;
use App\Support\Lists\ListSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** doc 08 §7 — GET /admin/audit-logs. Global-scope admins only. */
class AuditLogController extends Controller
{
    use FiltersLists;

    public function index(Request $request): JsonResponse
    {
        $params = $this->listParams($request);
        $sort = $params->sort(['created_at'], '-created_at');

        $query = AuditLog::query()->with('user');

        $this->whereInList($query, 'audit_logs.user_id', $params->ids('user_id'));

        // Exact, or a prefix when it ends in `*`: `orders.*` is every order action.
        if (($actions = $params->list('action')) !== []) {
            $query->where(function ($q) use ($actions) {
                foreach ($actions as $action) {
                    str_ends_with($action, '*')
                        ? $q->orWhereRaw("audit_logs.action LIKE ? ESCAPE '!'", [ArabicSearch::escapeLike(rtrim($action, '*')).'%'])
                        : $q->orWhere('audit_logs.action', $action);
                }
            });
        }

        if (($types = $params->list('auditable_type')) !== []) {
            // `order` is `App\Models\Order`; a full class name passes through.
            $query->whereIn('audit_logs.auditable_type', array_map(
                fn (string $type) => str_contains($type, '\\') ? $type : 'App\\Models\\'.Str::studly($type),
                $types,
            ));
        }

        $this->whereInList($query, 'audit_logs.auditable_id', $params->ids('auditable_id'));
        $this->whereBetweenDates($query, 'audit_logs.created_at', $params->dates());

        ListSearch::apply($query, $params->search(), [
            'refs' => ['action'],
            'relations' => ['user' => ['names' => ['name']]],
        ]);

        $logs = $sort->apply($query, [])->paginate($params->perPage(30));

        return $this->paginated($logs, sort: $sort);
    }
}
