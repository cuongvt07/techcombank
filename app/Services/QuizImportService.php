<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Quiz;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\HeadingRowImport;

/**
 * Import bộ câu hỏi từ Excel theo template chuẩn (spec 3.2.3).
 *
 * Template mỗi dòng là một câu hỏi:
 *   noi_dung | loai | dap_an_a | dap_an_b | dap_an_c | dap_an_d | dap_an_dung | diem | giai_thich
 *
 * Cột `dap_an_dung` nhận chữ cái đáp án, nhiều đáp án ngăn bằng dấu phẩy (vd "A,C").
 *
 * Nguyên tắc: kiểm tra toàn bộ file TRƯỚC khi ghi. File có dòng lỗi thì
 * không ghi gì cả — import nửa vời để lại ngân hàng câu hỏi sai lệch,
 * khó phát hiện hơn nhiều so với việc báo lỗi và bắt sửa file.
 */
class QuizImportService
{
    /** Nhãn cột đáp án theo thứ tự, dùng để ánh xạ "A" -> cột dap_an_a. */
    private const OPTION_LETTERS = ['a', 'b', 'c', 'd', 'e', 'f'];

    private const TYPE_MAP = [
        'single' => Question::TYPE_SINGLE,
        'mot' => Question::TYPE_SINGLE,
        'chon 1' => Question::TYPE_SINGLE,
        'multiple' => Question::TYPE_MULTIPLE,
        'nhieu' => Question::TYPE_MULTIPLE,
        'chon nhieu' => Question::TYPE_MULTIPLE,
        'truefalse' => Question::TYPE_TRUE_FALSE,
        'true_false' => Question::TYPE_TRUE_FALSE,
        'dungsai' => Question::TYPE_TRUE_FALSE,
        'dung sai' => Question::TYPE_TRUE_FALSE,
    ];

