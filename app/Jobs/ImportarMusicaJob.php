<?php

namespace App\Jobs;

use App\Services\Music\Importer\MusicImporterCore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class ImportarMusicaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $termoBusca;

    /**
     * Create a new job instance.
     */
    public function __construct(string $termoBusca)
    {
        $this->termoBusca = $termoBusca;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $importer = new MusicImporterCore();
        $importer->importar($this->termoBusca);
        // Libera a trava do cache ao finalizar
        $cacheKey = 'importar_musica_job_' . md5($this->termoBusca);
        Cache::forget($cacheKey);
    }
}