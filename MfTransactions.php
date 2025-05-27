<?php

namespace Apps\Fintech\Packages\Mf\Transactions;

use Apps\Fintech\Packages\Mf\Investments\MfInvestments;
use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Portfoliostimeline\MfPortfoliostimeline;
use Apps\Fintech\Packages\Mf\Schemes\MfSchemes;
use Apps\Fintech\Packages\Mf\Transactions\Model\AppsFintechMfTransactions;
use System\Base\BasePackage;

class MfTransactions extends BasePackage
{
    protected $modelToUse = AppsFintechMfTransactions::class;

    protected $packageName = 'mftransactions';

    public $mftransactions;

    protected $scheme;

    public function addMfTransaction($data)
    {
        $portfoliosPackage = $this->usepackage(MfPortfolios::class);

        $portfoliosTimelinePackage = $this->usepackage(MfPortfoliostimeline::class);

        $investmentsPackage = $this->usepackage(MfInvestments::class);

        $schemesPackage = $this->usepackage(MfSchemes::class);

        $data['account_id'] = $this->access->auth->account()['id'];

        $portfolio = $portfoliosPackage->getPortfolioById((int) $data['portfolio_id']);

        if ($data['type'] === 'buy') {
            $data['status'] = 'open';
            $data['user_id'] = $portfolio['user_id'];
            $data['available_amount'] = $data['amount'];
            if ($this->calculateTransactionUnitsAndValues($data)) {
                if ($this->add($data)) {
                    if (!isset($data['clone']) && !isset($data['via_strategies'])) {
                        $portfoliosPackage->recalculatePortfolio($data, true);

                        $portfoliosTimelinePackage->forceRecalculateTimeline($portfolio, $data['date']);
                    }

                    $this->addResponse('Ok', 0);

                    return true;
                }

                $this->addResponse('Error adding transaction', 1);

                return false;
            }

            $this->addResponse('Scheme/Scheme navs information not available!', 1);

            return false;
        } else if ($data['type'] === 'sell') {
            $this->scheme = $schemesPackage->getSchemeFromAmfiCodeOrSchemeId($data);

            if (!$this->scheme) {
                $this->addResponse('Scheme with id/amfi code not found!', 1);

                return false;
            }

            $canSellTransactions = [];

            $portfolio = $portfoliosPackage->getPortfolioById((int) $data['portfolio_id']);

            if ($portfolio &&
                $portfolio['transactions'] &&
                count($portfolio['transactions']) > 0
            ) {
                $portfolio['transactions'] = msort(array: $portfolio['transactions'], key: 'date', preserveKey: true);

                foreach ($portfolio['transactions'] as $transaction) {
                    if ($transaction['amfi_code'] != $this->scheme['amfi_code']) {
                        continue;
                    }

                    if ($transaction['status'] === 'close') {
                        continue;
                    }

                    if (\Carbon\Carbon::parse($transaction['date'])->gt(\Carbon\Carbon::parse($data['date']))) {
                        continue;
                    }

                    if ($transaction['type'] === 'buy') {
                        $transaction['available_units'] = $transaction['units_bought'];

                        if ($transaction['units_sold'] > 0) {
                            $transaction['available_units'] = $transaction['units_bought'] - $transaction['units_sold'];
                        }

                        if ($transaction['available_units'] == 0) {
                            continue;
                        }

                        if (isset($canSellTransactions[$transaction['amfi_code']])) {
                            $canSellTransactions[$transaction['amfi_code']]['available_units'] += $transaction['available_units'];
                        } else {
                            $canSellTransactions[$transaction['amfi_code']]['available_units'] = $transaction['available_units'];
                        }
                    }

                    $canSellTransactions[$transaction['amfi_code']]['available_units'] =
                        numberFormatPrecision($canSellTransactions[$transaction['amfi_code']]['available_units'], 2);

                    $canSellTransactions[$transaction['amfi_code']]['returns'] = $this->calculateTransactionReturns($transaction, false, null, $data);

                    $canSellTransactions[$transaction['amfi_code']]['available_amount'] =
                        numberFormatPrecision(
                            $canSellTransactions[$transaction['amfi_code']]['available_units'] * $canSellTransactions[$transaction['amfi_code']]['returns'][$data['date']]['nav'],
                            2
                        );
                }

                if (isset($canSellTransactions[$this->scheme['amfi_code']])) {
                    if (isset($data['amount'])) {
                        if ((float) $data['amount'] > $canSellTransactions[$this->scheme['amfi_code']]['available_amount']) {
                            if (!isset($data['sell_all']) ||
                                (isset($data['sell_all']) && $data['sell_all'] == 'false')
                            ) {
                                $this->addResponse('Amount exceeds from available amount', 1);

                                return false;
                            }
                        }

                        //Convert from $data['amount'] to $data['units']
                        $data['units'] = numberFormatPrecision($data['amount'] / $this->scheme['navs']['navs'][$data['date']]['nav'], 3);
                    } else if (isset($data['units'])) {
                        if ((float) $data['units'] > $canSellTransactions[$this->scheme['amfi_code']]['available_units']) {
                            if (!isset($data['sell_all']) ||
                                (isset($data['sell_all']) && $data['sell_all'] == 'false')
                            ) {
                                $this->addResponse('Units exceeds from available units', 1);

                                return false;
                            }
                        }

                        //Convert from $data['units'] to $data['amount']
                        $data['amount'] = $data['units'] * $this->scheme['navs']['navs'][$data['date']]['nav'];
                    }

                    $data['units_bought'] = 0;
                    $data['units_sold'] = $data['units'];
                    $data['latest_value'] = '-';
                    $data['latest_value_date'] = $data['date'];
                    $data['xirr'] = '-';
                    $data['status'] = 'close';
                    $data['user_id'] = $portfolio['user_id'];
                    $data['amfi_code'] = $this->scheme['amfi_code'];
                    $data['amc_id'] = $this->scheme['amc_id'];
                    $data['date_closed'] = $data['date'];

                    if ($this->add($data)) {
                        $data = array_merge($data, $this->packagesData->last);

                        $buyTransactions = [];
                        $sellTransactions = [];
                        $unitsToProcess = (float) $data['units'];

                        if ($unitsToProcess > 0) {
                            foreach ($portfolio['transactions'] as $transaction) {
                                if ($unitsToProcess <= 0) {
                                    break;
                                }

                                if ($transaction['status'] === 'close' || $transaction['type'] === 'sell') {
                                    continue;
                                }

                                if ($transaction['amfi_code'] != $this->scheme['amfi_code']) {
                                    continue;
                                }

                                if (\Carbon\Carbon::parse($transaction['date'])->gt(\Carbon\Carbon::parse($data['date']))) {
                                    continue;
                                }

                                $transaction['returns'] = $this->calculateTransactionReturns($transaction, false, null, $data);

                                if ($transaction['type'] === 'buy' && $transaction['status'] === 'open') {
                                    $availableUnits = $transaction['units_bought'] - $transaction['units_sold'];

                                    if (isset($data['sell_all']) && $data['sell_all'] == 'true') {
                                        $buyTransactions[$transaction['id']]['id'] = $transaction['id'];
                                        $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                        $buyTransactions[$transaction['id']]['units'] = numberFormatPrecision($availableUnits, 3);
                                        $buyTransactions[$transaction['id']]['amount'] = numberFormatPrecision($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);

                                        $sellTransactions[$data['id']]['id'] = $data['id'];
                                        $sellTransactions[$data['id']]['date'] = $data['date'];
                                        $sellTransactions[$data['id']]['units'] = numberFormatPrecision($availableUnits, 3);
                                        $sellTransactions[$data['id']]['amount'] = numberFormatPrecision($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);

                                        $transaction['units_sold'] = $transaction['units_bought'];

                                        $transaction['status'] = 'close';
                                        $transaction['date_closed'] = $data['date'];
                                        $transaction['available_amount'] = 0;
                                    } else {
                                        if ($availableUnits <= $unitsToProcess) {
                                            $transaction['units_sold'] = $transaction['units_bought'];

                                            $buyTransactions[$transaction['id']]['id'] = $transaction['id'];
                                            $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                            $buyTransactions[$transaction['id']]['units'] = numberFormatPrecision($availableUnits, 3);
                                            $buyTransactions[$transaction['id']]['amount'] = numberFormatPrecision($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);

                                            $sellTransactions[$data['id']]['id'] = $data['id'];
                                            $sellTransactions[$data['id']]['date'] = $data['date'];
                                            $sellTransactions[$data['id']]['units'] = numberFormatPrecision($availableUnits, 3);
                                            $sellTransactions[$data['id']]['amount'] = numberFormatPrecision($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);

                                            $transaction['status'] = 'close';
                                            $transaction['date_closed'] = $data['date'];
                                        } else if ($availableUnits > $unitsToProcess) {
                                            $transaction['units_sold'] = $transaction['units_sold'] + numberFormatPrecision($unitsToProcess, 3);

                                            $buyTransactions[$transaction['id']]['id'] = $transaction['id'];
                                            $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                            $buyTransactions[$transaction['id']]['units'] = numberFormatPrecision($unitsToProcess, 3);
                                            $buyTransactions[$transaction['id']]['amount'] = numberFormatPrecision($unitsToProcess * $transaction['returns'][$data['date']]['nav'], 2);

                                            $sellTransactions[$data['id']]['id'] = $data['id'];
                                            $sellTransactions[$data['id']]['date'] = $data['date'];
                                            $sellTransactions[$data['id']]['units'] = numberFormatPrecision($unitsToProcess, 3);
                                            $sellTransactions[$data['id']]['amount'] = numberFormatPrecision($unitsToProcess * $transaction['returns'][$data['date']]['nav'], 2);
                                        }

                                        $unitsToProcess = $unitsToProcess - $availableUnits;
                                    }

                                    if (isset($transaction['transactions'])) {
                                        if (is_string($transaction['transactions'])) {
                                            $transaction['transactions'] = $this->helper->decode($transaction['transactions'], true);
                                        }

                                        $transaction['transactions'] = array_replace($transaction['transactions'], $sellTransactions);
                                    } else {
                                        $transaction['transactions'] = $sellTransactions;
                                    }

                                    $this->update($transaction);
                                }
                            }
                        }

                        $data['transactions'] = $buyTransactions;

                        if ($this->update($data)) {
                            if (!isset($data['clone']) && !isset($data['via_strategies'])) {
                                $portfolio['investments'][$data['amfi_code']]['units'] =
                                    $portfolio['investments'][$data['amfi_code']]['units'] - $data['units'];

                                if ($portfolio['investments'][$data['amfi_code']]['units'] == 0) {
                                    $portfolio['investments'][$data['amfi_code']]['status'] = 'close';
                                }

                                $investmentsPackage->update($portfolio['investments'][$data['amfi_code']]);

                                $portfoliosPackage->recalculatePortfolio($data, true);

                                $portfoliosTimelinePackage->forceRecalculateTimeline($portfolio, $data['date']);
                            }

                            $this->addResponse('Ok', 0);

                            return true;
                        }
                    }
                }

                $this->addResponse('Error: There are no buy transactions with this Scheme.', 1);

                return false;
            }

            $this->addResponse('Error: There are currently no transactions for this portfolio', 1);

            return false;
        }

        $this->addResponse('Added transaction');

        return true;
    }

