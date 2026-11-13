<?php

namespace App\Http\Controllers;

use App\Models\Certificate;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trang xem chứng nhận hoàn thành khóa học (khổ A4 ngang, in / lưu PDF được).
 *
 * Chỉ chính nhân viên được cấp mới xem được; người khác nhận 403 dù đoán đúng id.
 */
class CertificateController extends Controller
{
    public function show(Request $request, Certificate $certificate): View
    {
        $employee = $request->user()?->employee;

        abort_unless($employee && $certificate->employee_id === $employee->id, 403);

        $certificate->loadMissing(['course', 'employee']);

        return view('certificates.show', [
            'certificate' => $certificate,
        ]);
    }
}
