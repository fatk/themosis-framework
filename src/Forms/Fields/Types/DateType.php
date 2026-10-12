<?php

namespace Themosis\Forms\Fields\Types;

/**
 * Native date input. Values use the existing string transformer and metadata lifecycle.
 */
class DateType extends TextType
{
    protected $view = 'types.date';

    protected $type = 'date';

    protected $component = 'themosis.fields.date';
}
