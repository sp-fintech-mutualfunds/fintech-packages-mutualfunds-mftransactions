<?php

namespace Apps\Fintech\Packages\Mf\Transactions;

use Apps\Fintech\Packages\Accounts\Balances\AccountsBalances;
use Apps\Fintech\Packages\Mf\Categories\MfCategories;
use Apps\Fintech\Packages\Mf\Investments\MfInvestments;
use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Portfolios\Model\AppsFintechMfPortfolios;
use Apps\Fintech\Packages\Mf\Schemes\MfSchemes;
use Apps\Fintech\Packages\Mf\Transactions\Model\AppsFintechMfTransactions;
use System\Base\BasePackage;

class MfTransactions extends BasePackage
{
    protected $modelToUse = AppsFintechMfTransactions::class;

    protected $packageName = 'mftransactions';

    public $mftransactions;

    protected $today;

    protected $buyTotal = 0;

    protected $sellTotal = 0;

    protected $totalValue = 0;

    protected $sellDetails = [];

    protected $portfolioXirrDatesArr = [];

    protected $portfolioXirrAmountsArr = [];

    protected $portfoliosPackage;

    protected $portfolio;

    protected $investmentsPackage;

    protected $investments = [];

    protected $schemesPackage;

    protected $categoriesPackage;

    protected $scheme;

    public function init()
    {
        $this->today = (\Carbon\Carbon::now(new \DateTimeZone('Asia/Kolkata')))->toDateString();

        $this->portfoliosPackage = $this->usepackage(MfPortfolios::class);

        $this->investmentsPackage = $this->usepackage(MfInvestments::class);

        $this->schemesPackage = $this->usepackage(MfSchemes::class);

        $this->categoriesPackage = $this->usepackage(MfCategories::class);

        parent::init();

        return $this;
    }

