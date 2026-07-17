<?php

namespace App\Services\Documents;

use RuntimeException;

class ClamAvScanner
{
    /**
     * @param  resource  $stream
     * @return 'clean'|'infected'
     */
    public function scan($stream): string
    {
        $host = (string) config('services.clamav.host');
        $port = (int) config('services.clamav.port');
        $timeout = (int) config('services.clamav.timeout');
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, $timeout);

        if (! is_resource($socket)) {
            throw new RuntimeException("ClamAV ist nicht erreichbar: {$errorCode} {$errorMessage}");
        }

        stream_set_timeout($socket, $timeout);
        fwrite($socket, "zINSTREAM\0");

        while (! feof($stream)) {
            $chunk = fread($stream, 8192);
            if ($chunk === false) {
                fclose($socket);
                throw new RuntimeException('Der Upload konnte nicht vollständig gelesen werden.');
            }

            if ($chunk !== '') {
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
        }

        fwrite($socket, pack('N', 0));
        $response = stream_get_contents($socket);
        fclose($socket);

        if (! is_string($response)) {
            throw new RuntimeException('ClamAV hat keine gültige Antwort geliefert.');
        }

        if (str_contains($response, 'FOUND')) {
            return 'infected';
        }

        if (str_contains($response, 'OK')) {
            return 'clean';
        }

        throw new RuntimeException('ClamAV-Scan fehlgeschlagen: '.trim($response));
    }
}
