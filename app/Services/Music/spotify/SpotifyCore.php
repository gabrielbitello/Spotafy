<?php
namespace App\Services\Music\spotify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;

class SpotifyCore{

    public $spotifyToken;
    private $spotifyTokenExpires;
    private $pastaDestino;
    private $maxRetries = 3;
    private $retryDelay = 1; // segundos

    /**
    * Inicializa autenticação do Spotify com validação
    */
    public function initializeSpotifyAuth(): void
    {
        if (!$this->validateSpotifyCredentials()) {
            Log::error("❌ Credenciais do Spotify não configuradas ou inválidas");
            return;
        }

        $this->getSpotifyToken();
    }

    /**
    * Valida se as credenciais do Spotify estão configuradas
    */
    public function validateSpotifyCredentials(): bool
    {
        $clientId = config('services.spotify.client_id');
        $clientSecret = config('services.spotify.client_secret');

        if (empty($clientId) || empty($clientSecret)) {
            Log::error("Credenciais do Spotify não encontradas no config/services.php");
            return false;
        }

        if (strlen($clientId) !== 32 || strlen($clientSecret) !== 32) {
            Log::error("Formato das credenciais do Spotify parece inválido");
            return false;
        }

        return true;
    }

    /**
    * Obtém token do Spotify com cache e renovação automática
    */
    public function getSpotifyToken(): void
    {
        try {
            // Verificar se já temos um token válido em cache
            $cachedToken = Cache::get('spotify_token');
            $cachedExpires = Cache::get('spotify_token_expires');

            if ($cachedToken && $cachedExpires && Carbon::now()->lt($cachedExpires)) {
                $this->spotifyToken = $cachedToken;
                $this->spotifyTokenExpires = $cachedExpires;
                Log::info("✅ Token do Spotify recuperado do cache");
                return;
            }

            Log::info("🔄 Solicitando novo token do Spotify...");

            $response = Http::timeout(15)
                ->asForm()
                ->withHeaders([
                    'Authorization' => 'Basic ' . base64_encode(
                        config('services.spotify.client_id') . ':' . config('services.spotify.client_secret')
                    )
                ])
                ->post('https://accounts.spotify.com/api/token', [
                    'grant_type' => 'client_credentials'
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $this->spotifyToken = $data['access_token'];
                
                // Calcular tempo de expiração (com margem de segurança de 5 minutos)
                $expiresIn = $data['expires_in'] ?? 3600;
                $this->spotifyTokenExpires = Carbon::now()->addSeconds($expiresIn - 300);

                // Armazenar no cache
                Cache::put('spotify_token', $this->spotifyToken, $this->spotifyTokenExpires);
                Cache::put('spotify_token_expires', $this->spotifyTokenExpires, $this->spotifyTokenExpires);

                Log::info("✅ Token do Spotify obtido com sucesso. Expira em: " . $this->spotifyTokenExpires->format('Y-m-d H:i:s'));
            } else {
                Log::error("❌ Erro ao obter token do Spotify: " . $response->status() . " - " . $response->body());
                $this->spotifyToken = null;
            }

        } catch (\Exception $e) {
            Log::error("❌ Exceção ao obter token do Spotify: " . $e->getMessage());
            $this->spotifyToken = null;
        }
    }

    /**
    * Limpa cache do Spotify (útil para debugging)
    */
    public function clearSpotifyCache(): void
    {
        Cache::forget('spotify_token');
        Cache::forget('spotify_token_expires');
        Cache::flush(); // Limpa todos os caches de gêneros também
        
        Log::info("🧹 Cache do Spotify limpo");
    }

    /**
    * Verifica se o token está válido e renova se necessário
    */
    public function ensureValidSpotifyToken(): bool
    {
        if (!$this->spotifyToken || 
            ($this->spotifyTokenExpires && Carbon::now()->gte($this->spotifyTokenExpires))) {
            
            Log::info("🔄 Token expirado ou inexistente, renovando...");
            $this->getSpotifyToken();
        }

        return !empty($this->spotifyToken);
    }
}
