<?php

namespace App\Services\GitHub;

use Illuminate\Http\Client\Response;
use RuntimeException;

class GitHubApiException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function fromResponse(Response $response): self
    {
        $message = $response->json('message');

        return new self($response->status(), is_string($message) ? $message : 'The GitHub request failed.');
    }
}
