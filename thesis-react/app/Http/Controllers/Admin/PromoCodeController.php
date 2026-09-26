<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Services\PromoCodeService;
use App\Helpers\AuditHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class PromoCodeController extends Controller
{
    public function __construct(private PromoCodeService $promoService) {}

    // ─────────────────────────────────────────────────────────────────────────
    // INDEX — list all promo codes with usage stats
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request)
    {
        $query = PromoCode::withCount([
            'usages',
            'usages as active_usages_count' => fn ($query) => $query->active(),
        ])
            ->withSum('usages', 'discount_amount');

        if ($request->filled('search')) {
            $s = $request->search;
            $query->where(function ($q) use ($s) {
                $q->where('code', 'like', "%{$s}%")
                  ->orWhere('name', 'like', "%{$s}%");
            });
        }

        if ($request->filled('status')) {
            match ($request->status) {
                'active'   => $query->where('is_active', true)
                                    ->where('end_date', '>=', today()),
                'inactive' => $query->where('is_active', false),
                'expired'  => $query->where('end_date', '<', today()),
                default    => null,
            };
        }

        $codes = $query->latest()->paginate($request->get('per_page', 20));

        return response()->json(['success' => true, 'data' => $codes]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // SHOW
    // ─────────────────────────────────────────────────────────────────────────

    public function show(PromoCode $promoCode)
    {
        $promoCode->loadCount([
            'usages',
            'usages as active_usages_count' => fn ($query) => $query->active(),
        ]);
        $promoCode->loadSum('usages', 'discount_amount');
        $promoCode->load(['usages' => fn ($q) => $q->latest()->limit(10)->with('booking')]);

        return response()->json(['success' => true, 'data' => $promoCode]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STORE — create a new promo code
    // ─────────────────────────────────────────────────────────────────────────

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), $this->rules());
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $promo = PromoCode::create([
            'code'                 => strtoupper(trim($request->code)),
            'name'                 => $request->name,
            'description'          => $request->description,
            'discount_type'        => $request->discount_type,
            'discount_value'       => $request->discount_value,
            'max_discount_amount'  => $request->max_discount_amount,
            'start_date'           => $this->parseDate($request->start_date),
            'end_date'             => $this->parseDate($request->end_date),
            'booking_start_date'   => $this->parseDate($request->booking_start_date),
            'booking_end_date'     => $this->parseDate($request->booking_end_date),
            'min_nights'           => $request->min_nights ?? 1,
            'max_nights'           => $request->max_nights,
            'usage_limit'          => $request->usage_limit,
            'usage_per_user_limit' => $request->usage_per_user_limit ?? 1,
            'online_only'          => (bool) ($request->online_only ?? false),
            'walk_in_only'         => (bool) ($request->walk_in_only ?? false),
            'is_active'            => (bool) ($request->is_active ?? true),
        ]);

        AuditHelper::log(
            actionActivity: 'Promo Code Created',
            modulePage:     'Promo Code Management',
            modelType:      'PromoCode',
            modelId:        $promo->id,
            recordAffected: 'Promo ' . $promo->code,
            newValues:      $promo->toArray(),
            action:         'created'
        );

        return response()->json([
            'success' => true,
            'message' => "Promo code {$promo->code} created successfully.",
            'data'    => $promo,
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // UPDATE
    // ─────────────────────────────────────────────────────────────────────────

    public function update(Request $request, PromoCode $promoCode)
    {
        $rules          = $this->rules();
        $rules['code'] .= ',' . $promoCode->id;
        $validator = Validator::make($request->all(), $rules);
        $activeUsageCount = $promoCode->usages()->active()->count();

        $validator->after(function ($validator) use ($request, $activeUsageCount) {
            if (
                $request->input('usage_limit') !== null
                && (int) $request->input('usage_limit') < $activeUsageCount
            ) {
                $validator->errors()->add(
                    'usage_limit',
                    'Global usage limit cannot be lower than the ' . $activeUsageCount . ' active '
                    . 'usage' . ($activeUsageCount === 1 ? '' : 's') . ' (reserved or consumed).'
                );
            }
        });

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $old = $promoCode->toArray();

        $promoCode->update([
            'code'                 => strtoupper(trim($request->code)),
            'name'                 => $request->name,
            'description'          => $request->description,
            'discount_type'        => $request->discount_type,
            'discount_value'       => $request->discount_value,
            'max_discount_amount'  => $request->max_discount_amount,
            'start_date'           => $this->parseDate($request->start_date),
            'end_date'             => $this->parseDate($request->end_date),
            'booking_start_date'   => $this->parseDate($request->booking_start_date),
            'booking_end_date'     => $this->parseDate($request->booking_end_date),
            'min_nights'           => $request->min_nights ?? 1,
            'max_nights'           => $request->max_nights,
            'usage_limit'          => $request->usage_limit,
            'usage_per_user_limit' => $request->usage_per_user_limit ?? 1,
            'online_only'          => (bool) ($request->online_only ?? false),
            'walk_in_only'         => (bool) ($request->walk_in_only ?? false),
            'is_active'            => (bool) ($request->is_active ?? true),
        ]);

        AuditHelper::log(
            actionActivity: 'Promo Code Updated',
            modulePage:     'Promo Code Management',
            modelType:      'PromoCode',
            modelId:        $promoCode->id,
            recordAffected: 'Promo ' . $promoCode->code,
            oldValues:      $old,
            newValues:      $promoCode->fresh()->toArray(),
            action:         'updated'
        );

        return response()->json([
            'success' => true,
            'message' => "Promo code {$promoCode->code} updated successfully.",
            'data'    => $promoCode->fresh(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // TOGGLE ACTIVE STATUS
    // ─────────────────────────────────────────────────────────────────────────

    public function toggleStatus(PromoCode $promoCode)
    {
        $promoCode->update(['is_active' => !$promoCode->is_active]);

        AuditHelper::log(
            actionActivity: $promoCode->is_active ? 'Promo Code Activated' : 'Promo Code Deactivated',
            modulePage:     'Promo Code Management',
            modelType:      'PromoCode',
            modelId:        $promoCode->id,
            recordAffected: 'Promo ' . $promoCode->code,
            newValues:      ['is_active' => $promoCode->is_active],
            action:         'updated'
        );

        return response()->json([
            'success' => true,
            'message' => "Promo code {$promoCode->code} "
                . ($promoCode->is_active ? 'activated' : 'deactivated') . '.',
            'data'    => $promoCode,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DESTROY
    // ─────────────────────────────────────────────────────────────────────────

    public function destroy(PromoCode $promoCode)
    {
        if ($promoCode->usages()->exists()) {
            return response()->json([
                'success' => false,
                'message' => "Cannot delete promo code {$promoCode->code} because it has booking usage history. Deactivate it instead.",
            ], 409);
        }

        $code = $promoCode->code;
        $promoCode->delete();

        AuditHelper::log(
            actionActivity: 'Promo Code Deleted',
            modulePage:     'Promo Code Management',
            modelType:      'PromoCode',
            modelId:        null,
            recordAffected: 'Promo ' . $code,
            action:         'deleted'
        );

        return response()->json([
            'success' => true,
            'message' => "Promo code {$code} deleted.",
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // VALIDATE (preview) — used by frontend to check + preview discount
    // ─────────────────────────────────────────────────────────────────────────

    public function validateCode(Request $request)
    {
        $request->validate([
            'code'           => 'required|string',
            'guest_email'    => 'required|email',
            'check_in'       => 'required|date_format:Y-m-d',
            'check_out'      => 'required|date_format:Y-m-d',
            'subtotal'       => 'required|numeric|min:0',
            'booking_source' => 'nullable|in:online,walk_in',
        ]);

        $result = $this->promoService->validate($request->code, [
            'guest_email'    => $request->guest_email,
            'check_in'       => $request->check_in,
            'check_out'      => $request->check_out,
            'subtotal'       => (float) $request->subtotal,
            'booking_source' => $request->booking_source ?? 'online',
        ]);

        $statusCode = $result['valid'] ? 200 : 422;

        if (isset($result['promo'])) {
            $result['promo'] = [
                'code'           => $result['promo']->code,
                'name'           => $result['promo']->name,
                'discount_type'  => $result['promo']->discount_type,
                'discount_value' => $result['promo']->discount_value,
            ];
        }

        return response()->json($result, $statusCode);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // STATS — for admin dashboard widget
    // ─────────────────────────────────────────────────────────────────────────

    public function stats()
    {
        $today = today()->toDateString();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_codes'          => PromoCode::count(),
                'active_codes'         => PromoCode::where('is_active', true)
                                            ->where('end_date', '>=', $today)->count(),
                'expired_codes'        => PromoCode::where('end_date', '<', $today)->count(),
                'total_usages'         => \App\Models\PromoCodeUsage::where('status', \App\Models\PromoCodeUsage::STATUS_CONSUMED)->count(),
                'reserved_usages'      => \App\Models\PromoCodeUsage::where('status', \App\Models\PromoCodeUsage::STATUS_RESERVED)->count(),
                'released_usages'      => \App\Models\PromoCodeUsage::where('status', \App\Models\PromoCodeUsage::STATUS_RELEASED)->count(),
                'total_discount_given' => (float) \App\Models\PromoCodeUsage::where('status', \App\Models\PromoCodeUsage::STATUS_CONSUMED)->sum('discount_amount'),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Parse a date string and return a clean Y-m-d string,
     * stripping any time or timezone component to prevent UTC offset shifting.
     * Returns null if the input is empty.
     */
    private function parseDate(?string $date): ?string
    {
        if (!$date) return null;
        // Split on 'T' first so ISO strings like "2026-03-20T00:00:00.000000Z"
        // are reduced to "2026-03-20" before Carbon touches them,
        // preventing any timezone conversion from shifting the day.
        return Carbon::parse(explode('T', $date)[0])->format('Y-m-d');
    }

    private function rules(): array
    {
        return [
            'code'                 => 'required|string|max:50|unique:promo_codes,code',
            'name'                 => 'required|string|max:255',
            'description'          => 'nullable|string|max:1000',
            'discount_type'        => 'required|in:percentage,fixed',
            'discount_value'       => 'required|numeric|min:0.01',
            'max_discount_amount'  => 'nullable|numeric|min:0',
            'start_date'           => 'required|date_format:Y-m-d',
            'end_date'             => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'booking_start_date'   => 'nullable|required_with:booking_end_date|date_format:Y-m-d',
            'booking_end_date'     => 'nullable|required_with:booking_start_date|date_format:Y-m-d|after_or_equal:booking_start_date',
            'min_nights'           => 'nullable|integer|min:1',
            'max_nights'           => 'nullable|integer|min:1|gte:min_nights',
            'usage_limit'          => 'nullable|integer|min:1',
            'usage_per_user_limit' => 'nullable|integer|min:1',
            'online_only'          => 'nullable|boolean',
            'walk_in_only'         => 'nullable|boolean',
            'is_active'            => 'nullable|boolean',
        ];
    }
}
