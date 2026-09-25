<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EmployeeController as AdminEmployeeController;
use App\Http\Controllers\Admin\ManagerController as AdminManagerController;
use App\Http\Controllers\Admin\PayrollController as AdminPayrollController;
use App\Http\Controllers\Admin\TopicController as AdminTopicController;
use App\Http\Controllers\EmployeePortalController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\ManagerPortalController;
use App\Http\Controllers\TrackerController;
use App\Models\Channel;
use App\Models\Topic;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

// ---- Landing page
Route::get('/', function () {
    return view('landing', [
        'channels' => Channel::withCount('topics')->orderBy('sort_order')->get(),
        'total' => Topic::count(),
        'done' => Topic::where('is_done', true)->count(),
    ]);
})->name('home');

// ---- One login for admin + employees
Route::get('login', [LoginController::class, 'show'])->name('login');
Route::post('login', [LoginController::class, 'login'])->middleware('throttle:10,1')->name('login.submit');
Route::post('logout', [LoginController::class, 'logout'])->name('logout');

// ---- Tracker: public to read, admin-only to change status
Route::get('/tracker', [TrackerController::class, 'index'])->name('tracker.index');
Route::post('/topics/{topic}/toggle', [TrackerController::class, 'toggle'])
    ->middleware('admin')->name('tracker.toggle');

// ---- Admin
Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');
    Route::get('topics', [AdminTopicController::class, 'index'])->name('topics.index');
    Route::post('topics', [AdminTopicController::class, 'store'])->name('topics.store');
    Route::put('topics/{topic}', [AdminTopicController::class, 'update'])->name('topics.update');
    Route::delete('topics/{topic}', [AdminTopicController::class, 'destroy'])->name('topics.destroy');
    Route::put('topics/{topic}/assign', [AdminEmployeeController::class, 'assign'])->name('topics.assign');

    Route::get('employees', [AdminEmployeeController::class, 'index'])->name('employees.index');
    Route::post('employees', [AdminEmployeeController::class, 'store'])->name('employees.store');
    Route::put('employees/{employee}', [AdminEmployeeController::class, 'update'])->name('employees.update');
    Route::delete('employees/{employee}', [AdminEmployeeController::class, 'destroy'])->name('employees.destroy');
    Route::put('rates', [AdminEmployeeController::class, 'saveRates'])->name('rates.save');

    Route::get('payroll', [AdminPayrollController::class, 'index'])->name('payroll');
    Route::post('payroll/payments', [AdminPayrollController::class, 'store'])->name('payments.store');
    Route::delete('payments/{payment}', [AdminPayrollController::class, 'destroy'])->name('payments.destroy');

    Route::get('managers', [AdminManagerController::class, 'index'])->name('managers.index');
    Route::post('managers', [AdminManagerController::class, 'store'])->name('managers.store');
    Route::put('managers/{manager}', [AdminManagerController::class, 'update'])->name('managers.update');
    Route::delete('managers/{manager}', [AdminManagerController::class, 'destroy'])->name('managers.destroy');
});

// ---- YouTube title preview. Deliberately stateless (no session/cookies): it is called via AJAX while an
// employee types, and a session-writing request racing the form POST would wipe the flash message.
Route::get('video-preview', [EmployeePortalController::class, 'preview'])
    ->withoutMiddleware([
        EncryptCookies::class,
        AddQueuedCookiesToResponse::class,
        StartSession::class,
        ShareErrorsFromSession::class,
        ValidateCsrfToken::class,
    ])
    ->middleware('throttle:30,1')
    ->name('video.preview');

// ---- Employee portal
Route::prefix('employee')->name('employee.')->middleware(['auth:employee', 'employee.active'])->group(function () {
    Route::get('/', [EmployeePortalController::class, 'dashboard'])->name('dashboard');
    Route::get('topics', [EmployeePortalController::class, 'topics'])->name('topics');
    Route::get('custom-topics', [EmployeePortalController::class, 'customTopics'])->name('custom-topics');
    Route::post('topics', [EmployeePortalController::class, 'storeTopic'])->name('topics.store');
    Route::put('topics/{topic}', [EmployeePortalController::class, 'updateTopic'])->name('topics.update');
    Route::delete('topics/{topic}', [EmployeePortalController::class, 'destroyTopic'])->name('topics.destroy');
    Route::post('topics/{topic}/video', [EmployeePortalController::class, 'saveVideo'])->name('video.save');
    Route::delete('topics/{topic}/video', [EmployeePortalController::class, 'removeVideo'])->name('video.remove');
    Route::post('topics/{topic}/toggle', [EmployeePortalController::class, 'toggle'])->name('toggle');
});

// ---- Manager portal
Route::prefix('manager')->name('manager.')->middleware(['auth:manager', 'manager.active'])->group(function () {
    Route::get('/', [ManagerPortalController::class, 'dashboard'])->name('dashboard');
    Route::get('topics', [ManagerPortalController::class, 'topics'])->name('topics');
    Route::post('topics', [ManagerPortalController::class, 'storeTopic'])->name('topics.store');
    Route::put('topics/{topic}', [ManagerPortalController::class, 'updateTopic'])->name('topics.update');
    Route::delete('topics/{topic}', [ManagerPortalController::class, 'destroyTopic'])->name('topics.destroy');
    Route::put('topics/{topic}/assign', [ManagerPortalController::class, 'assign'])->name('topics.assign');
    Route::get('earnings', [ManagerPortalController::class, 'earnings'])->name('earnings');
});
