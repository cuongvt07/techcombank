<?php

use App\Http\Controllers\AvatarController;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\UserGuideController;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Livewire\Admin\AccountManager;
use App\Livewire\Admin\AnnouncementManager;
use App\Livewire\Admin\ContractManager;
use App\Livewire\Admin\CourseBuilder;
use App\Livewire\Admin\Dashboard as AdminDashboard;
use App\Livewire\Admin\DocumentAccessManager;
use App\Livewire\Admin\DocumentLibrary;
use App\Livewire\Admin\EmployeeDetail;
use App\Livewire\Admin\EmployeeManager;
use App\Livewire\Admin\FileManager;
use App\Livewire\Admin\PermissionMatrix;
use App\Livewire\Admin\QuizBuilder;
use App\Livewire\Admin\SecurityCenter;
use App\Livewire\Admin\SettingsManager;
use App\Livewire\Auth\Login;
use App\Livewire\User\CoursePlayer;
use App\Livewire\User\DocumentBrowser;
use App\Livewire\User\LearningProgress;
use App\Livewire\User\EventFeed;
use App\Livewire\User\MyCourses;
use App\Livewire\User\Onboarding;
use App\Livewire\User\Profile as UserProfile;
use App\Livewire\User\QuizPlayer;
use App\Livewire\User\SupportRequestForm;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
 * Hai site chạy chung codebase nhưng tách prefix và middleware (spec mục 1):
 *  - /quan-tri : site quản trị, yêu cầu vai trò admin
 *  - /hoc-tap  : site người dùng, dành cho toàn bộ nhân viên
 */

Route::get('/', function () {
    if (! Auth::check()) {
        return redirect()->route('login');
    }

    return Auth::user()->isAdmin()
        ? redirect()->route('admin.dashboard')
        : redirect()->route('learn.events');
});

Route::middleware('guest')->group(function () {
    Route::get('/dang-nhap', Login::class)->name('login');
});

Route::post('/dang-xuat', function () {
    Auth::logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('login');
})->name('logout')->middleware('auth');

// ---------------------------------------------------------------------------
// SITE QUẢN TRỊ
// ---------------------------------------------------------------------------
Route::middleware(['auth', EnsureUserIsAdmin::class])
    ->prefix('quan-tri')
    ->name('admin.')
    ->group(function () {
        Route::get('/', AdminDashboard::class)->name('dashboard');

        // 3.1 Quản lý nhân sự
        Route::get('/tai-khoan', AccountManager::class)
            ->middleware('can:accounts.view')->name('accounts');
        Route::get('/nhan-su', EmployeeManager::class)
            ->middleware('can:employees.view')->name('employees');
        Route::get('/nhan-su/{employee}', EmployeeDetail::class)
            ->middleware('can:employees.view')->name('employees.show');
        Route::get('/hop-dong', ContractManager::class)
            ->middleware('can:contracts.view')->name('contracts');
        Route::get('/phan-quyen', PermissionMatrix::class)
            ->middleware('can:roles.manage')->name('roles');
        Route::get('/cau-hinh', SettingsManager::class)
            ->middleware('can:settings.manage')->name('settings');
        Route::get('/huong-dan/{audience?}', UserGuideController::class)->name('guide');

        // 3.1.5 Sự kiện & thông báo
        Route::get('/su-kien', AnnouncementManager::class)
            ->middleware('can:announcements.view')->name('announcements');

        // 3.2 Tài liệu nội bộ
        Route::get('/tai-lieu', DocumentLibrary::class)
            ->middleware('can:documents.view')->name('documents');
        Route::get('/bai-kiem-tra', QuizBuilder::class)
            ->middleware('can:quizzes.view')->name('quizzes');
        Route::get('/khoa-hoc', CourseBuilder::class)
            ->middleware('can:courses.view')->name('courses');
        Route::get('/kho-tai-nguyen', FileManager::class)
            ->middleware('can:documents.view')->name('files');

        // 3.3 Bảo mật
        Route::get('/phan-quyen-tai-lieu', DocumentAccessManager::class)
            ->middleware('can:documents.manage-access')->name('access-rules');
        Route::get('/bao-mat', SecurityCenter::class)
            ->middleware('can:security.view')->name('security');
    });

// ---------------------------------------------------------------------------
// SITE NGƯỜI DÙNG
// ---------------------------------------------------------------------------
Route::middleware('auth')
    ->prefix('hoc-tap')
    ->name('learn.')
    ->group(function () {
        Route::get('/su-kien', EventFeed::class)->name('events');
        Route::get('/chao-mung', Onboarding::class)->name('onboarding');

        // Ảnh đại diện: file nằm ngoài public nên phải đi qua controller
        Route::get('/anh-dai-dien/{employee}', [AvatarController::class, 'show'])->name('avatar');
        Route::get('/khoa-hoc', MyCourses::class)->name('courses');
        Route::get('/khoa-hoc/{course:slug}', CoursePlayer::class)->name('course');
        Route::get('/khoa-hoc/{course:slug}/kiem-tra/{lesson}', QuizPlayer::class)->name('quiz');
        Route::get('/tien-do', LearningProgress::class)->name('progress');
        Route::get('/tai-lieu', DocumentBrowser::class)->name('documents');
        Route::get('/ho-tro', SupportRequestForm::class)->name('support');
        Route::get('/huong-dan-su-dung', UserGuideController::class)->name('guide');
        Route::get('/ca-nhan', UserProfile::class)->name('profile');

        // Phát file tài liệu: quyền được kiểm tra lại và ghi log ở controller,
        // file nằm ngoài public nên không có đường nào truy cập vòng qua đây.
        Route::get('/tai-lieu/{document}/xem', [DocumentFileController::class, 'stream'])
            ->name('documents.stream');
        Route::get('/tai-lieu/{document}/tai-xuong', [DocumentFileController::class, 'download'])
            ->name('documents.download');
    });
