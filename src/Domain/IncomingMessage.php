<?php
declare(strict_types=1);

namespace NutriHelper\Domain;

final class IncomingMessage
{
    public function __construct(
        public readonly string $type, // 'text' | 'image' | 'poll_vote' | 'other'
        public readonly string $chatId,
        public readonly string $body,
        public readonly string $downloadUrl = '',
        public readonly string $idMessage = ''
    ) {
    }
}