    public function updateMfTransaction($data)
    {
        $portfoliosPackage = $this->usepackage(MfPortfolios::class);

        $portfoliosTimelinePackage = $this->usepackage(MfPortfoliostimeline::class);

        $mfTransaction = $this->getById((int) $data['id']);

        $portfolio = $portfoliosPackage->getPortfolioById((int) $mfTransaction['portfolio_id']);

        if ($mfTransaction) {
            if ($mfTransaction['type'] === 'buy' &&
                $mfTransaction['status'] === 'open' &&
                $mfTransaction['units_sold'] === 0
            ) {
                $mfTransactionOriginalDate = $mfTransaction['date'];
                $mfTransaction['date'] = $data['date'];
                $mfTransaction['amount'] = $data['amount'];
                $mfTransaction['scheme_id'] = $data['scheme_id'];
                $mfTransaction['amc_transaction_id'] = $data['amc_transaction_id'];
                $mfTransaction['details'] = $data['details'];

                if ($this->calculateTransactionUnitsAndValues($mfTransaction, true)) {
                    if ($this->update($mfTransaction)) {
                        $portfoliosPackage->recalculatePortfolio($mfTransaction, true);

                        $transactionDate = $mfTransactionOriginalDate;

                        if (\Carbon\Carbon::parse($data['date'])->lt(\Carbon\Carbon::parse($mfTransactionOriginalDate))) {
                            $transactionDate = $data['date'];
                        }

                        $portfoliosTimelinePackage->forceRecalculateTimeline($portfolio, $transactionDate);

                        $this->addResponse('Ok', 0);

                        return true;
                    }

                    $this->addResponse('Error adding transaction', 1);

                    return false;
                }

                $this->addResponse('Error getting transaction units', 1);

                return false;
            } else {
                $this->addResponse('Transaction is either closed or has units already sold. Cannot update!', 1);

                return false;
            }
        } else {
            $this->addResponse('Transaction is not found!', 1);

            return false;
        }

        $this->addResponse('Updated transaction');
    }

