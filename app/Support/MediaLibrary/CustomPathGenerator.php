<?php
// app/Support/MediaLibrary/CustomPathGenerator.php

namespace App\Support\MediaLibrary;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

class CustomPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->getBasePath($media) . '/';
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->getBasePath($media) . '/conversions/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->getBasePath($media) . '/responsive/';
    }

    protected function getBasePath(Media $media): string
    {
        if ($media->model_type === 'App\Models\Musica' && $media->model) {
            $musica = $media->model;
            
            if (!empty($musica->token)) {
                $hash = str_replace('mus_', '', $musica->token);
                
                if (strlen($hash) >= 4) {
                    $nivel1 = substr($hash, 0, 2);
                    $nivel2 = substr($hash, 2, 2);
                    
                    $collection = $media->collection_name;
                    return "musicas/{$collection}/{$nivel1}/{$nivel2}";
                }
            }
        }
        
        return $media->collection_name . '/' . $media->id;
    }
}