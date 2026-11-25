<?php

namespace Tests\Unit;

use App\Models\Employee;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EmployeeInitialsTest extends TestCase
{
    public static function names(): array
    {
        return [
            ['Phạm Thuỷ', 'PT'],
            ['Phan Thu Phương', 'PP'],
            ['Trịnh Phương Hà', 'TH'],
            ['Vũ Kim Quỳnh', 'VQ'],
            ['Nguyễn Thị Thảo', 'NT'],
            ['Lê Hồng Hà', 'LH'],
            ['Ngọc Mai Anh', 'NA'],
            ['  Ngọc   Mai Anh  ', 'NA'],
            ['Ánh', 'Á'],
            ['', '?'],
            [null, '?'],
        ];
    }

    #[DataProvider('names')]
    public function test_lay_chu_dau_cua_ho_va_ten(?string $name, string $expected): void
    {
        $this->assertSame($expected, Employee::initialsOf($name));
    }

    public function test_ten_dang_dau_tach_roi_van_lay_dung_chu_cai(): void
    {
        if (! class_exists(\Normalizer::class)) {
            $this->markTestSkipped('Thiếu extension intl');
        }

        $nfd = \Normalizer::normalize('Ấu Văn Đức', \Normalizer::FORM_D);
        $this->assertSame('ẤĐ', Employee::initialsOf($nfd));
    }
}
