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

        // The top-level idMessage identifies this specific webhook notification
        // (a poll vote update gets a fresh one each time, unlike pollMessageData's
        // stanzaId which stays fixed for the poll's whole lifetime), so prefer it
        // for dedupe purposes across all message types.
        $idMessage = (string)(
            $root['idMessage']
            ?? $payload['idMessage']
            ?? $messageData['idMessage']
            ?? $messageData['id']
            ?? $messageData['messageId']
            ?? ''
        );

        if ($typeMessage === 'pollUpdateMessage') {
            $votes = $messageData['pollMessageData']['votes'] ?? [];
            $selected = '';
            if (is_array($votes)) {
                foreach ($votes as $vote) {
                    $voters = $vote['optionVoters'] ?? [];
                    if (is_array($voters) && in_array($chatId, $voters, true)) {
                        $selected = (string)($vote['optionName'] ?? '');
                        break;
                    }
                }
            }

            return new IncomingMessage('poll_vote', $chatId, $selected, '', $idMessage);
        }

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
