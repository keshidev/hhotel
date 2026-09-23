<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\SchedulerHeartbeatService;
use Illuminate\Http\JsonResponse;

class SchedulerHealthController extends Controller
{
    public function __invoke(SchedulerHeartbeatService $heartbeatService): JsonResponse
    {
        $status = $heartbeatService->status();

        return response()
            ->json(['status' => $status['status']], $status['healthy'] ? 200 : 503)
            ->withHeaders([
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            ]);
    }
}
