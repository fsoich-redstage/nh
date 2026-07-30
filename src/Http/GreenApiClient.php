<?php
declare(strict_types=1);

namespace NutriHelper\Http;

/**
 * Thin client for the Green API WhatsApp endpoints this project uses.
 */
final class GreenApiClient
{
    /**
     * @param array{apiUrl:string,idInstance:string,apiToken:string} $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function sendMessage(string $chatId, string $message): array
    {
        return $this->request('sendMessage', [
            'chatId'      => $chatId,
            'message'     => $message,
            'linkPreview' => false,
        ]);
    }

    /**
     * @return array{name:string,shortName:string,pushname:string,profilePicUrl:string}|null
     */
    public function getContactInfo(string $chatId): ?array
    {
        $result = $this->request('GetContactInfo', ['chatId' => $chatId]);

        if ($result['status'] >= 400 || !is_array($result['data'])) {
            return null;
        }

        $data = $result['data'];

        return [
            'name'          => (string)($data['name'] ?? $data['contactName'] ?? ''),
            'shortName'     => (string)($data['shortName'] ?? ''),
            'pushname'      => (string)($data['pushname'] ?? ''),
            'profilePicUrl' => is_string($data['avatar'] ?? null) ? $data['avatar'] : '',
        ];
    }

    /**
     * Sends up to 3 tappable reply buttons. For reply-style buttons Green API
     * expects the dedicated sendInteractiveButtonsReply endpoint rather than
     * the mixed-button sendInteractiveButtons one.
     *
     * @param array<int,array{id:string,text:string}> $buttons
     */
    public function sendInteractiveButtons(string $chatId, string $body, array $buttons, string $header = '', string $footer = ''): array
    {
        $payload = [
            'chatId'  => $chatId,
            'body'    => $body,
            'buttons' => array_map(static fn (array $button) => [
                'buttonId'   => $button['id'],
                'buttonText' => $button['text'],
            ], $buttons),
        ];

        if ($header !== '') {
            $payload['header'] = $header;
        }
        if ($footer !== '') {
            $payload['footer'] = $footer;
        }

        return $this->request('sendInteractiveButtonsReply', $payload);
    }

    /**
     * Sends an interactive list message (WhatsApp "list" UI): a single button
     * that opens a menu of tappable rows, grouped into sections. Falls back
     * gracefully at the call site if Green API rejects it (older client
     * versions/instance types don't all support it) — callers should catch
     * failures and send a plain-text menu instead.
     *
     * @param array<int,array{title:string,rows:array<int,array{title:string,description?:string,rowId:string}>}> $sections
     */
    public function sendListMessage(string $chatId, string $message, string $buttonText, array $sections): array
    {
        return $this->request('sendListMessage', [
            'chatId'     => $chatId,
            'message'    => $message,
            'buttonText' => $buttonText,
            'sections'   => $sections,
        ]);
    }

    /**
     * Sends a file by public URL (e.g. a generated chart image) alongside a
     * caption — same public-URL pattern already used for meal photos served
     * from photos/.
     */
    public function sendFileByUrl(string $chatId, string $urlFile, string $fileName, string $caption = ''): array
    {
        return $this->request('sendFileByUrl', [
            'chatId'   => $chatId,
            'urlFile'  => $urlFile,
            'fileName' => $fileName,
            'caption'  => $caption,
        ]);
    }

    /**
     * Marks a chat (optionally up to a specific message) as read, so the
     * sender sees the double-tick without waiting for our reply. Best-effort:
     * callers should swallow failures rather than block message processing.
     */
    public function readChat(string $chatId, string $idMessage = ''): array
    {
        $payload = ['chatId' => $chatId];
        if ($idMessage !== '') {
            $payload['idMessage'] = $idMessage;
        }

        return $this->request('readChat', $payload);
    }

    /**
     * Instance connection state (e.g. "authorized", "notAuthorized",
     * "blocked") — backs bin/check_instance_health.php. Unlike the rest of
     * this client, Green API exposes this as a GET endpoint with no body.
     */
    public function getStateInstance(): array
    {
        return $this->request('getStateInstance', [], 'GET');
    }

    /**
     * Resolves the downloadable file URL for a media message when the webhook
     * payload itself did not include one.
     */
    public function downloadFileUrl(string $chatId, string $idMessage): string
    {
        if ($idMessage === '') {
            return '';
        }

        $result = $this->request('downloadFile', [
            'chatId'    => $chatId,
            'idMessage' => $idMessage,
        ]);

        if ($result['status'] >= 400 || !is_array($result['data'])) {
            return '';
        }

        $url = $result['data']['urlFile'] ?? $result['data']['downloadUrl'] ?? '';

        return is_string($url) ? $url : '';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,error:?string,response:?string,data:mixed}
     */
    private function request(string $endpoint, array $payload, string $method = 'POST'): array
    {
        $url = sprintf(
            '%s/waInstance%s/%s/%s',
            rtrim($this->config['apiUrl'], '/'),
            $this->config['idInstance'],
            $endpoint,
            $this->config['apiToken']
        );

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        if ($method === 'GET') {
            $options[CURLOPT_HTTPGET] = true;
        } else {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json; charset=utf-8'];
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $error = curl_error($ch) ?: null;
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = null;
        if (is_string($response) && $response !== '') {
            $decoded = json_decode($response, true);
        }

        return [
            'status'   => $status,
            'error'    => $error,
            'response' => $response === false ? null : $response,
            'data'     => $decoded,
        ];
    }
}
