<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Musica;

class Genero extends Model
{
    // Define os campos que podem ser preenchidos em massa
    protected $fillable = [
        'nome',
    ];

    public function musicas()
    {
        return $this->belongsToMany(Musica::class, 'genero_musica');
    }

    public function artistas()
    {
        return $this->belongsToMany(Artista::class, 'artista_genero');
    }

}
