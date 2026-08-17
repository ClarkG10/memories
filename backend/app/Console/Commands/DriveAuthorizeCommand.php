<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\GoogleDrive\GoogleDriveClientFactory;
use Illuminate\Console\Command;
use Throwable;

/**
 * One-time consent flow that turns a Google OAuth client into the long-lived
 * refresh token the server uses from then on.
 *
 * Deliberately a console command: the secret is printed once, into the
 * operator's terminal, and never travels through the browser or the API.
 */
class DriveAuthorizeCommand extends Command
{
    protected $signature = 'drive:authorize';

    protected $description = 'Connect the archive to a Google Drive account and print its refresh token';

    public function handle(GoogleDriveClientFactory $factory): int
    {
        if (blank(config('googledrive.oauth.client_id')) || blank(config('googledrive.oauth.client_secret'))) {
            $this->components->error(
                'Set GOOGLE_DRIVE_CLIENT_ID and GOOGLE_DRIVE_CLIENT_SECRET first.'
            );

            $this->line('  Create an OAuth client (type: Web application) at');
            $this->line('  https://console.cloud.google.com/apis/credentials and add');
            $this->line('  '.config('googledrive.oauth.redirect_uri').' as an authorised redirect URI.');

            return self::FAILURE;
        }

        $client = $factory->consentClient();

        $this->newLine();
        $this->components->info('Open this URL, choose the Google account that should hold the archive, and approve access:');
        $this->newLine();
        $this->line($client->createAuthUrl());
        $this->newLine();
        $this->line('Google will then send your browser to <options=bold>'.config('googledrive.oauth.redirect_uri').'</>');
        $this->line('That page <options=bold>will not load</> — nothing is listening there, and that is fine.');
        $this->line('What matters is the address bar, which now contains <options=bold>?code=...</>');
        $this->line('Copy the value between <options=bold>code=</> and the next <options=bold>&</>, and paste it below.');
        $this->newLine();

        $code = trim((string) $this->secret('Authorisation code'));

        if ($code === '') {
            $this->components->error('No code entered.');

            return self::FAILURE;
        }

        try {
            $token = $client->fetchAccessTokenWithAuthCode($code);
        } catch (Throwable $e) {
            $this->components->error('Google rejected the code: '.$e->getMessage());

            return self::FAILURE;
        }

        if (isset($token['error'])) {
            $this->components->error('Google rejected the code: '.($token['error_description'] ?? $token['error']));

            return self::FAILURE;
        }

        if (! isset($token['refresh_token'])) {
            $this->components->error('Google did not return a refresh token.');
            $this->line('  This happens when the account has already authorised this client.');
            $this->line('  Revoke it at https://myaccount.google.com/permissions and run this again.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Connected. Add this to your .env and do not commit it:');
        $this->newLine();
        $this->line('GOOGLE_DRIVE_REFRESH_TOKEN='.$token['refresh_token']);
        $this->newLine();

        return self::SUCCESS;
    }
}
