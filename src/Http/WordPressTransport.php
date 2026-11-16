<?php

declare(strict_types=1);

namespace MullaPayments\Http;

use MullaPayments\Vendor\Mulla\Exceptions\ConnectionException;
use MullaPayments\Vendor\Mulla\Http\Request;
use MullaPayments\Vendor\Mulla\Http\Response;
use MullaPayments\Vendor\Mulla\Http\Transport\TransportInterface;
use MullaPayments\Vendor\Mulla\Support\Redactor;

/**
 * Adapter: sends SDK requests through the WordPress HTTP API (wp_remote_request), so proxy
 * settings, the bundled CA certificates and `http_api_*` filters of the site all apply.
 */
final class WordPressTransport implements TransportInterface
{
    public function send(Request $request): Response
    {
        $args = [
            'method' => $request->method,
            'headers' => $request->headers,
            'timeout' => $request->timeout,
            'redirection' => 0,
            'sslverify' => true,
            'reject_unsafe_urls' => false,
            'user-agent' => $request->headers['user-agent'] ?? 'mulla-payments',
        ];
        if ($request->body !== null) {
            $args['body'] = $request->body;
        }

        $response = wp_remote_request($request->url, $args);

        if (is_wp_error($response)) {
            $message = $response->get_error_message();
            // WordPress reports "connection refused / could not resolve" before sending; anything else may have reached Mulla.
            $notSent = (bool) preg_match('/could not resolve|resolve host|connection refused|failed to connect|couldn\'t connect/i', $message);

            throw new ConnectionException(
                sprintf('Could not reach Mulla at %s: %s', Redactor::url($request->url), $message),
                0,
                null,
                !$notSent,
            );
        }

        $headers = [];
        foreach (wp_remote_retrieve_headers($response) as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? (string) end($value) : (string) $value;
        }

        return new Response((int) wp_remote_retrieve_response_code($response), $headers, (string) wp_remote_retrieve_body($response));
    }
}
