<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleDriveProofStorage
{
    private const API_URL = 'https://www.googleapis.com/drive/v3';

    private const UPLOAD_URL = 'https://www.googleapis.com/upload/drive/v3/files';

    public function isConfigured(): bool
    {
        $folderId = config('services.google_drive.folder_id');

        return is_string($folderId) && $folderId !== '';
    }

    public function store(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (! is_string($path)) {
            throw new GoogleSheetsException('The proof-of-payment file could not be read.');
        }

        return $this->storePath(
            $path,
            $file->getClientOriginalName(),
            (string) $file->getMimeType(),
        );
    }

    public function storePath(string $path, string $fileName, string $mimeType): string
    {
        $fileContents = file_get_contents($path);
        if (! is_string($fileContents)) {
            throw new GoogleSheetsException('The proof-of-payment file could not be read.');
        }

        $metadata = [
            'name' => $fileName,
        ];
        $folderId = config('services.google_drive.folder_id');
        if (is_string($folderId) && $folderId !== '') {
            $metadata['parents'] = [$folderId];
        }
        $boundary = 'drive-upload-'.bin2hex(random_bytes(12));
        $body = '--'.$boundary."\r\n"
            ."Content-Type: application/json; charset=UTF-8\r\n\r\n"
            .json_encode($metadata, JSON_THROW_ON_ERROR)."\r\n"
            .'--'.$boundary."\r\n"
            .'Content-Type: '.$mimeType."\r\n\r\n"
            .$fileContents."\r\n"
            .'--'.$boundary."--\r\n";

        try {
            $response = Http::withToken(app(GoogleSheetsOAuth::class)->accessToken())
                ->withHeaders(['Content-Type' => 'multipart/related; boundary='.$boundary])
                ->send('POST', self::UPLOAD_URL.'?uploadType=multipart&fields=id,name', [
                    'body' => $body,
                ])->throw();
        } catch (ConnectionException $exception) {
            throw new GoogleSheetsException('Google Drive could not store the proof-of-payment file.', previous: $exception);
        } catch (RequestException $exception) {
            throw new GoogleSheetsException($this->requestFailureMessage($exception), previous: $exception);
        }

        $fileId = $response->json('id');
        if (! is_string($fileId) || $fileId === '') {
            throw new GoogleSheetsException('Google Drive did not return a file ID.');
        }

        try {
            Http::withToken(app(GoogleSheetsOAuth::class)->accessToken())
                ->post(self::API_URL.'/files/'.rawurlencode($fileId).'/permissions', [
                    'type' => 'anyone',
                    'role' => 'reader',
                ])->throw();
        } catch (ConnectionException $exception) {
            throw new GoogleSheetsException('Google Drive could not make the proof-of-payment file viewable.', previous: $exception);
        } catch (RequestException $exception) {
            throw new GoogleSheetsException($this->requestFailureMessage($exception), previous: $exception);
        }

        return $this->encodePath($fileId, $fileName);
    }

    public function download(string $path): string
    {
        $fileId = $this->fileId($path);

        try {
            return Http::withToken(app(GoogleSheetsOAuth::class)->accessToken())
                ->get(self::API_URL.'/files/'.rawurlencode($fileId), ['alt' => 'media'])
                ->throw()
                ->body();
        } catch (ConnectionException $exception) {
            throw new GoogleSheetsException('Google Drive could not download the proof-of-payment file.', previous: $exception);
        } catch (RequestException $exception) {
            throw new GoogleSheetsException($this->requestFailureMessage($exception), previous: $exception);
        }
    }

    public function delete(string $path): void
    {
        if (! $this->isDrivePath($path)) {
            return;
        }

        try {
            Http::withToken(app(GoogleSheetsOAuth::class)->accessToken())
                ->delete(self::API_URL.'/files/'.rawurlencode($this->fileId($path)))
                ->throw();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
        }
    }

    public function viewUrl(string $path): string
    {
        return 'https://drive.google.com/uc?export=view&id='.rawurlencode($this->fileId($path));
    }

    public function fileName(string $path): string
    {
        if (! $this->isDrivePath($path)) {
            return basename($path);
        }

        return explode('|', substr($path, strlen('google-drive:')), 2)[1] ?? 'proof-of-payment';
    }

    public function isDrivePath(string $path): bool
    {
        return str_starts_with($path, 'google-drive:');
    }

    public function fileId(string $path): string
    {
        if (! $this->isDrivePath($path)) {
            throw new RuntimeException('The proof path is not a Google Drive file.');
        }

        return explode('|', substr($path, strlen('google-drive:')), 2)[0];
    }

    private function encodePath(string $fileId, string $fileName): string
    {
        return 'google-drive:'.$fileId.'|'.$fileName;
    }

    private function requestFailureMessage(RequestException $exception): string
    {
        $message = $exception->response->json('error.message');

        return is_string($message) && $message !== ''
            ? 'Google Drive returned HTTP '.$exception->response->status().': '.$message
            : 'Google Drive returned HTTP '.$exception->response->status().'.';
    }
}
