<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Services\Media\ImageStore;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Business profile and branding (name, GSTIN, logo, colour on the PWA icon). */
class TenantSettingsController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function show(): JsonResponse
    {
        $tenant = $this->context->get()->load('plan', 'subscription');

        return response()->json(['data' => $tenant]);
    }

    public function update(Request $request, ImageStore $images): JsonResponse
    {
        $tenant = $this->context->get();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'gstin' => ['nullable', 'string', 'regex:/^\d{2}[A-Z]{5}\d{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'primary_color' => ['sometimes', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => ['sometimes', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'issue_rules' => ['sometimes', 'array'],
            'issue_rules.*' => ['boolean'],
            'require_work_order' => ['sometimes', 'boolean'],
        ]);

        // Gold rules live in settings (JSON) next to the other workspace switches.
        if (isset($data['issue_rules']) || array_key_exists('require_work_order', $data)) {
            $settings = $tenant->settings ?? [];
            if (isset($data['issue_rules'])) {
                $settings['issue_rules'] = array_intersect_key(array_map('boolval', $data['issue_rules']), \App\Services\Metal\IssueRules::DEFAULTS) + ($settings['issue_rules'] ?? []);
            }
            if (array_key_exists('require_work_order', $data)) {
                $settings['require_work_order'] = (bool) $data['require_work_order'];
            }
            $tenant->settings = $settings;
            unset($data['issue_rules'], $data['require_work_order']);
        }

        $fields = Arr::except($data, ['logo']);
        if (array_key_exists('gstin', $fields)) {
            $fields['state_code'] = $fields['gstin'] ? substr($fields['gstin'], 0, 2) : null;
        }

        if ($request->hasFile('logo')) {
            $old = $tenant->logo_path;
            $stored = $images->store($request->file('logo'), $tenant->id, 'branding');
            $fields['logo_path'] = $stored['path'];
            $images->delete($old, $stored['thumb_path']);
        }

        $tenant->update($fields);

        return response()->json(['data' => $tenant->fresh()]);
    }
}
