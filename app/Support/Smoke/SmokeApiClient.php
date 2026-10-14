<?php

namespace App\Support\Smoke;

use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the application through its own HTTP kernel.
 *
 * Every request a student would make is dispatched here rather than being
 * short-circuited into a controller or service call: routing, the `api`
 * middleware group, `auth:sanctum`, the throttles, the form requests and the
 * API resources all run exactly as they do for a real client.
 *
 * Dispatching in-process rather than over the network is deliberate. The email
 * OTP is persisted only as a hash, so the one place the plaintext code exists is
 * the notification the registration request sends — which can only be observed
 * from inside the process that handled that request. See
 * {@see SmokeOtpInbox}.
 */
class SmokeApiClient
{
    private ?string $token = null;

    /**
     * The last response, kept so a failed assertion can report what came back.
     */
    private ?SmokeApiResponse $last = null;

    public function authenticateWith(string $token): void
    {
        $this->token = $token;
    }

    public function forgetToken(): void
    {
        $this->token = null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function post(string $uri, array $payload = []): SmokeApiResponse
    {
        return $this->send('POST', $uri, $payload);
    }

    public function get(string $uri): SmokeApiResponse
    {
        return $this->send('GET', $uri);
    }

    public function lastResponse(): ?SmokeApiResponse
    {
        return $this->last;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $method, string $uri, array $payload = []): SmokeApiResponse
    {
        // Each request must authenticate from its own headers.
        //
        // Guards resolve their user once and remember it, which is right for a
        // real request — one process, one request — but wrong here, where many
        // requests share a process. Without this, a run that authenticates as a
        // second student keeps being served as the first, and an ownership check
        // would pass for the wrong reason.
        app('auth')->forgetGuards();

        $request = Request::create(
            uri: $uri,
            method: $method,
            server: $this->serverHeaders(),
            content: $payload === [] ? null : (string) json_encode($payload),
        );

        /** @var Response $response */
        $response = app(HttpKernel::class)->handle($request);

        return $this->last = new SmokeApiResponse(
            $method,
            $uri,
            $response->getStatusCode(),
            (string) $response->getContent(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function serverHeaders(): array
    {
        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => 'ZMedicsSmokeTest/1.0',
        ];

        if ($this->token !== null) {
            $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$this->token;
        }

        return $headers;
    }
}
