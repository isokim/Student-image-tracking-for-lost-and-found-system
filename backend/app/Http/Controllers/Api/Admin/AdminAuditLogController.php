<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/admin/audit-logs
 *
 * Read-only by design: there is no store, update or destroy here, and
 * no route for one. An audit trail that can be edited or pruned through
 * the API is not an audit trail.
 *
 * The route sits behind `role:admin`, and the policy check below is the
 * second, independent layer - so removing the middleware by accident
 * would not silently open the audit log to staff.
 */
class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $logs = AuditLog::query()
            ->with('user:id,name,role')
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->when($request->filled('userId'), fn ($q) => $q->where('user_id', $request->integer('userId')))
            ->when($request->filled('entityType'), fn ($q) => $q->where('entity_type', $request->string('entityType')))
            ->latest()
            ->paginate(25);

        return $this->paginated($logs, AuditLogResource::class);
    }
}
