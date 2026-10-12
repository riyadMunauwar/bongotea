<?php

namespace App\Console\Commands;

use App\Models\Menu;
use App\Models\Page;
use App\Models\Role;
use App\Models\User;
use App\Support\Access\PermissionCatalogue;
use Database\Seeders\BlankStoreSeeder;
use Database\Seeders\CatalogueSeeder;
use Database\Seeders\DemoStoreSeeder;
use Database\Seeders\StorefrontSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Stand up an installation, from a fresh checkout to a store an Owner can
 * sign in to: the environment checked, the database migrated, the storage
 * link made, the Permissions registered, the first Owner created, the store
 * opened blank or on the demo, and the caches set for where it runs.
 *
 * Every step is safe to run again and only ever adds what is missing: an
 * Owner is asked for only while there is none, and blank or demo is asked
 * only of a store with no catalogue, no ordinary Pages and no Menus — so
 * the demo is never laid over a Merchant's own content, and nothing they
 * have edited, renamed or deleted since is put back (ADR-0030). Each answer
 * can be given as an option, for an unattended run in Docker or CI.
 */
class InstallCommand extends Command
{
    protected $signature = 'bongotea:install
        {--owner-name= : The first Owner\'s name}
        {--owner-email= : The first Owner\'s email address}
        {--owner-password= : The first Owner\'s password, at least 8 characters}
        {--demo : Open the store on the demo catalogue and Pages}
        {--blank : Open the store with only its System Pages}';

    protected $description = 'Migrate, link storage, register Permissions, create the first Owner and open the store blank or on the demo, adding only what is missing';

    /** @var array<string, list<string>> */
    private const OWNER_RULES = [
        'name' => ['required', 'string', 'max:255'],
        'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
        'password' => ['required', 'string', 'min:8'],
    ];

    public function handle(PermissionCatalogue $catalogue): int
    {
        if ($this->option('demo') && $this->option('blank')) {
            $this->components->error('Choose one of --demo and --blank, not both.');

            return self::FAILURE;
        }

        if (! $this->environmentIsReady()) {
            return self::FAILURE;
        }

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->components->error('The database could not be migrated, so nothing more is done.');

            return self::FAILURE;
        }

        if (! file_exists(public_path('storage'))) {
            $this->call('storage:link');
        }

        $this->call('permission:sync');

        if (! $this->ensureOwner($catalogue)) {
            return self::FAILURE;
        }

        if (! $this->openStore()) {
            return self::FAILURE;
        }

        $this->call($this->laravel->isProduction() ? 'optimize' : 'optimize:clear');

        if ($this->laravel->isProduction()) {
            $this->call('filament:optimize');
        }

        $this->checklist();

        return self::SUCCESS;
    }

    /**
     * An application key, generated into `.env` when there is a `.env` and no
     * key (an existing key is never replaced), and a database that answers.
     */
    private function environmentIsReady(): bool
    {
        if (blank(config('app.key'))) {
            if (! file_exists($this->laravel->environmentFilePath())) {
                $this->components->error('There is no .env file and no APP_KEY. Copy .env.example to .env, set the database, and run this again.');

                return false;
            }

            $this->call('key:generate', ['--force' => true]);
        }

        try {
            DB::connection()->getPdo();
        } catch (Throwable $exception) {
            $this->components->error('The database cannot be reached: '.$exception->getMessage());

            return false;
        }

        return true;
    }

    /**
     * The first Owner, made only while the installation has none.
     */
    private function ensureOwner(PermissionCatalogue $catalogue): bool
    {
        if (Role::owner()->users()->exists()) {
            $this->components->info('An Owner already exists, so none is created.');

            return true;
        }

        $answers = [
            'name' => $this->option('owner-name') ?? $this->whenInteractive(fn (): string => text(
                label: 'Owner\'s name',
                required: true,
                validate: fn (string $value): ?string => self::problem('name', $value),
            )),
            'email' => $this->option('owner-email') ?? $this->whenInteractive(fn (): string => text(
                label: 'Owner\'s email address',
                required: true,
                validate: fn (string $value): ?string => self::problem('email', $value),
            )),
            'password' => $this->option('owner-password') ?? $this->whenInteractive(fn (): string => self::askPassword()),
        ];

        if (in_array(null, $answers, true)) {
            $this->components->error('No Owner exists. Give --owner-name, --owner-email and --owner-password, or run this interactively.');

            return false;
        }

        foreach ($answers as $field => $value) {
            if (($problem = self::problem($field, $value)) !== null) {
                $this->components->error($problem);

                return false;
            }
        }

        $owner = User::query()->create($answers);
        $owner->assignRole(Role::owner());
        $catalogue->forget();

        $this->components->info("{$owner->name} ({$owner->email}) is the Owner.");

        return true;
    }

    /**
     * Blank or demo, asked only of a store with nothing of its own yet. The
     * System Pages are made either way, since the store cannot run without
     * them.
     */
    private function openStore(): bool
    {
        $isEmpty = self::storeIsEmpty();

        $this->seed(StorefrontSeeder::class);

        if (! $isEmpty) {
            $this->components->info('The store already has its own content, so it is opened neither blank nor on the demo.');

            return true;
        }

        $mode = match (true) {
            (bool) $this->option('demo') => 'demo',
            (bool) $this->option('blank') => 'blank',
            default => $this->whenInteractive(fn (): string => (string) select(
                label: 'Open the store blank or with demo data?',
                options: [
                    'demo' => 'Demo — the catalogue, about and tea-guide Pages, and full Menus',
                    'blank' => 'Blank — only the System Pages, to build from the panel',
                ],
                default: 'demo',
            )),
        };

        if ($mode === null) {
            $this->components->error('Give --demo or --blank, or run this interactively.');

            return false;
        }

        DB::transaction(function () use ($mode): void {
            foreach ($mode === 'demo' ? [CatalogueSeeder::class, DemoStoreSeeder::class] : [BlankStoreSeeder::class] as $seeder) {
                $this->seed($seeder);
            }
        });

        $this->components->info($mode === 'demo' ? 'The store is open on the demo.' : 'The store is open, blank.');

        return true;
    }

    private function seed(string $seeder): void
    {
        $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
    }

    /**
     * Whether the store has no catalogue, no ordinary Pages and no Menus of
     * its own — the only store blank or demo may be laid on.
     */
    private static function storeIsEmpty(): bool
    {
        return ! CatalogueSeeder::hasCatalogue()
            && ! Page::query()->whereNull('system_role')->exists()
            && ! Menu::query()->exists();
    }

    /**
     * What this command cannot do itself, said once at the end.
     */
    private function checklist(): void
    {
        $steps = [
            'Run the scheduler every minute: * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1',
        ];

        if (config('queue.default') !== 'sync') {
            $steps[] = 'Keep a queue worker running: php artisan queue:work';
        }

        if (! file_exists(public_path('build/manifest.json'))) {
            $steps[] = 'Build the storefront\'s assets: npm ci && npm run build';
        }

        $this->components->info('Bongotea is installed. Still to do on this server:');
        $this->components->bulletList($steps);
    }

    /**
     * A prompt's answer, or null when nobody is there to ask.
     *
     * @param  callable(): string  $prompt
     */
    private function whenInteractive(callable $prompt): ?string
    {
        return $this->input->isInteractive() ? $prompt() : null;
    }

    private static function askPassword(): string
    {
        while (true) {
            $first = password(label: 'Owner\'s password', required: true, validate: fn (string $value): ?string => self::problem('password', $value));
            $second = password(label: 'The password again', required: true);

            if ($first === $second) {
                return $first;
            }

            error('The two passwords differ. Try again.');
        }
    }

    /**
     * What is wrong with one of the Owner's answers, or null.
     */
    private static function problem(string $field, string $value): ?string
    {
        $validator = Validator::make([$field => $value], [$field => self::OWNER_RULES[$field]]);

        return $validator->fails() ? $validator->errors()->first($field) : null;
    }
}
