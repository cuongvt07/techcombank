<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Hồ sơ nhân sự (spec 3.1.2). Tách khỏi User: User là tài khoản đăng nhập,
 * Employee là con người trong sơ đồ tổ chức và là chủ thể của mọi tiến độ học tập.
 */
class Employee extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    public const STATUS_PROBATION = 'probation';
    public const STATUS_OFFICIAL = 'official';
    public const STATUS_RESIGNED = 'resigned';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'employee_code', 'user_id', 'full_name', 'email', 'phone',
        'date_of_birth', 'gender', 'department_id', 'job_title_id',
        'job_grade_id', 'manager_id', 'employment_status', 'joined_at',
        'resigned_at', 'is_new_hire', 'note', 'avatar_file_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'joined_at' => 'date',
            'resigned_at' => 'date',
            'is_new_hire' => 'boolean',
        ];
    }

    /**
     * Chỉ ghi log các trường ảnh hưởng tới quyền truy cập và vòng đời nhân sự,
     * tránh làm phình activity_log vì các sửa đổi vặt như số điện thoại.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly([
                'employee_code', 'full_name', 'department_id', 'job_title_id',
                'job_grade_id', 'manager_id', 'employment_status',
            ])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs()
            ->useLogName('employee');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function jobGrade(): BelongsTo
    {
        return $this->belongsTo(JobGrade::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function subordinates(): HasMany
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function assignmentHistories(): HasMany
    {
        // Thêm id vào khoá sắp xếp: nhiều lần điều chuyển trong cùng một ngày
        // có effective_from bằng nhau, chỉ orderBy ngày sẽ cho thứ tự không xác định.
        return $this->hasMany(EmployeeAssignmentHistory::class)
            ->orderByDesc('effective_from')
            ->orderByDesc('id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function lessonProgresses(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function quizAttempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function supportRequests(): HasMany
    {
        return $this->hasMany(SupportRequest::class);
    }

    /**
     * Hợp đồng đang hiệu lực - dùng cho điều kiện phân quyền theo trạng thái hợp đồng (spec 3.3.1).
     */
    public function activeContract(): ?Contract
    {
        return $this->contracts()
            ->whereIn('status', [Contract::STATUS_ACTIVE, Contract::STATUS_EXPIRING])
            ->orderByDesc('effective_from')
            ->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('employment_status', [self::STATUS_PROBATION, self::STATUS_OFFICIAL]);
    }

    public function scopeInDepartment(Builder $query, int $departmentId, bool $includeSub = true): Builder
    {
        if (! $includeSub) {
            return $query->where('department_id', $departmentId);
        }

        $department = Department::find($departmentId);

        return $query->whereIn('department_id', $department ? $department->descendantIds() : [$departmentId]);
    }

    public function isActive(): bool
    {
        return in_array($this->employment_status, [self::STATUS_PROBATION, self::STATUS_OFFICIAL], true);
    }

    public function avatarFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'avatar_file_id');
    }

    /**
     * Chữ cái viết tắt cho avatar mặc định.
     *
     * Lấy chữ đầu của họ và của tên (VD "Nguyễn Văn Minh" -> "NM"). Tên một chữ
     * thì lấy một chữ cái.
     */
    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim((string) $this->full_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return '?';
        }

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1));
        }

        return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr(end($parts), 0, 1));
    }
}
