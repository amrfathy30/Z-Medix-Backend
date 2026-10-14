<?php

namespace App\Support\Smoke;

use Illuminate\Support\Arr;

/**
 * One API response, read the way a client reads it: by status and by key path
 * into the decoded body. Nothing here knows about the study flow.
 */
final readonly class SmokeApiResponse
{
    /** @var array<string, mixed> */
    public array $body;

    public function __construct(
        public string $method,
        public string $uri,
        public int $status,
        public string $raw,
    ) {
        $decoded = json_decode($raw, associative: true);

        $this->body = is_array($decoded) ? $decoded : [];
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->body;
        }

        return Arr::get($this->body, $key, $default);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(?string $key = null): array
    {
        $value = $this->json($key === null ? 'data' : 'data.'.$key, []);

        return is_array($value) ? $value : [];
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * A single line naming the request and what came back, for failure output.
     */
    public function describe(): string
    {
        $message = $this->json('message');
        $code = $this->json('code');

        return sprintf(
            '%s %s -> %d%s%s',
            $this->method,
            $this->uri,
            $this->status,
            is_string($message) ? ' "'.$message.'"' : '',
            is_string($code) ? ' ['.$code.']' : '',
        );
    }
}
