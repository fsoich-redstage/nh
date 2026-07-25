<?php
declare(strict_types=1);

namespace NutriHelper\Domain;

final class ImageStore
{
    public function __construct(private readonly string $directory)
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("No se pudo crear el directorio de imágenes: {$this->directory}");
        }
    }

    /**
     * Stores a base64-encoded image and returns the file key used (no extension).
     */
    public function store(string $identifier, string $base64Data): string
    {
        $fileKey = time() . '_' . $identifier;
        $path = rtrim($this->directory, '/') . '/' . $fileKey . '.jpg';

        $bytes = base64_decode($base64Data, true);
        if ($bytes === false) {
            throw new \RuntimeException('Imagen en base64 inválida.');
        }

        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException("No se pudo guardar la imagen en {$path}.");
        }

        return $fileKey;
    }
}
