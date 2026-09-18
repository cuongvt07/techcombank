<?php

namespace App\Livewire\User;

use App\Models\Employee;
use App\Models\Enrollment;
use App\Models\Setting;
use App\Models\SupportContact;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Màn hình chào nhân viên mới (spec 4.4).
 *
 * Gom ba thứ người mới cần trong tuần đầu: giới thiệu công ty, lộ trình
 * onboarding đã được gán, và đầu mối liên hệ khi vướng mắc.
 */
class Onboarding extends Component
{
    public function render(): View
    {
        $employee = $this->employee();

        return view('livewire.user.onboarding', [
            'employee' => $employee,
            'company' => $this->companyInfo(),
            'onboardingCourses' => $this->onboardingCourses($employee),
            'contacts' => $this->contacts($employee),
            // diffInDays() trả về float ở Laravel 11 — không ép kiểu thì màn
            // hình hiện "Ngày thứ 5.64"
            'daysSinceJoined' => $employee?->joined_at
                ? (int) $employee->joined_at->diffInDays(now())
                : null,
        ])->layout('layouts.user', ['title' => 'Chào mừng bạn']);
    }

    private function employee(): ?Employee
    {
        return auth()->user()?->employee;
    }

    /**
     * Thông tin công ty lấy từ Cấu hình chung, quản trị viên sửa được.
     *
     * @return array<string, string>
     */
    private function companyInfo(): array
    {
        return [
            'name' => (string) Setting::get('company.name', 'Techcombank'),
            'intro' => (string) Setting::get(
                'company.intro',
                'Ngân hàng TMCP Kỹ thương Việt Nam, thành lập năm 1993, hoạt động trên toàn quốc.'
            ),
            'values' => (string) Setting::get('company.values', ''),
            'process' => (string) Setting::get('company.onboarding_process', ''),
        ];
    }

    /** Khóa onboarding đã gán cho nhân viên này. */
    private function onboardingCourses(?Employee $employee): Collection
    {
        if (! $employee) {
            return collect();
        }

        return Enrollment::query()
            ->with('course')
            ->where('employee_id', $employee->id)
            ->where('status', '!=', Enrollment::STATUS_CANCELLED)
            ->whereHas('course', fn ($q) => $q->where('is_onboarding', true))
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Đầu mối hỗ trợ: ưu tiên đầu mối của chính phòng ban nhân viên, rồi tới
     * đầu mối chung toàn công ty (spec 4.5).
     */
    private function contacts(?Employee $employee): Collection
    {
        return SupportContact::query()
            ->active()
            ->with('department:id,name')
            ->when($employee, fn ($q) => $q->where(function ($inner) use ($employee) {
                $inner->whereNull('department_id')
                    ->orWhere('department_id', $employee->department_id);
            }))
            ->orderByRaw('department_id IS NULL')
            ->orderBy('sort_order')
            ->get();
    }
}
