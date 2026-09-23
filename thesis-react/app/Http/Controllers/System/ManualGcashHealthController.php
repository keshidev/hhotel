<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\ManualGcashOperationalHealthService;
use Illuminate\Http\JsonResponse;

class ManualGcashHealthController extends Controller
{
    public function __invoke(ManualGcashOperationalHealthService $health): JsonResponse
    {
        $status = $health->status();

        return response()
            ->json(['status' => $status['status']], $status['healthy'] ? 200 : 503)
            ->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]);
    }
}