    public function removeMfTransaction($data)
    {
        $portfoliosPackage = $this->usepackage(MfPortfolios::class);

        $portfoliosTimelinePackage = $this->usepackage(MfPortfoliostimeline::class);

        $investmentsPackage = $this->usepackage(MfInvestments::class);

        $mfTransaction = $this->getById($data['id']);

        if (!$mfTransaction) {
            $this->addResponse('Id not found', 1);

            return false;
        }

        $portfolio = $portfoliosPackage->getPortfolioById((int) $mfTransaction['portfolio_id']);

        if ($mfTransaction) {
            if ($mfTransaction['type'] === 'buy') {
                if ($mfTransaction['status'] !== 'open' || $mfTransaction['units_sold'] > 0) {
                    $this->addResponse('Transaction is being used by other transactions. Cannot remove', 1);

                    return false;
                }

                if ($this->remove($mfTransaction['id'])) {
                    $portfolio['investments'][$mfTransaction['amfi_code']]['units'] =
                        $portfolio['investments'][$mfTransaction['amfi_code']]['units'] - $mfTransaction['units_bought'];

                    if ($portfolio['investments'][$mfTransaction['amfi_code']]['units'] == 0) {
                        $portfolio['investments'][$mfTransaction['amfi_code']]['status'] = 'close';
                    }

                    $investmentsPackage->update($portfolio['investments'][$mfTransaction['amfi_code']]);

                    unset($portfolio['transactions'][$mfTransaction['id']]);

                    $portfoliosPackage->update($portfolio);

                    $portfoliosPackage->recalculatePortfolio($mfTransaction);

                    $portfoliosTimelinePackage->forceRecalculateTimeline($portfolio, $mfTransaction['date']);

                    $this->addResponse('Transaction removed');

                    return true;
                }
            } else if ($mfTransaction['type'] === 'sell') {
                if ($mfTransaction['transactions']) {
                    if (is_string($mfTransaction['transactions'])) {
                        $mfTransaction['transactions'] = $this->helper->decode($mfTransaction['transactions']);
                    }

                    if (count($mfTransaction['transactions']) > 0) {
                        foreach ($mfTransaction['transactions'] as $correspondingTransactionArr) {
                            $correspondingTransaction = $this->getById((int) $correspondingTransactionArr['id']);

                            if ($correspondingTransaction) {
                                if ($correspondingTransaction['type'] === 'buy') {
                                    $correspondingTransaction['status'] = 'open';
                                    $correspondingTransaction['date_closed'] = null;

                                    $correspondingTransaction['units_sold'] = numberFormatPrecision($correspondingTransaction['units_sold'] - $correspondingTransactionArr['units'], 3);

                                    unset($correspondingTransaction['transactions'][$mfTransaction['id']]);
                                }
                            }

                            $this->update($correspondingTransaction);
                        }
                    }

                    if (isset($portfolio['investments'][$mfTransaction['amfi_code']]) &&
                        $portfolio['investments'][$mfTransaction['amfi_code']]['status'] === 'close'
                    ) {
                        $portfolio['investments'][$mfTransaction['amfi_code']]['status'] = 'open';

                        $investmentsPackage->update($portfolio['investments'][$mfTransaction['amfi_code']]);
                    }
                }

                if ($this->remove($mfTransaction['id'])) {
                    $portfoliosPackage->update($portfolio);

                    $portfoliosPackage->recalculatePortfolio($mfTransaction, true);

                    $portfoliosTimelinePackage->forceRecalculateTimeline($portfolio, $data['date']);

                    $this->addResponse('Transaction removed');

                    return true;
                }
            }

            $this->addResponse('Unknown Transaction type. Contact developer!', 1);

            return false;
        }

        $this->addResponse('Error, contact developer', 1);
    }

