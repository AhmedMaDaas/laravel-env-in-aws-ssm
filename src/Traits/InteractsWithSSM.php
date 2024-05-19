<?php

namespace Nandi95\LaravelEnvInAwsSsm\Traits;

use Aws\Credentials\Credentials;
use Aws\Ssm\SsmClient;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Dotenv\Dotenv;

trait InteractsWithSSM
{
    /**
     * The stage/environment of the app.
     *
     * @var string
     */
    protected string $stage;

    /**
     * The name of the app.
     *
     * @var string
     */
    private string $appName;

    /**
     * The region we're operating in.
     *
     * @var string
     */
    private string $region;

    /**
     * The Dotenv instance.
     *
     * @var Dotenv
     */
    private Dotenv $dotEnv;

    /**
     * @var Credentials
     */
    private Credentials $credentials;

    /**
     * @var SsmClient
     */
    private SsmClient $client;

    /**
     * Get the parameter name as a qualified path
     *
     * @link https://docs.aws.amazon.com/systems-manager/latest/APIReference/API_PutParameter.html#systemsmanager-PutParameter-request-Name
     *
     * @param string $name
     *
     * @return string
     */
    public function qualifyKey(string $name): string
    {
        // todo - replace with tagging? https://docs.aws.amazon.com/systems-manager/latest/userguide/tagging-parameters.html
        return '/' . $this->getAppName() . '/' . $this->stage . '/' . $name;
    }

    /**
     * Get the key without the aws namespace.
     *
     * @param string $name
     *
     * @return string
     */
    public function unQualifyKey(string $name): string
    {
        return Str::afterLast($name, '/');
    }

    /**
     * @return Dotenv
     */
    public function getDotenv(): Dotenv
    {
        if (!isset($this->dotEnv)) {
            $this->dotEnv = new Dotenv;
        }

        return $this->dotEnv;
    }

    /**
     * @return Credentials
     */
    public function getCredentials(): Credentials
    {
        if (isset($this->credentials)) {
            return $this->credentials;
        }

        $env = $this->getEnvironmentVarsFromFile();
        $awsAccessKeyId = $this->option('accessKey') ?? $env->pull('AWS_ACCESS_KEY_ID');
        $awsSecretAccessKey = $this->option('secretKey') ?? $env->pull('AWS_SECRET_ACCESS_KEY');

        if (!$awsSecretAccessKey) {
            $awsSecretAccessKey = $this->secret('AWS_SECRET_ACCESS_KEY');
        }

        if (!$awsAccessKeyId) {
            $awsAccessKeyId = $this->secret('AWS_ACCESS_KEY_ID');
        }

        $this->credentials = new Credentials($awsAccessKeyId, $awsSecretAccessKey);

        return $this->credentials;
    }

    /**
     * Get the region we're operating in.
     *
     * @return string
     */
    public function getRegion(): string
    {
        if (isset($this->region)) {
            return $this->region;
        }

        if ($this->option('region')) {
            $this->region = $this->option('region');
            return $this->region;
        }

        if (file_exists('.env.' . $this->stage)) {
            $env = $this->getDotenv()->parse(file_get_contents('.env.' . $this->stage));

            if (isset($env['AWS_DEFAULT_REGION'])) {
                $this->region = $env['AWS_DEFAULT_REGION'];
            }
        }

        if (!isset($this->region)) {
            $this->region = $this->ask('AWS_DEFAULT_REGION');
        }

        return $this->region;
    }

    /**
     * Get the SSM client.
     *
     * @return SsmClient
     */
    public function getClient(): SsmClient
    {
        if (!isset($this->client)) {
            $this->client = new SsmClient([
                'region'      => $this->getRegion(),
                'version'     => 'latest',
                'credentials' => $this->getCredentials(),
            ]);
        }

        return $this->client;
    }

    /**
     * Get the name of the app.
     *
     * @return string
     */
    public function getAppName(): string
    {
        if (isset($this->appName)) {
            return $this->appName;
        }

        $appName = $this->option('appName');

        if (!$appName) {
            $appName = $this->getEnvironmentVarsFromFile()->get('APP_NAME');
        }

        $this->appName = $appName ?? $this->ask('App name');

        return $this->appName;
    }

    /**
     * Get the environment variables as a collection.
     *
     * @return Collection<string, string>
     */
    private function getEnvironmentVarsFromFile(): Collection
    {
        $path = '.env.' . $this->stage;

        if (file_exists($path)) {
            return collect($this->getDotenv()->parse(file_get_contents($path)));
        }

        return collect();
    }

    /**
     * Get the environment variables from SSM parameter store.
     *
     * @param string|null $nextToken
     *
     * @return Collection<string, string>
     *
     * @throws Exception
     */
    public function getEnvironmentVarsFromRemote(string $nextToken = null): Collection
    {
        $arguments = ['Path' => '/' . $this->getAppName() . '/' . $this->stage];

        if ($nextToken) {
            $arguments['NextToken'] = $nextToken;
        }

        $retryIntervals = [3000, 6000, 9000];
        $attempt = 0;
        $maxAttempts = count($retryIntervals) + 1;

        while ($attempt < $maxAttempts) {
            try {
                $awsResult = $getClient()->getParametersByPath($arguments);
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

        $parameters = collect($awsResult['Parameters'])
            ->mapWithKeys(fn (array $parameter) => [$this->unQualifyKey($parameter['Name']) => $parameter['Value']]);

        if ($awsResult['NextToken']) {
            $this->getEnvironmentVarsFromRemote($awsResult['NextToken'])
                ->each(fn (string $val, string $key) => $parameters->put($key, $val));
        }

        return $parameters;
    }

    /**
     * Unify values that were split into multiple parameters due to size.
     *
     * @param Collection $keyValues
     *
     * @return Collection
     */
    public function unifySplitValues(Collection $keyValues): Collection
    {
        $unified = collect();

        $keyValues->each(function ($value, $key) use ($unified) {
            if (preg_match('/\.(part)\d+$/', $key) === 1) {
                $key = Str::beforeLast($key, '.part');

                if ($unified->has($key)) {
                    $value = $unified->get($key) . $value;
                }
            }

            $unified->put($key, $value);
        });

        return $unified;
    }
}
