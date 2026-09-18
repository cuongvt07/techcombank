<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            DemoDataSeeder::class,

            // Bổ sung quy mô: ~120 nhân viên, 12 phòng ban, hợp đồng đủ trạng thái
            DemoBulkSeeder::class,

            // Bộ khóa học đầy đủ nội dung, video nhúng YouTube, rule gán tự động
            DemoCourseSeeder::class,

            // Tài liệu nội bộ, rule phân quyền và sự kiện ở quy mô dùng được
            DemoContentSeeder::class,

            // Hoạt động học tập mẫu, chạy sau khi đã có nhân sự và khoá học
            DemoActivitySeeder::class,
        ]);
    }
}
