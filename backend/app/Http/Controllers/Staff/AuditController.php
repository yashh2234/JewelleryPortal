<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

/** Who did what, when, and what changed (spec §14). Read-only; never pruned. */
class AuditController extends Controller
{
    public function index(Request $request, TenantContext $context): JsonResponse
    {
        $rows = Activity::query()
            ->where('tenant_id', $context->id())
            ->with('causer')
            ->when($request->filled('subject'), fn ($q) => $q->where('subject_type', 'like', '%'.$request->string('subject')))
            ->when($request->filled('subject_id'), fn ($q) => $q->where('subject_id', $request->integer('subject_id')))
            ->when($request->filled('user_id'), fn ($q) => $q->where('causer_type', (new \App\Models\User)->getMorphClass())->where('causer_id', $request->integer('user_id')))
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->string('event')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to')->endOfDay()))
            ->when($request->filled('search'), fn ($q) => $q->where('description', 'like', '%'.$request->string('search').'%'))
            ->latest('id')
            ->paginate($request->integer('per_page', 50));

        $rows->getCollection()->transform(fn (Activity $a) => [
            'id' => $a->id,
            'at' => $a->created_at,
            'event' => $a->event ?? $a->log_name,
            'description' => $a->description,
            'subject' => class_basename((string) $a->subject_type),
            'subject_id' => $a->subject_id,
            'by' => $a->causer?->name ?? ($a->causer_type ? class_basename($a->causer_type) : 'System'),
            'changes' => $a->properties['attributes'] ?? null,
            'old' => $a->properties['old'] ?? null,
            'extra' => collect($a->properties)->except(['attributes', 'old'])->all() ?: null,
        ]);

        return response()->json($rows);
    }
}
