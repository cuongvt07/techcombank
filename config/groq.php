<?php

/**
 * Cấu hình trợ lý AI đào tạo (spec 4.3).
 *
 * API key KHÔNG bao giờ đặt trong file này — luôn đọc từ .env để không lọt vào
 * mã nguồn hay lịch sử commit.
 */
return [
    'api_key' => env('GROQ_API_KEY'),

    'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),

    /*
     * Model chính. Đo trên dữ liệu thật: trả lời tiếng Việt tốt nhất, bám ngữ
     * cảnh, từ chối đúng khi hỏi ngoài phạm vi.
     */
    'model' => env('GROQ_MODEL', 'openai/gpt-oss-120b'),

    /*
     * Danh sách dự phòng, thử lần lượt khi model chính hỏng.
     *
     * Model trên Groq có thể ngừng dùng được vì nhiều lý do: bị khóa ở mức
     * project (groq/compound đang vướng đúng lỗi này), bị gỡ khỏi nền tảng,
     * hoặc quá tải tạm thời. Không có dự phòng thì trợ lý chết hẳn.
     *
     * Thứ tự đã sắp theo kết quả đo:
     *  - qwen3.8-27b : chất lượng gần tương đương, ít token hơn 22%
     *  - gpt-oss-20b : cùng nhà với model chính, đôi khi trả content rỗng
     *                  (đã có xử lý đọc sang trường reasoning)
     *
     * KHÔNG đưa qwen3.6-27b vào: nó rò nguyên đoạn "<think>..." ra câu trả lời
     * cho người dùng đọc.
     */
    'fallback_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('GROQ_FALLBACK_MODELS', 'qwen/qwen3.8-27b,openai/gpt-oss-20b'))
    ))),

    /*
     * Sau khi một model lỗi, tạm loại nó trong bao nhiêu giây.
     *
     * Tránh việc mỗi câu hỏi lại tốn thời gian thử lại model đang hỏng: người
     * dùng phải chờ thêm một lần timeout vô ích cho mỗi lượt hỏi.
     */
    'failure_cooldown' => (int) env('GROQ_FAILURE_COOLDOWN', 300),

    // Thấp để bám sát tài liệu, hạn chế bịa
    'temperature' => (float) env('GROQ_TEMPERATURE', 0.2),

    'max_tokens' => (int) env('GROQ_MAX_TOKENS', 1200),

    'timeout' => (int) env('GROQ_TIMEOUT', 30),

    /*
     * Số đoạn ngữ cảnh nhiều nhất nhét vào một câu hỏi. Nhiều quá thì tốn token
     * và model dễ lạc; ít quá thì thiếu dữ kiện để trả lời.
     */
    'max_context_chunks' => (int) env('GROQ_MAX_CONTEXT', 14),

    // Số lượt hỏi tối đa mỗi phút cho một nhân viên
    'rate_limit_per_minute' => (int) env('GROQ_RATE_LIMIT', 10),

    'enabled' => env('GROQ_ENABLED', true),
];
