<?php
/**
 * Field-specific route input errors from the existing field and route-state
 * validators. The admin renderer consumes these without inventing a second
 * validation policy. The exception is not a persisted route state.
 */
final class GrTransportRouteValidationException extends InvalidArgumentException
{
    private array $fieldErrors;

    public function __construct(array $fieldErrors)
    {
        $this->fieldErrors = $fieldErrors;
        parent::__construct(implode(' ', array_values($fieldErrors)));
    }

    public function fieldErrors(): array
    {
        return $this->fieldErrors;
    }
}
