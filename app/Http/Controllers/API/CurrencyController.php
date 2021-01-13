<?php

namespace App\Http\Controllers\API;

use App\Classes\Currency;
use App\Classes\ExchangeRate;
use App\Http\Controllers\Controller;
use App\Http\Requests\CurrencyRequest;
use Carbon\Carbon;

class CurrencyController extends Controller
{
    public function index(CurrencyRequest $currencyRequest)
    {
        $exchangeRates = new ExchangeRate();
        return $exchangeRates->exchangeRateBetweenDateRange($currencyRequest->report_by, $currencyRequest->currency, Carbon::parse($currencyRequest->start_date), Carbon::parse($currencyRequest->end_date));
    }

    public function currencies()
    {
        $currencies = new Currency();
        return $currencies->allowableCurrencies;
    }
}
