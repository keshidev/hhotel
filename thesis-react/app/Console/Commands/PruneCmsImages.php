<?php

namespace App\Console\Commands;

use App\Models\CmsSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneCmsImages extends Command
{
    protected $signature = 'cms:prune-images {--hours=24} {--dry-run}';

    protected $description = 'Delete abandoned CMS uploads that are no longer referenced';

    public function handle(): int
    {
        $hours = max(0, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours)->timestamp;
        $disk = Storage::disk('public');
        $referenced = $this->referencedPaths();
        $deleted = 0;

        foreach ($disk->allFiles('cms') as $path) {
            if (isset($referenced[$path]) || $disk->lastModified($path) > $cutoff) {
                continue;
            }

            if (! $this->option('dry-run') && ! $disk->delete($path)) {
                $this->error("Failed to delete {$path}.");

                return self::FAILURE;
            }

            $deleted++;
        }

        $verb = $this->option('dry-run') ? 'eligible for deletion' : 'deleted';
        $this->info("CMS image cleanup: {$deleted} unreferenced file(s) {$verb}.");

        return self::SUCCESS;
    }

    private function referencedPaths(): array
    {
        $paths = [];

        foreach (CmsSetting::query()->pluck('value') as $value) {
            $content = str_replace('\\/', '/', (string) $value);
            preg_match_all('#(?:https?://[^"\\s]+)?/storage/(cms/[A-Za-z0-9._/-]+)#', $content, $matches);
            foreach ($matches[1] ?? [] as $path) {
                $paths[rawurldecode($path)] = true;
            }
        }

        return $paths;
    }
}
