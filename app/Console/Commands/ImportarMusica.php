<?php
namespace App\Console\Commands;

use App\Services\Music\Importer\MusicImporterCore;
use Illuminate\Console\Command;

class ImportarMusica extends Command
{
    protected $signature = 'musica:import {query : Termo de busca da música}';
    protected $description = 'Importa uma música do YouTube com metadados completos';

    public function handle(MusicImporterCore $importer)
    {
        $query = $this->argument('query');

        $this->info("🎵 Importando: {$query}");

        try {
            $musica = $importer->importar($query);
            $this->info("✅ Música importada com sucesso!");
        } catch (\Exception $e) {
            $this->error("❌ Erro: " . $e->getMessage());
            return 1;
        }

        return 0;
    }
}


