<?php

namespace App\Classes;

use App\Repositories\CacheRepository;
use Carbon\Carbon;
use Exception;
use GuzzleHttp\Client;

class ExchangeRate
{
    /**
     * The object used for making requests to the currency
     * conversion API.
     *
     * @var RequestBuilder
     */
    private $requestBuilder;

    /**
     * The repository used for accessing the cache.
     *
     * @var CacheRepository
     */
    private $cacheRepository;

    /**
     * Whether of not the exchange rate should be cached
     * after being fetched from the API.
     *
     * @var bool
     */
    private $shouldCache = true;

    /**
     * Whether or not the cache should be busted and a new
     * value should be fetched from the API.
     *
     * @var bool
     */
    private $shouldBustCache = false;

    /**
     * ExchangeRate constructor.
     *
     * @param  RequestBuilder|null  $requestBuilder
     * @param  CacheRepository|null  $cacheRepository
     */
    public function __construct(RequestBuilder $requestBuilder = null, CacheRepository $cacheRepository = null)
    {
        $this->requestBuilder = $requestBuilder ?? new RequestBuilder(new Client());
        $this->cacheRepository = $cacheRepository ?? new CacheRepository();
    }

    /**
     * Return an array of available currencies that
     * can be used with this package.
     *
     * @param  array  $currencies
     *
     * @return array
     */
    public function currencies(array $currencies = []): array
    {
        $cacheKey = 'currencies';

        if ($cachedExchangeRate = $this->attemptToResolveFromCache($cacheKey)) {
            return $cachedExchangeRate;
        }

        $response = $this->requestBuilder->makeRequest('/latest', []);

        $currencies[] = $response['base'];

        foreach ($response['rates'] as $currency => $rate) {
            $currencies[] = $currency;
        }

        if ($this->shouldCache) {
            $this->cacheRepository->storeInCache($cacheKey, $currencies);
        }

        return $currencies;
    }

    /**
     * Return the exchange rates between the given
     * date range.
     *
     * @param  string  $from
     * @param  string|array  $to
     * @param  Carbon  $date
     * @param  Carbon  $endDate
     * @param  array  $conversions
     *
     * @return array
     * @throws Exception
     */
    public function exchangeRateBetweenDateRange(
        string $from,
        $to,
        Carbon $date,
        Carbon $endDate,
        array $conversions = []
    ): array {
        Validation::validateCurrencyCode($from);
        Validation::validateStartAndEndDates($date, $endDate);
        Validation::validateIsStringOrArray($to);

        is_string($to) ? Validation::validateCurrencyCode($to) : Validation::validateCurrencyCodes($to);

        $cacheKey = $this->cacheRepository->buildCacheKey($from, $to, $date, $endDate);

        if ($cachedExchangeRate = $this->attemptToResolveFromCache($cacheKey)) {
            return $cachedExchangeRate;
        }

        $conversions = $from === $to
            ? $this->exchangeRateDateRangeResultWithSameCurrency($date, $endDate, $conversions)
            : $this->makeRequestForExchangeRates($from, $to, $date, $endDate);

        if ($this->shouldCache) {
            $this->cacheRepository->storeInCache($cacheKey, $conversions);
        }

        return $conversions;
    }

    /**
     * Make a request to the Exchange Rates API to get the
     * exchange rates between a date range. If only one
     * currency is being used, we flatten the array
     * to remove currency symbol before returning
     * it.
     *
     * @param  string  $from
     * @param  string|array  $to
     * @param  Carbon  $date
     * @param  Carbon  $endDate
     * @return array
     */
    private function makeRequestForExchangeRates(string $from, $to, Carbon $date, Carbon $endDate): array
    {
        $symbols = is_string($to) ? $to : implode(',', $to);

        $result = $this->requestBuilder->makeRequest('/history', [
            'base'     => $from,
            'start_at' => $date->format('Y-m-d'),
            'end_at'   => $endDate->format('Y-m-d'),
            'symbols'  => $symbols,
        ]);

        $conversions = $result['rates'];

        if (is_string($to)) {
            foreach ($conversions as $date => $rate) {
                $conversions[$date] = $rate[$to];
            }
        }

        $sorted = array();
        foreach ($this->insertionSort(array_keys($conversions)) as $key) {
            $sorted[$key] = $conversions[$key];
        }

        return $sorted;
    }

    /**
     * If the 'from' and 'to' currencies are the same, we
     * don't need to make a request to the API. Instead,
     * we can build the response ourselves to improve
     * the performance.
     *
     * @param  Carbon  $startDate
     * @param  Carbon  $endDate
     * @param  array  $conversions
     * @return array
     */
    private function exchangeRateDateRangeResultWithSameCurrency(
        Carbon $startDate,
        Carbon $endDate,
        array $conversions = []
    ): array {
        for ($date = clone $startDate; $date->lte($endDate); $date->addDay()) {
            if ($date->isWeekday()) {
                $conversions[$date->format('Y-m-d')] = 1.0;
            }
        }

        return $conversions;
    }

    /**
     * Determine whether if the exchange rate should be
     * cached after it is fetched from the API.
     *
     * @param  bool  $shouldCache
     * @return $this
     */
    public function shouldCache(bool $shouldCache = true): self
    {
        $this->shouldCache = $shouldCache;

        return $this;
    }

    /**
     * Determine whether if the cached result (if it
     * exists) should be deleted. This will force
     * a new exchange rate to be fetched from
     * the API.
     *
     * @param  bool  $bustCache
     * @return $this
     */
    public function shouldBustCache(bool $bustCache = true): self
    {
        $this->shouldBustCache = $bustCache;

        return $this;
    }

    /**
     * Attempt to fetch an item (more than likely an
     * exchange rate) from the cache. If it exists,
     * return it. If it has been specified, bust
     * the cache.
     *
     * @param  string  $cacheKey
     * @return mixed
     */
    private function attemptToResolveFromCache(string $cacheKey)
    {
        if ($this->shouldBustCache) {
            $this->cacheRepository->forget($cacheKey);
            $this->shouldBustCache = false;
        } elseif ($cachedValue = $this->cacheRepository->getFromCache($cacheKey)) {
            return $cachedValue;
        }
    }

    /**
     * Sort by method isertion sort
     *
     * @param $arr
     * @return array
     */
    private function insertionSort($arr): array
    {
        for ($i = 0; $i < count($arr); $i++) {
            $j = $i;
            while ($j > 0 && $arr[$j] < $arr[$j-1]) {
                $tmp = $arr[$j-1];
                $arr[$j-1] = $arr[$j];
                $arr[$j] = $tmp;
                $j--;
            }
        }

        return $arr;
    }
}
