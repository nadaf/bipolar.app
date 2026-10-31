<?php

use App\Http\Controllers\Api\V1\AlertSettingsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CarePatientController;
use App\Http\Controllers\Api\V1\ClinicProfessionalController;
use App\Http\Controllers\Api\V1\MoodEntryController;
use App\Http\Controllers\Api\V1\SubdomainController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function () {
    Route::middleware('throttle:auth')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register'])->name('auth.register');
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
    });

    Route::middleware(['auth:sanctum', 'throttle:api', 'tenant'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('account', [AuthController::class, 'me'])->name('account.show');
        Route::post('auth/mfa/totp', [AuthController::class, 'mfaStub'])->name('auth.mfa');
        Route::put('account/subdomain', [SubdomainController::class, 'update'])->name('account.subdomain');

        Route::middleware('patient')->group(function () {
            Route::get('entries', [MoodEntryController::class, 'index'])->name('entries.index');
            Route::put('entries', [MoodEntryController::class, 'upsert'])->name('entries.upsert');
            Route::get('entries/{id}', [MoodEntryController::class, 'show'])->whereNumber('id')->name('entries.show');
            Route::delete('entries/{id}', [MoodEntryController::class, 'destroy'])->whereNumber('id')->name('entries.destroy');
            Route::get('settings/alerts', [AlertSettingsController::class, 'show'])->name('settings.alerts.show');
            Route::put('settings/alerts', [AlertSettingsController::class, 'update'])->name('settings.alerts.update');
        });

        Route::middleware('role:admin')->group(function () {
            Route::get('clinic/professionals', [ClinicProfessionalController::class, 'index'])->name('clinic.professionals.index');
            Route::post('clinic/professionals', [ClinicProfessionalController::class, 'store'])->name('clinic.professionals.store');
            Route::delete('clinic/professionals/{userId}', [ClinicProfessionalController::class, 'destroy'])->whereNumber('userId')->name('clinic.professionals.destroy');
        });

        Route::middleware('role:professional')->group(function () {
            Route::get('care/patients', [CarePatientController::class, 'index'])->name('care.patients.index');
            Route::get('care/patients/{patientId}/entries', [CarePatientController::class, 'entries'])->whereNumber('patientId')->name('care.patients.entries');
        });
    });
});
