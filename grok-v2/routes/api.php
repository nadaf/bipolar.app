<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AlertSettingsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CareGrantController;
use App\Http\Controllers\Api\V1\CareInviteController;
use App\Http\Controllers\Api\V1\CarePatientController;
use App\Http\Controllers\Api\V1\ClinicProfessionalController;
use App\Http\Controllers\Api\V1\DayController;
use App\Http\Controllers\Api\V1\MoodEntryController;
use App\Http\Controllers\Api\V1\PrivateModuleController;
use App\Http\Controllers\Api\V1\ReminderController;
use App\Http\Controllers\Api\V1\ReportController;
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
        Route::patch('account', [AccountController::class, 'update'])->name('account.update');
        Route::delete('account', [AccountController::class, 'destroy'])->name('account.destroy');
        Route::put('account/pin', [AccountController::class, 'pin'])->middleware('throttle:auth')->name('account.pin');
        Route::post('account/email', [AccountController::class, 'email'])->middleware('throttle:auth')->name('account.email');
        Route::post('private-modules/unlock', [PrivateModuleController::class, 'unlock'])->middleware('throttle:auth')->name('private.unlock');
        Route::get('private-modules', [PrivateModuleController::class, 'index'])->name('private.index');
        Route::post('care/invites/{token}/accept', [CareInviteController::class, 'accept'])->middleware('throttle:auth')->name('care.invites.accept');

        Route::middleware('patient')->group(function () {
            Route::get('entries', [MoodEntryController::class, 'index'])->name('entries.index');
            Route::put('entries', [MoodEntryController::class, 'upsert'])->name('entries.upsert');
            Route::get('entries/{id}', [MoodEntryController::class, 'show'])->whereNumber('id')->name('entries.show');
            Route::delete('entries/{id}', [MoodEntryController::class, 'destroy'])->whereNumber('id')->name('entries.destroy');
            Route::get('settings/alerts', [AlertSettingsController::class, 'show'])->name('settings.alerts.show');
            Route::put('settings/alerts', [AlertSettingsController::class, 'update'])->name('settings.alerts.update');
            Route::get('days/{date}', [DayController::class, 'show'])->name('days.show');
            Route::put('days/{date}/blocks/{block}', [DayController::class, 'save'])->whereAlpha('block')->name('days.blocks.save');
            Route::get('reminders', [ReminderController::class, 'show'])->name('reminders.show');
            Route::put('reminders', [ReminderController::class, 'update'])->name('reminders.update');
            Route::get('reports/summary', [ReportController::class, 'summary'])->name('reports.summary');
            Route::get('care/grants', [CareGrantController::class, 'index'])->name('care.grants.index');
            Route::post('care/grants', [CareGrantController::class, 'store'])->name('care.grants.store');
            Route::patch('care/grants/{id}', [CareGrantController::class, 'update'])->whereNumber('id')->name('care.grants.update');
            Route::delete('care/grants/{id}', [CareGrantController::class, 'destroy'])->whereNumber('id')->name('care.grants.destroy');
            Route::get('care/grants/{id}/audit', [CareGrantController::class, 'audit'])->whereNumber('id')->name('care.grants.audit');
            Route::post('export', [AccountController::class, 'export'])->name('export');
        });

        Route::middleware('role:admin')->group(function () {
            Route::get('clinic/professionals', [ClinicProfessionalController::class, 'index'])->name('clinic.professionals.index');
            Route::post('clinic/professionals', [ClinicProfessionalController::class, 'store'])->name('clinic.professionals.store');
            Route::delete('clinic/professionals/{userId}', [ClinicProfessionalController::class, 'destroy'])->whereNumber('userId')->name('clinic.professionals.destroy');
        });

        // Rede de apoio (profissional OU familiar): so o que o paciente concedeu a ESTE usuario.
        Route::get('care/patients', [CarePatientController::class, 'index'])->name('care.patients.index');
        Route::get('care/patients/{patientId}/entries', [CarePatientController::class, 'entries'])->whereNumber('patientId')->name('care.patients.entries');
        Route::get('care/patients/{patientId}/report', [CarePatientController::class, 'report'])->whereNumber('patientId')->name('care.patients.report');
    });
});
