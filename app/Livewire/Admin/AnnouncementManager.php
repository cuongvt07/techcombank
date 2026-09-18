<?php

namespace App\Livewire\Admin;

use App\Models\Announcement;
use App\Models\Employee;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Quản lý sự kiện / thông báo gửi tới nhân viên (spec 3.1.5).
 *
 * Hai phạm vi gán: toàn công ty hoặc riêng một nhân viên. Sự kiện riêng sẽ
 * được đẩy lên đầu danh sách bên site người dùng.
 */
class AnnouncementManager extends Component
{
    use WithPagination;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    /** Lọc theo phạm vi: '' = tất cả, 'all' = toàn công ty, 'personal' = riêng cá nhân. */
    #[Url]
    public string $scopeFilter = '';

    #[Url]
    public string $typeFilter = '';

    public bool $showModal = false;
    public ?int $editingId = null;

    // --- Form ---
    public string $title = '';
    public string $content = '';
    public string $type = Announcement::TYPE_GENERAL;

    /** Phạm vi gán: 'all' = toàn công ty, 'personal' = riêng một nhân viên. */
    public string $scope = 'all';
    public ?int $employee_id = null;

    public ?string $starts_at = null;
    public ?string $ends_at = null;
    public string $location = '';

    public ?string $published_at = null;
    public ?string $expires_at = null;

    public function render(): View
    {
        return view('livewire.admin.announcement-manager', [
            'announcements' => $this->announcements(),
            'employees' => Employee::active()->orderBy('full_name')->limit(300)->get(),
            'types' => Announcement::TYPES,
            'stats' => $this->stats(),
        ])->layout('layouts.admin', ['title' => 'Sự kiện & thông báo']);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'scopeFilter', 'typeFilter'], true)) {
            $this->resetPage();
        }

        // Chuyển về toàn công ty thì bỏ nhân viên đã chọn, tránh lưu nhầm
        if ($property === 'scope' && $this->scope === 'all') {
            $this->employee_id = null;
        }
    }

    private function announcements()
    {
        return Announcement::query()
            ->with(['employee:id,full_name', 'createdBy:id,name'])
            ->when($this->search !== '', function ($q) {
                $term = '%' . $this->search . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('title', 'like', $term)
                        ->orWhere('content', 'like', $term)
                        ->orWhere('location', 'like', $term);
                });
            })
            ->when($this->scopeFilter === 'all', fn ($q) => $q->whereNull('employee_id'))
            ->when($this->scopeFilter === 'personal', fn ($q) => $q->whereNotNull('employee_id'))
            ->when($this->typeFilter !== '', fn ($q) => $q->where('type', $this->typeFilter))
            // Sắp diễn ra lên đầu, rồi tới sự kiện không có mốc thời gian
            ->orderByRaw('starts_at IS NULL')
            ->orderByDesc('starts_at')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /** @return array<string, int> */
    private function stats(): array
    {
        return [
            'total' => Announcement::count(),
            'published' => Announcement::visible()->count(),
            'upcoming' => Announcement::visible()->where('starts_at', '>', now())->count(),
            'draft' => Announcement::whereNull('published_at')->count(),
        ];
    }

    public function create(): void
    {
        $this->resetForm();
        $this->published_at = now()->format('Y-m-d\TH:i');
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $announcement = Announcement::findOrFail($id);

        $this->editingId = $announcement->id;
        $this->title = $announcement->title;
        $this->content = $announcement->content;
        $this->type = $announcement->type;

        $this->scope = $announcement->employee_id ? 'personal' : 'all';
        $this->employee_id = $announcement->employee_id;

        $this->starts_at = $announcement->starts_at?->format('Y-m-d\TH:i');
        $this->ends_at = $announcement->ends_at?->format('Y-m-d\TH:i');
        $this->location = (string) $announcement->location;

        $this->published_at = $announcement->published_at?->format('Y-m-d\TH:i');
        $this->expires_at = $announcement->expires_at?->format('Y-m-d\TH:i');

        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate($this->rules(), $this->messages());

        Announcement::updateOrCreate(
            ['id' => $this->editingId],
            [
                'title' => $data['title'],
                'content' => $data['content'],
                'type' => $data['type'],

                // Toàn công ty thì employee_id để NULL
                'employee_id' => $this->scope === 'personal' ? $data['employee_id'] : null,
                'department_id' => null,

                'starts_at' => $data['starts_at'] ?: null,
                'ends_at' => $data['ends_at'] ?: null,
                'location' => $data['location'] ?: null,

                'published_at' => $data['published_at'] ?: null,
                'expires_at' => $data['expires_at'] ?: null,

                'created_by' => $this->editingId ? Announcement::find($this->editingId)?->created_by : auth()->id(),
            ]
        );

        session()->flash('status', $this->editingId ? 'Đã cập nhật sự kiện.' : 'Đã tạo sự kiện.');

        $this->showModal = false;
        $this->resetForm();
    }

    /** Phát hành ngay một sự kiện còn ở dạng nháp. */
    public function publish(int $id): void
    {
        $announcement = Announcement::findOrFail($id);
        $announcement->update(['published_at' => now()]);

        session()->flash('status', 'Đã phát hành sự kiện.');
    }

    /** Gỡ khỏi site người dùng nhưng vẫn giữ nội dung để đăng lại. */
    public function unpublish(int $id): void
    {
        Announcement::findOrFail($id)->update(['published_at' => null]);

        session()->flash('status', 'Đã gỡ sự kiện khỏi site người dùng.');
    }

    public function delete(int $id): void
    {
        Announcement::findOrFail($id)->delete();

        session()->flash('status', 'Đã xóa sự kiện.');
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:5000'],
            'type' => ['required', Rule::in(array_keys(Announcement::TYPES))],

            'scope' => ['required', Rule::in(['all', 'personal'])],
            // Chỉ bắt buộc chọn nhân viên khi gán riêng
            'employee_id' => [
                Rule::requiredIf(fn () => $this->scope === 'personal'),
                'nullable',
                'exists:employees,id',
            ],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['nullable', 'string', 'max:255'],

            'published_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after:published_at'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'title.required' => 'Hãy nhập tiêu đề sự kiện.',
            'content.required' => 'Hãy nhập nội dung sự kiện.',
            'employee_id.required' => 'Hãy chọn nhân viên nhận sự kiện này.',
            'ends_at.after_or_equal' => 'Thời gian kết thúc phải sau thời gian bắt đầu.',
            'expires_at.after' => 'Thời gian hết hiệu lực phải sau thời gian phát hành.',
        ];
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'title', 'content', 'type', 'scope', 'employee_id',
            'starts_at', 'ends_at', 'location', 'published_at', 'expires_at',
        ]);

        $this->type = Announcement::TYPE_GENERAL;
        $this->scope = 'all';

        $this->resetValidation();
    }
}
