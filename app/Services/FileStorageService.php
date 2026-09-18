<?php

namespace App\Services;

use App\Models\FileFolder;
use App\Models\StoredFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Nghiệp vụ kho tài nguyên: tạo thư mục, tải file lên, di chuyển, xoá.
 *
 * Mọi file trong hệ thống đi qua đây, nên đổi nơi lưu (local → S3) chỉ cần
 * đổi cấu hình disk, không phải sửa các màn hình nghiệp vụ.
 */
class FileStorageService
{
    /** Disk lưu file. Tách hằng số để đổi một chỗ khi chuyển sang S3. */
    public const DISK = 'local';

    /** Thư mục gốc trong disk, tách khỏi file tạm của Livewire. */
    private const ROOT = 'kho-tai-nguyen';

    /**
     * Kích thước tối đa mỗi file, tính theo KB (đơn vị của luật validate `max`).
     *
     * Khớp với upload_max_filesize của PHP. Đặt cao hơn giới hạn PHP là vô nghĩa:
     * PHP chặn trước khi request tới Laravel, người dùng chỉ thấy lỗi khó hiểu
     * thay vì thông báo rõ ràng. Nâng mức này phải nâng cả php.ini.
     */
    public const MAX_FILE_KB = 2048;

    /** Mục đích sử dụng file — dùng cho storeForPurpose(). */
    public const PURPOSE_DOCUMENT = 'document';
    public const PURPOSE_VIDEO = 'video';
    public const PURPOSE_CONTRACT = 'contract';
    public const PURPOSE_FORM = 'form';
    public const PURPOSE_AVATAR = 'avatar';

    /**
     * Ánh xạ mục đích → tên thư mục hệ thống.
     * Khai báo một chỗ để ensureSystemFolders() và storeForPurpose() không lệch nhau.
     */
    private const PURPOSE_FOLDERS = [
        self::PURPOSE_DOCUMENT => 'Tài liệu đào tạo',
        self::PURPOSE_VIDEO => 'Video bài giảng',
        self::PURPOSE_CONTRACT => 'Hợp đồng',
        self::PURPOSE_FORM => 'Biểu mẫu',
        self::PURPOSE_AVATAR => 'Ảnh đại diện',
    ];

    /**
     * Danh sách mục đích file và tên thư mục tương ứng.
     *
     * @return array<string, string>
     */
    public static function purposes(): array
    {
        return self::PURPOSE_FOLDERS;
    }

