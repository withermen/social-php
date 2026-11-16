<?php

namespace Social\Support;

use Social\Exceptions\ValidationException;

final class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        $clean = [];

        foreach ($rules as $field => $ruleString) {
            $raw = $data[$field] ?? null;
            $parts = explode('|', $ruleString);
            $required = in_array('required', $parts, true);
            $nullable = in_array('nullable', $parts, true);
            $empty = $raw === null || (is_string($raw) && trim($raw) === '');

            if ($empty) {
                if ($required) {
                    $errors[$field] = 'This field is required.';
                } elseif ($nullable) {
                    $clean[$field] = null;
                }

                continue;
            }

            foreach ($parts as $part) {
                if ($part === '' || $part === 'required' || $part === 'nullable') {
                    continue;
                }

                [$name, $argument] = array_pad(explode(':', $part, 2), 2, null);
                $failure = null;

                switch ($name) {
                    case 'string':
                        if (!is_string($raw) && !is_numeric($raw)) {
                            $failure = 'This field must be a string.';
                            break;
                        }

                        $raw = trim((string) $raw);
                        [$min, $max] = self::range($argument, 0, 255);

                        if (mb_strlen($raw, 'UTF-8') < $min) {
                            $failure = 'This field must be at least ' . $min . ' characters.';
                        } elseif (mb_strlen($raw, 'UTF-8') > $max) {
                            $failure = 'This field may not be longer than ' . $max . ' characters.';
                        }
                        break;

                    case 'password':
                        if (!is_string($raw)) {
                            $failure = 'This field must be a string.';
                            break;
                        }

                        $min = $argument === null ? 8 : (int) $argument;
                        $length = mb_strlen($raw, 'UTF-8');

                        if ($length < $min) {
                            $failure = 'Password must be at least ' . $min . ' characters.';
                        } elseif ($length > 72) {
                            $failure = 'Password may not be longer than 72 characters.';
                        }
                        break;

                    case 'email':
                        $raw = mb_strtolower(trim((string) $raw), 'UTF-8');

                        if (mb_strlen($raw, 'UTF-8') > 190 || filter_var($raw, FILTER_VALIDATE_EMAIL) === false) {
                            $failure = 'Please enter a valid email address.';
                        }
                        break;

                    case 'username':
                        $raw = Str::normalizeUsername((string) $raw);

                        if (!preg_match('/^[a-z0-9_][a-z0-9_.]{1,22}[a-z0-9_]$/', $raw)) {
                            $failure = 'Username must be 3-24 characters using letters, numbers, underscores or dots.';
                        }
                        break;

                    case 'integer':
                        if (filter_var($raw, FILTER_VALIDATE_INT) === false) {
                            $failure = 'This field must be an integer.';
                            break;
                        }

                        $raw = (int) $raw;
                        [$min, $max] = self::range($argument, PHP_INT_MIN, PHP_INT_MAX);

                        if ($raw < $min || $raw > $max) {
                            $failure = 'This field is out of range.';
                        }
                        break;

                    case 'boolean':
                        $parsed = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

                        if ($parsed === null) {
                            $failure = 'This field must be true or false.';
                        } else {
                            $raw = $parsed;
                        }
                        break;

                    case 'array':
                        if (!is_array($raw)) {
                            $failure = 'This field must be an array.';
                        }
                        break;

                    case 'in':
                        $allowed = $argument === null ? [] : explode(',', $argument);

                        if (!in_array((string) $raw, $allowed, true)) {
                            $failure = 'This field must be one of: ' . implode(', ', $allowed) . '.';
                        }
                        break;

                    case 'url':
                        $raw = trim((string) $raw);

                        if (filter_var($raw, FILTER_VALIDATE_URL) === false) {
                            $failure = 'Please enter a valid URL.';
                        }
                        break;

                    default:
                        throw new \InvalidArgumentException('Unknown validation rule: ' . $name);
                }

                if ($failure !== null) {
                    $errors[$field] = $failure;
                    break;
                }
            }

            if (!isset($errors[$field])) {
                $clean[$field] = $raw;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $clean;
    }

    public static function pagination(array $data, int $default = 20, int $max = 100): array
    {
        $page = filter_var($data['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $perPage = filter_var($data['per_page'] ?? $default, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]) ?: $default;

        return [$page, $perPage];
    }

    private static function range(?string $argument, int $defaultMin, int $defaultMax): array
    {
        if ($argument === null) {
            return [$defaultMin, $defaultMax];
        }

        $values = explode(',', $argument);

        return [(int) ($values[0] ?? $defaultMin), (int) ($values[1] ?? $defaultMax)];
    }
}
