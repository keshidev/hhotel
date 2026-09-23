<?php

namespace App\Console\Commands;

use App\Services\BackupVerificationService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class VerifyReleaseBackup extends Command
{
    protected $signature = 'system:verify-backup
        {database : Path to the database dump}
        {private-files : Path to the private-files archive}
        {--restore-test-reference= : Identifier for the completed isolated restore test}';

    protected $description = 'Verify fresh backup artifacts and record evidence of a completed restore test';

    public function handle(BackupVerificationService $verification): int
    {
        try {
            $evidence = $verification->verifyAndRecord(
                (string) $this->argument('database'),
                (string) $this->argument('private-files'),
                (string) $this->option('restore-test-reference')
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Backup and restore verification recorded.');
        $this->line('Environment: '.$evidence['environment']);
        $this->line('Database backup: '.$evidence['artifacts']['database']['name'].' ('.$evidence['artifacts']['database']['bytes'].' bytes)');
        $this->line('Private files backup: '.$evidence['artifacts']['private_files']['name'].' ('.$evidence['artifacts']['private_files']['bytes'].' bytes)');
        $this->line('Restore test: '.$evidence['restore_test_reference']);

        return self::SUCCESS;
    }
}
