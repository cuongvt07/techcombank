<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lượt làm bài cấp thêm cho một nhân viên đã hết lượt (báo cáo Kết quả kiểm tra).
 * Mỗi lần cấp là một dòng để còn truy vết ai cấp, khi nào, vì sao.
 */
class QuizAttemptGrant extends Model
{
    protected $fillable = ['quiz_id', 'employee_id', 'extra_attempts', 'reason', 'granted_by'];

    protected function casts(): array
    {
        return ['extra_attempts' => 'integer'];
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
