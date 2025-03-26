<?php

namespace Apps\Fintech\Packages\Mf\Transactions;

use Apps\Fintech\Packages\Accounts\Balances\AccountsBalances;
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

    public function init()
    {
        $this->today = (\Carbon\Carbon::now(new \DateTimeZone('Asia/Kolkata')))->toDateString();

        parent::init();

        return $this;
    }

    public function getMfTransactionById($id)
    {
        $mfTransactions = $this->getById($id);

        if ($mfTransactions) {
            //
            $this->addResponse('Success');

            return;
        }

        $this->addResponse('Error', 1);
    }

    public function addMfTransaction($data)
    {
        $data['account_id'] = $this->access->auth->account()['id'];

        if ($data['type'] === 'buy') {
            $data['status'] = 'open';

            if ($this->calculateTransactionUnitsAndValues($data)) {
                if ($this->add($data)) {
                    $this->recalculatePortfolioTransactions($data);

                    $portfolioPackage = new MfPortfolios;

                    $portfolio = $portfolioPackage->getPortfolioById((int) $data['portfolio_id']);

                    if ($portfolio) {
                        $portfolio['recalculate_timeline'] = true;

                        $portfolioPackage->update($portfolio);
                    }

                    $this->addResponse('Ok', 0);

                    return true;
                }

                $this->addResponse('Error adding transaction', 1);

                return false;
            }

            $this->addResponse('Error getting transaction units', 1);

            return false;
        } else if ($data['type'] === 'sell') {
            $this->recalculatePortfolioTransactions($data);

            $schemesPackage = $this->usepackage(MfSchemes::class);

            if (isset($data['scheme_id']) && $data['scheme_id'] !== '') {
                $scheme = $schemesPackage->getSchemeById((int) $data['scheme_id']);

                if (!$scheme) {
                    $this->addResponse('Scheme with scheme id not found!', 1);

                    return false;
                }
            } else if (isset($data['amfi_code']) && $data['amfi_code'] !== '') {
                if ($this->config->databasetype === 'db') {
                    $conditions =
                        [
                            'conditions'    => 'amfi_code = :amfi_code:',
                            'bind'          =>
                                [
                                    'amfi_code'       => (int) $data['amfi_code'],
                                ]
                        ];
                } else {
                    $conditions =
                        [
                            'conditions'    => ['amfi_code', '=', (int) $data['amfi_code']]
                        ];
                }

                $scheme = $schemesPackage->getByParams($conditions);

                if ($scheme && isset($scheme[0])) {
                    $scheme = $schemesPackage->getSchemeById((int) $scheme[0]['id']);
                }

                if (!$scheme) {
                    $this->addResponse('Scheme with amfi code not found!', 1);

                    return false;
                }
            }

            $canSellTransactions = [];

            $portfolioPackage = new MfPortfolios;

            $portfolio = $portfolioPackage->getPortfolioById((int) $data['portfolio_id']);

            if ($portfolio &&
                $portfolio['transactions'] &&
                count($portfolio['transactions']) > 0
            ) {
                $portfolio['transactions'] = msort(array: $portfolio['transactions'], key: 'date', preserveKey: true);

                foreach ($portfolio['transactions'] as $transaction) {
                    if ($transaction['amfi_code'] != $data['amfi_code']) {
                        continue;
                    }

                    if ($transaction['type'] === 'buy' && $transaction['status'] === 'open') {
                        $transaction['available_units'] = $transaction['units_bought'];

                        if ($transaction['units_sold'] > 0) {
                            $transaction['available_units'] = $transaction['units_bought'] - $transaction['units_sold'];
                        }

                        if (isset($canSellTransactions[$transaction['amfi_code']])) {
                            $canSellTransactions[$transaction['amfi_code']]['available_units'] += $transaction['available_units'];
                        } else {
                            $canSellTransactions[$transaction['amfi_code']] = $transaction;
                        }
                    } else {
                        continue;
                    }

                    $canSellTransactions[$transaction['amfi_code']]['available_units'] = round($canSellTransactions[$transaction['amfi_code']]['available_units'], 2);
                    $canSellTransactions[$transaction['amfi_code']]['available_amount'] =
                        round($canSellTransactions[$transaction['amfi_code']]['available_units'] * $canSellTransactions[$transaction['amfi_code']]['returns'][$data['date']]['nav'], 2);
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
                        $data['units'] = round($data['amount'] / $scheme['navs']['navs'][$data['date']]['nav'], 3);
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
                    $data['cagr'] = '-';
                    $data['status'] = 'close';

                    if ($this->add($data)) {
                        $data = array_merge($data, $this->packagesData->last);

                        $buyTransactions = [];
                        $sellTransactions = [];
                        $unitsToProcess = (float) $data['units'];

                        foreach ($portfolio['transactions'] as $transaction) {
                            if ($unitsToProcess <= 0) {
                                break;
                            }

                            if ($transaction['amfi_code'] != $data['amfi_code']) {
                                continue;
                            }

                            if ($transaction['type'] === 'buy' && $transaction['status'] === 'open') {
                                $availableUnits = $transaction['units_bought'] - $transaction['units_sold'];

                                if (isset($data['sell_all']) && $data['sell_all'] == 'true') {
                                    $buyTransactions[$transaction['id']]['id'] = $transaction['id'];
                                    $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                    $buyTransactions[$transaction['id']]['units'] = round($availableUnits, 3);
                                    $buyTransactions[$transaction['id']]['amount'] = round($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);

                                    $sellTransactions[$data['id']]['id'] = $data['id'];
                                    $sellTransactions[$data['id']]['date'] = $data['date'];
                                    $sellTransactions[$data['id']]['units'] = round($availableUnits, 3);
                                    $sellTransactions[$data['id']]['amount'] = round($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);

                                    $transaction['units_sold'] = $transaction['units_bought'];

                                    $transaction['status'] = 'close';
                                    $transaction['date_closed'] = $data['date'];
                                } else {
                                    if ($availableUnits <= $unitsToProcess) {
                                        $transaction['units_sold'] = $transaction['units_bought'];

                                        $buyTransactions[$transaction['id']]['id'] = $transaction['id'];
                                        $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                        $buyTransactions[$transaction['id']]['units'] = round($availableUnits, 3);
                                        $buyTransactions[$transaction['id']]['amount'] = round($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);

                                        $sellTransactions[$data['id']]['id'] = $data['id'];
                                        $sellTransactions[$data['id']]['date'] = $data['date'];
                                        $sellTransactions[$data['id']]['units'] = round($availableUnits, 3);
                                        $sellTransactions[$data['id']]['amount'] = round($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);

                                        $transaction['status'] = 'close';
                                        $transaction['date_closed'] = $data['date'];
                                    } else if ($availableUnits > $unitsToProcess) {
                                        $transaction['units_sold'] = $transaction['units_sold'] + round($unitsToProcess, 3);

                                        $buyTransactions[$transaction['id']]['id'] = $transaction['id'];
                                        $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                        $buyTransactions[$transaction['id']]['units'] = round($unitsToProcess, 3);
                                        $buyTransactions[$transaction['id']]['amount'] = round($unitsToProcess * $transaction['returns'][$data['date']]['nav'], 2);

                                        $sellTransactions[$data['id']]['id'] = $data['id'];
                                        $sellTransactions[$data['id']]['date'] = $data['date'];
                                        $sellTransactions[$data['id']]['units'] = round($unitsToProcess, 3);
                                        $sellTransactions[$data['id']]['amount'] = round($unitsToProcess * $transaction['returns'][$data['date']]['nav'], 2);
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

                        $data['transactions'] = $buyTransactions;

                        if ($this->update($data)) {
                            $this->recalculatePortfolioTransactions($data);

                            $portfolio = $portfolioPackage->getPortfolioById((int) $data['portfolio_id']);

                            $portfolio['recalculate_timeline'] = true;

                            $portfolioPackage->update($portfolio);

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
    }

    public function updateMfTransaction($data)
    {
        $mfTransaction = $this->getById((int) $data['id']);

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
                        $this->recalculatePortfolioTransactions($mfTransaction);

                        $portfolioPackage = new MfPortfolios;

                        $portfolio = $portfolioPackage->getPortfolioById((int) $mfTransaction['portfolio_id']);

                        if ($portfolio) {
                            $portfolio['recalculate_timeline'] = true;

                            $portfolioPackage->update($portfolio);
                        }

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
    }

    public function removeMfTransaction($data)
    {
        $mfTransaction = $this->getById($data['id']);

        if ($mfTransaction) {
            $portfolioPackage = new MfPortfolios;

            if ($mfTransaction['type'] === 'buy') {
                if ($mfTransaction['status'] !== 'open' || $mfTransaction['units_sold'] > 0) {
                    $this->addResponse('Transaction is being used by other transactions. Cannot remove', 1);

                    return false;
                }

                if ($this->remove($mfTransaction['id'])) {
                    $this->recalculatePortfolioTransactions(['portfolio_id' => $mfTransaction['portfolio_id']]);

                    $portfolio = $portfolioPackage->getPortfolioById((int) $mfTransaction['portfolio_id']);

                    if ($portfolio) {
                        $portfolio['recalculate_timeline'] = true;

                        if (!$portfolio['transactions']) {
                            $portfolio['timeline'] = [];

                            $portfolio['recalculate_timeline'] = false;
                        }

                        $portfolioPackage->update($portfolio);
                    }

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

                                    $correspondingTransaction['units_sold'] = round($correspondingTransaction['units_sold'] - $correspondingTransactionArr['units'], 3);

                                    unset($correspondingTransaction['transactions'][$mfTransaction['id']]);
                                }
                            }

                            $this->update($correspondingTransaction);
                        }
                    }
                }

                if ($this->remove($mfTransaction['id'])) {
                    $this->recalculatePortfolioTransactions(['portfolio_id' => $mfTransaction['portfolio_id']]);

                    $portfolio = $portfolioPackage->getPortfolioById((int) $mfTransaction['portfolio_id']);

                    $portfolio['recalculate_timeline'] = true;

                    if (!$portfolio['transactions']) {
                        $portfolio['timeline'] = [];

                        $portfolio['recalculate_timeline'] = false;
                    }

                    $portfolioPackage->update($portfolio);

                    $this->addResponse('Transaction removed');

                    return true;
                }
            }

            $this->addResponse('Unknown Transaction type. Contact developer!', 1);

            return false;
        }

        $this->addResponse('Error, contact developer', 1);
    }

    public function recalculatePortfolioTransactions($data)
    {
        if ($this->config->databasetype === 'db') {
            $conditions =
                [
                    'conditions'    => 'portfolio_id = :portfolio_id:',
                    'bind'          =>
                        [
                            'portfolio_id'       => (int) $data['portfolio_id'],
                        ]
                ];
        } else {
            $conditions =
                [
                    'conditions'    => ['portfolio_id', '=', (int) $data['portfolio_id']]
                ];
        }

        $transactionsArr = $this->getByParams($conditions);

        $schemesPackage = $this->usepackage(MfSchemes::class);

        $buyTotal = 0;
        $sellTotal = 0;
        $totalValue = 0;
        $portfolioXirrDatesArr = [];
        $portfolioXirrAmountsArr = [];
        $sellDetails = [];

        $transactions = [];
        if ($transactionsArr && count($transactionsArr) > 0) {
            //Re arrange with date
            $transactionsArr = msort(array: $transactionsArr, key: 'date', preserveKey: true);

            // rearrange with ID as we need this for $sellDetails
            foreach ($transactionsArr as $transaction) {
                $transactions[$transaction['id']] = $transaction;
            }
        }

        $portfolioPackage = $this->usepackage(MfPortfolios::class);
        $portfolio = $portfolioPackage->getById((int) $data['portfolio_id']);

        if (!$portfolio) {
            $this->addResponse('Portfolio not found', 1);

            return false;
        }

        if ($transactions && count($transactions) > 0) {
            if (isset($data['timeline']) && $data['timeline'] == true) {
                $portfolioTimeline = [];

                $transactionDate = (\Carbon\Carbon::parse($this->helper->first($transactions)['date'])->setTimezone('Asia/Kolkata'))->toDateString();

                while ($transactionDate !== $this->today) {
                    $buyTotal = 0;
                    $sellTotal = 0;
                    $totalValue = 0;
                    $portfolioXirrDatesArr = [];
                    $portfolioXirrAmountsArr = [];
                    $sellDetails = [];

                    $this->processTransactions(
                        $transactions, $buyTotal, $sellTotal, $schemesPackage, $sellDetails, $data, $totalValue, $portfolioXirrDatesArr, $portfolioXirrAmountsArr, $transactionDate
                    );

                    $portfolio = $this->getPortfolioNumbers($portfolio, $totalValue, $buyTotal, $sellTotal, $sellDetails, $portfolioXirrDatesArr, $portfolioXirrAmountsArr, $transactionDate);
                    // if ($transactionDate === '2025-03-14') {
                    //     trace([$buyTotal, $sellTotal, $sellDetails, $totalValue, $portfolioXirrDatesArr, $portfolioXirrAmountsArr, $transactionDate, $portfolio]);
                    // }

                    $portfolioTimeline[$transactionDate]['invested_amount'] = $portfolio['invested_amount'];
                    $portfolioTimeline[$transactionDate]['total_value'] = $portfolio['total_value'];
                    $portfolioTimeline[$transactionDate]['profit_loss'] = $portfolio['profit_loss'];
                    $portfolioTimeline[$transactionDate]['xirr'] = $portfolio['xirr'];

                    // if ($transactionDate === '2025-03-22') {
                    //     trace([$portfolioTimeline]);
                    // }
                    $transactionDate = (\Carbon\Carbon::parse($transactionDate)->setTimezone('Asia/Kolkata'))->addDay(1)->toDateString();

                    //Values for Today
                    if ($transactionDate === $this->today) {
                        $portfolioTimeline[$transactionDate]['invested_amount'] = $portfolio['invested_amount'];
                        $portfolioTimeline[$transactionDate]['total_value'] = $portfolio['total_value'];
                        $portfolioTimeline[$transactionDate]['profit_loss'] = $portfolio['profit_loss'];
                        $portfolioTimeline[$transactionDate]['xirr'] = $portfolio['xirr'];
                    }
                }
                trace([$portfolioTimeline]);
            } else {
                $this->processTransactions($transactions, $buyTotal, $sellTotal, $schemesPackage, $sellDetails, $data, $totalValue, $portfolioXirrDatesArr, $portfolioXirrAmountsArr);

                if (count($sellDetails) > 0) {
                    foreach ($sellDetails as $transactionId => $soldUnit) {
                        if (isset($transactions[$transactionId])) {
                            $transactions[$transactionId]['units_sold'] = $soldUnit;

                            $this->update($transactions[$transactionId]);
                        }
                    }
                }

                $portfolio = $this->getPortfolioNumbers($portfolio, $totalValue, $buyTotal, $sellTotal, [], $portfolioXirrDatesArr, $portfolioXirrAmountsArr);

                $portfolioPackage->update($portfolio);

                $returnArr =
                    [
                        'invested_amount' => $portfolio['invested_amount'],
                        'profit_loss' => $portfolio['profit_loss'],
                        'total_value' => $portfolio['total_value'],
                        'xir' => $portfolio['xirr']
                    ];

                $this->addResponse('Recalculated',
                                   0,
                                   [
                                        'invested_amount' => str_replace('EN_ ',
                                                                        '',
                                                                        (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                                            ->formatCurrency($portfolio['invested_amount'], 'en_IN')
                                                                        ),
                                        'total_value' => str_replace('EN_ ',
                                                                        '',
                                                                        (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                                            ->formatCurrency($portfolio['total_value'], 'en_IN')
                                                                        ),
                                        'profit_loss' => str_replace('EN_ ',
                                                                        '',
                                                                        (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                                            ->formatCurrency($portfolio['profit_loss'], 'en_IN')
                                                                        ),
                                        'xirr' => $portfolio['xirr']
                                    ]
                );

                return $returnArr;
            }
        }

        $this->addResponse('Portfolio has no transactions. Nothing to calculate!', 1);

        return false;
    }

    protected function processTransactions(
        &$transactions, &$buyTotal,
        &$sellTotal, $schemesPackage,
        &$sellDetails, $data, &$totalValue,
        &$portfolioXirrDatesArr, &$portfolioXirrAmountsArr,
        $transactionDate = null
    ) {
        foreach ($transactions as $transactionId => &$transaction) {
            //for Timeline generation, If the transaction has taken place beyond the requested date, we return.
            if ($transactionDate) {
                $timelineTransactionDate = (\Carbon\Carbon::parse($transactionDate)->setTimezone('Asia/Kolkata'));
                $transactionTransactionDate = (\Carbon\Carbon::parse($transaction['date'])->setTimezone('Asia/Kolkata'));

                if ($transactionTransactionDate->gt($timelineTransactionDate)) {
                    return;
                }

                // if ($transaction['date_closed']) {
                //     $transactionDateClosed = (\Carbon\Carbon::parse($transaction['date_closed'])->setTimezone('Asia/Kolkata'));

                //     if ($timelineTransactionDate->gt($transactionDateClosed)) {
                //         // continue;
                //     }
                // }
            }
            // var_dump($transactionDate, $transaction);

            if ($transaction['type'] === 'buy') {
                $buyTotal = $buyTotal + $transaction['amount'];

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

                $scheme = $schemesPackage->getByParams($conditions);

                if ($scheme && isset($scheme[0])) {
                    $transaction['scheme_id'] = (int) $scheme[0]['id'];
                    $scheme = $schemesPackage->getSchemeById((int) $scheme[0]['id']);

                    $this->calculateTransactionUnitsAndValues($transaction, false, $transactionDate, $data);

                    if ($transaction['latest_value'] == 0) {
                        $transaction['diff'] = 0;
                        $transaction['xirr'] = 0;
                    } else {
                        if ($transaction['status'] === 'open' && $transaction['units_sold'] > 0) {
                            //The calculation of diff if units_sold is gt 0, example:
                            //If you buy 10000 (100 units) worth of fund on 1st and sell 5000 worth of fund on the 10th.
                            //Depending on the price, the number of units will change on the 10th. If the price of NAV per unit went up,
                            //the number of units worth 5000 will be less than 50 (# of units on the 1st)
                            //So the calculation will be done with left over units on the 10th which would be higher and
                            //diff will be taken out comparing the left over units on the 10th with left over units on the 1st
                            $totalUnits = round($transaction['units_bought'] - $transaction['units_sold'], 3);
                            $diff = $transaction['latest_value'] - ($scheme['navs']['navs'][$transaction['date']]['nav'] * $totalUnits);//Value on the day of purchase
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
                            $diff = $transaction['latest_value'] - $transaction['amount'];
                            $transactionXirrDatesArr = [$this->today, $transaction['date']];
                            $transactionXirrAmountsArr = [(float) $transaction['latest_value'], (float) -$transaction['amount']];
                        }

                        $transaction['diff'] = round($diff, 2);
                        $transaction['xirr'] =
                            round(
                                (float) \PhpOffice\PhpSpreadsheet\Calculation\Financial\CashFlow\Variable\NonPeriodic::rate(
                                    array_values($transactionXirrAmountsArr),
                                    array_values($transactionXirrDatesArr)
                                ) * 100, 2
                            );
                    }

                    if (!$transactionDate) {
                        $this->update($transaction);
                    }

                    $totalValue = $totalValue + $transaction['latest_value'];

                    array_push($portfolioXirrDatesArr, $this->helper->first($transaction['returns'])['date']);
                    array_push($portfolioXirrAmountsArr, round(-$transaction['amount'], 2));
                }
            } else if ($transaction['type'] === 'sell') {
                $sellTotal = $sellTotal + $transaction['amount'];

                if ($transactionDate) {
                    array_push($sellDetails, $transaction['date']);
                } else {
                    if (isset($transaction['transactions']) && count($transaction['transactions']) > 0) {
                        foreach ($transaction['transactions'] as $buyTransactionId => $buyTransaction) {
                            if (isset($sellDetails[$buyTransactionId])) {
                                $sellDetails[$buyTransactionId] += $buyTransaction['units'];
                            } else {
                                $sellDetails[$buyTransactionId] = $buyTransaction['units'];
                            }
                        }
                    }
                }

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

                $scheme = $schemesPackage->getByParams($conditions);

                if ($scheme && isset($scheme[0])) {
                    $transaction['scheme_id'] = (int) $scheme[0]['id'];
                    $this->calculateTransactionUnitsAndValues($transaction, false, $transactionDate, $data);
                }

                if (!$transactionDate) {
                    $this->update($transaction);
                }

                // $totalValue = $totalValue + $transaction['amount'];

                array_push($portfolioXirrDatesArr, $this->helper->first($transaction['returns'])['date']);
                array_push($portfolioXirrAmountsArr, round($transaction['amount'], 2));
            }
            if ($transactionDate === '2025-03-14') {
                // var_dump($transaction);
            }
        }
    }

    protected function getPortfolioNumbers($portfolio, $totalValue, $buyTotal, $sellTotal, $sellDetails, $portfolioXirrDatesArr, $portfolioXirrAmountsArr, $transactionDate = null)
    {
        if ($transactionDate === '2025-03-22') {
            // var_dump([$totalValue, $buyTotal, $sellTotal, $portfolio['total_value'], $portfolio['invested_amount'], $sellDetails]);
        }
        if ($sellTotal > 0) {
            $portfolio['invested_amount'] = $buyTotal;

            if ($portfolio['invested_amount'] < 0) {
                $portfolio['invested_amount'] = 0;
            }

            if ($sellDetails && count($sellDetails) > 0) {
                if (in_array($transactionDate, $sellDetails)) {
                    $totalValue = $totalValue + $sellTotal;
                }
            } else {
                $totalValue = $totalValue + $sellTotal;
            }
                    // var_dump($transactionDate, $totalValue);

            $portfolio['profit_loss'] = round($totalValue, 2);

            if ($sellTotal > $buyTotal) {
                $portfolio['profit_loss'] = abs($portfolio['profit_loss']);
            } else {
                if ($totalValue > ($buyTotal + $sellTotal)) {
                    $portfolio['profit_loss'] = round($totalValue - ($buyTotal + $sellTotal), 2);
                } else {
                    $portfolio['profit_loss'] = round($totalValue - $buyTotal, 2);
                }
            }

            if ($portfolio['invested_amount'] === 0) {
                $portfolio['total_value'] = round($sellTotal, 2);

                $portfolio['profit_loss'] = round($sellTotal - $buyTotal, 2);
            } else {
                if ($totalValue >= ($buyTotal + $sellTotal)) {
                    $portfolio['total_value'] = round($totalValue - $sellTotal, 2);
                } else {
                    $portfolio['total_value'] = round($totalValue, 2);
                }
            }
        } else {
            $portfolio['invested_amount'] = $buyTotal - $sellTotal;

            if ($portfolio['invested_amount'] < 0) {
                $portfolio['invested_amount'] = 0;
            }

            $portfolio['profit_loss'] = round($totalValue - $portfolio['invested_amount'], 2);

            $portfolio['total_value'] = round($buyTotal + $portfolio['profit_loss'], 2);
        }
        if ($transactionDate === '2025-03-22') {
            // var_dump([$totalValue, $buyTotal, $sellTotal, $portfolio['total_value']]);
        }
        // trace([$totalValue, $portfolio['profit_loss'], $portfolio['total_value'], $buyTotal, $sellTotal, $portfolio['invested_amount']]);
        if (count($portfolioXirrDatesArr) > 0) {
            if ($portfolio['invested_amount'] !== 0) {
                if ($transactionDate) {
                    array_push($portfolioXirrDatesArr, $transactionDate);
                } else {
                    array_push($portfolioXirrDatesArr, $this->today);
                }
                if ($sellTotal > 0) {
                    array_push($portfolioXirrAmountsArr, $totalValue - $sellTotal);
                } else {
                    array_push($portfolioXirrAmountsArr, $buyTotal + $portfolio['profit_loss']);
                }
            }

            $portfolioXirrDatesArr = array_reverse($portfolioXirrDatesArr, true);
            $portfolioXirrAmountsArr = array_reverse($portfolioXirrAmountsArr, true);
            // trace([$portfolioXirrDatesArr, $portfolioXirrAmountsArr]);
            $portfolio['xirr'] =
                round(
                    (float) \PhpOffice\PhpSpreadsheet\Calculation\Financial\CashFlow\Variable\NonPeriodic::rate(
                        array_values($portfolioXirrAmountsArr),
                        array_values($portfolioXirrDatesArr)
                    ) * 100, 2
                );
            // trace([$portfolioXirrAmountsArr, $portfolioXirrDatesArr, $portfolio['xirr']]);
        } else {
            $portfolio['xirr'] = 0;
        }

        // $portfolio['total_value'] = round($portfolio['total_value'] + $sellTotal, 2);

        return $portfolio;
    }

    protected function calculateTransactionUnitsAndValues(&$transaction, $update = false, $transactionDate = null, $sellTransactionData = null)
    {
        $schemesPackage = $this->usepackage(MfSchemes::class);

        $scheme = $schemesPackage->getSchemeById((int) $transaction['scheme_id']);

        if ($scheme) {
            $transaction['amfi_code'] = $scheme['amfi_code'];

            if ($scheme['navs'] && isset($scheme['navs']['navs'][$transaction['date']])) {
                $units = $transaction['amount'] / $scheme['navs']['navs'][$transaction['date']]['nav'];

                if ($transaction['type'] === 'buy') {
                    $transaction['units_bought'] = (float) round($units, 3);
                }

                if (!isset($transaction['units_sold'])) {
                    $transaction['units_sold'] = 0;
                }

                $transaction['returns'] = $this->calculateTransactionReturns($scheme, $transaction, $update, $transactionDate, $sellTransactionData);

                //We calculate the total number of units for latest_value
                if ($transactionDate) {
                    if (isset($scheme['navs']['navs'][$transactionDate])) {
                        $lastTransactionNav = $scheme['navs']['navs'][$transactionDate];
                    } else {
                        $lastTransactionNav = false;
                    }
                } else {
                    $lastTransactionNav = $this->helper->last($scheme['navs']['navs']);
                }

                if ($transaction['type'] === 'buy') {
                    if ($transactionDate) {
                        $totalUnits = round($transaction['units_bought'], 3);
                        if ($transaction['transactions'] && count($transaction['transactions']) > 0) {
                            foreach ($transaction['transactions'] as $soldTransaction) {
                                if ($transactionDate === $soldTransaction['date']) {
                                    $totalUnits = round($transaction['units_bought'] - $soldTransaction['units'], 3);

                                    break;
                                }
                            }
                        }
                    } else {
                        $totalUnits = round($transaction['units_bought'] - $transaction['units_sold'], 3);
                    }

                    if ($transaction['date_closed'] && !$transactionDate) {
                        $lastTransactionNav = $scheme['navs']['navs'][$transaction['date_closed']];
                    }
                } else {
                    $totalUnits = $transaction['units_sold'];
                }

                if ($lastTransactionNav) {
                    $latestNav = $lastTransactionNav;

                    $transaction['latest_value_date'] = $latestNav['date'];
                    $transaction['latest_value'] = round($latestNav['nav'] * $totalUnits, 2);
                } else {
                    $transaction['latest_value_date'] = 0;
                    $transaction['latest_value'] = 0;
                }

                return $transaction;
            }
        }

        return false;
    }

    public function calculateTransactionReturns($scheme, $transaction, $update = false, $transactionDate = null, $sellTransactionData = null)
    {
        if (!$transactionDate && $transaction['status'] === 'close' && isset($transaction['returns']) && count($transaction['returns']) > 0) {
            return $transaction['returns'];
        }

        if (!isset($transaction['returns']) || $update || $transactionDate) {
            $transaction['returns'] = [];
        }

        if ($transactionDate) {
            $units = round($transaction['units_bought'], 3);
            if ($transaction['transactions'] && count($transaction['transactions']) > 0) {
                foreach ($transaction['transactions'] as $soldTransaction) {
                    if ($transactionDate === $soldTransaction['date']) {
                        $units = round($transaction['units_bought'] - $soldTransaction['units'], 3);

                        break;
                    }
                }
            }
        } else {
            $units = round($transaction['units_bought'] - $transaction['units_sold'], 3);
        }

        if ($units < 0) {
            $units = 0;
        }

        $navs = $scheme['navs']['navs'];

        $navsKeys = array_keys($navs);

        if ($transactionDate) {
            $transactionDateKey = array_search($transactionDate, $navsKeys);
        } else {
            $transactionDateKey = array_search($transaction['date'], $navsKeys);
        }

        $navsToProcess = [];

        if ($transactionDate) {
                if ($transactionDate === '2025-03-22') {
                    // trace([$transactionDateKey, array_reverse($navs, true)]);
                }
            $navs = array_slice($navs, $transactionDateKey, 1);

            $navsToProcess[$this->helper->firstKey($navs)] = $this->helper->first($navs);
        } else {
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

            if ($transaction['type'] === 'buy') {
                $transaction['returns'][$nav['date']]['units'] = $units;
                $transaction['returns'][$nav['date']]['total_return'] = round($nav['nav'] * $transaction['units_bought'], 2);
                $return = $transaction['returns'][$nav['date']]['calculated_return'] = round($nav['nav'] * $units, 2);//Units bought - units sold
                $transaction['returns'][$nav['date']]['diff'] = round($return - $transaction['amount'], 2);

                if ($nav['date'] === $transaction['date_closed']) {
                    break;
                }
            } else {
                if ($transactionDate) {
                    $transaction['returns'][$nav['date']]['units'] = $units;
                } else {
                    $transaction['returns'][$nav['date']]['units'] = $transaction['units_sold'];
                }
                $transaction['returns'][$nav['date']]['total_return'] = round((float) $transaction['amount'], 2);
                $return = $transaction['returns'][$nav['date']]['calculated_return'] = round((float) $transaction['amount'], 2);
                $transaction['returns'][$nav['date']]['diff'] = 0;
            }
        }

        $transaction['returns'] = msort(array: $transaction['returns'], key: 'date', preserveKey: true);

        return $transaction['returns'];
    }
}