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
     * Sends a poll. Green API allows 2-12 options, message up to 255 chars,
     * each option up to 100 chars, all unique.
     *
     * @param string[] $optionNames
     */
    public function sendPoll(string $chatId, string $message, array $optionNames, bool $multipleAnswers = false): array
    {
        return $this->request('sendPoll', [
            'chatId'          => $chatId,
            'message'         => $message,
            'options'         => array_map(static fn (string $name) => ['optionName' => $name], $optionNames),
            'multipleAnswers' => $multipleAnswers,
        ]);
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
    private function request(string $endpoint, array $payload): array
    {
        $url = sprintf(
            '%s/waInstance%s/%s/%s',
            rtrim($this->config['apiUrl'], '/'),
            $this->config['idInstance'],
            $endpoint,
            $this->config['apiToken']
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json; charset=utf-8'],
            CURLOPT_TIMEOUT        => 30,
        ]);

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
