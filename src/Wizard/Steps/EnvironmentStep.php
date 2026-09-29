<?php

declare(strict_types=1);

namespace Cpdeploy\Wizard\Steps;

use Cpdeploy\Database\DatabaseService;
use Cpdeploy\Env\EnvFile;
use Cpdeploy\Laravel\AppKey;
use Cpdeploy\Menus\MenuContext;
use Cpdeploy\Support\Errors\CpdeployException;
use Cpdeploy\Wizard\WizardRun;
use Cpdeploy\Wizard\WizardState;
use Cpdeploy\Wizard\WizardStep;
use Throwable;

/**
 * Step 8 (§9.3): where shared/.env comes from (ENV-09, pasted, imported, or
 * later) and the database (DB-01…04). Laravel only. Nothing is created here:
 * the database and .env are made at Create (WIZ-01).
 */
final class EnvironmentStep implements WizardStep
{
    public function run(WizardRun $w): string
    {
        $state = $w->state;
        $ctx = $w->ctx;
        if (($state->type ?? 'laravel') !== 'laravel') {
            $state->dbMode = WizardState::DB_NONE;

            return $w->skip();
        }

        if ($state->envMode === WizardState::ENV_IMPORT && $state->importFrom !== null) {
            $w->title(8, 'Environment (.env)');
            $ctx->line('Using .env from ' . $w->tilde($state->importFrom . '/.env'));
            $state->dbMode = WizardState::DB_NONE;
            $this->testImported($w, $state->importFrom . '/.env');
            $choice = $ctx->choose('Continue?', ['continue' => 'Continue']);

            return $choice === MenuContext::BACK ? self::BACK : self::NEXT;
        }

        while (true) {
            $w->title(8, 'Environment (.env)');
            $choice = (string) $ctx->choose('Where does the .env come from?', [
                WizardState::ENV_EXAMPLE => 'Create from .env.example and fill in the essentials',
                WizardState::ENV_PASTE => 'Paste an existing .env',
                WizardState::ENV_LATER => "I'll add it later (deploys are blocked until it exists)",
            ], in_array($state->envMode, [WizardState::ENV_PASTE, WizardState::ENV_LATER], true) ? $state->envMode : WizardState::ENV_EXAMPLE);
            if ($choice === MenuContext::BACK) {
                return self::BACK;
            }
            if ($choice === WizardState::ENV_LATER) {
                $state->envMode = WizardState::ENV_LATER;
                $state->dbMode = WizardState::DB_NONE;

                return self::NEXT;
            }
            if ($choice === WizardState::ENV_EXAMPLE && !$this->fromExample($w)) {
                continue;
            }
            if ($choice === WizardState::ENV_PASTE && !$this->paste($w)) {
                continue;
            }
            if ($this->database($w)) {
                return self::NEXT;
            }
        }
    }

    /**
     * APP_NAME and APP_URL; false = back to the .env question.
     */
    private function fromExample(WizardRun $w): bool
    {
        $state = $w->state;
        $ctx = $w->ctx;
        $example = $state->files?->read('.env.example');
        if ($example === null) {
            $ctx->line('The repository has no .env.example: a minimal Laravel .env is used.');
        }
        $exampleName = null;
        try {
            $exampleName = $example !== null ? EnvFile::parse($example, '.env.example')->get('APP_NAME') : null;
        } catch (CpdeployException) {
            $exampleName = null;
        }
        $defaultName = $state->appName !== '' ? $state->appName : ($exampleName !== null && $exampleName !== '' && $exampleName !== 'Laravel' ? $exampleName : $state->name);
        $name = $w->text('APP_NAME', $defaultName);
        if ($name === null) {
            return false;
        }
        $url = $w->text('APP_URL', $state->appUrl !== '' ? $state->appUrl : 'https://' . $state->domain, 'https://shop.example.com', static fn (string $v): ?string => preg_match('#^https?://[^\s/]+#', $v) === 1 ? null : 'A URL starting with https:// (or http://)');
        if ($url === null) {
            return false;
        }
        $state->envMode = WizardState::ENV_EXAMPLE;
        $state->envContent = null;
        $state->appName = $name;
        $state->appUrl = $url;
        $ctx->line('Set automatically: APP_ENV=production · APP_DEBUG=false · APP_KEY=base64:•••• (generated)');

        return true;
    }

    /**
     * ENV-01 on the pasted text; false = back to the .env question.
     */
    private function paste(WizardRun $w): bool
    {
        $state = $w->state;
        $ctx = $w->ctx;
        while (true) {
            $text = $ctx->asker->textarea('Paste the .env (an empty answer goes back)', $state->envMode === WizardState::ENV_PASTE ? (string) $state->envContent : '');
            if (trim($text) === '' || trim($text) === WizardRun::BACK_INPUT) {
                return false;
            }
            try {
                $env = EnvFile::parse($text);
            } catch (CpdeployException $e) {
                $ctx->error($e);
                continue;
            }
            $w->services()->masker()->addEnv($env->all());
            if (!AppKey::isSet($env->get('APP_KEY'))) {
                $ctx->warn('APP_KEY is empty: it will be generated.');
            }
            if (strtolower((string) $env->get('APP_DEBUG')) === 'true') {
                $ctx->warn('APP_DEBUG=true shows error details to visitors; use false in production.');
            }
            $state->envMode = WizardState::ENV_PASTE;
            $state->envContent = $text;

            return true;
        }
    }

