<?php

namespace Themosis\Metabox;

use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Themosis\Forms\Contracts\FieldTypeInterface;
use Themosis\Forms\Fields\Contracts\CanHandleMetabox;
use Themosis\Forms\Fields\Types\BaseType;
use Themosis\Metabox\Contracts\MetaboxInterface;
use Themosis\Metabox\Contracts\MetaboxManagerInterface;

class Manager implements MetaboxManagerInterface
{
    /**
     * @var ValidationFactory
     */
    protected $factory;

    public function __construct(ValidationFactory $factory)
    {
        $this->factory = $factory;
    }

    /**
     * Handle metabox initialization.
     * Set the metabox fields value and return the metabox instance.
     *
     * @param MetaboxInterface $metabox
     * @param Request          $request
     *
     * @return MetaboxInterface
     */
    public function getFields(MetaboxInterface $metabox, Request $request): MetaboxInterface
    {
        $post_id = $this->authorizeTarget($metabox, $request);
        foreach ($metabox->repository()->all() as $field) {
            if (method_exists($field, 'metaboxGet')) {
                $field->metaboxGet($post_id);
            }
        }

        return $metabox;
    }

    /**
     * Handle metabox post meta save.
     *
     * @param MetaboxInterface $metabox
     * @param Request          $request
     *
     * @throws MetaboxException
     *
     * @return MetaboxInterface
     */
    public function saveFields(MetaboxInterface $metabox, Request $request): MetaboxInterface
    {
        $post_id = $this->authorizeTarget($metabox, $request);
        $data = $this->getMetaboxData(collect($request->get('fields')));
        $fields = $metabox->repository()->all();

        /** @var $validator Validator */
        $validator = $this->factory->make(
            $data,
            $this->getMetaboxRules($fields),
            $this->getMetaboxMessages($fields),
            $this->getMetaboxPlaceholders($fields),
        );

        $validatedData = $validator->valid();

        foreach ($fields as $field) {
            /** @var FieldTypeInterface|CanHandleMetabox|BaseType $field */
            $field->setErrorMessageBag($validator->errors());

            if (method_exists($field, 'metaboxSave')) {
                $value = isset($validatedData[$field->getName()]) ? $validatedData[$field->getName()] : null;
                $field->metaboxSave($value, $post_id);
            } else {
                throw new MetaboxException(
                    'Unable to save [' . $field->getName() . ']. The [metaboxSave] method is missing.',
                );
            }
        }

        return $metabox;
    }

    /**
     * Authorize the same target before either loading or saving fields.
     */
    protected function authorizeTarget(MetaboxInterface $metabox, Request $request): int
    {
        $id = filter_var($request->query('post_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $post = $id === false ? null : get_post($id);

        if (! $post || ! current_user_can('edit_post', $id)) {
            throw new NotFoundHttpException();
        }

        $capability = $metabox->getCapability();
        if ($capability !== '' && ! current_user_can($capability)) {
            throw new NotFoundHttpException();
        }

        $screens = $metabox->getScreen();
        $screens = is_array($screens) ? $screens : [$screens];
        $applies = false;
        foreach ($screens as $screen) {
            $type = $screen instanceof \WP_Screen ? $screen->post_type : $screen;
            if ($type === $post->post_type) {
                $applies = true;
                break;
            }
        }
        $templates = $metabox->getTemplate()[$post->post_type] ?? [];
        if (! $applies || ($templates !== [] && ! in_array(get_post_meta($id, '_wp_page_template', true), $templates, true))) {
            throw new NotFoundHttpException();
        }

        return $id;
    }

    /**
     * Return the metabox data for validation.
     *
     * @param Collection $fields
     *
     * @return array
     */
    protected function getMetaboxData(Collection $fields)
    {
        $data = [];

        foreach ($fields as $field) {
            $data[$field['name']] = $field['value'];
        }

        return $data;
    }

    /**
     * Return the metabox rules for validation.
     *
     * @param array $fields
     *
     * @return array
     */
    protected function getMetaboxRules(array $fields)
    {
        $rules = [];

        foreach ($fields as $field) {
            /** @var FieldTypeInterface $field */
            $rules[$field->getName()] = $field->getOption('rules');
        }

        return $rules;
    }

    /**
     * Return the metabox validation messages.
     *
     * @param array $fields
     *
     * @return array
     */
    protected function getMetaboxMessages(array $fields)
    {
        // Each message is defined by field and its own rules.
        // In our case, we need to prepend the field name (attribute)
        // using a "dot" notation. Ex.: email.required
        $messages = [];

        foreach ($fields as $field) {
            /** @var FieldTypeInterface $field */
            foreach ($field->getOption('messages') as $attr => $message) {
                $messages[$field->getName() . '.' . $attr] = $message;
            }
        }

        return $messages;
    }

    /**
     * Return the metabox messages placeholders.
     *
     * @param array $fields
     *
     * @return array
     */
    protected function getMetaboxPlaceholders(array $fields)
    {
        $placeholders = [];

        foreach ($fields as $field) {
            /** @var FieldTypeInterface $field */
            $placeholders[$field->getName()] = $field->getOption('placeholder');
        }

        return $placeholders;
    }
}
