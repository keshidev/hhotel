<?php

namespace App\Services;

class PaymentProviderService
{
    public const DISABLED = 'disabled';

    public const MANUAL_GCASH = 'manual_gcash';

    public function __construct(
        private ManualGcashConfigurationService $manualGcashConfiguration
    ) {}

    public function provider(): string
    {
        $configured = strtolower(trim((string) config('payment.provider', '')));

        return $configured !== '' ? $configured : self::DISABLED;
    }

    public function uses(string $provider): bool
    {
        return $this->provider() === strtolower(trim($provider));
    }

    public function configurationIssues(): array
    {
        $provider = $this->provider();
        $supported = config('payment.supported_providers', []);

        if (! is_array($supported) || ! in_array($provider, $supported, true)) {
            return ['payment_provider_invalid'];
        }

        return match ($provider) {
            self::DISABLED => [],
            self::MANUAL_GCASH => $this->manualGcashIssues(),
            default => ['payment_provider_invalid'],
        };
    }

    /**
     * @return array<int, string>
     */
    public function manualGcashProductionLaunchIssues(): array
    {
        if (strtolower(trim((string) config('app.env'))) !== 'production') {
            return [];
        }

        $requirements = [
            'payment.manual_gcash.launch.merchant_verified' => 'manual_gcash_merchant_verification_not_confirmed',
            'payment.manual_gcash.launch.staff_trained' => 'manual_gcash_staff_training_not_confirmed',
            'payment.manual_gcash.launch.backup_confirmed' => 'manual_gcash_backup_not_confirmed',
            'payment.manual_gcash.launch.rollback_reviewed' => 'manual_gcash_rollback_not_confirmed',
            'payment.manual_gcash.launch.production_approved' => 'manual_gcash_production_launch_not_approved',
        ];

        $issues = [];
        foreach ($requirements as $configuration => $issue) {
            if (! (bool) config($configuration, false)) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    public function operationalIssues(): array
    {
        if ($this->uses(self::DISABLED)) {
            return ['payment_provider_disabled'];
        }

        return $this->configurationIssues();
    }

    public function isOperational(): bool
    {
        return $this->operationalIssues() === [];
    }

    public function pendingPaymentNote(float $downpaymentPercentage): string
    {
        $percentage = rtrim(rtrim(number_format($downpaymentPercentage, 2, '.', ''), '0'), '.');

        return $this->uses(self::MANUAL_GCASH)
            ? "Awaiting manually verified GCash downpayment ({$percentage}%)."
            : "Online payment unavailable ({$percentage}% downpayment required).";
    }

    /**
     * @return array<int, string>
     */
    private function manualGcashIssues(): array
    {
        if (! (bool) config('payment.manual_gcash.implemented', false)) {
            return ['manual_gcash_not_implemented'];
        }

        return array_values(array_unique([
            ...$this->manualGcashConfiguration->issues(),
            ...$this->manualGcashProductionLaunchIssues(),
        ]));
    }
}
