<?php

namespace Nandi95\LaravelEnvInAwsSsm\Console;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nandi95\LaravelEnvInAwsSsm\Traits\InteractsWithSSM;

class EnvPush extends Command
{
    use InteractsWithSSM;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'env:push
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
    protected $description = 'Set the environment variables for the given stage in the SSM parameter store.';

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

        if (!file_exists('.env.' . $this->stage)) {
            throw new InvalidArgumentException("'.env.$this->stage' doesn't exists.");
        }

        $localEnvs = $this->getEnvironmentVarsFromFile();

        // SSM parameter store has a limit of 4kb per value for standard quota
        [$over, $under] = $localEnvs->partition(fn (string $val) => Str::length($val) >= 4096);

        $over->each(function (string $val, string $key) use ($under) {
            $this->warn("Value for $key is over 4kb, splitting into multiple keys.");

            collect(mb_str_split($val, 4096))
                ->each(function (string $chunk, int $index) use ($key, $under) {
                    $under->put($key . '.part' . $index, $chunk);
                });
        });

        $bar = $this->getOutput()->createProgressBar($under->count() + 1);
        $remoteEnvs = $this->getEnvironmentVarsFromRemote();
        $bar->advance();

        $remoteKeysNotInLocal = $remoteEnvs->diffKeys($under);

        // user deleted some keys, remove from remote too
        if ($remoteKeysNotInLocal->isNotEmpty()) {
            $bar->clear();
            $this->info($remoteKeysNotInLocal->count() . ' variables found not present in .env.' . $this->stage . '. Deleting removed keys.');
            $bar->display();
            if ($under->isEmpty()) {
                $this->warn('There are no environment variables set locally.');
                $this->confirm('This will remove all variables in SSM, are you sure you want to proceed?');
            }

            $qualifiedKeys = $remoteKeysNotInLocal
                ->keys()
                ->map(fn(string $key) => $this->qualifyKey($key))
                ->toArray();

            $this->getClient()->deleteParameters(['Names' => $qualifiedKeys]);
        }

        $retryIntervals = [3000, 6000, 9000];

        $under->each(function (string $val, string $key) use ($bar, $retryIntervals) {
            $attempt = 0;
            $maxAttempts = count($retryIntervals) + 1;

            while ($attempt < $maxAttempts) {
                try {
                    $this->getClient()->putParameter([
                        'Name' => $this->qualifyKey($key),
                        'Value' => $val,
                        'Overwrite' => true,
                        'Type' => 'String'
                    ]);
                    break; // If the request is successful, break out of the loop
                } catch (\Exception $e) {
                    $attempt++;
                    if ($attempt >= $maxAttempts) {
                        throw $e; // Re-throw the exception if all attempts fail
                    }
                    // Wait for the specified interval before the next attempt
                    usleep($retryIntervals[$attempt - 1] * 1000); // Convert milliseconds to microseconds
                }
            }
            $bar->advance();
        });

        return 0;
    }
}
