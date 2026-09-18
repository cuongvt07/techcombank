<?php

namespace App\Livewire\Admin;

use App\Models\FileFolder;
use App\Models\StoredFile;
use App\Services\FileStorageService;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kho tài nguyên dạng Drive: cây thư mục bên trái, nội dung bên phải,
 * kéo file từ máy vào để tải lên.
 *
 * Đây là tầng lưu trữ dùng chung — tài liệu đào tạo, hợp đồng, ảnh đều
 * lấy file từ đây thay vì mỗi nơi giữ một bản sao.
 */
class FileManager extends Component
{
    use WithPagination, WithFileUploads;

    /** Thư mục đang mở. NULL = thư mục gốc. */
    #[Url]
    public ?int $folder = null;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    #[Url]
    public string $kindFilter = '';

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploads = [];

    // --- Form thư mục ---
    public bool $showFolderModal = false;
    public ?int $editingFolderId = null;
    public string $folderName = '';
    public string $folderDescription = '';

    // --- Form đổi tên file ---
    public bool $showRenameModal = false;
    public ?int $renamingFileId = null;
    public string $fileName = '';

    // --- Di chuyển ---
    public bool $showMoveModal = false;
    public ?int $movingFileId = null;
    public ?int $movingFolderId = null;
    public ?int $moveTargetId = null;

