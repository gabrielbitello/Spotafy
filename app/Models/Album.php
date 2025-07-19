<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use App\Models\Musica;

class Album extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'titulo',
        'artista_id',
        'lancamento',
    ];

    protected $casts = [
        'lancamento' => 'date',
    ];

    public function musicas()
    {
        return $this->hasMany(Musica::class);
    }

    public function artista()
    {
        return $this->belongsTo(Artista::class);
    }

}
