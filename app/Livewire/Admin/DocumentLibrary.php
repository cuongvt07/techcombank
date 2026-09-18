<?php

namespace App\Livewire\Admin;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentVersion;
use App\Services\FileStorageService;
use App\Services\VideoEmbedService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Thư viện tài liệu nội bộ (spec 3.2.1 + 3.2.2).
 *
 * File và video dùng chung màn hình vì chia sẻ toàn bộ vòng đời: metadata,
 * versioning, phân quyền, watermark, log truy cập. Chỉ khác vài trường riêng
 * (thời lượng, chương, phụ đề) hiện theo `kind`.
 */
class DocumentLibrary extends Component
{
    use WithPagination, WithFileUploads;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    #[Url]
    public string $kindFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $categoryFilter = '';

    public bool $showModal = false;
    public bool $showVersions = false;
    public ?int $editingId = null;
    public ?int $versionDocumentId = null;

    public string $title = '';
    public string $description = '';
    public string $kind = Document::KIND_FILE;
    public ?int $document_category_id = null;
    public ?int $owner_department_id = null;
    public string $confidentiality = Document::CONF_INTERNAL;
    public bool $allow_download = false;
    public bool $enable_watermark = true;
    public ?int $duration_seconds = null;
    /** Link video từ nguồn ngoài — video không upload được do giới hạn 2MB. */
    public string $video_url = '';
    public string $change_note = '';
    public $upload = null;

    public function render(): View
    {
        return view('livewire.admin.document-library', [
            'documents' => $this->documents(),
            'categories' => DocumentCategory::active()->orderBy('name')->get(),
            'departments' => Department::active()->orderBy('name')->get(),
            'versions' => $this->versions(),
        ])->layout('layouts.admin', ['title' => 'Thư viện tài liệu']);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'kindFilter', 'statusFilter', 'categoryFilter'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $document = Document::findOrFail($id);

        $this->editingId = $document->id;
        $this->title = $document->title;
        $this->description = (string) $document->description;
        $this->kind = $document->kind;
        $this->document_category_id = $document->document_category_id;
        $this->owner_department_id = $document->owner_department_id;
        $this->confidentiality = $document->confidentiality;
        $this->allow_download = (bool) $document->allow_download;
        $this->enable_watermark = (bool) $document->enable_watermark;
        $this->duration_seconds = $document->duration_seconds;
        $this->video_url = (string) $document->video_url;
        $this->change_note = '';
        $this->upload = null;

        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate($this->rules());

        DB::transaction(function () use ($data) {
            $payload = [
                'title' => $data['title'],
                'description' => $data['description'] ?: null,
                'kind' => $data['kind'],
                'document_category_id' => $data['document_category_id'],
                'owner_department_id' => $data['owner_department_id'],
                'confidentiality' => $data['confidentiality'],
                'allow_download' => $this->allow_download,
                'enable_watermark' => $this->enable_watermark,
                'duration_seconds' => $data['kind'] === Document::KIND_VIDEO ? $data['duration_seconds'] : null,
            ];

            // Video nhúng từ nguồn ngoài: tách mã video để dựng link iframe
            if ($data['kind'] === Document::KIND_VIDEO) {
                $parsed = app(VideoEmbedService::class)->parse($this->video_url);

                $payload['video_url'] = $this->video_url ?: null;
                $payload['video_provider'] = $parsed['provider'] ?? null;
                $payload['video_embed_id'] = $parsed['id'] ?? null;
            } else {
                $payload['video_url'] = null;
                $payload['video_provider'] = null;
                $payload['video_embed_id'] = null;
            }

            if ($this->editingId) {
                $document = Document::findOrFail($this->editingId);
                $document->update($payload);
            } else {
                $payload['slug'] = $this->uniqueSlug($data['title']);
                $payload['created_by'] = auth()->id();
                $payload['status'] = Document::STATUS_DRAFT;
                $document = Document::create($payload);
            }

            // File mới luôn sinh một phiên bản mới, giữ nguyên lịch sử cũ (spec 3.2.1).
            // Video không đi đường này: nội dung nằm ở nguồn ngoài, không có file để lưu.
            if ($this->upload && ! $document->isVideo()) {
                $this->storeVersion($document);
            }
        });

        session()->flash('status', $this->editingId ? 'Đã cập nhật tài liệu.' : 'Đã tạo tài liệu.');

        $this->showModal = false;
        $this->resetForm();
    }

    /**
     * Xuất bản tài liệu. Chỉ cho xuất bản khi đã có ít nhất một phiên bản file,
     * tránh nhân viên mở ra thấy tài liệu rỗng.
     */
    public function publish(int $id): void
    {
        $document = Document::findOrFail($id);

        // Video cần link nguồn, tài liệu cần file — hai loại nội dung khác nhau
        if ($document->isVideo() && ! $document->hasPlayableVideo()) {
            session()->flash('error', 'Chưa có link video, không thể xuất bản.');

            return;
        }

        if (! $document->isVideo() && ! $document->current_version_id) {
            session()->flash('error', 'Chưa có file nội dung, không thể xuất bản.');

            return;
        }

        $document->update([
            'status' => Document::STATUS_PUBLISHED,
            'published_at' => now(),
            'published_by' => auth()->id(),
        ]);

        session()->flash('status', 'Đã xuất bản tài liệu.');
    }

