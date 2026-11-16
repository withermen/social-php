<?php

namespace Social\Exceptions;

final class ValidationException extends ApiException
{
    public function __construct(array $fields, string $message = 'The given data was invalid.')
    {
        parent::__construct(422, 'validation_failed', $message, ['fields' => $fields]);
    }
}
