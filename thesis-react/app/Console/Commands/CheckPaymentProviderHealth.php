<?php

namespace App\Console\Commands;

use App\Services\PaymentProviderService;
use Illuminate\Console\Command;

class CheckPaymentProviderHealth extends Command
{
    protected $signature = 'payments:health
        {--require-operational : Fail when the provider is safely disabled}';

    protected $description = 'Validate the selected online payment provider configuration';

    public function handle(PaymentProviderService $paymentProvider): int
    {
        $provider = $paymentProvider->provider();
        $this->line("Payment provider: {$provider}");

        $configurationIssues = $paymentProvider->configurationIssues();
        if ($configurationIssues !== []) {
            $this->error('Configuration issues: ' . implode(', ', $configurationIssues));

            return self::FAILURE;
        }

        if ($provider === PaymentProviderService::DISABLED) {
            $this->warn('Configuration is safe, but online payments are disabled.');

            return $this->option('require-operational')
                ? self::FAILURE
                : self::SUCCESS;
        }

        $this->info('Payment provider configuration is valid and online booking is available.');

        return self::SUCCESS;
    }
}
