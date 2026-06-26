<?php
namespace Quiznosis\Core;

class Validator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data) {
        $this->data = $data;
    }

    public static function make(array $data): self
    {
        return new self($data);
    }

    public function required(string $field, string $msg = null): self
    {
        $v = $this->data[$field] ?? null;
        if ($v === null || $v === '' || (is_array($v) && empty($v))) {
            $this->errors[$field] = $msg ?? "{$field} is required";
        }
        return $this;
    }

    public function email(string $field, string $msg = 'Invalid email format'): self
    {
        $v = $this->data[$field] ?? null;
        if ($v !== null && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = $msg;
        }
        return $this;
    }

    public function minLength(string $field, int $min, string $msg = null): self
    {
        $v = $this->data[$field] ?? '';
        if (is_string($v) && strlen($v) < $min) {
            $this->errors[$field] = $msg ?? "{$field} must be at least {$min} characters";
        }
        return $this;
    }

    public function in(string $field, array $allowed, string $msg = null): self
    {
        $v = $this->data[$field] ?? null;
        if ($v !== null && !in_array($v, $allowed, true)) {
            $this->errors[$field] = $msg ?? "{$field} must be one of: " . implode(',', $allowed);
        }
        return $this;
    }

    public function strongPassword(string $field): self
    {
        $v = $this->data[$field] ?? '';
        if (!Util::isStrongPassword((string)$v)) {
            $this->errors[$field] = 'Password must contain at least one uppercase letter, one lowercase letter, one number, and one special character.';
        }
        return $this;
    }

    public function fails(): bool { return !empty($this->errors); }
    public function errors(): array { return $this->errors; }
    public function firstError(): string { return reset($this->errors) ?: ''; }

    /** Send a 400 with the first error and exit if any failed. */
    public function abortIfFails(): void
    {
        if ($this->fails()) {
            Response::error($this->firstError(), 400, ['errors' => $this->errors]);
        }
    }
}
