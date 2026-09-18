<?php

namespace App\Livewire\User;

use App\Models\Document;
use App\Models\DocumentAccessLog;
use App\Models\DocumentCategory;
use App\Models\Employee;
use App\Services\DocumentAccessService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Danh mục tài liệu được cấp quyền (spec 4.1).
 *
 * Chỉ hiển thị tài liệu nhân viên có quyền xem — lọc ở tầng truy vấn qua
 * DocumentAccessService, không lọc sau khi đã nạp, để danh sách còn phân trang được.
 */
class DocumentBrowser extends Component
{
    use WithPagination;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    #[Url]
    public string $categoryFilter = '';

    #[Url]
    public string $kindFilter = '';

    public bool $showViewer = false;
    public ?int $viewingId = null;
    public ?array $permission = null;

    public function render(): View
    {
        $employee = $this->employee();

        return view('livewire.user.document-browser', [
            'documents' => $this->documents($employee),
            'categories' => DocumentCategory::active()->orderBy('name')->get(),
            'viewingDocument' => $this->viewingDocument(),
            'watermarkText' => $employee && $this->viewingDocument()?->enable_watermark
                ? app(DocumentAccessService::class)->watermarkText($employee)
                : null,
        ])->layout('layouts.user', ['title' => 'Tài liệu nội bộ']);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'categoryFilter', 'kindFilter'], true)) {
            $this->resetPage();
        }
    }

    /** Mở tài liệu: kiểm tra lại quyền ở thời điểm mở và ghi log truy cập. */
    public function view(int $id, DocumentAccessService $service): void
    {
        $employee = $this->employee();
        $document = Document::find($id);

        if (! $employee || ! $document) {
            throw new NotFoundHttpException();
        }

        $resolved = $service->resolve($employee, $document);

        if (! $resolved['can_view']) {
            // Ghi cả lượt bị từ chối để phát hiện dò tìm tài liệu (spec 3.3.2)
            $service->logAccess($employee, $document, DocumentAccessLog::ACTION_DENIED, $resolved['reason']);
            session()->flash('error', 'Bạn không có quyền xem tài liệu này.');

            return;
        }

        $service->logAccess($employee, $document, DocumentAccessLog::ACTION_VIEW);

        $this->viewingId = $id;
        $this->permission = [
            'can_download' => $resolved['can_download'] && $document->allow_download,
            'can_print' => $resolved['can_print'],
        ];
        $this->showViewer = true;
    }

    private function employee(): ?Employee
    {
        return auth()->user()?->employee;
    }

    private function viewingDocument(): ?Document
    {
        return $this->viewingId ? Document::with('category')->find($this->viewingId) : null;
    }

    private function documents(?Employee $employee)
    {
        if (! $employee) {
            return Document::whereRaw('1 = 0')->paginate(12);
        }

        return app(DocumentAccessService::class)
            ->visibleDocumentsQuery($employee)
            ->with('category')
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';
                $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->when($this->categoryFilter, fn ($q) => $q->where('document_category_id', $this->categoryFilter))
            ->when($this->kindFilter, fn ($q) => $q->where('kind', $this->kindFilter))
            ->orderByDesc('published_at')
            ->paginate(12);
    }
}
