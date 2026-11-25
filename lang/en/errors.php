<?php

return [
    'UNAUTHORIZED' => [
        'title' => 'Unauthorized',
        'detail' => 'Authentication is required to access this resource.',
    ],
    'FORBIDDEN' => [
        'title' => 'Forbidden',
        'detail' => 'You do not have sufficient permissions to perform this action.',
    ],
    'RESOURCE_NOT_FOUND' => [
        'title' => 'Resource Not Found',
        'detail' => 'The requested resource was not found.',
    ],
    'CONFLICT' => [
        'title' => 'Conflict',
        'detail' => 'A state conflict occurred while processing the request.',
    ],
    'VALIDATION_FAILED' => [
        'title' => 'Validation Failed',
        'detail' => 'The provided input fields failed validation.',
    ],
    'RATE_LIMITED' => [
        'title' => 'Rate Limit Exceeded',
        'detail' => 'You have exceeded the allowed rate limit, please retry later.',
    ],
    'INTERNAL_ERROR' => [
        'title' => 'Internal Server Error',
        'detail' => 'An unexpected error occurred while processing your request. Please contact support with the trace ID.',
    ],
    'BAD_REQUEST' => [
        'title' => 'Bad Request',
        'detail' => 'The server cannot process the request due to an apparent client error.',
    ],
    'METHOD_NOT_ALLOWED' => [
        'title' => 'Method Not Allowed',
        'detail' => 'The HTTP method used is not supported for this route.',
    ],
    'SERVICE_UNAVAILABLE' => [
        'title' => 'Service Unavailable',
        'detail' => 'The service is temporarily unavailable. Please retry later.',
    ],
];
