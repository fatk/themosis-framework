<?php

namespace Themosis\Forms\Resources\Transformers {
    function get_attached_file($id)
    {
        return \Themosis\Tests\Forms\MediaFieldTransformerTest::$file;
    }

    function wp_get_attachment_image_src($id, $size, $icon)
    {
        return \Themosis\Tests\Forms\MediaFieldTransformerTest::$thumbnail;
    }

    function wp_basename($path)
    {
        return basename($path);
    }
}

namespace Themosis\Tests\Forms {
    use PHPUnit\Framework\TestCase;
    use Themosis\Forms\Contracts\FieldTypeInterface;
    use Themosis\Forms\Resources\Transformers\MediaFieldTransformer;

    class MediaFieldTransformerTest extends TestCase
    {
        public static $file = false;
        public static $thumbnail = false;

        public function testMediaResourcesTolerateUnavailableFilesAndThumbnails(): void
        {
            $file = tempnam(sys_get_temp_dir(), 'themosis-media-');
            file_put_contents($file, str_repeat('a', 2048));
            $previous = [self::$file, self::$thumbnail];
            set_error_handler(function ($severity, $message) {
                throw new \ErrorException($message, 0, $severity);
            });
            try {
                $field = $this->createStub(FieldTypeInterface::class);
                $field->method('getOptions')->willReturn(['button' => 'Choose media']);
                $field->method('getValue')->willReturn(123);
                $field->method('getAttributes')->willReturn([]);
                $field->method('getBaseName')->willReturn('image');
                $field->method('getComponent')->willReturn('themosis.fields.media');
                $field->method('getName')->willReturn('image');
                $field->method('getTheme')->willReturn('themosis');
                $field->method('getType')->willReturn('media');
                $field->method('getRawValue')->willReturn(123);
                $transformer = new MediaFieldTransformer();
                foreach ([
                    [$file, ['https://example.test/thumb.png', 80, 80], basename($file), 'https://example.test/thumb.png', '2 KB'],
                    [$file, ['https://example.test/document-icon.png', 48, 64], basename($file), 'https://example.test/document-icon.png', '2 KB'],
                    [$file, false, basename($file), null, '2 KB'],
                    [false, false, '', null, null],
                    [$file . '-missing', false, basename($file) . '-missing', null, null],
                    ['https://example.test/offloaded.png', false, 'offloaded.png', null, null],
                    ['s3://unregistered/offloaded.png', false, 'offloaded.png', null, null],
                ] as [$path, $thumbnail, $name, $image, $size]) {
                    self::$file = $path;
                    self::$thumbnail = $thumbnail;
                    $resource = $transformer->transform($field);
                    self::assertSame(['name' => $name, 'thumbnail' => $image, 'filesize' => $size], $resource['options']['media']);
                    self::assertSame('Choose media', $resource['options']['button']);
                    self::assertSame(123, $resource['value']);
                    self::assertSame('themosis.fields.media', $resource['component']);
                }
                unlink($file);
                self::$file = $file;
                self::assertNull($transformer->transform($field)['options']['media']['filesize']);
            } finally {
                restore_error_handler();
                [self::$file, self::$thumbnail] = $previous;
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
