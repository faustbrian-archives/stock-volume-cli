<?php

namespace App\Commands;

use Carbon\Carbon;
use Scheb\YahooFinanceApi\ApiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use DoubleMadOutliers\DoubleMadOutliers;
use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Scheb\YahooFinanceApi\ApiClientFactory;

class InspiringCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'inspiring {days=3} {deviation=10}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Display an inspiring quote';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $tickers = Cache::remember('tickers', Carbon::now()->addHour(), fn () => $this->getTickers());

        $progressBar = $this->output->createProgressBar(count($tickers));
        $progressBar->start();

        foreach($tickers as $ticker) {
            try {
                // $this->info("[{$ticker}] Fetching history...");
                $history = $this->getHistory($ticker);

                // $this->info("[{$ticker}] Computing outliers...");
                $outliers = $this->getOutliers($history);

                if (empty($outliers)) {
                    // $this->info("[{$ticker}] Failed to determine any outliers with the given parameters.");
                } else {
                    foreach ($outliers as $date => $volume) {
                        if (Carbon::parse($date)->diffInDays() <= $this->argument('days')) {
                            $this->line("[{$ticker}] {$date} - {$volume}");
                        }
                    }
                }
            } catch (\Throwable $th) {
                $this->error("[{$ticker}] Failed to fetch the history or computed outliers.");
            }

            $progressBar->advance();

            $this->line(PHP_EOL);
        }

        $progressBar->finish();
    }

    private function getTickers() {
        $result = [];

        foreach (['SymbolDirectory/nasdaqlisted.txt', 'SymbolDirectory/otherlisted.txt'] as $file) {
            $tickers = Storage::disk('nasdaq')->get($file);
            $tickers = explode("\n", $tickers);

            foreach ($tickers as $ticker) {
                $name = explode('|', $ticker)[0];

                if (empty($name) || $name === 'Symbol' || $name === 'ACT Symbol') {
                    continue;
                }

                $result[] = $name;
            }
        }

        return $result;
    }

    private function getHistory(string $ticker) {
        $client = ApiClientFactory::createApiClient();

        $days = $client->getHistoricalData(
            $ticker,
            ApiClient::INTERVAL_1_DAY,
            new \DateTime('-3 months'),
            new \DateTime('today')
        );

        return collect($days)->mapWithKeys(function ($day) {
            return [Carbon::parse($day->getdate())->toDateString() => $day->getVolume()];
        })->toArray();
    }

    private function getOutliers(array $history) {
        if (empty($history)) {
            return;
        }

        return (new DoubleMadOutliers($history, $this->argument('deviation')))->findOutliers();
    }
}
