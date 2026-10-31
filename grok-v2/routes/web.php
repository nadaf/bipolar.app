<?php

use App\Http\Controllers\Web\AlertSettingsController;
use App\Http\Controllers\Web\DiaryController;
use App\Http\Controllers\Web\SessionController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/hoje');
Route::get('/entrar', [SessionController::class, 'form'])->name('login');
Route::post('/entrar', [SessionController::class, 'login'])->middleware('throttle:auth');
Route::post('/sair', [SessionController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware(['auth', 'tenant', 'patient'])->group(function () {
    Route::get('/hoje', [DiaryController::class, 'today'])->name('diary.today');
    Route::post('/hoje/registro', [DiaryController::class, 'save'])->name('diary.save');
    Route::get('/ajustes/alertas', [AlertSettingsController::class, 'edit'])->name('settings.alerts');
    Route::post('/ajustes/alertas', [AlertSettingsController::class, 'update'])->name('settings.alerts.save');
});