    public function calculateTransactionUnitsAndValues(&$transaction, $update = false, $timelineDate = null, $sellTransactionData = null)
    {
        $schemesPackage = $this->usepackage(MfSchemes::class);

        $this->scheme = $schemesPackage->getSchemeFromAmfiCodeOrSchemeId($transaction);

        if ($this->scheme) {
            $transaction['amfi_code'] = $this->scheme['amfi_code'];

            if ($this->scheme['navs'] && isset($this->scheme['navs']['navs'][$transaction['date']])) {
                $units = (float) $transaction['amount'] / $this->scheme['navs']['navs'][$transaction['date']]['nav'];

                if ($transaction['type'] === 'buy') {
                    $transaction['units_bought'] = numberFormatPrecision($units, 3);
                }

                if (!isset($transaction['units_sold'])) {
                    $transaction['units_sold'] = 0;
                }

                $transaction['returns'] = $this->calculateTransactionReturns($transaction, $update, $timelineDate, $sellTransactionData);

                //We calculate the total number of units for latest_value
                if ($timelineDate) {
                    $lastTransactionNav = $transaction['returns'][$timelineDate];
                } else {
                    $lastTransactionNav = $this->helper->last($transaction['returns']);
                }

                if ($lastTransactionNav) {
                    $transaction['latest_value_date'] = $lastTransactionNav['date'];
                    $transaction['latest_value'] = $lastTransactionNav['total_return'];
                } else {
                    $transaction['latest_value_date'] = 0;
                    $transaction['latest_value'] = 0;
                }

                return $transaction;
            }
        }

        return false;
    }

