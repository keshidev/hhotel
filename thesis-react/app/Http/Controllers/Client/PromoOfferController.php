<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use Illuminate\Http\JsonResponse;

class PromoOfferController extends Controller
{
    public function index(): JsonResponse
    {
        $today = today();

        $offers = PromoCode::query()
            ->withCount([
                'usages as active_usage_count' => fn ($query) => $query->active(),
            ])
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today)
            ->where(function ($query) {
                $query->where('walk_in_only', false)
                    ->orWhere('online_only', true);
            })
            ->orderBy('end_date')
            ->orderBy('name')
            ->get()
            ->filter(function (PromoCode $promo) {
                return $promo->usage_limit === null
                    || $promo->active_usage_count < $promo->usage_limit;
            })
            ->values()
            ->map(function (PromoCode $promo) {
                return [
                    'id' => $promo->id,
                    'code' => $promo->code,
                    'name' => $promo->name,
                    'description' => $promo->description,
                    'discount_type' => $promo->discount_type,
                    'discount_value' => (float) $promo->discount_value,
                    'max_discount_amount' => $promo->max_discount_amount !== null
                        ? (float) $promo->max_discount_amount
                        : null,
                    'start_date' => $promo->start_date?->toDateString(),
                    'end_date' => $promo->end_date?->toDateString(),
                    'booking_start_date' => $promo->booking_start_date?->toDateString(),
                    'booking_end_date' => $promo->booking_end_date?->toDateString(),
                    'min_nights' => $promo->min_nights,
                    'max_nights' => $promo->max_nights,
                    'usage_per_user_limit' => $promo->usage_per_user_limit,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $offers,
        ]);
    }
}
