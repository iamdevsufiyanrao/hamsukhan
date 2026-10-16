<?php

namespace Hamsukhan\Connector;

use Hamsukhan\Connector\Contracts\EventHandler;
use Throwable;

/**
 * For plain PHP (no framework):
 *
 *   require 'vendor/autoload.php';          // or require the 5 files in src/
 *   Hamsukhan\Connector\Receiver::run('whsec_...', function (Hamsukhan\Connector\Event $e) {
 *       $pdo->prepare('INSERT INTO orders (name, phone, items) VALUES (?, ?, ?)')
 *           ->execute([$e->customerName(), $e->phone(), $e->field('items')]);
 *       return 'ORD-'.$pdo->lastInsertId();
 *   });
 */
final class Receiver
{
    /** Verify the signature and parse the body. Throws InvalidSignature. */
    public static function capture(string $secret, ?string $body = null, ?string $signatureHeader = null): Event
    {
        $body ??= (string) file_get_contents('php://input');
        $signatureHeader ??= $_SERVER['HTTP_HAMSUKHAN_SIGNATURE'] ?? null;

        if (! Signature::verify($body, $signatureHeader, $secret)) {
            throw new InvalidSignature('Hamsukhan signature check failed.');
        }

        return Event::fromJson($body);
    }

    /** Full request cycle: verify → call your handler → answer Hamsukhan with JSON. */
    public static function run(string $secret, callable|EventHandler $handler): never
    {
        try {
            $event = self::capture($secret);
        } catch (InvalidSignature) {
            self::respond(401, ['ok' => false, 'error' => 'invalid signature']);
        } catch (Throwable) {
            self::respond(400, ['ok' => false, 'error' => 'invalid payload']);
        }

        try {
            $reference = $handler instanceof EventHandler ? $handler->handle($event) : $handler($event);
        } catch (Throwable $e) {
            self::respond(500, ['ok' => false, 'error' => 'handler failed']); // Hamsukhan retries 5xx
        }

        self::respond(200, ['ok' => true, 'reference' => is_scalar($reference ?? null) ? (string) $reference : null]);
    }

    private static function respond(int $status, array $body): never
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($body);
        exit;
    }
}
