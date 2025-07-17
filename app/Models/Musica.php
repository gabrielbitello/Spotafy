<?php
// app/Models/Musica.php

namespace App\Models;

use App\Traits\GeneratesMusicToken;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Carbon\Carbon;

class Musica extends Model implements HasMedia
{
    use InteractsWithMedia, GeneratesMusicToken;

    protected $fillable = [
        'token',
        'titulo',
        'artista_id',
        'album_id',
        'duracao',
        'spotify_id',
        'popularidade',
        'data_lancamento',
        'explicit',
        'preview_url',
        'isrc'
    ];

    protected $casts = [
        'duracao' => 'integer',
        'popularidade' => 'integer',
        'data_lancamento' => 'datetime', // Mudança aqui: datetime em vez de date
        'explicit' => 'boolean',
    ];

    // Event listeners
    protected static function boot()
    {
        parent::boot();
        
        // Gerar token antes de criar
        static::creating(function ($musica) {
            if (!$musica->token) {
                $artista = $musica->artista?->nome ?? 'Unknown Artist';
                $dataLancamento = $musica->data_lancamento?->format('Y-m-d');
                
                $musica->token = self::generateMusicToken(
                    $artista,
                    $musica->titulo,
                    $dataLancamento
                );
            }
        });
    }

    // Relacionamentos
    public function artista(): BelongsTo
    {
        return $this->belongsTo(Artista::class);
    }

    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    public function generos(): BelongsToMany
    {
        return $this->belongsToMany(Genero::class, 'genero_musica');
    }

    public function playlists(): BelongsToMany
    {
        return $this->belongsToMany(Playlist::class, 'musica_playlist')->withTimestamps();
    }

    // Media Library - Organização em subpastas
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('audio')
              ->acceptsMimeTypes(['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/ogg'])
              ->singleFile();
              
        $this->addMediaCollection('capas')
              ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
              ->singleFile();
    }

    public function registerMediaConversions(Media $media = null): void
    {
        // Conversões para capas (diferentes tamanhos)
        $this->addMediaConversion('thumb')
              ->width(150)
              ->height(150)
              ->sharpen(10)
              ->performOnCollections('capas');
              
        $this->addMediaConversion('medium')
              ->width(300)
              ->height(300)
              ->performOnCollections('capas');
    }

    // Método para organizar arquivos em subpastas baseado no token
    public function getMediaPath(): string
    {
        // Usar os primeiros 2 caracteres do token para criar subpasta
        $subfolder = substr($this->token, 4, 2); // Pula o prefixo 'mus_'
        return "musicas/{$subfolder}";
    }

    // Accessors
    public function getDuracaoFormatadaAttribute(): string
    {
        if (!$this->duracao) {
            return '00:00';
        }
        
        $minutos = floor($this->duracao / 60);
        $segundos = $this->duracao % 60;
        
        return sprintf('%02d:%02d', $minutos, $segundos);
    }

    public function getAnoLancamentoAttribute(): ?int
    {
        return $this->data_lancamento?->year;
    }

    public function getSpotifyUrlAttribute(): ?string
    {
        return $this->spotify_id ? "https://open.spotify.com/track/{$this->spotify_id}" : null;
    }

    // Métodos para arquivos
    public function getAudioUrl(): ?string
    {
        $audio = $this->getFirstMedia('audio');
        return $audio ? $audio->getUrl() : null;
    }

    public function getCapaUrl(string $conversion = ''): ?string
    {
        $capa = $this->getFirstMedia('capas');
        
        if (!$capa) {
            return null;
        }
        
        return $conversion ? $capa->getUrl($conversion) : $capa->getUrl();
    }

    // Scopes
    public function scopeByToken($query, string $token)
    {
        return $query->where('token', $token);
    }

    public function scopePopulares($query, int $minPopularidade = 50)
    {
        return $query->where('popularidade', '>=', $minPopularidade);
    }

    public function scopeDoSpotify($query)
    {
        return $query->whereNotNull('spotify_id');
    }

    // Método para regenerar token se necessário
    public function regenerateToken(): string
    {
        $artista = $this->artista?->nome ?? 'Unknown Artist';
        $dataLancamento = $this->data_lancamento?->format('Y-m-d');
        
        $this->token = self::generateMusicToken(
            $artista,
            $this->titulo,
            $dataLancamento
        );
        
        $this->save();
        
        return $this->token;
    }
}