    public function calculateTransactionReturns($transaction, $update = false, $timelineDate = null, $sellTransactionData = null)
    {
        if (!$timelineDate && $transaction['status'] === 'close') {
            return $transaction['returns'];
        }

        if (!isset($transaction['returns']) || $update || $timelineDate) {
            $transaction['returns'] = [];
        }

        if ($transaction['type'] === 'buy') {
            if ($timelineDate) {
                $units = numberFormatPrecision($transaction['units_bought'], 3);

                //Check this for timeline!!
                if ($transaction['transactions'] && count($transaction['transactions']) > 0) {
                    foreach ($transaction['transactions'] as $soldTransaction) {
                        if ($timelineDate === $soldTransaction['date']) {
                            $units = numberFormatPrecision($transaction['units_bought'] - $soldTransaction['units'], 3);

                            break;
                        }
                    }
                }
            } else {
                $units = numberFormatPrecision($transaction['units_bought'] - $transaction['units_sold'], 3);
            }
        } else {
            $units = $transaction['units_sold'];
        }

        if ($units < 0) {
            $units = 0;
        }

        $navs = $this->scheme['navs']['navs'];
        $navsKeys = array_keys($navs);
        $navsToProcess = [];

        if ($timelineDate) {
            if (!isset($navs[$timelineDate])) {
                $timelineDate = $this->helper->last($navs)['date'];
            }

            $navsToProcess[$transaction['date']] = $navs[$transaction['date']];

            $navsToProcess[$timelineDate] = $navs[$timelineDate];
        } else {
            $transactionDateKey = array_search($transaction['date'], $navsKeys);

            $navs = array_slice($navs, $transactionDateKey);

            $navsToProcess[$this->helper->firstKey($navs)] = $this->helper->first($navs);

            if ($sellTransactionData && isset($sellTransactionData['date']) && isset($navs[$sellTransactionData['date']])) {
                $navsToProcess[$sellTransactionData['date']] = $navs[$sellTransactionData['date']];
            }

            $navsToProcess[$this->helper->lastKey($navs)] = $this->helper->last($navs);
        }

        $transaction['returns'] = [];

        foreach ($navsToProcess as $nav) {
            $transaction['returns'][$nav['date']] = [];
            $transaction['returns'][$nav['date']]['date'] = $nav['date'];
            $transaction['returns'][$nav['date']]['timestamp'] = $nav['timestamp'];
            $transaction['returns'][$nav['date']]['nav'] = $nav['nav'];
            $transaction['returns'][$nav['date']]['units'] = $units;
            $transaction['returns'][$nav['date']]['total_return'] = numberFormatPrecision($nav['nav'] * $units, 2);//Units bought - units sold

            if (isset($transaction['date_closed']) &&
                ($nav['date'] === $transaction['date_closed'])
            ) {
                break;
            }
        }

        $transaction['returns'] = msort(array: $transaction['returns'], key: 'date', preserveKey: true);

        return $transaction['returns'];
    }
}