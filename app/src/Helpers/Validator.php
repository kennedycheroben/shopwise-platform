<?php

namespace App\Helpers;

use App\Database\Database;
use PDO;

/**
 * Server-Side Form Validation Helper
 */
class Validator
{
    private array $data;
    private array $rules;
    private array $errors = [];

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
        $this->validate();
    }

    public static function make(array $data, array $rules): self
    {
        return new self($data, $rules);
    }

    private function validate(): void
    {
        foreach ($this->rules as $field => $ruleSet) {
            $rules = is_string($ruleSet) ? explode('|', $ruleSet) : $ruleSet;
            $value = $this->data[$field] ?? null;

            foreach ($rules as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$ruleName, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                } else {
                    $ruleName = $rule;
                }

                $this->applyRule($field, $value, $ruleName, $params);
            }
        }
    }

    private function applyRule(string $field, mixed $value, string $rule, array $params): void
    {
        $label = ucfirst(str_replace('_', ' ', $field));

        switch ($rule) {
            case 'required':
                if ($value === null || (is_string($value) && trim($value) === '') || (is_array($value) && empty($value))) {
                    $this->addError($field, "{$label} is required.");
                }
                break;

            case 'email':
                if (!empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, "Please enter a valid email address.");
                }
                break;

            case 'phone':
                if (!empty($value) && normalize_phone((string)$value) === null) {
                    $this->addError($field, "Please provide a valid Kenyan phone number (e.g., 0712345678 or +254712345678).");
                }
                break;

            case 'numeric':
                if (!empty($value) && !is_numeric($value)) {
                    $this->addError($field, "{$label} must be a number.");
                }
                break;

            case 'min':
                $min = (int)($params[0] ?? 0);
                if (is_numeric($value)) {
                    if ((float)$value < $min) {
                        $this->addError($field, "{$label} must be at least {$min}.");
                    }
                } elseif (is_string($value)) {
                    if (mb_strlen(trim($value)) < $min) {
                        $this->addError($field, "{$label} must be at least {$min} characters.");
                    }
                }
                break;

            case 'max':
                $max = (int)($params[0] ?? 255);
                if (is_numeric($value)) {
                    if ((float)$value > $max) {
                        $this->addError($field, "{$label} may not be greater than {$max}.");
                    }
                } elseif (is_string($value)) {
                    if (mb_strlen(trim($value)) > $max) {
                        $this->addError($field, "{$label} may not exceed {$max} characters.");
                    }
                }
                break;

            case 'match':
                $targetField = $params[0] ?? '';
                $targetValue = $this->data[$targetField] ?? null;
                if ($value !== $targetValue) {
                    $targetLabel = ucfirst(str_replace('_', ' ', $targetField));
                    $this->addError($field, "{$label} does not match {$targetLabel}.");
                }
                break;

            case 'in':
                if (!empty($value) && !in_array($value, $params, true)) {
                    $this->addError($field, "{$label} contains an invalid selection.");
                }
                break;

            case 'unique':
                $table = $params[0] ?? '';
                $column = $params[1] ?? $field;
                $exceptId = $params[2] ?? null;

                if (!empty($value) && $table !== '') {
                    $pdo = Database::getInstance();
                    $sql = "SELECT id FROM `{$table}` WHERE `{$column}` = :val";
                    $binds = [':val' => $value];
                    if ($exceptId !== null) {
                        $sql .= " AND id != :except_id";
                        $binds[':except_id'] = $exceptId;
                    }
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($binds);
                    if ($stmt->fetch()) {
                        $this->addError($field, "This {$label} is already taken.");
                    }
                }
                break;
        }
    }

    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    public function passes(): bool
    {
        return empty($this->errors);
    }

    public function fails(): bool
    {
        return !$this->passes();
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(?string $field = null): ?string
    {
        if ($field !== null) {
            return $this->errors[$field] ?? null;
        }
        return !empty($this->errors) ? reset($this->errors) : null;
    }
}
