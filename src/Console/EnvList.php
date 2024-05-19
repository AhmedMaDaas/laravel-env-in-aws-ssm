<?php

namespace Nandi95\LaravelEnvInAwsSsm\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nandi95\LaravelEnvInAwsSsm\Traits\InteractsWithSSM;

class EnvList extends Command
{
    use InteractsWithSSM;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'env:list 
                            {stage : The environment of the app}
                            {--key= : Display only this key}
                            {--appName=}
                            {--secretKey=}
                            {--accessKey=}
                            {--region=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display the environment variables for the given stage from the SSM parameter store.';

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
        $keyValues = $this->unifySplitValues($this->getEnvironmentVarsFromRemote()->sortKeys());

        if ($this->option('key')) {
            $keyValues = $keyValues->filter(fn ($value, $key) => $key === $this->option('key'));
        }

        $this->table(
            ['Key', 'Value'],
            $keyValues->map(fn ($value, $key) => [$key, $value])->toArray()
        );

        return 0;
    }
}
