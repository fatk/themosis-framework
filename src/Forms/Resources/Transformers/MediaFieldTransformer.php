<?php

namespace Themosis\Forms\Resources\Transformers;

use Themosis\Forms\Contracts\FieldTypeInterface;

class MediaFieldTransformer extends FieldTransformer
{
    /**
     * Return media field options.
     *
     * @param FieldTypeInterface $field
     *
     * @return array
     */
    protected function getOptions(FieldTypeInterface $field)
    {
        $options = parent::getOptions($field);

        $attachedFile = get_attached_file($field->getValue());
        $thumbnail = wp_get_attachment_image_src($field->getValue(), 'thumbnail', true);
        $filesize = $attachedFile && ! str_contains($attachedFile, '://')
            && is_file($attachedFile) && is_readable($attachedFile)
            ? filesize($attachedFile) : false;

        $options['media'] = [
            'name' => $attachedFile ? wp_basename($attachedFile) : '',
            'thumbnail' => $thumbnail ? $thumbnail[0] : null,
            'filesize' => $filesize === false ? null : round($filesize / 1024) . ' KB',
        ];

        return $options;
    }
}
