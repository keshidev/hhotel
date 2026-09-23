<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditTrailController extends Controller
{
    /**
     * Fields that are always excluded from the audit display —
     * they are system/internal and add no value to the audit trail.
     */
    private const EXCLUDED_KEYS = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /**
     * GET /api/admin/audit-trail
     */
    public function index(Request $request)
    {
        try {
            $query = ActivityLog::with('user')->orderBy('created_at', 'desc');

            if ($request->filled('search')) {
                $s = $request->search;
                $query->where(function ($q) use ($s) {
                    $q->where('user_staff_name',  'like', "%{$s}%")
                      ->orWhere('action_activity', 'like', "%{$s}%")
                      ->orWhere('record_affected', 'like', "%{$s}%")
                      ->orWhere('module_page',     'like', "%{$s}%");
                });
            }

            if ($request->filled('module')) {
                $query->where('module_page', $request->module);
            }

            if ($request->filled('action')) {
                $query->where('action_activity', 'like', "%{$request->action}%");
            }

            if ($request->filled('user')) {
                $query->where('user_staff_name', 'like', "%{$request->user}%");
            }

            if ($request->filled('date_from')) {
                $query->whereDate('created_at', '>=', $request->date_from);
            }

            if ($request->filled('date_to')) {
                $query->whereDate('created_at', '<=', $request->date_to);
            }

            $summaryQuery = clone $query;
            if (! $request->filled('date_from') && ! $request->filled('date_to')) {
                $summaryQuery->whereDate('created_at', '>=', now()->subDays(29)->toDateString());
            }

            $activitySummary = $summaryQuery
                ->reorder()
                ->selectRaw("DATE(created_at) as date, COALESCE(action, 'other') as action, COUNT(*) as count")
                ->groupBy(DB::raw('DATE(created_at)'), 'action')
                ->orderBy('date')
                ->get()
                ->map(fn ($row) => [
                    'date' => $row->date,
                    'action' => strtolower((string) $row->action),
                    'count' => (int) $row->count,
                ]);

            $perPage = $request->get('per_page', 20);
            $logs    = $query->paginate($perPage);

            // Per-row guard: a single malformed row must never kill the whole page
            $logs->getCollection()->transform(function (ActivityLog $log) {
                try {
                    return $this->format($log);
                } catch (\Throwable $e) {
                    return [
                        'id'                => $log->id,
                        'user_staff_name'   => $log->user_staff_name ?? '—',
                        'action_activity'   => $log->action_activity ?? $log->action ?? '—',
                        'module_page'       => $log->module_page     ?? '—',
                        'record_affected'   => $log->record_affected ?? '—',
                        'has_changes'       => false,
                        'ip_address'        => $log->ip_address      ?? '—',
                        'date_time'         => $log->created_at?->setTimezone('Asia/Manila')->format('F j, Y — g:i A'),
                        'date_time_raw'     => $log->created_at?->setTimezone('Asia/Manila')->toISOString(),
                        'old_value_summary' => 'N/A',
                        'new_value_summary' => 'N/A',
                    ];
                }
            });

            $modules = ActivityLog::select('module_page')
                ->distinct()
                ->whereNotNull('module_page')
                ->pluck('module_page')
                ->sort()
                ->values();

            return response()->json([
                'success' => true,
                'data'    => $logs,
                'modules' => $modules,
                'activity_summary' => $activitySummary,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch audit trail',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/admin/audit-trail/{id}
     */
    public function show($id)
    {
        try {
            $log = ActivityLog::with('user')->findOrFail($id);

            return response()->json([
                'success' => true,
                'data'    => $this->format($log, full: true),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Audit log not found',
            ], 404);
        }
    }

    /**
     * GET /api/admin/audit-trail/export
     */
    public function export(Request $request)
    {
        try {
            $query = ActivityLog::orderBy('created_at', 'desc');

            if ($request->filled('search')) {
                $s = $request->search;
                $query->where(function ($q) use ($s) {
                    $q->where('user_staff_name',  'like', "%{$s}%")
                      ->orWhere('action_activity', 'like', "%{$s}%")
                      ->orWhere('record_affected', 'like', "%{$s}%");
                });
            }
            if ($request->filled('module'))    $query->where('module_page', $request->module);
            if ($request->filled('date_from')) $query->whereDate('created_at', '>=', $request->date_from);
            if ($request->filled('date_to'))   $query->whereDate('created_at', '<=', $request->date_to);

            $logs = $query->limit(5000)->get();

            $headers = [
                'Content-Type'        => 'text/csv',
                'Content-Disposition' => 'attachment; filename="audit_trail_' . now('Asia/Manila')->format('Y-m-d') . '.csv"',
            ];

            $callback = function () use ($logs) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'ID', 'User/Staff', 'Action', 'Module/Page',
                    'Record Affected', 'Old Values', 'New Values',
                    'IP Address', 'Date & Time',
                ]);

                foreach ($logs as $log) {
                    try {
                        $formatted  = $this->format($log);
                        $oldSummary = $formatted['old_value_summary'];
                        $newSummary = $formatted['new_value_summary'];
                    } catch (\Throwable $e) {
                        $oldSummary = 'N/A';
                        $newSummary = 'N/A';
                    }

                    fputcsv($handle, [
                        $log->id,
                        $log->user_staff_name ?? '—',
                        $log->action_activity ?? $log->action,
                        $log->module_page     ?? '—',
                        $log->record_affected ?? '—',
                        $oldSummary,
                        $newSummary,
                        $log->ip_address ?? '—',
                        $log->created_at?->setTimezone('Asia/Manila')->format('F j, Y — g:i A'),
                    ]);
                }

                fclose($handle);
            };

            return response()->stream($callback, 200, $headers);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Export failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    private function format(ActivityLog $log, bool $full = false): array
    {
        $hasChanges = $this->safeHasChanges($log);

        $base = [
            'id'              => $log->id,
            'user_staff_name' => $log->user_staff_name ?? '—',
            'action_activity' => $log->action_activity ?? $log->action,
            'module_page'     => $log->module_page     ?? '—',
            'record_affected' => $log->record_affected ?? '—',
            'has_changes'     => $hasChanges,
            'ip_address'      => $log->ip_address      ?? '—',
            'date_time'       => $log->created_at?->setTimezone('Asia/Manila')->format('F j, Y — g:i A'),
            'date_time_raw'   => $log->created_at?->setTimezone('Asia/Manila')->toISOString(),
        ];

        $oldFlat = $log->old_values ? self::flattenValues($log->old_values, 'old') : [];
        $newFlat = $log->new_values ? self::flattenValues($log->new_values, 'new') : [];

        // Determine which keys to show:
        if (empty($oldFlat) && !empty($newFlat)) {
            // Created action — no old values, show only non-empty new values
            $changedKeys = array_keys(array_filter(
                $newFlat,
                fn($v) => $v !== '' && $v !== null && $v !== '[]'
            ));
        } elseif (!empty($oldFlat) && !empty($newFlat)) {
            // Updated action — only show fields where value actually changed
            $changedKeys = array_keys(array_filter(
                $newFlat,
                fn($v, $k) => !array_key_exists($k, $oldFlat) || (string) $oldFlat[$k] !== (string) $v,
                ARRAY_FILTER_USE_BOTH
            ));
        } else {
            // Deleted or no values — show whatever old values exist non-empty
            $changedKeys = array_keys(array_filter(
                $oldFlat,
                fn($v) => $v !== '' && $v !== null && $v !== '[]'
            ));
        }

        // Always strip internal system fields
        $changedKeys = array_values(array_diff($changedKeys, self::EXCLUDED_KEYS));

        if ($full) {
            $base['old_values'] = !empty($oldFlat)
                ? array_filter(
                    array_intersect_key($oldFlat, array_flip($changedKeys)),
                    fn($v) => $v !== '' && $v !== null && $v !== '[]'
                )
                : null;

            $base['new_values'] = !empty($newFlat)
                ? array_filter(
                    array_intersect_key($newFlat, array_flip($changedKeys)),
                    fn($v) => $v !== '' && $v !== null && $v !== '[]'
                )
                : null;

            // Set to null if empty after filtering so frontend shows "no changes"
            if (empty($base['old_values'])) $base['old_values'] = null;
            if (empty($base['new_values'])) $base['new_values'] = null;

        } else {
            $base['old_value_summary'] = !empty($oldFlat)
                ? self::summarise(array_intersect_key($oldFlat, array_flip($changedKeys)))
                : 'N/A';

            $base['new_value_summary'] = !empty($newFlat)
                ? self::summarise(array_intersect_key($newFlat, array_flip($changedKeys)))
                : 'N/A';
        }

        return $base;
    }

    /**
     * Safe replacement for $log->hasValueChanges() — never throws.
     */
    private function safeHasChanges(ActivityLog $log): bool
    {
        try {
            if (method_exists($log, 'hasValueChanges')) {
                return $log->hasValueChanges();
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return !empty($log->old_values) || !empty($log->new_values);
    }

    /**
     * Normalise any stored payload shape into a flat ['field' => 'value'] array.
     *
     * $side = 'old' | 'new' — used to pick the correct sub-value from nested shapes.
     *
     * Shape A — correct flat:
     *   {"status":"available"}
     *   → ['status' => 'available']
     *
     * Shape B — whole diff object:
     *   {"old":{"status":"cleaning"},"new":{"status":"available"}}
     *   → when $side='old': ['status' => 'cleaning']
     *   → when $side='new': ['status' => 'available']
     *
     * Shape C — per-field nested:
     *   {"status":{"old":"cleaning","new":"available"}}
     *   → when $side='old': ['status' => 'cleaning']
     *   → when $side='new': ['status' => 'available']
     *
     * Mixed (Shape A + C together):
     *   {"room_number":"103","status":{"old":"cleaning","new":"available"}}
     *   → when $side='old': ['room_number'=>'103', 'status'=>'cleaning']
     *   → when $side='new': ['room_number'=>'103', 'status'=>'available']
     */
    private static function flattenValues(array $values, string $side = 'new'): array
    {
        if (empty($values)) return [];

        $keys = array_keys($values);

        // ── Shape B: top-level keys are ONLY 'old' / 'new' ──────────────────
        $onlyOldNew = count(array_diff($keys, ['old', 'new'])) === 0
            && count(array_intersect($keys, ['old', 'new'])) > 0;

        if ($onlyOldNew) {
            $sub = $values[$side] ?? $values[array_key_first($values)];
            if (is_array($sub)) {
                return array_map(
                    fn($v) => is_array($v) ? json_encode($v) : (string) ($v ?? ''),
                    $sub
                );
            }
            return [];
        }

        // ── Shape A / C / Mixed ──────────────────────────────────────────────
        $flat = [];
        foreach ($values as $k => $v) {
            if (is_array($v)) {
                // Shape C: {'old': x, 'new': y} — pick the correct side
                if (array_key_exists('old', $v) || array_key_exists('new', $v)) {
                    $flat[$k] = (string) ($v[$side] ?? $v['new'] ?? $v['old'] ?? '');
                } else {
                    // Unknown nested array — encode so it at least shows something
                    $flat[$k] = json_encode($v);
                }
            } else {
                // Shape A — plain scalar
                $flat[$k] = (string) ($v ?? '');
            }
        }

        return $flat;
    }

    /**
     * Convert flat ['field' => 'value'] map to a readable summary string.
     * Skips empty, null, and empty-array values.
     * e.g. ['status' => 'available', 'end_date' => '2026-03-19'] → "status: available, end_date: 2026-03-19"
     */
    private static function summarise(array $flat): string
    {
        if (empty($flat)) return 'N/A';

        $result = collect($flat)
            ->filter(fn($v) => $v !== '' && $v !== null && $v !== '[]')
            ->map(fn($v, $k) => "{$k}: {$v}")
            ->implode(', ');

        return $result ?: 'N/A';
    }
}
