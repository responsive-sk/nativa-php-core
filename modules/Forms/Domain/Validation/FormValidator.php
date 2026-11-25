<?php

declare(strict_types = 1);

namespace Modules\Forms\Domain\Validation;

use Modules\Forms\Domain\Entities\FormField;
use Modules\Forms\Domain\Model\FormFieldType;
use Modules\Forms\Domain\Model\FormSchema;

/**
 * FormValidator — a submission against the schema it was rendered from.
 *
 * Validation is per field and returns BOTH halves of the answer: the messages
 * a reader sees, and the keys the renderer marks `aria-invalid`. They are
 * produced together because they describe the same fact, and computing them
 * apart is how a field ends up marked invalid with no message or the reverse.
 *
 * Only declared keys are considered. The caller drops undeclared input before
 * this point, and this class would ignore it anyway: a payload is stored with
 * exactly the keys the definition names.
 *
 * ## The messages are copy, not code
 *
 * A reader on a Slovak page was answered in English, because every sentence
 * here was a PHP literal. `$copy` is the `forms` translation map for the
 * request's locale (`FormsServices::copy()`), and each message is a template
 * with `{label}` and `{max}` placeholders: the field's own label sits inside
 * the sentence, and English and Slovak do not have to put it in the same
 * place. An empty map keeps the English sentences below, so a tree without
 * the Locales module still says something a reader can act on.
 */
final readonly class FormValidator
{
    /**
     * @param array<string, string> $payload
     * @param array<string, string> $copy    `forms.*` template rows for the request's locale
     *
     * @return array{errors: list<string>, invalid: list<string>}
     */
    public function validate(FormSchema $schema, array $payload, array $copy = []): array
    {
        $errors = [];
        $invalid = [];

        foreach ($schema->fields() as $field) {
            $value = $payload[$field->key] ?? '';
            $message = $this->message($field, $value, $copy);

            if (null === $message) {
                continue;
            }

            $errors[] = $message;
            $invalid[] = $field->key;
        }

        return ['errors' => $errors, 'invalid' => $invalid];
    }

    /**
     * The one message this field has to say about this value, or null when the
     * value is acceptable.
     *
     * @param array<string, string> $copy
     */
    private function message(FormField $field, string $value, array $copy): ?string
    {
        if (FormFieldType::CHECKBOX === $field->type) {
            if ($field->required && '1' !== $value) {
                return $this->template($copy, 'forms.error.consent', 'Please confirm "{label}".', $field);
            }

            return null;
        }

        if ('' === $value) {
            return $field->required
                ? $this->template($copy, 'forms.error.required', 'Please fill in "{label}".', $field)
                : null;
        }

        if (FormFieldType::EMAIL === $field->type && false === filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return $this->template($copy, 'forms.error.email', '"{label}" must be a valid e-mail address.', $field);
        }

        if ($field->type->isTextLike() && \strlen($value) > $field->max) {
            return $this->template(
                $copy,
                'forms.error.too_long',
                '"{label}" is longer than {max} characters.',
                $field,
            );
        }

        if ($field->type->isChoice() && !\in_array($value, $field->options, true)) {
            return $this->template(
                $copy,
                'forms.error.choice',
                'Choose one of the listed options for "{label}".',
                $field,
            );
        }

        return null;
    }

    /**
     * One stored template with the field's facts substituted, or the English
     * floor when this locale has no row for the key.
     *
     * @param array<string, string> $copy
     */
    private function template(array $copy, string $key, string $fallback, FormField $field): string
    {
        $template = '' !== trim($copy[$key] ?? '') ? $copy[$key] : $fallback;

        return strtr($template, [
            '{label}' => $field->label,
            '{max}'   => (string) $field->max,
        ]);
    }
}
