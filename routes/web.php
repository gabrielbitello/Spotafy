<?php

use Illuminate\Support\Facades\Route;
use App\Livewire\SearchMusica;

Route::get('/', function () {
    return view('pages.home');
});

Route::get('/search/{pesquisa?}', function ($pesquisa = null) {
    return view('pages.search', ['pesquisa' => $pesquisa]);
})->name('search');