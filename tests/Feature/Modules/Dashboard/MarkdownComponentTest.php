<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Dashboard;

use Tests\TestCase;

class MarkdownComponentTest extends TestCase
{
    private function render(array $data): string
    {
        return view('dashboard::components.markdown', $data)->render();
    }

    public function test_empty_source_renders_nothing_visible(): void
    {
        $html = $this->render(['source' => '']);

        $this->assertStringNotContainsString('<div', $html);
        $this->assertStringNotContainsString('<span', $html);
    }

    public function test_null_source_renders_nothing_visible(): void
    {
        $html = $this->render(['source' => null]);

        $this->assertStringNotContainsString('<div', $html);
        $this->assertStringNotContainsString('<span', $html);
    }

    public function test_renders_paragraphs_and_line_breaks(): void
    {
        $html = $this->render(['source' => "First paragraph.\n\nSecond paragraph."]);

        $this->assertStringContainsString('<p>First paragraph.</p>', $html);
        $this->assertStringContainsString('<p>Second paragraph.</p>', $html);
    }

    public function test_renders_unordered_list(): void
    {
        $source = "- one\n- two\n- three";
        $html = $this->render(['source' => $source]);

        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>one</li>', $html);
        $this->assertStringContainsString('<li>three</li>', $html);
    }

    public function test_renders_strong_emphasis_and_inline_code(): void
    {
        $html = $this->render(['source' => 'This is **bold**, this is *italic*, this is `code`.']);

        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<code>code</code>', $html);
    }

    public function test_renders_fenced_code_block(): void
    {
        $source = "```\necho 'hello';\n```";
        $html = $this->render(['source' => $source]);

        $this->assertStringContainsString('<pre>', $html);
        $this->assertStringContainsString('<code>', $html);
        $this->assertStringContainsString("echo 'hello';", $html);
    }

    public function test_escapes_raw_html_to_prevent_xss(): void
    {
        $html = $this->render(['source' => "<script>alert('xss')</script>\n\nHello."]);

        $this->assertStringNotContainsString("<script>alert('xss')</script>", $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('Hello.', $html);
    }

    public function test_blocks_unsafe_links(): void
    {
        $html = $this->render(['source' => '[click me](javascript:alert(1))']);

        $this->assertStringNotContainsString('href="javascript:', $html);
    }

    public function test_wrapper_has_prose_classes_by_default(): void
    {
        $html = $this->render(['source' => 'Hello.']);

        $this->assertStringContainsString('prose prose-sm max-w-none', $html);
    }

    public function test_extra_class_is_appended(): void
    {
        $html = $this->render(['source' => 'Hello.', 'class' => 'text-gray-600 mb-4']);

        $this->assertStringContainsString('text-gray-600', $html);
        $this->assertStringContainsString('mb-4', $html);
        $this->assertStringContainsString('prose prose-sm max-w-none', $html);
    }

    public function test_plain_mode_strips_markdown_to_text(): void
    {
        $html = $this->render([
            'source' => "**Bold** title.\n\n- item 1\n- item 2",
            'plain' => true,
        ]);

        $this->assertStringNotContainsString('<p>', $html);
        $this->assertStringNotContainsString('<strong>', $html);
        $this->assertStringNotContainsString('<ul>', $html);
        $this->assertStringContainsString('Bold title.', $html);
        $this->assertStringContainsString('item 1', $html);
    }

    public function test_plain_mode_uses_span_wrapper_with_class(): void
    {
        $html = $this->render([
            'source' => 'Some text.',
            'plain' => true,
            'class' => 'line-clamp-2',
        ]);

        $this->assertStringContainsString('<span class="line-clamp-2"', $html);
    }
}
