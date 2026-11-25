<?php

namespace App\Jobs;

use App\Mail\RegistrationReceived;
use App\Models\Registration;
use App\Services\GoogleDriveProofStorage;
use App\Services\GoogleSheetsAppender;
use App\Services\GoogleSheetsException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class ProcessRegistrationPostSubmission implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public array $registrationIds,
        public ?string $proofFileName = null,
    ) {}

    public function handle(
        GoogleSheetsAppender $sheetsAppender,
        GoogleDriveProofStorage $driveStorage,
    ): void {
        $registrations = Registration::query()
            ->whereIn('id', $this->registrationIds)
            ->get();

        if ($registrations->isEmpty()) {
            Log::warning('Post-registration job found no registrations.', [
                'registration_ids' => $this->registrationIds,
            ]);

            return;
        }

        $emailFailures = [];
        $registration = $registrations->firstOrFail();
        $this->moveProofToGoogleDrive($registrations, $driveStorage, $emailFailures);
        $this->sendNotification(
            $registration,
            $registration->email,
            false,
            'submitter confirmation',
            $emailFailures,
            $registrations,
        );

        $adminEmail = config('registrations.admin_email');
        if (is_string($adminEmail) && $adminEmail !== '') {
            $this->sendNotification(
                $registration,
                $adminEmail,
                true,
                'admin notification',
                $emailFailures,
                $registrations,
            );
        } else {
            $emailFailures[] = 'admin notification (ADMIN_EMAIL is not configured)';
        }

        try {
            $sheetsAppender->appendRegistrations($registrations);
            Registration::query()
                ->whereIn('id', $registrations->modelKeys())
                ->update(['google_sheets_synced_at' => now()]);
        } catch (GoogleSheetsException $exception) {
            Log::error('Convention registrations could not be appended to Google Sheets.', [
                'registration_ids' => $registrations->modelKeys(),
                'exception' => $exception->getMessage(),
            ]);
            $emailFailures[] = 'Google Sheets sync: '.$exception->getMessage();
        } catch (Throwable $exception) {
            Log::error('Unexpected error while syncing convention registrations to Google Sheets.', [
                'registration_ids' => $registrations->modelKeys(),
                'exception' => $exception->getMessage(),
            ]);
            $emailFailures[] = 'Google Sheets sync';
        }

        if ($emailFailures !== []) {
            Log::warning('Some post-registration notifications were not completed.', [
                'registration_ids' => $registrations->modelKeys(),
                'failures' => array_values(array_unique($emailFailures)),
            ]);
        }
    }

    private function moveProofToGoogleDrive(
        Collection $registrations,
        GoogleDriveProofStorage $driveStorage,
        array &$failures,
    ): void {
        $proofPath = $registrations->firstOrFail()->proof_of_payment_path;
        if (! $driveStorage->isConfigured() || $driveStorage->isDrivePath($proofPath)) {
            return;
        }

        try {
            $drivePath = $driveStorage->storePath(
                Storage::disk('local')->path($proofPath),
                $this->proofFileName ?: $driveStorage->fileName($proofPath),
                Storage::disk('local')->mimeType($proofPath) ?: 'application/octet-stream',
            );

            Registration::query()
                ->whereIn('id', $registrations->modelKeys())
                ->update(['proof_of_payment_path' => $drivePath]);

            Storage::disk('local')->delete($proofPath);
            $registrations->each(function (Registration $registration) use ($drivePath): void {
                $registration->proof_of_payment_path = $drivePath;
            });
        } catch (GoogleSheetsException $exception) {
            Log::error('The proof-of-payment file could not be moved to Google Drive.', [
                'registration_ids' => $registrations->modelKeys(),
                'exception' => $exception->getMessage(),
            ]);
            $failures[] = 'Google Drive sync: '.$exception->getMessage();
        } catch (Throwable $exception) {
            Log::error('Unexpected error while moving proof-of-payment file to Google Drive.', [
                'registration_ids' => $registrations->modelKeys(),
                'exception' => $exception->getMessage(),
            ]);
            $failures[] = 'Google Drive sync';
        }
    }

    private function sendNotification(
        Registration $registration,
        string $recipient,
        bool $includeProof,
        string $notificationName,
        array &$emailFailures,
        Collection $registrations,
    ): void {
        try {
            Mail::to($recipient)->send(new RegistrationReceived(
                $registration,
                $includeProof,
                $registrations->all(),
            ));
        } catch (TransportExceptionInterface $exception) {
            Log::error('A registration email could not be delivered.', [
                'registration_id' => $registration->id,
                'recipient' => $recipient,
                'exception' => $exception->getMessage(),
            ]);
            $emailFailures[] = $notificationName;
        } catch (Throwable $exception) {
            Log::error('A registration email could not be prepared or delivered.', [
                'registration_id' => $registration->id,
                'recipient' => $recipient,
                'exception' => $exception->getMessage(),
            ]);
            $emailFailures[] = $notificationName;
        }
    }
}
