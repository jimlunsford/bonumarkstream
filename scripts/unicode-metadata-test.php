<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
require_once dirname(__DIR__) . '/_bonumark_stream/app/functions.php';
foreach (['🌅', '日本語', 'é', '👨‍👩‍👧‍👦'] as $symbol) {
    foreach ([1, 68, 69, 70, 89, 90, 158, 159, 160] as $boundary) {
        $body = str_repeat('a', $boundary) . $symbol . str_repeat(' word', 40);
        $fields = ['title' => bms_stream_generated_post_title($body),
            'description' => bms_stream_generated_description($body),
            'seo_title' => bms_stream_generated_seo_title($body), 'status' => 'draft', 'slug' => 'unicode-test'];
        $page = bms_parse_markdown_string(bms_build_markdown_document($fields, $body));
        foreach (['title', 'description', 'seo_title'] as $key) {
            if (preg_match('//u', $page[$key]) !== 1 || $page[$key] !== $fields[$key]) {
                throw new RuntimeException('Invalid Unicode metadata at boundary ' . $boundary . ': ' . $key);
            }
        }
        if (bms_text_length($fields['title']) > 70 || bms_text_length($fields['description']) > 160) {
            throw new RuntimeException('Generated metadata exceeded its character limit.');
        }
    }
}
if (bms_text_substr('a🌅日本語', -3, 2) !== '日本') {
    throw new RuntimeException('Unicode fallback substring offsets are incorrect.');
}
echo 'PASS 36 Unicode metadata boundary cases; mbstring=' . (extension_loaded('mbstring') ? 'on' : 'off') . PHP_EOL;
