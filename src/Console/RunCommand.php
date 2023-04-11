<?php

namespace Tideways\Shopware6Benchmarking\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Tideways\Shopware6Benchmarking\Configuration;
use Tideways\Shopware6Benchmarking\ExecutionMode;
use Tideways\Shopware6Benchmarking\GlobalConfiguration;
use Tideways\Shopware6Benchmarking\Services\SitemapFixturesDownloader;

class RunCommand extends Command implements SignalableCommandInterface
{
    protected static $defaultName = 'run';
    protected static $defaultDescription = 'Run the Locust Loadtest for benchmarking Shopware based on configuration.';
    private ?Process $locustProcess = null;

    protected function configure(): void
    {
        $this->addOption(
            'config',
            'c',
            InputOption::VALUE_REQUIRED,
            'Scenario configuration file',
            getcwd() . '/default.json'
        );

        $this->addOption(
            'duration',
            'd',
            InputOption::VALUE_REQUIRED,
            'Custom duration that overwrite the one defined in scenario configuration file.',
            null
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $config = Configuration::fromFile($input->getOption('config'));
        $workingDir = __DIR__ . '/../../';

        $output->writeln('Update listings and products from sitemap.xml');

        $sitemapDownloader = new SitemapFixturesDownloader();
        $sitemapDownloader->download($config);

        if ($sitemapDownloader->isCachedSitemapEmpty($config)) {
            throw new \RuntimeException("The category and product urls from the sitemap are empty. Was the sitemap generated on Shopware side?");
        }

        $globalConfiguration = GlobalConfiguration::createFromGlobalDirectory();
        $command = $this->getLocustCommandBasedOnExecutionMode($globalConfiguration->executionMode, $workingDir);
        $duration = $input->getOption('duration') ?: $config->scenario->duration;

        $this->locustProcess = new Process(array_merge($command, [
            '--headless',
            '--host=' . $config->scenario->host,
            '-u',
            $config->scenario->concurrentThreads,
            '-r',
            $config->scenario->userSpawnRate,
            '-t',
            $duration,
            '--autostart',
            '--autoquit',
            5,
            '--csv=' . $config->getName(),
            '--csv-full-history',
            '--print-stats',
        ]));
        $this->locustProcess->setEnv([
            'SWBENCH_NAME' => $config->getName(),
            'SWBENCH_DATA_DIR' => $config->getDataDirectory(),
            'LOCUST_TIDEWAYS_APIKEY' => $config->tideways->apiKey,
            'LOCUST_TIDEWAYS_TRACE_RATE' => $config->tideways->traceSampleRate,
            'LOCUST_GUEST_RATIO' => $config->scenario->browsingGuestRatio,
            'LOCUST_ACCOUNTS_NEW_RATIO' => $config->scenario->browsingAccountsNewRatio,
            'LOCUST_CHECKOUT_GUEST_RATIO' => $config->scenario->checkoutGuestRatio,
            'LOCUST_CHECKOUT_ACCOUNTS_NEW_RATIO' => $config->scenario->checkoutAccountsNewRatio,
            'LOCUST_FILTERER_MIN_FILTERS' => $config->scenario->filtererMinFilters,
            'LOCUST_FILTERER_MAX_FILTERS' => $config->scenario->filtererMaxFilters,
            'LOCUST_FILTERER_VISIT_PRODUCT_RATIO' => $config->scenario->filtererVisitProductRatio,
            'LOCUST_MAX_PAGINATION_SURFING' => $config->scenario->maxPaginationSurfing,
            'SWBENCH_PURCHASER_WEIGHT' => $config->scenario->conversionRatio,
            'SWBENCH_CART_ABANDONMENT_WEIGHT' => $config->scenario->cartAbandonmentRatio,
            'SWBENCH_BROWSING_USER_WEIGHT' => 100 - $config->scenario->conversionRatio - $config->scenario->cartAbandonmentRatio,
            'TZ' => 'UTC', // Set timezone to make sure we know how to work with dates later in reporting.
        ]);
        $this->locustProcess->setWorkingDirectory($workingDir);
        $this->locustProcess->setTimeout(null);

        $output->writeln("Running benchmark for " . $duration . "...");

        $this->locustProcess->run(function ($type, $buffer) use ($output) {
            $output->write($buffer);
        });

        $endTime = microtime(true);
        $locustDurationSeconds = $endTime - $this->locustProcess->getStartTime();

        $output->writeln(sprintf("Complete after %.0f seconds.", $locustDurationSeconds));

        $resultFiles = ["_exceptions.csv", "_failures.csv", "_stats.csv", "_stats_history.csv", "_requests.csv"];
        foreach ($resultFiles as $resultFile) {
            $fileName = $config->getName() . $resultFile;
            @copy($workingDir . '/' . $fileName, $config->getDataDirectory() . '/'. $fileName);
        }

        return Command::SUCCESS;
    }

    public function getSubscribedSignals(): array
    {
        return [SIGINT];
    }

    public function handleSignal(int $signal): void
    {
        if ($signal === SIGINT && $this->locustProcess && $this->locustProcess->isRunning()) {
            $this->locustProcess->stop();
        }
    }

    private function getLocustCommandBasedOnExecutionMode(ExecutionMode $executionMode, string $workingDir) : array
    {
        return match ($executionMode) {
            ExecutionMode::DOCKER => [
                    'docker-compose',
                    'run',
                    'master',
                    '-f',
                    '/mnt/locust/locustfile.py',
                ],
            ExecutionMode::LOCAL => [
                    'locust',
                    '-f',
                    $workingDir . '/locustfile.py',
                ],
        };
    }
}