    /**
     * DB-01…03 choice; false = back to the .env question.
     */
    private function database(WizardRun $w): bool
    {
        $state = $w->state;
        $ctx = $w->ctx;
        $names = null;
        try {
            $names = $w->services()->databases()->names($state->name);
        } catch (Throwable) {
            $names = null;
        }
        while (true) {
            $choice = (string) $ctx->choose('Database', [
                WizardState::DB_CREATE => 'Create a new MySQL database and user' . ($names !== null ? "   ({$names[0]} / {$names[1]})" : ''),
                WizardState::DB_EXISTING => 'Use an existing database…',
                WizardState::DB_SQLITE => 'SQLite (file kept in shared/database/)',
                WizardState::DB_NONE => "Skip — I'll set DB_* myself",
            ], $state->dbMode === WizardState::DB_NONE && $state->envMode === WizardState::ENV_EXAMPLE ? WizardState::DB_CREATE : $state->dbMode);
            if ($choice === MenuContext::BACK) {
                return false;
            }
            if ($choice === WizardState::DB_EXISTING) {
                if (!$this->existing($w)) {
                    continue;
                }
            } else {
                // A new database is made at Create (WIZ-01), its names chosen again then.
                $state->dbName = null;
                $state->dbUser = null;
                $state->dbPassword = null;
            }
            $state->dbMode = $choice;

            return true;
        }
    }

    /**
     * DB-02 with the DB-04 test; false = back to the database question.
     */
    private function existing(WizardRun $w): bool
    {
        $state = $w->state;
        $ctx = $w->ctx;
        $service = $w->services()->databases();
        try {
            $databases = $service->existing();
        } catch (CpdeployException $e) {
            $ctx->error($e);

            return false;
        }
        if ($databases === []) {
            $ctx->line('This account has no MySQL databases yet.');

            return false;
        }
        $database = $ctx->pick('Which database?', array_combine(array_keys($databases), array_keys($databases)));
        if ($database === MenuContext::BACK) {
            return false;
        }
        $users = $databases[$database];
        if ($users === []) {
            $ctx->warn("No user has access to {$database}: add one in cPanel → MySQL Databases first.");

            return false;
        }
        $user = (string) $ctx->choose('Which user?', array_combine($users, $users));
        if ($user === MenuContext::BACK) {
            return false;
        }
        while (true) {
            $password = $ctx->asker->password("Password of {$user}");
            $w->services()->masker()->add($password);
            $result = $this->test($w, DatabaseService::mysqlEnv($database, $user, $password));
            if ($result === null || $result) {
                break;
            }
            $again = (string) $ctx->asker->select('The connection failed', [
                'retry' => 'Enter the password again',
                'keep' => 'Use it anyway (fix it in .env later)',
                'back' => $ctx->theme->symbol('back') . ' Back',
            ], 'retry');
            if ($again === 'back') {
                return false;
            }
            if ($again === 'keep') {
                break;
            }
        }
        $state->dbName = $database;
        $state->dbUser = $user;
        $state->dbPassword = $password;

        return true;
    }

    /**
     * DB-04 with the chosen PHP. Null when it couldn't run (no PHP chosen, …).
     *
     * @param array<string, string> $env
     */
    private function test(WizardRun $w, array $env): ?bool
    {
        $ctx = $w->ctx;
        $state = $w->state;
        if ($state->phpVersion === null) {
            return null;
        }
        try {
            $php = $w->services()->php()->resolve($state->phpVersion, $state->phpFamily);
            $ctx->reporter->start('Database connection');
            $result = $w->services()->databases()->test($php->binary, $env, $w->services()->paths()->home());
        } catch (CpdeployException $e) {
            $ctx->warn("Couldn't test the connection: " . $e->getMessage());

            return null;
        }
        if ($result->ok()) {
            $ctx->reporter->succeed('connected');

            return true;
        }
        $ctx->reporter->fail($result->detail);

        return false;
    }

    private function testImported(WizardRun $w, string $file): void
    {
        try {
            $env = EnvFile::fromFile($file)->all();
        } catch (CpdeployException $e) {
            $w->ctx->error($e);

            return;
        }
        $w->services()->masker()->addEnv($env);
        if (($env['DB_CONNECTION'] ?? 'mysql') === '' || !isset($env['DB_DATABASE'])) {
            return;
        }
        if ($this->test($w, $env) === false) {
            $w->ctx->warn('The imported .env can\'t reach its database yet. Check DB_* before the first deploy.');
        }
    }
}
