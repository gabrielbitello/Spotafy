<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use App\Models\Musica;
use App\Models\Album;

class Artista extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'nome',
        'bio',
    ];

    public function musicas()
    {
        return $this->hasMany(Musica::class);
    }

    public function albums()
    {
        return $this->hasMany(Album::class);
    }

    public function generos()
    {
        return $this->belongsToMany(Genero::class, 'artista_genero');
    }
}
