<?php

namespace App\Console\Commands;

use App\Helpers\AuditHelper;
use App\Models\ContactInquiry;
use Illuminate\Console\Command;

class PruneContactInquiries extends Command
{
    protected $signature = 'contact-inquiries:prune {--dry-run}';

    protected $description = 'Anonymize old resolved guest contact inquiries';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('contact.retention_days', 365));
        $query = ContactInquiry::whereIn('status', ['resolved', 'spam'])
            ->whereNull('anonymized_at')
            ->where('resolved_at', '<=', $cutoff);
        $eligible = (clone $query)->count();

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$eligible} contact inquiry record(s) eligible for anonymization.");
            return self::SUCCESS;
        }

        $changed = 0;
        $query->chunkById(100, function ($inquiries) use (&$changed) {
            foreach ($inquiries as $inquiry) {
                $inquiry->forceFill([
                    'first_name' => null,
                    'last_name' => null,
                    'email' => null,
                    'phone' => null,
                    'booking_reference' => null,
                    'message' => null,
                    'resolution_message' => null,
                    'source_ip_hash' => null,
                    'submission_key' => hash('sha256', 'anonymized|'.$inquiry->id.'|'.now()->timestamp),
                    'notification_error' => null,
                    'resolution_email_error' => null,
                    'anonymized_at' => now(),
                ])->save();
                $inquiry->statusHistory()->update(['reason' => null]);
                $changed++;
            }
        });

        if ($changed > 0) {
            AuditHelper::log(
                'Guest Inquiry Data Anonymized',
                'Contact Inquiries',
                'ContactInquiry',
                null,
                "{$changed} inquiry record(s)",
                null,
                ['records_anonymized' => $changed],
                'anonymized',
                actorLabel: 'Scheduler (System)'
            );
        }

        $this->info("Anonymized {$changed} contact inquiry record(s).");
        return self::SUCCESS;
    }
}