    /**
     * Đọc và kiểm tra file, chưa ghi vào CSDL.
     *
     * @return array{rows: array<int, array>, errors: array<int, string>}
     */
    public function preview(string $filePath): array
    {
        $sheets = Excel::toArray(new class {}, $filePath);
        $raw = $sheets[0] ?? [];

        if (count($raw) < 2) {
            return ['rows' => [], 'errors' => ['File không có dòng dữ liệu nào (cần dòng tiêu đề + ít nhất 1 câu hỏi).']];
        }

        $header = $this->normaliseHeader(array_shift($raw));
        $missing = array_diff(['noi_dung', 'dap_an_dung'], $header);

        if ($missing) {
            return ['rows' => [], 'errors' => [
                'Thiếu cột bắt buộc: ' . implode(', ', $missing) . '. Hãy tải file mẫu và giữ nguyên dòng tiêu đề.',
            ]];
        }

        $rows = [];
        $errors = [];

        foreach ($raw as $index => $line) {
            // +2 vì đã bỏ dòng tiêu đề và Excel đánh số từ 1
            $lineNo = $index + 2;
            $row = $this->mapRow($header, $line);

            if ($this->isEmptyRow($row)) {
                continue;
            }

            $parsed = $this->parseRow($row, $lineNo, $errors);

            if ($parsed) {
                $rows[] = $parsed;
            }
        }

        if ($rows === [] && $errors === []) {
            $errors[] = 'Không tìm thấy câu hỏi hợp lệ nào trong file.';
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Ghi các câu hỏi đã kiểm tra vào một bài kiểm tra.
     *
     * @param  array<int, array>  $rows  Kết quả từ preview()
     * @return int Số câu hỏi được thêm
     */
    public function import(Quiz $quiz, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        return DB::transaction(function () use ($quiz, $rows) {
            $sortOrder = (int) $quiz->questions()->max('sort_order');
            $count = 0;

            foreach ($rows as $row) {
                $question = Question::create([
                    'quiz_id' => $quiz->id,
                    'content' => $row['content'],
                    'type' => $row['type'],
                    'explanation' => $row['explanation'],
                    'score' => $row['score'],
                    'sort_order' => ++$sortOrder,
                    'is_active' => true,
                ]);

                foreach ($row['options'] as $optionIndex => $option) {
                    QuestionOption::create([
                        'question_id' => $question->id,
                        'content' => $option['content'],
                        'is_correct' => $option['is_correct'],
                        'sort_order' => $optionIndex,
                    ]);
                }

                $count++;
            }

            return $count;
        });
    }

    /** Nội dung file mẫu, xuất cho admin tải về (spec 3.2.3: template chuẩn). */
    public function templateRows(): array
    {
        return [
            ['noi_dung', 'loai', 'dap_an_a', 'dap_an_b', 'dap_an_c', 'dap_an_d', 'dap_an_dung', 'diem', 'giai_thich'],
            [
                'Dấu hiệu nào sau đây thường gặp ở email lừa đảo?',
                'single',
                'Yêu cầu cung cấp mật khẩu gấp',
                'Email từ đồng nghiệp đã xác minh',
                'Thông báo lịch họp nội bộ',
                '',
                'A',
                '1',
                'Email lừa đảo thường tạo cảm giác khẩn cấp.',
            ],
            [
                'Những việc nào cần làm khi phát hiện rò rỉ dữ liệu?',
                'multiple',
                'Báo bộ phận an ninh thông tin',
                'Ghi nhận thời điểm phát hiện',
                'Tự ý công bố ra ngoài',
                'Xóa dấu vết',
                'A,B',
                '2',
                '',
            ],
            [
                'Mật khẩu dùng chung cho nhiều hệ thống là an toàn.',
                'dungsai',
                'Đúng',
                'Sai',
                '',
                '',
                'B',
                '1',
                'Mỗi hệ thống cần một mật khẩu riêng.',
            ],
        ];
    }

    /** Chuẩn hoá tiêu đề cột: bỏ dấu, khoảng trắng thành gạch dưới. */
    private function normaliseHeader(array $header): array
    {
        return array_map(function ($cell) {
            $value = mb_strtolower(trim((string) $cell));
            $value = $this->removeAccents($value);

            return preg_replace('/[^a-z0-9]+/', '_', $value);
        }, $header);
    }

    private function mapRow(array $header, array $line): array
    {
        $row = [];

        foreach ($header as $position => $key) {
            if ($key !== '' && $key !== '_') {
                $row[$key] = isset($line[$position]) ? trim((string) $line[$position]) : '';
            }
        }

        return $row;
    }

    private function isEmptyRow(array $row): bool
    {
        return collect($row)->filter(fn ($v) => $v !== '' && $v !== null)->isEmpty();
    }

    /**
     * Kiểm tra và chuyển một dòng thành cấu trúc câu hỏi.
     * Lỗi được gom vào $errors kèm số dòng để admin sửa đúng chỗ.
     */
    private function parseRow(array $row, int $lineNo, array &$errors): ?array
    {
        $content = $row['noi_dung'] ?? '';

        if ($content === '') {
            $errors[] = "Dòng {$lineNo}: thiếu nội dung câu hỏi.";

            return null;
        }

        $type = $this->resolveType($row['loai'] ?? '');

        // Thu thập các đáp án có nội dung, giữ nguyên vị trí chữ cái
        $options = [];
        $letterToIndex = [];

        foreach (self::OPTION_LETTERS as $letter) {
            $value = $row['dap_an_' . $letter] ?? '';

            if ($value !== '') {
                $letterToIndex[$letter] = count($options);
                $options[] = ['content' => $value, 'is_correct' => false];
            }
        }

        if (count($options) < 2) {
            $errors[] = "Dòng {$lineNo}: cần ít nhất 2 đáp án.";

            return null;
        }

        $correctRaw = $row['dap_an_dung'] ?? '';

        if ($correctRaw === '') {
            $errors[] = "Dòng {$lineNo}: chưa chỉ định đáp án đúng.";

            return null;
        }

        $correctLetters = collect(preg_split('/[,;\s]+/', mb_strtolower($correctRaw)))
            ->filter()
            ->unique();

        foreach ($correctLetters as $letter) {
            if (! isset($letterToIndex[$letter])) {
                $errors[] = "Dòng {$lineNo}: đáp án đúng \"{$letter}\" không tồn tại trong các cột đáp án.";

                return null;
            }

            $options[$letterToIndex[$letter]]['is_correct'] = true;
        }

        // Câu chọn 1 đáp án mà đánh dấu nhiều đáp án đúng là mâu thuẫn cấu hình
        if ($type !== Question::TYPE_MULTIPLE && $correctLetters->count() > 1) {
            $errors[] = "Dòng {$lineNo}: loại câu hỏi chỉ cho phép 1 đáp án đúng nhưng đang có {$correctLetters->count()}.";

            return null;
        }

        $score = $row['diem'] ?? '';

        if ($score !== '' && (! is_numeric($score) || (float) $score <= 0)) {
            $errors[] = "Dòng {$lineNo}: điểm phải là số lớn hơn 0.";

            return null;
        }

        return [
            'content' => $content,
            'type' => $type,
            'options' => $options,
            'score' => $score === '' ? 1.0 : (float) $score,
            'explanation' => ($row['giai_thich'] ?? '') ?: null,
            'line' => $lineNo,
        ];
    }

    private function resolveType(string $raw): string
    {
        $key = preg_replace('/[^a-z0-9 ]+/', '', $this->removeAccents(mb_strtolower(trim($raw))));

        return self::TYPE_MAP[$key] ?? Question::TYPE_SINGLE;
    }

    /** Bỏ dấu tiếng Việt để so khớp tiêu đề cột và tên loại câu hỏi. */
    private function removeAccents(string $value): string
    {
        $map = [
            'a' => 'áàảãạăắằẳẵặâấầẩẫậ',
            'e' => 'éèẻẽẹêếềểễệ',
            'i' => 'íìỉĩị',
            'o' => 'óòỏõọôốồổỗộơớờởỡợ',
            'u' => 'úùủũụưứừửữự',
            'y' => 'ýỳỷỹỵ',
            'd' => 'đ',
        ];

        foreach ($map as $plain => $accented) {
            $chars = preg_split('//u', $accented, -1, PREG_SPLIT_NO_EMPTY);
            $value = str_replace($chars, $plain, $value);
        }

        return $value;
    }
}