    public function createFolder(string $name, ?int $parentId = null, ?int $userId = null): FileFolder
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('Tên thư mục không được để trống.');
        }

        // Tên trùng trong cùng thư mục cha sẽ khiến người dùng không phân biệt được
        $exists = FileFolder::where('parent_id', $parentId)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw new RuntimeException("Thư mục \"{$name}\" đã tồn tại ở vị trí này.");
        }

        return FileFolder::create([
            'name' => $name,
            'parent_id' => $parentId,
            'created_by' => $userId,
        ]);
    }

    public function renameFolder(FileFolder $folder, string $name): FileFolder
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('Tên thư mục không được để trống.');
        }

        $exists = FileFolder::where('parent_id', $folder->parent_id)
            ->where('name', $name)
            ->where('id', '!=', $folder->id)
            ->exists();

        if ($exists) {
            throw new RuntimeException("Thư mục \"{$name}\" đã tồn tại ở vị trí này.");
        }

        $folder->update(['name' => $name]);

        return $folder;
    }

    /**
     * Di chuyển thư mục sang vị trí khác.
     * Path của toàn bộ nhánh con phải được cập nhật theo.
     */
    public function moveFolder(FileFolder $folder, ?int $targetFolderId): void
    {
        if ($folder->is_system) {
            throw new RuntimeException('Không thể di chuyển thư mục hệ thống.');
        }

        if (! $folder->canMoveTo($targetFolderId)) {
            throw new RuntimeException('Không thể di chuyển thư mục vào bên trong chính nó.');
        }

        DB::transaction(function () use ($folder, $targetFolderId) {
            $descendants = $folder->descendants()->get();

            $folder->update(['parent_id' => $targetFolderId]);
            $folder->refresh();

            // save() trên từng bản ghi để hook booted() tính lại path/depth
            foreach ($descendants as $descendant) {
                $descendant->save();
            }
        });
    }

    /**
     * Xoá thư mục. Mặc định chặn nếu còn nội dung bên trong — xoá nhầm cả nhánh
     * là mất mát khó khôi phục, nên bắt người dùng xác nhận có chủ đích.
     */
    public function deleteFolder(FileFolder $folder, bool $force = false): void
    {
        if ($folder->is_system) {
            throw new RuntimeException('Không thể xóa thư mục hệ thống.');
        }

        $childCount = $folder->children()->count();
        $fileCount = $folder->files()->count();

        if (! $force && ($childCount > 0 || $fileCount > 0)) {
            throw new RuntimeException(
                "Thư mục đang chứa {$childCount} thư mục con và {$fileCount} file. "
                . 'Hãy chuyển nội dung đi trước hoặc chọn xóa cả nội dung.'
            );
        }

        DB::transaction(function () use ($folder) {
            $folderIds = $folder->descendants()->pluck('id')->push($folder->id);

            StoredFile::whereIn('folder_id', $folderIds)->delete();
            FileFolder::whereIn('id', $folderIds)->delete();
        });
    }

    /**
     * Lưu một file tải lên vào kho.
     *
     * Tên file trên đĩa được sinh ngẫu nhiên, tên gốc giữ ở CSDL — tránh xung đột
     * và tránh tên file người dùng đặt lọt vào đường dẫn hệ thống.
     */
    public function storeUpload(UploadedFile $upload, ?int $folderId = null, ?int $userId = null): StoredFile
    {
        $extension = strtolower($upload->getClientOriginalExtension());
        $storedName = Str::uuid() . ($extension ? '.' . $extension : '');

        // Chia theo năm/tháng để một thư mục đĩa không chứa hàng vạn file
        $directory = self::ROOT . '/' . now()->format('Y/m');
        $path = $upload->storeAs($directory, $storedName, self::DISK);

        if (! $path) {
            throw new RuntimeException('Không lưu được file lên hệ thống.');
        }

        return StoredFile::create([
            'folder_id' => $folderId,
            'name' => $upload->getClientOriginalName(),
            'original_name' => $upload->getClientOriginalName(),
            'disk' => self::DISK,
            'path' => $path,
            'mime_type' => $upload->getClientMimeType(),
            'extension' => $extension ?: null,
            'size_bytes' => $upload->getSize(),
            'checksum' => hash_file('sha256', $upload->getRealPath()),
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * Đưa một file đã ghép từ các mảnh vào kho.
     *
     * Khác storeUpload() ở chỗ nhận đường dẫn tuyệt đối thay vì UploadedFile —
     * file ghép không đi qua tầng upload của PHP nên không có đối tượng đó.
     */
    public function storeMergedFile(
        string $absolutePath,
        string $originalName,
        ?int $folderId = null,
        ?int $userId = null,
    ): StoredFile {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('File ghép không tồn tại.');
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $storedName = Str::uuid() . ($extension ? '.' . $extension : '');
        $directory = self::ROOT . '/' . now()->format('Y/m');
        $relativePath = $directory . '/' . $storedName;

        $disk = Storage::disk(self::DISK);
        $disk->makeDirectory($directory);

        // Di chuyển thay vì copy: file ghép đã nằm cùng disk, tránh nhân đôi dung lượng
        if (! rename($absolutePath, $disk->path($relativePath))) {
            throw new RuntimeException('Không chuyển được file vào kho.');
        }

        $fullPath = $disk->path($relativePath);

        return StoredFile::create([
            'folder_id' => $folderId,
            'name' => $originalName,
            'original_name' => $originalName,
            'disk' => self::DISK,
            'path' => $relativePath,
            'mime_type' => mime_content_type($fullPath) ?: null,
            'extension' => $extension ?: null,
            'size_bytes' => filesize($fullPath),
            'checksum' => hash_file('sha256', $fullPath),
            'uploaded_by' => $userId,
        ]);
    }

    /**
     * Tải file lên đúng thư mục hệ thống theo mục đích sử dụng.
     *
     * Nhờ vậy tài liệu đào tạo, video, hợp đồng tự vào đúng ngăn mà người tải
     * không phải nhớ chọn thư mục — nhưng vẫn thấy được và di chuyển được
     * trong kho như mọi file khác.
     */
    public function storeForPurpose(UploadedFile $upload, string $purpose, ?int $userId = null): StoredFile
    {
        $folder = $this->systemFolderFor($purpose);

        return $this->storeUpload($upload, $folder?->id, $userId);
    }

    /**
     * Thư mục hệ thống ứng với một mục đích. Tự tạo nếu chưa có, để luồng
     * upload không vỡ khi ai đó lỡ xoá hoặc hệ thống chưa seed.
     */
    public function systemFolderFor(string $purpose): ?FileFolder
    {
        $name = self::PURPOSE_FOLDERS[$purpose] ?? null;

        if ($name === null) {
            return null;
        }

        return FileFolder::firstOrCreate(
            ['parent_id' => null, 'name' => $name],
            ['is_system' => true],
        );
    }

    public function renameFile(StoredFile $file, string $name): StoredFile
    {
        $name = trim($name);

        if ($name === '') {
            throw new RuntimeException('Tên file không được để trống.');
        }

        $file->update(['name' => $name]);

        return $file;
    }

    public function moveFile(StoredFile $file, ?int $targetFolderId): void
    {
        if ($targetFolderId !== null && ! FileFolder::whereKey($targetFolderId)->exists()) {
            throw new RuntimeException('Thư mục đích không tồn tại.');
        }

        $file->update(['folder_id' => $targetFolderId]);
    }

    /** Xoá mềm để còn khôi phục được; file vật lý chỉ mất khi forceDelete. */
    public function deleteFile(StoredFile $file): void
    {
        $file->delete();
    }

    /** Tìm file trùng nội dung — hữu ích khi cảnh báo trước lúc tải lên bản sao. */
    public function findDuplicate(string $checksum, ?int $excludeId = null): ?StoredFile
    {
        return StoredFile::where('checksum', $checksum)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->first();
    }

    /** @return array{folders: int, files: int, size: int} */
    public function stats(): array
    {
        return [
            'folders' => FileFolder::count(),
            'files' => StoredFile::count(),
            'size' => (int) StoredFile::sum('size_bytes'),
        ];
    }

    /**
     * Tạo bộ thư mục mặc định khi khởi tạo hệ thống.
     * Đặt is_system để không ai xoá nhầm các thư mục mà nghiệp vụ đang dựa vào.
     */
    public function ensureSystemFolders(): void
    {
        $descriptions = [
            self::PURPOSE_DOCUMENT => 'Tài liệu, quy trình dùng trong các khóa học',
            self::PURPOSE_VIDEO => 'Video phục vụ đào tạo nội bộ',
            self::PURPOSE_CONTRACT => 'Hợp đồng lao động đã ký, bản scan',
            self::PURPOSE_FORM => 'Biểu mẫu dùng chung toàn công ty',
        ];

        foreach (self::PURPOSE_FOLDERS as $purpose => $name) {
            FileFolder::firstOrCreate(
                ['parent_id' => null, 'name' => $name],
                ['description' => $descriptions[$purpose] ?? null, 'is_system' => true],
            );
        }
    }

    public function humanSize(int $bytes): string
    {
        return match (true) {
            $bytes >= 1073741824 => round($bytes / 1073741824, 1) . ' GB',
            $bytes >= 1048576 => round($bytes / 1048576, 1) . ' MB',
            $bytes >= 1024 => round($bytes / 1024) . ' KB',
            default => $bytes . ' B',
        };
    }
}
