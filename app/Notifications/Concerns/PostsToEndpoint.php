<?php

namespace App\Notifications\Concerns;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

trait PostsToEndpoint
{
    /**
     * Redirects are refused rather than followed: the endpoint was validated
     * when saved, the redirect target never was.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     *
     * @throws RequestException
     */
    protected function postToEndpoint(string $url, array $payload, array $headers = []): void
    {
        $response = Http::timeout(10)->withoutRedirecting()->withHeaders($headers)->post($url, $payload);

        throw_if($response->redirect(), new RequestException($response));

        $response->throw();
    }
}
