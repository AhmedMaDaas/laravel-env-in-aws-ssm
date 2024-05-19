<?php

namespace Nandi95\LaravelEnvInAwsSsm\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nandi95\LaravelEnvInAwsSsm\Traits\InteractsWithSSM;

class EnvPull extends Command
{
    use InteractsWithSSM;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'env:pull
                            {stage : The environment of the app}
                            {--appName=}
                            {--secretKey=}
                            {--accessKey=}
                            {--region=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Retrieve environment variables for the given stage from the SSM parameter store.';

    /**
     * Execute the console command.
     *
     * @return int
     *
     * @throws Exception
     */
    public function handle(): int
    {
        $this->stage = str_replace('stage=', '', $this->argument('stage'));

        $resolvedEnv = '';

        $this->unifySplitValues($this->getEnvironmentVarsFromRemote())
            ->sortKeys()
            ->mapToGroups(function ($value, $key) {
                return [Str::before($key, '_') => $key . '=' . $value];
            })
            ->each(static function (Collection $envs) use (&$resolvedEnv) {
                $resolvedEnv .= $envs->join("\n") . "\n\n";
            });

        if (file_exists('.env.' . $this->stage)) {
            $this->backupEnvFile();
        }

        file_put_contents('.env.' . $this->stage, $resolvedEnv);

        return 0;
    }

    /**
     * @return void
     */
    public function backupEnvFile(): void
    {
        $this->line('Backing up \'' . '.env.' . $this->stage . '\'');
        $backupFile = '.env.' . $this->stage . '.backup';

        if (file_exists($backupFile)) {
            $this->info("Skipping backing up, $backupFile already exists.");
            return;
        }

        copy('.env.' . $this->stage, $backupFile);
    }
}
