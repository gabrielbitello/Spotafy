<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Musica;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Playlist extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $fillable = [
        'nome',
        'descricao',
        'user_id',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function musicas()
    {
        return $this->belongsToMany(Musica::class, 'musica_playlist')->withTimestamps();
    }
}