    public function addMfTransaction($data)
    {
        $data['account_id'] = $this->access->auth->account()['id'];

        $this->portfolio = $this->portfoliosPackage->getPortfolioById((int) $data['portfolio_id']);

        if ($data['type'] === 'buy') {
            $data['status'] = 'open';

            if ($this->calculateTransactionUnitsAndValues($data)) {
                if ($this->add($data)) {
                    $this->recalculatePortfolio($data, true);

                    $this->portfolio['recalculate_timeline'] = true;
                    $this->portfoliosPackage->update($this->portfolio);

                    $this->addResponse('Ok', 0);

                    return true;
                }

                $this->addResponse('Error adding transaction', 1);

                return false;
            }

            $this->addResponse('Scheme/Scheme navs information not available!', 1);

            return false;
        } else if ($data['type'] === 'sell') {
            // $this->recalculatePortfolio($data, true);

            // $schemesPackage = $this->usepackage(MfSchemes::class);

            // if (isset($data['scheme_id']) && $data['scheme_id'] !== '') {
            //     $scheme = $this->schemesPackage->getSchemeById((int) $data['scheme_id']);

            //     if (!$scheme) {
            //         $this->addResponse('Scheme with scheme id not found!', 1);

            //         return false;
            //     }
            // } else if (isset($data['amfi_code']) && $data['amfi_code'] !== '') {
            //     if ($this->config->databasetype === 'db') {
            //         $conditions =
            //             [
            //                 'conditions'    => 'amfi_code = :amfi_code:',
            //                 'bind'          =>
            //                     [
            //                         'amfi_code'       => (int) $data['amfi_code'],
            //                     ]
            //             ];
            //     } else {
            //         $conditions =
            //             [
            //                 'conditions'    => ['amfi_code', '=', (int) $data['amfi_code']]
            //             ];
            //     }

            //     $scheme = $this->schemesPackage->getByParams($conditions);

            //     if ($scheme && isset($scheme[0])) {
            //         $scheme = $this->schemesPackage->getSchemeById((int) $scheme[0]['id']);
            //     }

            //     if (!$scheme) {
            //         $this->addResponse('Scheme with amfi code not found!', 1);

            //         return false;
            //     }
            // }
            $scheme = $this->getSchemeFromAmfiCode($data);

            if (!$scheme) {
                $this->addResponse('Scheme with id/amfi code not found!', 1);

                return false;
            }

            $canSellTransactions = [];

            $this->portfolio = $this->portfoliosPackage->getPortfolioById((int) $data['portfolio_id']);

            if ($this->portfolio &&
                $this->portfolio['transactions'] &&
                count($this->portfolio['transactions']) > 0
            ) {
                $this->portfolio['transactions'] = msort(array: $this->portfolio['transactions'], key: 'date', preserveKey: true);

                foreach ($this->portfolio['transactions'] as $transaction) {
                    if ($transaction['amfi_code'] != $data['amfi_code']) {
                        continue;
                    }

                    if ($transaction['status'] === 'close') {
                        continue;
                    }

                    if (\Carbon\Carbon::parse($transaction['date'])->gt(\Carbon\Carbon::parse($data['date']))) {
                        continue;
                    }

                    $scheme = $this->getSchemeFromAmfiCode($transaction);

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
                            $canSellTransactions[$transaction['amfi_code']] = $transaction;
                        }
                    }

                    $canSellTransactions[$transaction['amfi_code']]['available_units'] =
                        numberFormatPrecision($canSellTransactions[$transaction['amfi_code']]['available_units'], 2);
                    $canSellTransactions[$transaction['amfi_code']]['returns'] = $this->calculateTransactionReturns($scheme, $transaction, false, null, $data);

                    $canSellTransactions[$transaction['amfi_code']]['available_amount'] =
                        numberFormatPrecision(
                            $canSellTransactions[$transaction['amfi_code']]['available_units'] * $canSellTransactions[$transaction['amfi_code']]['returns'][$data['date']]['nav'],
                            2
                        );
                }

                if (isset($canSellTransactions[$data['amfi_code']])) {
                    if (isset($data['amount'])) {
                        if ((float) $data['amount'] > $canSellTransactions[$data['amfi_code']]['available_amount']) {
                            if (!isset($data['sell_all'])) {
                                $this->addResponse('Amount exceeds from available amount', 1);

                                return false;
                            }
                        }

                        //Convert from $data['amount'] to $data['units']
                        $data['units'] = numberFormatPrecision($data['amount'] / $scheme['navs']['navs'][$data['date']]['nav'], 3);
                    } else if (isset($data['units'])) {
                        if ((float) $data['units'] > $canSellTransactions[$data['amfi_code']]['available_units']) {
                            if (!isset($data['sell_all'])) {
                                $this->addResponse('Units exceeds from available units', 1);

                                return false;
                            }
                        }

                        //Convert from $data['units'] to $data['amount']
                        $data['amount'] = $data['units'] * $scheme['navs']['navs'][$data['date']]['nav'];
                    }

                    $data['units_bought'] = 0;
                    $data['units_sold'] = $data['units'];
                    $data['latest_value'] = '-';
                    $data['latest_value_date'] = '-';
                    $data['xirr'] = '-';
                    $data['status'] = 'close';

                    if ($this->add($data)) {
                        $data = array_merge($data, $this->packagesData->last);

                        $buyTransactions = [];
                        $sellTransactions = [];
                        $unitsToProcess = (float) $data['units'];

                        if ($unitsToProcess > 0) {
                            foreach ($this->portfolio['transactions'] as $transaction) {
                                if ($transaction['status'] === 'close' || $transaction['type'] === 'sell') {
                                    continue;
                                }

                                if ($transaction['amfi_code'] != $data['amfi_code']) {
                                    continue;
                                }

                                if (\Carbon\Carbon::parse($transaction['date'])->gt(\Carbon\Carbon::parse($data['date']))) {
                                    continue;
                                }

                                $transaction['returns'] = $this->calculateTransactionReturns($scheme, $transaction, false, null, $data);

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
                            $this->recalculatePortfolio($data, true);

                            $this->portfolio['recalculate_timeline'] = true;

                            $this->portfoliosPackage->update($this->portfolio);

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
    }

    public function updateMfTransaction($data)
    {
        $mfTransaction = $this->getById((int) $data['id']);

        $this->portfolio = $this->portfoliosPackage->getPortfolioById((int) $mfTransaction['portfolio_id']);

        if ($mfTransaction) {
            if ($mfTransaction['type'] === 'buy' &&
                $mfTransaction['status'] === 'open' &&
                $mfTransaction['units_sold'] === 0
            ) {
                $mfTransaction['date'] = $data['date'];
                $mfTransaction['amount'] = $data['amount'];
                $mfTransaction['scheme_id'] = $data['scheme_id'];
                $mfTransaction['amc_transaction_id'] = $data['amc_transaction_id'];
                $mfTransaction['details'] = $data['details'];

                if ($this->calculateTransactionUnitsAndValues($mfTransaction, true)) {
                    if ($this->update($mfTransaction)) {
                        $this->recalculatePortfolio($mfTransaction, true);

                        $this->portfolio['recalculate_timeline'] = true;
                        $this->portfoliosPackage->update($this->portfolio);

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
        $mfTransaction = $this->getById($data['id']);

        $this->portfolio = $this->portfoliosPackage->getPortfolioById((int) $mfTransaction['portfolio_id']);

        if ($mfTransaction) {
            if ($mfTransaction['type'] === 'buy') {
                if ($mfTransaction['status'] !== 'open' || $mfTransaction['units_sold'] > 0) {
                    $this->addResponse('Transaction is being used by other transactions. Cannot remove', 1);

                    return false;
                }

                if ($this->remove($mfTransaction['id'])) {
                    $this->recalculatePortfolio(['portfolio_id' => $mfTransaction['portfolio_id']], true);

                    $this->portfolio['recalculate_timeline'] = true;

                    if (!$this->portfolio['transactions']) {
                        $this->portfolio['timeline'] = [];

                        $this->portfolio['recalculate_timeline'] = false;
                    }

                    $this->portfoliosPackage->update($this->portfolio);

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
                }

                if ($this->remove($mfTransaction['id'])) {
                    $this->recalculatePortfolio(['portfolio_id' => $mfTransaction['portfolio_id']], true);

                    $this->portfolio['recalculate_timeline'] = true;

                    if (!$this->portfolio['transactions']) {
                        $this->portfolio['timeline'] = [];

                        $this->portfolio['recalculate_timeline'] = false;
                    }

                    $this->portfoliosPackage->update($this->portfolio);

                    $this->addResponse('Transaction removed');

                    return true;
                }
            }

            $this->addResponse('Unknown Transaction type. Contact developer!', 1);

            return false;
        }

        $this->addResponse('Error, contact developer', 1);
    }

    public function recalculatePortfolio($data, $viaAddUpdateRemove = false)
    {
        if (!$this->portfolio) {
            $this->portfolio = $this->portfoliosPackage->getPortfolioById((int) $data['portfolio_id']);
        }

        if (!$this->portfolio) {
            $this->addResponse('Portfolio not found', 1);

            return false;
        }

        //Increase memory_limit to 1G as the process takes a bit of memory to process the scheme's navs array.
        if ((int) ini_get('memory_limit') < 1024) {
            ini_set('memory_limit', '1024M');
        }

        if ($this->portfolio['transactions'] && count($this->portfolio['transactions']) > 0) {
            // if (isset($data['timeline']) && $data['timeline'] == true) {
            //     $portfolioTimeline = [];

            //     $transactionDate = (\Carbon\Carbon::parse($this->helper->first($transactions)['date'])->setTimezone('Asia/Kolkata'))->toDateString();

            //     while ($transactionDate !== $this->today) {
            //         $this->buyTotal = 0;
            //         $this->sellTotal = 0;
            //         $this->totalValue = 0;
            //         $this->portfolioXirrDatesArr = [];
            //         $this->portfolioXirrAmountsArr = [];
            //         $this->sellDetails = [];

            //         $this->processTransactionsNumbers($transactions, $data, $transactionDate);

            //         $this->portfolio = $this->processPortfolioNumbers($portfolio, $transactionDate);

            //         $portfolioTimeline[$transactionDate]['invested_amount'] = $portfolio['invested_amount'];
            //         $portfolioTimeline[$transactionDate]['total_value'] = $portfolio['total_value'];
            //         $portfolioTimeline[$transactionDate]['profit_loss'] = $portfolio['profit_loss'];
            //         $portfolioTimeline[$transactionDate]['xirr'] = $portfolio['xirr'];
            //         $this->basepackages->utils->setMicroTimer('number_end');

            //         $transactionDate = (\Carbon\Carbon::parse($transactionDate)->setTimezone('Asia/Kolkata'))->addDay(1)->toDateString();

            //         //Values for Today
            //         if ($transactionDate === $this->today) {
            //             $portfolioTimeline[$transactionDate]['invested_amount'] = $portfolio['invested_amount'];
            //             $portfolioTimeline[$transactionDate]['total_value'] = $portfolio['total_value'];
            //             $portfolioTimeline[$transactionDate]['profit_loss'] = $portfolio['profit_loss'];
            //             $portfolioTimeline[$transactionDate]['xirr'] = $portfolio['xirr'];
            //         }
            //     }
            //     trace([$portfolioTimeline]);
            // } else {
                $this->processTransactionsNumbers($data, null, $viaAddUpdateRemove);

                if (count($this->sellDetails) > 0) {
                    foreach ($this->sellDetails as $transactionId => $soldUnit) {
                        if (isset($transactionsArr[$transactionId])) {
                            $transactionsArr[$transactionId]['units_sold'] = $soldUnit;

                            $this->update($transactionsArr[$transactionId]);
                        }
                    }
                }

                $this->processInvestmentNumbers();

                $this->processPortfolioNumbers();

                $this->portfoliosPackage->update($this->portfolio);

                $returnArr =
                    [
                        'invested_amount' => $this->portfolio['invested_amount'],
                        'profit_loss' => $this->portfolio['profit_loss'],
                        'total_value' => $this->portfolio['total_value'],
                        'xir' => $this->portfolio['xirr']
                    ];

                $this->addResponse('Recalculated',
                                   0,
                                   [
                                        'invested_amount' => str_replace('EN_ ',
                                                                        '',
                                                                        (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                                            ->formatCurrency($this->portfolio['invested_amount'], 'en_IN')
                                                                        ),
                                        'total_value' => str_replace('EN_ ',
                                                                        '',
                                                                        (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                                            ->formatCurrency($this->portfolio['total_value'], 'en_IN')
                                                                        ),
                                        'profit_loss' => str_replace('EN_ ',
                                                                        '',
                                                                        (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                                            ->formatCurrency($this->portfolio['profit_loss'], 'en_IN')
                                                                        ),
                                        'xirr' => $this->portfolio['xirr']
                                    ]
                );

                return $returnArr;
            // }
        }

        $this->addResponse('Portfolio has no transactions. Nothing to calculate!', 1);

        return false;
    }

    protected function processTransactionsNumbers($data, $transactionDate = null, $viaAddUpdateRemove = false)
    {
        foreach ($this->portfolio['transactions'] as $transactionId => &$transaction) {
            //for Timeline generation, If the transaction has taken place beyond the requested date, we return.
            // if ($transactionDate) {
            //     $timelineTransactionDate = (\Carbon\Carbon::parse($transactionDate)->setTimezone('Asia/Kolkata'));
            //     $transactionTransactionDate = (\Carbon\Carbon::parse($transaction['date'])->setTimezone('Asia/Kolkata'));

            //     if ($transactionTransactionDate->gt($timelineTransactionDate)) {
            //         return;
            //     }

                // if ($transaction['date_closed']) {
                //     $transactionDateClosed = (\Carbon\Carbon::parse($transaction['date_closed'])->setTimezone('Asia/Kolkata'));

                //     if ($timelineTransactionDate->gt($transactionDateClosed)) {
                //         // continue;
                //     }
                // }
            // }
            // var_dump($transactionDate, $transaction);

            if ($transaction['type'] === 'buy') {
                if ($transaction['status'] === 'open') {
                    // $this->buyTotal = $this->buyTotal + $transaction['amount'];

                    $this->getSchemeFromAmfiCode($transaction);

                    if (!$viaAddUpdateRemove) {
                        $this->calculateTransactionUnitsAndValues($transaction, false, $transactionDate, $data);
                    }

                    if (isset($this->investments[$transaction['amfi_code']]['units'])) {
                        $this->investments[$transaction['amfi_code']]['units'] += $transaction['units_bought'] - $transaction['units_sold'];
                    } else {
                        $this->investments[$transaction['amfi_code']]['units'] = $transaction['units_bought'] - $transaction['units_sold'];
                    }
                    if (isset($this->investments[$transaction['amfi_code']]['amount'])) {
                        $this->investments[$transaction['amfi_code']]['amount'] += (float) $transaction['amount'];
                    } else {
                        $this->investments[$transaction['amfi_code']]['amount'] = (float) $transaction['amount'];
                    }
                    if (!isset($this->investments[$transaction['amfi_code']]['latest_nav'])) {
                        $this->investments[$transaction['amfi_code']]['latest_nav'] = $this->helper->last($transaction['returns'])['nav'];
                    }
                    if (!isset($this->investments[$transaction['amfi_code']]['latest_nav_date'])) {
                        $this->investments[$transaction['amfi_code']]['latest_nav_date'] = $this->helper->last($transaction['returns'])['date'];
                    }
                    if (!isset($this->investments[$transaction['amfi_code']]['xirrDatesArr'])) {
                        $this->investments[$transaction['amfi_code']]['xirrDatesArr'] = [];
                    }
                    if (!isset($this->investments[$transaction['amfi_code']]['xirrAmountsArr'])) {
                        $this->investments[$transaction['amfi_code']]['xirrAmountsArr'] = [];
                    }

                    $this->investments[$transaction['amfi_code']]['amc_id'] = $this->scheme['amc_id'];
                    $this->investments[$transaction['amfi_code']]['scheme_id'] = $this->scheme['id'];

                    if ($transaction['latest_value'] == 0) {
                        $transaction['diff'] = 0;
                        $transaction['xirr'] = 0;
                    } else {
                        if (!$transactionDate) {
                            if ($transaction['units_sold'] > 0) {
                                //The calculation of diff if units_sold is gt 0, example:
                                //If you buy 10000 (100 units) worth of fund on 1st and sell 5000 worth of fund on the 10th.
                                //Depending on the price, the number of units will change on the 10th. If the price of NAV per unit went up,
                                //the number of units worth 5000 will be less than 50 (# of units on the 1st)
                                //So the calculation will be done with left over units on the 10th which would be higher and
                                //diff will be taken out comparing the left over units on the 10th with left over units on the 1st
                                $totalUnits = numberFormatPrecision($transaction['units_bought'] - $transaction['units_sold'], 3);
                                $diff = $transaction['latest_value'] - ($this->scheme['navs']['navs'][$transaction['date']]['nav'] * $totalUnits);//Value on the day of purchase
                                $transactionXirrDatesArr = [$this->today, $transaction['date']];

                                $soldAmount = 0;
                                foreach ($transaction['transactions'] as $soldTransaction) {
                                    $soldAmount = $soldAmount + $soldTransaction['amount'];
                                }
                                if ($transaction['amount'] < $soldAmount) {
                                    $soldTransactionAmount = (float) -($transaction['amount'] - $soldAmount);

                                    if ($soldTransactionAmount < 0) {
                                        $soldTransactionAmount = (float) 0;
                                    }
                                } else {
                                    $soldTransactionAmount = (float) ($soldAmount - $transaction['amount']);
                                }

                                $transactionXirrAmountsArr = [(float) $transaction['latest_value'], $soldTransactionAmount];
                            } else {
                                $diff = $this->helper->last($transaction['returns'])['total_return'] - $this->helper->first($transaction['returns'])['total_return'];
                                $transaction['diff'] = numberFormatPrecision((float) $diff, 2);

                                $transactionXirrDatesArr = [$this->helper->last($transaction['returns'])['date'], $transaction['date']];
                                $transactionXirrAmountsArr = [(float) $this->helper->last($transaction['returns'])['total_return'], (float) -$transaction['amount']];

                                array_push($this->investments[$transaction['amfi_code']]['xirrDatesArr'], $transaction['date']);
                                array_push($this->investments[$transaction['amfi_code']]['xirrAmountsArr'], (float) -$transaction['amount']);
                            }
                        }
                    }

                        $transaction['xirr'] =
                            numberFormatPrecision(
                                (float) \PhpOffice\PhpSpreadsheet\Calculation\Financial\CashFlow\Variable\NonPeriodic::rate(
                                    array_values($transactionXirrAmountsArr),
                                    array_values($transactionXirrDatesArr)
                                ) * 100, 2
                            );
                    // if ($transactionDate) {
                    //     array_push($portfolioXirrDatesArr, $transaction['date']);
                    //     array_push($portfolioXirrAmountsArr, numberFormatPrecision(-$transaction['amount'], 2));
                    // } else {
                        $this->update($transaction);

                        // array_push($this->portfolioXirrDatesArr, $this->helper->first($transaction['returns'])['date']);
                        // array_push($this->portfolioXirrAmountsArr, numberFormatPrecision(-$transaction['amount'], 2));
                    // }

                    // $this->totalValue = $this->totalValue + $transaction['latest_value'];
                }
            // } else if ($transaction['type'] === 'sell') {
                // $sellTotal = $sellTotal + $transaction['amount'];

            //     if ($transactionDate) {
            //         array_push($sellDetails, $transaction['date']);
            //     } else {
            //         if (isset($transaction['transactions']) && count($transaction['transactions']) > 0) {
            //             foreach ($transaction['transactions'] as $buyTransactionId => $buyTransaction) {
            //                 if (isset($sellDetails[$buyTransactionId])) {
            //                     $sellDetails[$buyTransactionId] += $buyTransaction['units'];
            //                 } else {
            //                     $sellDetails[$buyTransactionId] = $buyTransaction['units'];
            //                 }
            //             }
            //         }
            //     }

            //     if ($this->config->databasetype === 'db') {
            //         $conditions =
            //             [
            //                 'conditions'    => 'amfi_code = :amfi_code:',
            //                 'bind'          =>
            //                     [
            //                         'amfi_code'       => (int) $transaction['amfi_code'],
            //                     ]
            //             ];
            //     } else {
            //         $conditions =
            //             [
            //                 'conditions'    => ['amfi_code', '=', (int) $transaction['amfi_code']]
            //             ];
            //     }

            //     $scheme = $this->schemesPackage->getByParams($conditions);

            //     if ($scheme && isset($scheme[0])) {
            //         $transaction['scheme_id'] = (int) $scheme[0]['id'];
            //         $this->calculateTransactionUnitsAndValues($transaction, false, $transactionDate, $data);
            //     }

            //     if ($transactionDate) {
            //         array_push($portfolioXirrDatesArr, $transaction['date']);
            //         array_push($portfolioXirrAmountsArr, numberFormatPrecision($transaction['amount'], 2));
            //     } else {
            //         $this->update($transaction);

            //         array_push($portfolioXirrDatesArr, $this->helper->first($transaction['returns'])['date']);
            //         array_push($portfolioXirrAmountsArr, numberFormatPrecision($transaction['amount'], 2));
            //     }

            //     // $totalValue = $totalValue + $transaction['amount'];
            }
            // if ($transactionDate === '2025-03-14') {
            //     // var_dump($transaction);
            // }
        }
    }

    protected function processInvestmentNumbers()
    {
        if (count($this->investments) > 0) {
            $this->portfolio['invested_amount'] = 0;
            $this->portfolio['total_value'] = 0;
            $this->portfolio['allocation'] = [];
            $this->portfolio['allocation']['by_schemes'] = [];
            $this->portfolio['allocation']['by_categories'] = [];
            $this->portfolio['allocation']['by_subcategories'] = [];

            foreach ($this->investments as $amfiCode => &$investment) {
                if (isset($this->portfolio['investments'][$amfiCode])) {
                    $portfolioInvestment = $this->portfolio['investments'][$amfiCode];
                }

                $portfolioInvestment['amc_id'] = $investment['amc_id'];
                $portfolioInvestment['scheme_id'] = $investment['scheme_id'];
                $portfolioInvestment['account_id'] = $this->portfolio['account_id'];
                $portfolioInvestment['user_id'] = $this->portfolio['user_id'];
                $portfolioInvestment['portfolio_id'] = $this->portfolio['id'];
                $portfolioInvestment['amfi_code'] = $amfiCode;
                $this->portfolio['invested_amount'] += $investment['amount'];
                $portfolioInvestment['amount'] = numberFormatPrecision($investment['amount'], 2);
                $portfolioInvestment['units'] = $investment['units'];
                $portfolioInvestment['latest_value'] = $this->investments[$amfiCode]['latest_value'] = numberFormatPrecision($investment['latest_nav'] * $investment['units'], 2);
                $this->portfolio['total_value'] += $portfolioInvestment['latest_value'];
                $portfolioInvestment['latest_value_date'] = $investment['latest_nav_date'];
                $portfolioInvestment['diff'] = numberFormatPrecision($portfolioInvestment['latest_value'] - $portfolioInvestment['amount'], 2);

                array_push($investment['xirrDatesArr'], $investment['latest_nav_date']);
                array_push($investment['xirrAmountsArr'], (float) $portfolioInvestment['latest_value']);

                $portfolioInvestment['xirr'] =
                    numberFormatPrecision(
                        (float) \PhpOffice\PhpSpreadsheet\Calculation\Financial\CashFlow\Variable\NonPeriodic::rate(
                            array_values($investment['xirrAmountsArr']),
                            array_values($investment['xirrDatesArr'])
                        ) * 100, 2
                    );

                $this->portfolioXirrDatesArr = array_merge($this->portfolioXirrDatesArr, $investment['xirrDatesArr']);
                $this->portfolioXirrAmountsArr = array_merge($this->portfolioXirrAmountsArr, $investment['xirrAmountsArr']);

                if (array_key_exists('id', $portfolioInvestment)) {
                    $this->investmentsPackage->update($portfolioInvestment);
                } else {
                    $this->investmentsPackage->add($portfolioInvestment);
                }

                $investment['id'] = $this->investmentsPackage->packagesData->last['id'];
            }


            //Running loop again to recalculate category percentage. We need to calculate the portfolio total in order to get percentage of categories.
            unset($investment);//Unset as we are using the same var $investment again.
            foreach ($this->investments as $investment) {
                $scheme = $this->schemesPackage->getSchemeById($investment['scheme_id']);

                if (!isset($this->portfolio['allocation']['by_schemes'][$scheme['id']])) {
                    $this->portfolio['allocation']['by_schemes'][$scheme['id']] = [];
                }

                $schemeAllocation = &$this->portfolio['allocation']['by_schemes'][$scheme['id']];
                $schemeAllocation['scheme_id'] = $scheme['id'];
                $schemeAllocation['scheme_name'] = $scheme['name'];
                $schemeAllocation['invested_amount'] = $investment['amount'];
                $schemeAllocation['invested_percent'] = round(($investment['amount'] / $this->portfolio['invested_amount']) * 100, 2);
                $schemeAllocation['return_amount'] = $investment['latest_value'];
                $schemeAllocation['return_percent'] = round(($investment['latest_value'] / $this->portfolio['total_value']) * 100, 2);

                if (!isset($schemeAllocation['investments'])) {
                    $schemeAllocation['investments'] = [];
                }

                array_push($schemeAllocation['investments'], $investment['id']);

                $categoryId = $scheme['category_id'];

                if (isset($scheme['category']['parent_id'])) {
                    $parent = $this->categoriesPackage->getMfCategoryParent($scheme['category_id']);

                    if ($parent && isset($parent['id'])) {
                        $categoryId = $parent['id'];
                    }
                }

                if (!isset($this->portfolio['allocation']['by_categories'][$categoryId])) {
                    $this->portfolio['allocation']['by_categories'][$categoryId] = [];
                    $this->portfolio['allocation']['by_categories'][$categoryId]['category'] = $parent;
                }

                $parentCategory = &$this->portfolio['allocation']['by_categories'][$categoryId];

                if (isset($parent)) {
                    $this->portfolio['allocation']['by_categories'][$categoryId]['category'] = $parent;

                    if (!isset($this->portfolio['allocation']['by_subcategories'][$scheme['category_id']])) {
                        $this->portfolio['allocation']['by_subcategories'][$scheme['category_id']]['category'] = $scheme['category'];

                        //Referencing for cleaner code
                        $subCategory = &$this->portfolio['allocation']['by_subcategories'][$scheme['category_id']];
                    }

                    if (!isset($subCategory['invested_amount'])) {
                        $subCategory['invested_amount'] = $investment['amount'];
                    } else {
                        $subCategory['invested_amount'] += $investment['amount'];
                    }

                    $subCategory['invested_percent'] =
                        round(($subCategory['invested_amount'] / $this->portfolio['invested_amount']) * 100, 2);

                    if (!isset($subCategory['return_amount'])) {
                        $subCategory['return_amount'] = $investment['latest_value'];
                    } else {
                        $subCategory['return_amount'] =
                            numberFormatPrecision($subCategory['return_amount'] + $investment['latest_value'], 2);
                    }

                    $subCategory['return_percent'] =
                        round(($subCategory['return_amount'] / $this->portfolio['total_value']) * 100, 2);

                    if (!isset($subCategory['investments'])) {
                        $subCategory['investments'] = [];
                    }

                    array_push($subCategory['investments'], $investment['id']);
                }

                if (!isset($parentCategory['invested_amount'])) {
                    $parentCategory['invested_amount'] = $investment['amount'];
                } else {
                    $parentCategory['invested_amount'] += $investment['amount'];
                }

                $parentCategory['invested_percent'] =
                    round(($parentCategory['invested_amount'] / $this->portfolio['invested_amount']) * 100, 2);

                if (!isset($parentCategory['return_amount'])) {
                    $parentCategory['return_amount'] = $investment['latest_value'];
                } else {
                    $parentCategory['return_amount'] =
                        numberFormatPrecision($parentCategory['return_amount'] + $investment['latest_value'], 2);
                }

                $parentCategory['return_percent'] =
                    round(($parentCategory['return_amount'] / $this->portfolio['total_value']) * 100, 2);

                if (!isset($parentCategory['investments'])) {
                    $parentCategory['investments'] = [];
                }

                array_push($parentCategory['investments'], $investment['id']);
            }
        }

        if ($this->portfolio['profit_loss'] > 0) {
            $this->portfolio['status'] = 'positive';
        } else if ($this->portfolio['profit_loss'] < 0) {
            $this->portfolio['status'] = 'negative';
        } else if ($this->portfolio['profit_loss'] == 0) {
            $this->portfolio['status'] = 'neutral';
        }
    }

    protected function processPortfolioNumbers()
    {
        // if ($this->sellTotal > 0) {
        //     $this->portfolio['invested_amount'] = $this->buyTotal - $this->sellTotal;

        //     if ($this->portfolio['invested_amount'] < 0) {
        //         $this->portfolio['invested_amount'] = 0;
        //     }

        //     if (count($this->sellDetails) > 0) {
        //         if (in_array($transactionDate, $this->sellDetails)) {
        //             $this->totalValue = $this->totalValue + $this->sellTotal;
        //         }
        //     } else {
        //         $this->totalValue = $this->totalValue + $this->sellTotal;
        //     }

        //     $this->portfolio['profit_loss'] = numberFormatPrecision($this->totalValue, 2);

        //     if ($this->sellTotal > $this->buyTotal) {
        //         $this->portfolio['profit_loss'] = abs($this->portfolio['profit_loss']);
        //     } else {
        //         if ($this->totalValue > ($this->buyTotal + $this->sellTotal)) {
        //             $this->portfolio['profit_loss'] = numberFormatPrecision($this->totalValue - ($this->buyTotal + $this->sellTotal), 2);
        //         } else {
        //             $this->portfolio['profit_loss'] = numberFormatPrecision($this->totalValue - $this->buyTotal, 2);
        //         }
        //     }

        //     if ($this->portfolio['invested_amount'] === 0) {
        //         $this->portfolio['total_value'] = numberFormatPrecision($this->sellTotal, 2);

        //         $this->portfolio['profit_loss'] = numberFormatPrecision($this->sellTotal - $this->buyTotal, 2);
        //     } else {
        //         if ($this->totalValue >= ($this->buyTotal + $this->sellTotal)) {
        //             $this->portfolio['total_value'] = numberFormatPrecision($this->totalValue - $this->sellTotal, 2);
        //         } else {
        //             $this->portfolio['total_value'] = numberFormatPrecision($this->totalValue, 2);
        //         }
        //     }
        // } else {
        //     $this->portfolio['invested_amount'] = (float) $this->buyTotal;

        //     if ($this->portfolio['invested_amount'] < 0) {
        //         $this->portfolio['invested_amount'] = 0;
        //     }

        //     $this->portfolio['profit_loss'] = numberFormatPrecision($this->totalValue - $this->portfolio['invested_amount'], 2);

        //     $this->portfolio['total_value'] = numberFormatPrecision($this->buyTotal + $this->portfolio['profit_loss'], 2);
        // }

        if (count($this->portfolioXirrDatesArr) > 0 &&
            count($this->portfolioXirrAmountsArr) > 0 &&
            (count($this->portfolioXirrDatesArr) == count($this->portfolioXirrAmountsArr))
        ) {
            // if ($this->portfolio['invested_amount'] !== 0) {
            //     // if ($transactionDate) {
            //     //     array_push($this->portfolioXirrDatesArr, $transactionDate);
            //     // } else {
            //         array_push($this->portfolioXirrDatesArr, $this->today);
            //     // }

            //     if ($this->sellTotal > 0) {
            //         array_push($this->portfolioXirrAmountsArr, $this->totalValue - $this->sellTotal);
            //     } else {
            //         array_push($this->portfolioXirrAmountsArr, $this->buyTotal + $this->portfolio['profit_loss']);
            //     }
            // }

            // $this->portfolioXirrDatesArr = array_reverse($this->portfolioXirrDatesArr, true);
            // $this->portfolioXirrAmountsArr = array_reverse($this->portfolioXirrAmountsArr, true);

            $this->portfolio['xirr'] =
                numberFormatPrecision(
                    (float) \PhpOffice\PhpSpreadsheet\Calculation\Financial\CashFlow\Variable\NonPeriodic::rate(
                        array_values($this->portfolioXirrAmountsArr),
                        array_values($this->portfolioXirrDatesArr)
                    ) * 100, 2
                );
        } else {
            $this->portfolio['xirr'] = 0;
        }

        $this->portfolio['profit_loss'] = numberFormatPrecision($this->portfolio['total_value'] - $this->portfolio['invested_amount'], 2);
    }

    protected function calculateTransactionUnitsAndValues(&$transaction, $update = false, $transactionDate = null, $sellTransactionData = null)
    {
        $this->getSchemeFromAmfiCode($transaction);

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

                $transaction['returns'] = $this->calculateTransactionReturns($transaction, $update, $transactionDate, $sellTransactionData);

                //We calculate the total number of units for latest_value
                if ($transactionDate) {
                    if (isset($transaction['returns'][$transactionDate])) {
                        $lastTransactionNav = $transaction['returns'][$transactionDate];
                    } else {
                        $lastTransactionNav = false;
                    }
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

    public function calculateTransactionReturns($transaction, $update = false, $transactionDate = null, $sellTransactionData = null)
    {
        if (!$transactionDate && $transaction['status'] === 'close') {
            return $transaction['returns'];
        }

        if (!isset($transaction['returns']) || $update || $transactionDate) {
            $transaction['returns'] = [];
        }

        if ($transaction['type'] === 'buy') {
            if ($transactionDate) {
                $units = numberFormatPrecision($transaction['units_bought'], 3);

                if ($transaction['transactions'] && count($transaction['transactions']) > 0) {
                    foreach ($transaction['transactions'] as $soldTransaction) {
                        if ($transactionDate === $soldTransaction['date']) {
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

        $navsToProcess = $navs = $this->scheme['navs']['navs'];

        $navsKeys = array_keys($navs);

        // $navsToProcess = [];

        if (!$transactionDate) {
            $navsToProcess = [];
            // $transactionDateKey = array_search($transactionDate, $navsKeys);
            $transactionDateKey = array_search($transaction['date'], $navsKeys);
        // } else {
        // }


        // if (!$transactionDate) {
                // if ($transactionDate === '2025-03-22') {
                    // trace([$transactionDateKey, array_reverse($navs, true)]);
                // }
            // $navs = array_slice($navs, $transactionDateKey, 1);

            // $navsToProcess[$this->helper->firstKey($navs)] = $this->helper->first($navs);
            // $navsToProcess = $navs;
        // } else {
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

    protected function getSchemeFromAmfiCode(&$transaction)
    {
        if (isset($transaction['scheme_id']) && $transaction['scheme_id'] !== '') {
            $this->scheme = [$this->schemesPackage->getSchemeById((int) $transaction['scheme_id'])];
        } else if (isset($transaction['amfi_code']) && $transaction['amfi_code'] !== '') {
            if ($this->config->databasetype === 'db') {
                $conditions =
                    [
                        'conditions'    => 'amfi_code = :amfi_code:',
                        'bind'          =>
                            [
                                'amfi_code'       => (int) $transaction['amfi_code'],
                            ]
                    ];
            } else {
                $conditions =
                    [
                        'conditions'    => ['amfi_code', '=', (int) $transaction['amfi_code']]
                    ];
            }

            $this->scheme = $this->schemesPackage->getByParams($conditions);

            if ($this->scheme && isset($this->scheme[0])) {
                $this->scheme = [$this->schemesPackage->getSchemeById((int) $this->scheme[0]['id'])];
            }
        }

        if (isset($this->scheme) && isset($this->scheme[0])) {
            $this->scheme = $this->scheme[0];

            $transaction['scheme_id'] = (int) $this->scheme['id'];

            return $this->scheme;
        }

        return false;
    }
}