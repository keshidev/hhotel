<?php

namespace App\Helpers;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;

class AuditHelper
{
    /**
     * Write an immutable audit log entry.
     *
     * @param string      $actionActivity  Human-readable action   e.g. "Room Status Updated"
     * @param string      $modulePage      Module name             e.g. "Room Management"
     * @param string      $modelType       Model class name        e.g. "Room"
     * @param int|null    $modelId         Primary key of record
     * @param string|null $recordAffected  Human label             e.g. "Room 103"
     * @param array|null  $oldValues       State before change (diff only — not full snapshot)
     * @param array|null  $newValues       State after change  (diff only — not full snapshot)
     * @param string|null $action          Short verb              e.g. "created", "updated"
     * @param \App\Models\User|null $actorUser  Explicit User model if known
     * @param string|null $actorLabel      String label for system/webhook actors with no User model
     */
    public static function log(
        string  $actionActivity,
        string  $modulePage,
        string  $modelType       = '',
        ?int    $modelId         = null,
        ?string $recordAffected  = null,
        ?array  $oldValues       = null,
        ?array  $newValues       = null,
        string  $action          = 'action',
        ?\App\Models\User $actorUser  = null,
        ?string $actorLabel      = null,
    ): void {
        try {
            $user = $actorUser ?? Auth::user();

            // Mask sensitive fields before storing
            if ($oldValues) $oldValues = self::maskSensitive($oldValues);
            if ($newValues) $newValues = self::maskSensitive($newValues);

            // Priority: explicit $actorUser → explicit $actorLabel → session user → fallback
            $staffName = match (true) {
                $user !== null       => $user->name . ' (' . ucfirst($user->role) . ')',
                $actorLabel !== null => $actorLabel,
                default              => 'Guest / System',
            };

            ActivityLog::create([
                'user_id'         => $user?->id,
                'user_staff_name' => $staffName,
                'action'          => $action,
                'action_activity' => $actionActivity,
                'model_type'      => $modelType,
                'model_id'        => $modelId ?? 0,
                'module_page'     => $modulePage,
                'record_affected' => $recordAffected,
                'old_values'      => $oldValues,
                'new_values'      => $newValues,
                'ip_address'      => Request::ip(),
            ]);

        } catch (\Throwable $e) {
            // Audit failure must NEVER break the main flow
            Log::error('AuditHelper::log failed: ' . $e->getMessage(), [
                'action'   => $actionActivity,
                'module'   => $modulePage,
                'model_id' => $modelId,
            ]);
        }
    }

    /**
     * Build a diff array between old and new model attributes.
     * Only includes keys that actually changed.
     *
     * Hardening: normalizes values before comparing to prevent false positives
     * caused by type mismatches after a DB round-trip, e.g.:
     *   - int 1500  vs string "1500.00"  (price_per_night)
     *   - int 1     vs string "1"        (floor, capacity)
     *   - null      vs ""               (nullable fields)
     *
     * Usage:
     *   $diff = AuditHelper::diff($oldValues, $newValues);
     *   // $diff['old'] and $diff['new'] contain only the changed keys
     */
    public static function diff(array $old, array $new): array
    {
        $changedOld = [];
        $changedNew = [];

        foreach ($new as $key => $value) {
            if (!array_key_exists($key, $old)) continue;

            $oldNormalized = self::normalize($old[$key]);
            $newNormalized = self::normalize($value);

            if ($oldNormalized !== $newNormalized) {
                $changedOld[$key] = $old[$key];
                $changedNew[$key] = $value;
            }
        }

        return ['old' => $changedOld, 'new' => $changedNew];
    }

    /**
     * Normalize a scalar value to a string for reliable equality comparison.
     *
     * - Floats  → "1500.00"  (2 decimal places, matches typical DB precision)
     * - Nulls   → ""         (treat null and empty string as equivalent)
     * - Others  → (string)   cast
     */
    private static function normalize(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value)) {
            return number_format($value, 2, '.', '');
        }

        // Cast numeric strings that look like floats (e.g. "1500.00" from DB) to float first
        if (is_string($value) && is_numeric($value) && str_contains($value, '.')) {
            return number_format((float) $value, 2, '.', '');
        }

        return (string) $value;
    }

    /**
     * Mask sensitive fields so they are never stored in audit logs.
     */
    private static function maskSensitive(array $data): array
    {
        $sensitive = [
            'password', 'token', 'api_key', 'secret',
            'remember_token', 'access_token', 'proof_image',
        ];

        foreach ($sensitive as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = '*** REDACTED ***';
            }
        }

        return $data;
    }
}
