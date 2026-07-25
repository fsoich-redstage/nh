<?php
declare(strict_types=1);

namespace NutriHelper\Domain;

/**
 * Normalizes the raw Green API webhook payload (which can arrive in a couple
 * of slightly different shapes depending on the notification type) into a
 * single internal IncomingMessage.
 */
final class WebhookPayloadNormalizer
{
    public function normalize(array $payload): ?IncomingMessage
    {
        $root = $payload['body'] ?? $payload;
        $senderData = $root['senderData'] ?? [];
        $messageData = $root['messageData'] ?? ($payload['data']['message'] ?? null);

        if (!is_array($messageData)) {
            return null;
        }

        $chatId = (string)($senderData['chatId'] ?? $senderData['sender'] ?? $messageData['from'] ?? '');
        if ($chatId === '') {
            return null;
        }

        $typeMessage = (string)($messageData['typeMessage'] ?? $messageData['type'] ?? '');
        $idMessage = (string)($messageData['idMessage'] ?? $messageData['id'] ?? $messageData['messageId'] ?? '');

        if ($typeMessage === 'imageMessage' || $typeMessage === 'image') {
            $downloadUrl = (string)(
                $messageData['downloadUrl']
                ?? $messageData['imageMessageData']['downloadUrl']
                ?? $messageData['fileMessageData']['downloadUrl']
                ?? ''
            );

            $captionSources = [
                $messageData['fileMessageData']['caption'] ?? null,
                $messageData['imageMessageData']['caption'] ?? null,
                $messageData['caption'] ?? null,
                $messageData['body'] ?? null,
            ];
            $caption = '';
            foreach ($captionSources as $candidate) {
                if (is_string($candidate) && trim($candidate) !== '') {
                    $caption = trim($candidate);
                    break;
                }
            }

            return new IncomingMessage('image', $chatId, $caption, $downloadUrl, $idMessage);
        }

        if ($typeMessage === 'textMessage' || $typeMessage === 'extendedTextMessage' || $typeMessage === '' || $typeMessage === 'chat') {
            $body = (string)(
                $messageData['textMessage']
                ?? $messageData['textMessageData']['textMessage']
                ?? $messageData['body']
                ?? ''
            );

            return new IncomingMessage('text', $chatId, $body, '', $idMessage);
        }

        return new IncomingMessage('other', $chatId, '', '', $idMessage);
    }
}