    public function render(): View
    {
        $service = app(FileStorageService::class);

        return view('livewire.admin.file-manager', [
            'currentFolder' => $this->currentFolder(),
            'breadcrumbs' => $this->breadcrumbs(),
            'folders' => $this->folders(),
            'files' => $this->files(),
            'tree' => $this->tree(),
            'stats' => $service->stats(),
            'moveTargets' => $this->showMoveModal ? $this->moveTargets() : collect(),
        ])->layout('layouts.admin', ['title' => 'Kho tài nguyên']);
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'kindFilter'], true)) {
            $this->resetPage();
        }

        // File vừa thả vào được lưu ngay, không bắt bấm thêm nút xác nhận
        if (str_starts_with($property, 'uploads')) {
            $this->saveUploads();
        }
    }

    public function openFolder(?int $id): void
    {
        $this->folder = $id;
        $this->search = '';
        $this->resetPage();
    }

    // ---- Tải lên ------------------------------------------------------------

    /** Lưu các file vừa thả vào thư mục đang mở. */
    public function saveUploads(): void
    {
        if ($this->uploads === []) {
            return;
        }

        $this->validate([
            'uploads.*' => ['file', 'max:' . FileStorageService::MAX_FILE_KB],
        ], [
            'uploads.*.max' => 'Mỗi file không được vượt quá 2MB.',
        ]);

        $service = app(FileStorageService::class);
        $count = 0;

        foreach ($this->uploads as $upload) {
            $service->storeUpload($upload, $this->folder, auth()->id());
            $count++;
        }

        $this->uploads = [];
        $this->resetPage();

        session()->flash('status', "Đã tải lên {$count} file.");
    }

    // ---- Thư mục ------------------------------------------------------------

    public function createFolder(): void
    {
        $this->reset(['editingFolderId', 'folderName', 'folderDescription']);
        $this->showFolderModal = true;
    }

    public function editFolder(int $id): void
    {
        $folder = FileFolder::findOrFail($id);

        $this->editingFolderId = $folder->id;
        $this->folderName = $folder->name;
        $this->folderDescription = (string) $folder->description;
        $this->showFolderModal = true;
    }

    public function saveFolder(FileStorageService $service): void
    {
        $this->validate([
            'folderName' => ['required', 'string', 'max:255'],
            'folderDescription' => ['nullable', 'string', 'max:500'],
        ], [
            'folderName.required' => 'Hãy nhập tên thư mục.',
        ]);

        try {
            if ($this->editingFolderId) {
                $folder = FileFolder::findOrFail($this->editingFolderId);
                $service->renameFolder($folder, $this->folderName);
                $folder->update(['description' => $this->folderDescription ?: null]);
                session()->flash('status', 'Đã cập nhật thư mục.');
            } else {
                $folder = $service->createFolder($this->folderName, $this->folder, auth()->id());
                $folder->update(['description' => $this->folderDescription ?: null]);
                session()->flash('status', 'Đã tạo thư mục.');
            }
        } catch (RuntimeException $e) {
            $this->addError('folderName', $e->getMessage());

            return;
        }

        $this->showFolderModal = false;
        $this->reset(['editingFolderId', 'folderName', 'folderDescription']);
    }

    public function deleteFolder(int $id, bool $force = false): void
    {
        try {
            app(FileStorageService::class)->deleteFolder(FileFolder::findOrFail($id), $force);
            session()->flash('status', 'Đã xóa thư mục.');
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // ---- File ---------------------------------------------------------------

    public function renameFile(int $id): void
    {
        $file = StoredFile::findOrFail($id);

        $this->renamingFileId = $file->id;
        $this->fileName = $file->name;
        $this->showRenameModal = true;
    }

    public function saveFileName(FileStorageService $service): void
    {
        $this->validate([
            'fileName' => ['required', 'string', 'max:255'],
        ], [
            'fileName.required' => 'Hãy nhập tên file.',
        ]);

        $service->renameFile(StoredFile::findOrFail($this->renamingFileId), $this->fileName);

        session()->flash('status', 'Đã đổi tên file.');

        $this->showRenameModal = false;
        $this->reset(['renamingFileId', 'fileName']);
    }

    public function deleteFile(int $id, FileStorageService $service): void
    {
        $service->deleteFile(StoredFile::findOrFail($id));
        session()->flash('status', 'Đã xóa file. File vẫn khôi phục được trong thùng rác.');
    }

    /** Tải file về máy. Stream thay vì đọc hết vào bộ nhớ, tránh chết với file lớn. */
    public function download(int $id): StreamedResponse
    {
        $file = StoredFile::findOrFail($id);

        abort_unless($file->exists(), 404, 'File không còn tồn tại trên hệ thống.');

        return \Illuminate\Support\Facades\Storage::disk($file->disk)
            ->download($file->path, $file->name);
    }

    // ---- Di chuyển ----------------------------------------------------------

    public function moveFile(int $id): void
    {
        $this->reset(['movingFolderId', 'moveTargetId']);
        $this->movingFileId = $id;
        $this->showMoveModal = true;
    }

    public function moveFolderTo(int $id): void
    {
        $this->reset(['movingFileId', 'moveTargetId']);
        $this->movingFolderId = $id;
        $this->showMoveModal = true;
    }

    public function confirmMove(FileStorageService $service): void
    {
        try {
            if ($this->movingFileId) {
                $service->moveFile(StoredFile::findOrFail($this->movingFileId), $this->moveTargetId);
                session()->flash('status', 'Đã di chuyển file.');
            } elseif ($this->movingFolderId) {
                $service->moveFolder(FileFolder::findOrFail($this->movingFolderId), $this->moveTargetId);
                session()->flash('status', 'Đã di chuyển thư mục.');
            }
        } catch (RuntimeException $e) {
            session()->flash('error', $e->getMessage());
        }

        $this->showMoveModal = false;
        $this->reset(['movingFileId', 'movingFolderId', 'moveTargetId']);
    }

    // ---- Truy vấn -----------------------------------------------------------

    private function currentFolder(): ?FileFolder
    {
        return $this->folder ? FileFolder::find($this->folder) : null;
    }

    private function breadcrumbs()
    {
        $folder = $this->currentFolder();

        return $folder ? $folder->ancestors()->push($folder) : collect();
    }

    /** Thư mục con của thư mục đang mở. Khi đang tìm kiếm thì không hiện. */
    private function folders()
    {
        if ($this->search !== '') {
            return collect();
        }

        return FileFolder::query()
            ->where('parent_id', $this->folder)
            ->withCount(['children', 'files'])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();
    }

    /**
     * File trong thư mục đang mở.
     * Khi tìm kiếm thì tìm toàn kho, vì người dùng thường không nhớ file nằm ở đâu.
     */
    private function files()
    {
        return StoredFile::query()
            ->with(['uploadedBy', 'folder'])
            ->when(
                $this->search !== '',
                fn ($q) => $q->where('name', 'like', '%' . $this->search . '%'),
                fn ($q) => $q->inFolder($this->folder),
            )
            ->when($this->kindFilter, fn ($q) => $q->ofKind($this->kindFilter))
            ->orderByDesc('created_at')
            ->paginate(20);
    }

    /** Cây thư mục ở sidebar — nạp một lần rồi dựng cấu trúc lồng nhau ở PHP. */
    private function tree()
    {
        return FileFolder::query()
            ->withCount('files')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->groupBy('parent_id');
    }

    /** Thư mục có thể chuyển đến, loại bỏ nhánh con của chính thư mục đang chuyển. */
    private function moveTargets()
    {
        $query = FileFolder::query()->orderBy('path')->orderBy('name');

        if ($this->movingFolderId) {
            $moving = FileFolder::find($this->movingFolderId);

            if ($moving) {
                $query->where('id', '!=', $moving->id)
                    ->where('path', 'not like', $moving->path . $moving->id . '/%');
            }
        }

        return $query->get();
    }
}
