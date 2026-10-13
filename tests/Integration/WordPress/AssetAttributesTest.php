<?php

namespace Themosis\Tests\Integration\WordPress;

class AssetAttributesTest extends WordPressTestCase
{
    public static function handles(): array
    {
        return ['lowercase handle' => ['owned-'], 'mixed-case handle' => ['Owned-']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('handles')]
    public function testScriptAttributesTargetTheHandleTagOnly(string $prefix): void
    {
        $handle = $prefix . bin2hex(random_bytes(4));
        app('asset')->add($handle, 'https://cdn.example.invalid/app.js')->attributes(['async', 'data-owned' => 'yes']);
        wp_register_script($handle, 'https://cdn.example.invalid/app.js', [], null);
        wp_add_inline_script($handle, 'image.src = "inline";', 'before');

        try {
            ob_start();
            wp_scripts()->do_items([$handle]);
            $html = ob_get_clean();
        } finally {
            wp_deregister_script($handle);
        }

        self::assertStringContainsString("<script id=\"{$handle}-js-before\">\nimage.src = \"inline\";", $html, 'Inline code is left untouched.');
        self::assertStringContainsString("<script id=\"{$handle}-js\" async data-owned=\"yes\" src=\"https://cdn.example.invalid/app.js\"></script>", $html);
    }

    public function testStyleAttributesKeepTheirPreviousPlacement(): void
    {
        $handle = 'owned-' . bin2hex(random_bytes(4));
        app('asset')->add($handle, 'https://cdn.example.invalid/app.css')->attributes(['crossorigin' => 'anonymous']);
        wp_register_style($handle, 'https://cdn.example.invalid/app.css', [], null);

        try {
            ob_start();
            wp_styles()->do_items([$handle]);
            $html = ob_get_clean();
        } finally {
            wp_deregister_style($handle);
        }

        self::assertMatchesRegularExpression("/<link rel='stylesheet' id='" . preg_quote($handle, '/') . "-css' crossorigin=\"anonymous\" href='https:\/\/cdn\.example\.invalid\/app\.css'/", $html);
    }
}
