<?php

/**
 * includes/Core/Validator.php
 * ==========================================================================
 * Rule-based input validator.
 *
 * Validate a data array against a set of rules and collect per-field error
 * messages. Rules are given either as pipe strings ("required|email") or as
 * arrays (['required', 'regex:/^\d+$/']) — use the array form when a parameter
 * itself contains a pipe.
 *
 * Supported rules: required, email, url, integer, float, numeric, string,
 * boolean, length:min,max, min:n, max:n, between:a,b, regex:pattern, in:a,b,c,
 * file. Each rule is also exposed as a static predicate for one-off checks.
 *
 * Pairs with Upload for deep file validation (MIME/extension/size).
 * ==========================================================================
 */

declare(strict_types=1);

namespace DMF\Core;

final class Validator
{
    /** @var array<string,array<int,string>> field => list of messages */
    private array $errors = [];

    private bool $ran = false;

    /**
     * @param array<string,mixed>                 $data
     * @param array<string,string|array<int,string>> $rules
     */
    public function __construct(
        private array $data,
        private array $rules,
    ) {
    }

    /**
     * @param array<string,mixed>                 $data
     * @param array<string,string|array<int,string>> $rules
     */
    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    // ── Execution ─────────────────────────────────────────────────────────

    public function validate(): bool
    {
        $this->errors = [];

        foreach ($this->rules as $field => $ruleset) {
            $rules = is_array($ruleset) ? $ruleset : explode('|', $ruleset);
            $value = $this->data[$field] ?? null;
            $present = array_key_exists($field, $this->data) && $value !== null && $value !== '';

            foreach ($rules as $rule) {
                [$name, $param] = $this->parseRule((string) $rule);

                // Only "required" fires on absent/empty values; other rules skip.
                if (!$present && $name !== 'required') {
                    continue;
                }
                $this->applyRule($field, $name, $param, $value);
            }
        }

        $this->ran = true;
        return $this->errors === [];
    }

    public function passes(): bool
    {
        if (!$this->ran) {
            $this->validate();
        }
        return $this->errors === [];
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    /**
     * @return array<string,array<int,string>>
     */
    public function errors(): array
    {
        if (!$this->ran) {
            $this->validate();
        }
        return $this->errors;
    }

    public function firstError(?string $field = null): ?string
    {
        $errors = $this->errors();
        if ($field !== null) {
            return $errors[$field][0] ?? null;
        }
        foreach ($errors as $messages) {
            return $messages[0] ?? null;
        }
        return null;
    }

    /**
     * The subset of input covered by rules that passed and were present.
     *
     * @return array<string,mixed>
     */
    public function validated(): array
    {
        $out = [];
        foreach (array_keys($this->rules) as $field) {
            if (!isset($this->errors[$field]) && array_key_exists($field, $this->data)) {
                $out[$field] = $this->data[$field];
            }
        }
        return $out;
    }

    // ── Rule dispatch ─────────────────────────────────────────────────────

    /**
     * @return array{0:string,1:string}
     */
    private function parseRule(string $rule): array
    {
        if (str_contains($rule, ':')) {
            [$name, $param] = explode(':', $rule, 2);
            return [strtolower(trim($name)), $param];
        }
        return [strtolower(trim($rule)), ''];
    }

    private function applyRule(string $field, string $name, string $param, mixed $value): void
    {
        $ok = match ($name) {
            'required' => self::isRequired($value),
            'email'    => is_string($value) && self::isEmail($value),
            'url'      => is_string($value) && self::isUrl($value),
            'integer'  => self::isInteger($value),
            'float'    => self::isFloat($value),
            'numeric'  => is_numeric($value),
            'string'   => is_string($value),
            'boolean'  => is_bool($value) || in_array($value, ['0', '1', 0, 1, true, false], true),
            'length'   => is_string($value) && $this->checkLength($value, $param),
            'min'      => $this->checkMin($value, $param),
            'max'      => $this->checkMax($value, $param),
            'between'  => $this->checkBetween($value, $param),
            'regex'    => is_string($value) && self::matchesRegex($value, $param),
            'in'       => in_array((string) $value, array_map('trim', explode(',', $param)), true),
            'file'     => self::isUploadedFile($value),
            default    => true,
        };

        if (!$ok) {
            $this->errors[$field][] = $this->message($field, $name, $param);
        }
    }

    private function checkLength(string $value, string $param): bool
    {
        [$min, $max] = array_pad(array_map('trim', explode(',', $param)), 2, null);
        $len = mb_strlen($value);
        if ($min !== null && $min !== '' && $len < (int) $min) {
            return false;
        }
        if ($max !== null && $max !== '' && $len > (int) $max) {
            return false;
        }
        return true;
    }

    private function checkMin(mixed $value, string $param): bool
    {
        $n = (float) $param;
        return is_numeric($value) ? (float) $value >= $n : mb_strlen((string) $value) >= $n;
    }

    private function checkMax(mixed $value, string $param): bool
    {
        $n = (float) $param;
        return is_numeric($value) ? (float) $value <= $n : mb_strlen((string) $value) <= $n;
    }

    private function checkBetween(mixed $value, string $param): bool
    {
        [$a, $b] = array_pad(array_map('trim', explode(',', $param)), 2, '0');
        return $this->checkMin($value, (string) $a) && $this->checkMax($value, (string) $b);
    }

    private function message(string $field, string $name, string $param): string
    {
        $label = ucfirst(str_replace(['_', '.'], ' ', $field));
        return match ($name) {
            'required' => "{$label} is required.",
            'email'    => "{$label} must be a valid email address.",
            'url'      => "{$label} must be a valid URL.",
            'integer'  => "{$label} must be an integer.",
            'float'    => "{$label} must be a number.",
            'numeric'  => "{$label} must be numeric.",
            'string'   => "{$label} must be text.",
            'boolean'  => "{$label} must be true or false.",
            'length'   => "{$label} has an invalid length.",
            'min'      => "{$label} must be at least {$param}.",
            'max'      => "{$label} must not exceed {$param}.",
            'between'  => "{$label} must be between {$param}.",
            'regex'    => "{$label} format is invalid.",
            'in'       => "{$label} is not an allowed value.",
            'file'     => "{$label} must be a valid uploaded file.",
            default    => "{$label} is invalid.",
        };
    }

    // ── Static predicates (reusable one-offs) ─────────────────────────────

    public static function isRequired(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }
        return $value !== null && trim((string) $value) !== '';
    }

    public static function isEmail(string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function isUrl(string $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_URL) !== false;
    }

    public static function isInteger(mixed $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_INT) !== false;
    }

    public static function isFloat(mixed $value): bool
    {
        return filter_var($value, \FILTER_VALIDATE_FLOAT) !== false;
    }

    public static function matchesRegex(string $value, string $pattern): bool
    {
        return @preg_match($pattern, $value) === 1;
    }

    /**
     * @param array<string,mixed>|mixed $value A $_FILES entry.
     */
    public static function isUploadedFile(mixed $value): bool
    {
        return is_array($value)
            && isset($value['error'])
            && (int) $value['error'] === \UPLOAD_ERR_OK;
    }
}
