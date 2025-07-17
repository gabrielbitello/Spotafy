<?php
// app/Observers/MediaObserver.php

namespace App\Observers;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Illuminate\Support\Facades\Storage;

class MediaObserver
{
    public function created(Media $media)
    {
        // Organizar arquivo após criação se for música
        if ($media->model_type === 'App\Models\Musica') {
            $this->organizarArquivoMusica($media);
        }
    }

    private function organizarArquivoMusica(Media $media)
    {
        $musica = $media->model;
        
        if ($musica && $musica->token) {
            $subfolder = substr($musica->token, 4, 2);
            $collection = $media->collection_name;
            
            // Mover arquivo para subpasta organizada
            $novoPath = "musicas/{$collection}/{$subfolder}/";
            
            // Implementar lógica de movimentação se necessário
        }
    }
}