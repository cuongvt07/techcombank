<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Gom hoạt động của một nhân viên từ nhiều nguồn thành một dòng thời gian.
 *
 * Đây là cách CRM hiển thị bản ghi: thay vì bắt người dùng mở 5 màn hình khác nhau
 * để ghép lại câu chuyện, mọi sự kiện liên quan đến một người nằm chung một dòng
 * thời gian xếp theo thứ tự.
 */
class EmployeeTimelineService
{
    /**
     * @return Collection<int, array{
     *     at: \Illuminate\Support\Carbon,
     *     type: string,
     *     icon: string,
     *     tone: string,
     *     title: string,
     *     detail: ?string
     * }>
     */
    public function build(Employee $employee, int $limit = 40): Collection
    {
        $events = collect()
            ->merge($this->assignmentEvents($employee))
            ->merge($this->enrollmentEvents($employee))
            ->merge($this->lessonEvents($employee))
            ->merge($this->quizEvents($employee))
            ->merge($this->certificateEvents($employee))
            ->merge($this->contractEvents($employee))
            ->merge($this->documentEvents($employee))
            ->merge($this->supportEvents($employee));

        return $events
            ->filter(fn ($e) => $e['at'] !== null)
            ->sortByDesc('at')
            ->take($limit)
            ->values();
    }

    private function assignmentEvents(Employee $employee): Collection
    {
        return $employee->assignmentHistories()
            ->with(['department', 'jobTitle'])
            ->get()
            ->map(fn ($history) => [
                'at' => $history->created_at,
                'type' => 'assignment',
                'icon' => 'heroicon-o-arrows-right-left',
                'tone' => 'neutral',
                'title' => $history->change_reason ?? 'Thay đổi vị trí công tác',
                'detail' => collect([
                    $history->department?->name,
                    $history->jobTitle?->name,
                ])->filter()->join(' · ') ?: null,
            ]);
    }

    private function enrollmentEvents(Employee $employee): Collection
    {
        return $employee->enrollments()
            ->with('course')
            ->get()
            ->flatMap(function ($enrollment) {
                $events = [[
                    'at' => $enrollment->assigned_at,
                    'type' => 'enrollment',
                    'icon' => 'heroicon-o-academic-cap',
                    'tone' => 'neutral',
                    'title' => 'Được giao khóa học',
                    'detail' => $enrollment->course?->title,
                ]];

                if ($enrollment->completed_at) {
                    $events[] = [
                        'at' => $enrollment->completed_at,
                        'type' => 'course_completed',
                        'icon' => 'heroicon-o-check-circle',
                        'tone' => 'success',
                        'title' => 'Hoàn thành khóa học',
                        'detail' => $enrollment->course?->title,
                    ];
                }

                return $events;
            });
    }

    /** Chỉ lấy mốc hoàn thành bài, không lấy từng lượt mở bài — tránh làm nhiễu dòng thời gian. */
    private function lessonEvents(Employee $employee): Collection
    {
        return $employee->lessonProgresses()
            ->with('lesson.course')
            ->where('status', 'completed')
            ->latest('completed_at')
            ->limit(15)
            ->get()
            ->map(fn ($progress) => [
                'at' => $progress->completed_at,
                'type' => 'lesson',
                'icon' => 'heroicon-o-book-open',
                'tone' => 'neutral',
                'title' => 'Hoàn thành bài học',
                'detail' => $progress->lesson?->title,
            ]);
    }

    private function quizEvents(Employee $employee): Collection
    {
        return $employee->quizAttempts()
            ->with('quiz')
            ->whereNotNull('submitted_at')
            ->latest('submitted_at')
            ->limit(15)
            ->get()
            ->map(fn ($attempt) => [
                'at' => $attempt->submitted_at,
                'type' => 'quiz',
                'icon' => $attempt->is_passed ? 'heroicon-o-clipboard-document-check' : 'heroicon-o-x-circle',
                'tone' => $attempt->is_passed ? 'success' : 'danger',
                'title' => $attempt->is_passed ? 'Đạt bài kiểm tra' : 'Chưa đạt bài kiểm tra',
                'detail' => sprintf(
                    '%s — %d%% (lần %d)',
                    $attempt->quiz?->title ?? 'Bài kiểm tra',
                    (int) $attempt->percentage,
                    $attempt->attempt_no,
                ),
            ]);
    }

    private function certificateEvents(Employee $employee): Collection
    {
        return $employee->certificates()
            ->with('course')
            ->get()
            ->map(fn ($certificate) => [
                'at' => $certificate->issued_at,
                'type' => 'certificate',
                'icon' => 'heroicon-o-trophy',
                'tone' => 'success',
                'title' => 'Được cấp chứng nhận',
                'detail' => $certificate->course?->title . ' · ' . $certificate->certificate_no,
            ]);
    }

    private function contractEvents(Employee $employee): Collection
    {
        return $employee->contracts()
            ->with('contractType')
            ->get()
            ->map(fn ($contract) => [
                'at' => $contract->created_at,
                'type' => 'contract',
                'icon' => 'heroicon-o-document-check',
                'tone' => 'neutral',
                'title' => 'Hợp đồng ' . ($contract->contractType?->name ?? ''),
                'detail' => $contract->contract_no . ' · hiệu lực từ '
                    . $contract->effective_from?->format('d/m/Y'),
            ]);
    }

    /** Gộp lượt xem tài liệu theo ngày, nếu không dòng thời gian sẽ ngập log truy cập. */
    private function documentEvents(Employee $employee): Collection
    {
        return \App\Models\DocumentAccessLog::query()
            ->where('employee_id', $employee->id)
            ->selectRaw('DATE(accessed_at) as day, COUNT(*) as total, MAX(accessed_at) as last_at')
            ->groupBy('day')
            ->orderByDesc('day')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'at' => \Illuminate\Support\Carbon::parse($row->last_at),
                'type' => 'document',
                'icon' => 'heroicon-o-document-magnifying-glass',
                'tone' => 'muted',
                'title' => 'Truy cập tài liệu nội bộ',
                'detail' => $row->total . ' lượt xem trong ngày',
            ]);
    }

    private function supportEvents(Employee $employee): Collection
    {
        return $employee->supportRequests()
            ->get()
            ->map(fn ($request) => [
                'at' => $request->created_at,
                'type' => 'support',
                'icon' => 'heroicon-o-lifebuoy',
                'tone' => $request->status === 'resolved' ? 'success' : 'warning',
                'title' => 'Gửi yêu cầu hỗ trợ',
                'detail' => $request->ticket_no . ' · ' . $request->subject,
            ]);
    }
}
