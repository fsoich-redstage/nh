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
        // (a button/list reply gets a fresh one each time, unlike the
        // stanzaId inside templateButtonReplyMessage/listResponseMessage,
        // which stays fixed for the original message's whole lifetime), so
        // prefer it for dedupe purposes across all message types.
        $idMessage = (string)(
            $root['idMessage']
            ?? $payload['idMessage']
            ?? $messageData['idMessage']
            ?? $messageData['id']
            ?? $messageData['messageId']
            ?? ''
        );

        if (in_array($typeMessage, [
            'templateButtonReplyMessage',
            'templateButtonsReplyMessage',
            'interactiveButtonReply',
            'interactiveButtonsReply',
            'interactiveButtonsResponse',
            'buttonsResponseMessage',
        ], true)) {
            $body = $this->resolveButtonReplyBody($messageData);

            return new IncomingMessage('button_reply', $chatId, $body, '', $idMessage);
        }

        if ($typeMessage === 'listResponseMessage') {
            $selectedId = (string)($messageData['listResponseMessage']['singleSelectReply'] ?? '');
            if ($selectedId === '') {
                $selectedId = trim((string)($messageData['listResponseMessage']['title'] ?? ''));
            }

            return new IncomingMessage('list_reply', $chatId, $selectedId, '', $idMessage);
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
                ?? $messageData['extendedTextMessageData']['text']
                ?? $messageData['body']
                ?? ''
            );

            return new IncomingMessage('text', $chatId, $body, '', $idMessage);
        }

        return new IncomingMessage('other', $chatId, '', '', $idMessage);
    }

    /**
     * Green API has shipped multiple reply payload shapes/names for the same
     * user action; prefer the explicit id, but fall back to display text so
     * flows still advance when the provider leaves selectedId empty.
     *
     * @param array<string,mixed> $messageData
     */
    private function resolveButtonReplyBody(array $messageData): string
    {
        $replyPayloads = [
            $messageData['templateButtonReplyMessage'] ?? null,
            $messageData['templateButtonsReplyMessage'] ?? null,
            $messageData['interactiveButtonsReply'] ?? null,
            $messageData['interactiveButtonReply'] ?? null,
            $messageData['interactiveButtonsResponse'] ?? null,
            $messageData['buttonsResponseMessage'] ?? null,
        ];

        foreach ($replyPayloads as $replyPayload) {
            if (!is_array($replyPayload)) {
                continue;
            }

            $selectedId = trim((string)(
                $replyPayload['selectedId']
                ?? $replyPayload['buttonId']
                ?? $replyPayload['selectedButtonId']
                ?? ''
            ));
            if ($selectedId !== '') {
                return $selectedId;
            }

            $displayText = trim((string)(
                $replyPayload['selectedDisplayText']
                ?? $replyPayload['buttonText']
                ?? $replyPayload['selectedButtonText']
                ?? ''
            ));
            if ($displayText !== '') {
                return $displayText;
            }
        }

        return '';
    }
}
