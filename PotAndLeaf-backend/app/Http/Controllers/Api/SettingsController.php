<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingsService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        abort_unless($request->user()->hasPermission('settings.view', $company->id)
            || $request->user()->hasPermission('*', $company->id)
            || $request->user()->is_super_admin, 403);

        return $this->ok($this->settings->all($company->id));
    }

    public function update(Request $request): JsonResponse
    {
        $company = $request->attributes->get('company');
        abort_unless($request->user()->hasPermission('settings.update', $company->id)
            || $request->user()->hasPermission('*', $company->id)
            || $request->user()->is_super_admin, 403);

        $data = $request->validate([
            'loyalty_earn_rupees'        => ['sometimes', 'numeric', 'min:1'],
            'loyalty_earn_points'        => ['sometimes', 'integer', 'min:1'],
            'loyalty_redeem_rupees'      => ['sometimes', 'numeric', 'min:0.01'],
            'loyalty_redeem_cap_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'discount_ceiling_percent'   => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'reorder_alert_default'      => ['sometimes', 'numeric', 'min:0'],
            'website_integration'        => ['sometimes', 'in:0,1,true,false'],
            'whatsapp_enabled'           => ['sometimes', 'in:0,1,true,false'],
        ]);

        foreach (['website_integration', 'whatsapp_enabled'] as $flag) {
            if (isset($data[$flag])) {
                $data[$flag] = in_array($data[$flag], [true, 'true', '1', 1], true) ? '1' : '0';
            }
        }

        return $this->ok($this->settings->setMany($company->id, $data), 'Settings saved.');
    }
}
