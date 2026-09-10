<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Mint the VAPID key pair, once, for the life of the app.
 *
 * These keys are the app's identity to every push service. Rotating them
 * silently invalidates every subscription already stored — every browser out
 * there is holding a subscription bound to the old public key, and no error
 * is raised anywhere: pushes simply stop arriving. Treat them like `APP_KEY`
 * and back them up somewhere the database backup is not.
 *
 * Deliberately prints rather than writes to `.env`. Deployment here is a
 * single self-managed VPS with a hand-edited env file, and a command that
 * rewrites it would be a second source of truth for the one file that has to
 * stay hand-auditable.
 */
class PushVapidCommand extends Command
{
    protected $signature = 'push:vapid';

    protected $description = 'Generate a VAPID key pair for Web Push';

    public function handle(): int
    {
        if (config('services.webpush.public_key')) {
            $this->components->warn('VAPID keys are already configured.');
            $this->line('Replacing them silently breaks every existing subscription.');
            $this->newLine();

            if (! $this->confirm('Generate a new pair anyway?', false)) {
                return self::SUCCESS;
            }
        }

        $keys = VAPID::createVapidKeys();

        $this->newLine();
        $this->line('Add these to your .env file:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->components->info('Back the private key up. Losing it means re-subscribing every device.');

        return self::SUCCESS;
    }
}