    public function unpublish(int $id): void
    {
        Document::findOrFail($id)->update(['status' => Document::STATUS_ARCHIVED]);
        session()->flash('status', 'Đã lưu trữ tài liệu.');
    }

    public function viewVersions(int $id): void
    {
        $this->versionDocumentId = $id;
        $this->showVersions = true;
    }

    /** Quay về một phiên bản cũ mà không xoá lịch sử (spec 3.2.1). */
    public function rollback(int $versionId): void
    {
        $version = DocumentVersion::findOrFail($versionId);
        $version->document->rollbackTo($version);

        session()->flash('status', "Đã khôi phục về phiên bản {$version->version_no}.");
    }

    /**
     * Lưu file vào kho tài nguyên rồi tạo phiên bản trỏ tới nó.
     *
     * File đi vào kho chung thay vì bản sao riêng của tài liệu: quản trị viên
     * thấy được nó trong Kho tài nguyên, và đổi nơi lưu chỉ sửa một chỗ.
     */
    private function storeVersion(Document $document): void
    {
        $storedFile = app(FileStorageService::class)->storeForPurpose(
            $this->upload,
            $document->isVideo()
                ? FileStorageService::PURPOSE_VIDEO
                : FileStorageService::PURPOSE_DOCUMENT,
            auth()->id(),
        );

        $document->newVersion([
            'stored_file_id' => $storedFile->id,
            'change_note' => $this->change_note ?: null,
            'original_filename' => $storedFile->original_name,
            'mime_type' => $storedFile->mime_type,
            'size_bytes' => $storedFile->size_bytes,
            'checksum' => $storedFile->checksum,
        ], auth()->id());
    }

    private function documents()
    {
        return Document::query()
            ->with(['category', 'ownerDepartment', 'currentVersion'])
            ->withCount('versions')
            ->when($this->search, function ($query) {
                $term = '%' . $this->search . '%';
                $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('description', 'like', $term));
            })
            ->when($this->kindFilter, fn ($q) => $q->where('kind', $this->kindFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->categoryFilter, fn ($q) => $q->where('document_category_id', $this->categoryFilter))
            ->orderByDesc('id')
            ->paginate(15);
    }

    private function versions()
    {
        if (! $this->versionDocumentId) {
            return collect();
        }

        return DocumentVersion::with('createdBy')
            ->where('document_id', $this->versionDocumentId)
            ->orderByDesc('version_no')
            ->get();
    }

    /** Slug phải duy nhất vì dùng làm định danh trên URL xem tài liệu. */
    private function uniqueSlug(string $title): string
    {
        $base = Str::slug($title) ?: 'tai-lieu';
        $slug = $base;
        $i = 1;

        while (Document::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base . '-' . (++$i);
        }

        return $slug;
    }

    private function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'kind' => ['required', Rule::in([Document::KIND_FILE, Document::KIND_VIDEO])],
            'document_category_id' => ['nullable', Rule::exists('document_categories', 'id')],
            'owner_department_id' => ['nullable', Rule::exists('departments', 'id')],
            'confidentiality' => ['required', Rule::in([
                Document::CONF_PUBLIC, Document::CONF_INTERNAL,
                Document::CONF_CONFIDENTIAL, Document::CONF_RESTRICTED,
            ])],
            'duration_seconds' => ['nullable', 'integer', 'min:1'],
            'change_note' => ['nullable', 'string', 'max:255'],

            // Video: nhập link nguồn ngoài, không upload (giới hạn 2MB không đủ cho video)
            'video_url' => [
                Rule::requiredIf($this->kind === Document::KIND_VIDEO),
                'nullable',
                'url',
                'max:500',
                function (string $attribute, $value, $fail) {
                    if ($this->kind !== Document::KIND_VIDEO || blank($value)) {
                        return;
                    }

                    if (! app(VideoEmbedService::class)->parse($value)) {
                        $fail('Link không nhận diện được. Hỗ trợ YouTube, Vimeo hoặc link file .mp4/.webm/.m3u8.');
                    }
                },
            ],

            // Tài liệu mới bắt buộc có file; sửa metadata thì không cần tải lại
            'upload' => [
                ($this->editingId || $this->kind === Document::KIND_VIDEO) ? 'nullable' : 'required',
                'file',
                'max:' . FileStorageService::MAX_FILE_KB,
                'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx',
            ],
        ];
    }

    private function resetForm(): void
    {
        $this->reset([
            'editingId', 'title', 'description', 'document_category_id',
            'owner_department_id', 'duration_seconds', 'video_url', 'change_note', 'upload',
        ]);
        $this->kind = Document::KIND_FILE;
        $this->confidentiality = Document::CONF_INTERNAL;
        $this->allow_download = false;
        $this->enable_watermark = true;
        $this->resetValidation();
    }
}
