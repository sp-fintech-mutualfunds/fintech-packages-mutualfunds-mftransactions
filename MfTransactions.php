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
                $portfolio['transactions'] = msort($portfolio['transactions'], 'date');

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
        $xirrDatesArr = [];
        $xirrAmountsArr = [];

        //Re arrange with ID as Key.
        $transactions = [];
        if ($transactionsArr && count($transactionsArr) > 0) {
            $transactionsArr = msort($transactionsArr, 'date');

            foreach ($transactionsArr as $transaction) {
                $transactions[$transaction['id']] = $transaction;
            }
        }

        if (isset($data['timelinemode']) && $data['timelinemode'] == true) {
            $portfolioTimeline = [];
        }

        $portfolioPackage = $this->usepackage(MfPortfolios::class);
        $portfolio = $portfolioPackage->getById((int) $data['portfolio_id']);

        $soldUnits = [];
        if ($transactions && count($transactions) > 0) {
            foreach ($transactions as $transactionId => &$transaction) {
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

                        if (isset($data['timelinemode']) && $data['timelinemode'] == true) {
                            $this->calculateTransactionUnitsAndValues($transaction, false, true);
                        } else {
                            $this->calculateTransactionUnitsAndValues($transaction, false, false, $data);
                        }

                        $yearsDiff = floor((\Carbon\Carbon::parse($transaction['date']))->diffInYears(\Carbon\Carbon::parse($transaction['latest_value_date'])));
                        if ($yearsDiff == 0) {
                            $yearsDiff = 1;
                        }

                        if ($transaction['latest_value'] == 0) {
                            $transaction['cagr'] = 0;
                            $transaction['diff'] = 0;
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
                                $cagr = $transaction['latest_value'] / ($scheme['navs']['navs'][$transaction['date']]['nav'] * $totalUnits);
                            } else {
                                $diff = $transaction['latest_value'] - $transaction['amount'];
                                $cagr = $transaction['latest_value'] / $transaction['amount'];
                            }

                            $transaction['diff'] = round($diff, 2);
                            $transaction['cagr'] = round((pow(($cagr), (1 / $yearsDiff)) - 1) * 100, 2);
                        }

                        $this->update($transaction);

                        $totalValue = $totalValue + $transaction['latest_value'];

                        array_push($xirrDatesArr, $this->helper->first($transaction['returns'])['date']);
                        array_push($xirrAmountsArr, round(-$transaction['amount'], 2));
                    }
                } else if ($transaction['type'] === 'sell') {
                    $sellTotal = $sellTotal + $transaction['amount'];

                    if (isset($transaction['buy_transactions']) && count($transaction['buy_transactions']) > 0) {
                        foreach ($transaction['buy_transactions'] as $buyTransactionId => $buyTransaction) {
                            if (isset($soldUnits[$buyTransactionId])) {
                                $soldUnits[$buyTransactionId] += $buyTransaction['units'];
                            } else {
                                $soldUnits[$buyTransactionId] = $buyTransaction['units'];
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
                        if (isset($data['timelinemode']) && $data['timelinemode'] == true) {
                            $this->calculateTransactionUnitsAndValues($transaction, false, true);
                        } else {
                            $this->calculateTransactionUnitsAndValues($transaction, false, false, $data);
                        }
                    }

                    $this->update($transaction);

                    $totalValue = $totalValue + $transaction['amount'];

                    array_push($xirrDatesArr, $this->helper->first($transaction['returns'])['date']);
                    array_push($xirrAmountsArr, round($transaction['amount'], 2));
                }

                if (isset($data['timelinemode']) && $data['timelinemode'] == true) {
                    if ($transaction['returns'] && $transaction['returns'] > 0) {
                        foreach ($transaction['returns'] as $returnDate => $return) {
                            if (!isset(($portfolioTimeline[$returnDate]))) {
                                trace([$portfolioTimeline, $return]);

                            } else {
                                if ($transaction['type'] === 'sell') {
                                    // trace([$portfolioTimeline[$returnDate], $return, $transaction]);
                                    $portfolioTimeline[$returnDate]['units'] =
                                        number_format($portfolioTimeline[$returnDate]['units'] - $transaction['units_sold'], 3, '.', '');
                                    $portfolioTimeline[$returnDate]['total_return'] =
                                        round((float) $portfolioTimeline[$returnDate]['total_return'] - (float) $transaction['amount'], 2);
                                    $portfolioTimeline[$returnDate]['calculated_return'] =
                                        round((float) $portfolioTimeline[$returnDate]['calculated_return'] - (float) $transaction['amount'], 2);
                                    $portfolioTimeline[$returnDate]['diff'] =
                                        round((float) $portfolioTimeline[$returnDate]['diff'] - (float) $return['diff'], 2);
                                    $portfolioTimeline[$returnDate]['cagr'] =
                                        round((float) $portfolioTimeline[$returnDate]['cagr'] - (float) $return['cagr'], 2);
                                    // trace([$returnDate, $portfolioTimeline[$returnDate]]);
                                } else {
                                    $portfolioTimeline[$returnDate]['units'] =
                                        number_format($portfolioTimeline[$returnDate]['units'] + $return['units'], 3, '.', '');
                                    $portfolioTimeline[$returnDate]['total_return'] =
                                        round((float) $portfolioTimeline[$returnDate]['total_return'] + (float) $return['total_return'], 2);
                                    $portfolioTimeline[$returnDate]['calculated_return'] =
                                        round((float) $portfolioTimeline[$returnDate]['calculated_return'] + (float) $return['calculated_return'], 2);
                                    $portfolioTimeline[$returnDate]['diff'] =
                                        round((float) $portfolioTimeline[$returnDate]['diff'] + (float) $return['diff'], 2);
                                    $portfolioTimeline[$returnDate]['cagr'] =
                                        round((float) $portfolioTimeline[$returnDate]['cagr'] + (float) $return['cagr'], 2);
                                }
                            }

                            if (count($xirrDatesArr) > 0) {
                                $timelineXirrDatesArr = $xirrDatesArr;
                                $timelineXirrAmountsArr = $xirrAmountsArr;

                                array_push($timelineXirrDatesArr, $return['date']);
                                array_push($timelineXirrAmountsArr, $portfolioTimeline[$returnDate]['calculated_return']);

                                $timelineXirrDatesArr = array_reverse($timelineXirrDatesArr, true);
                                $timelineXirrAmountsArr = array_reverse($timelineXirrAmountsArr, true);

                                try {
                                    $portfolioTimeline[$returnDate]['xirr'] =
                                        round(
                                            (float) \PhpOffice\PhpSpreadsheet\Calculation\Financial\CashFlow\Variable\NonPeriodic::rate(
                                                array_values($timelineXirrAmountsArr),
                                                array_values($timelineXirrDatesArr)
                                            ) * 100, 2
                                        );
                                } catch (\throwable $e) {
                                    $portfolioTimeline[$returnDate]['xirr'] = 0;
                                }
                            } else {
                                $portfolioTimeline[$returnDate]['xirr'] = 0;
                            }
                        }
                    }
                }
            }
        }

        if (count($soldUnits) > 0) {
            foreach ($soldUnits as $transactionId => $soldUnit) {
                if (isset($transactions[$transactionId])) {
                    $transactions[$transactionId]['units_sold'] = $soldUnit;

                    $this->update($transactions[$transactionId]);
                }
            }
        }

        if ($portfolio) {
            $portfolio['invested_amount'] = $buyTotal - $sellTotal;
            if ($portfolio['invested_amount'] < 0) {
                $portfolio['invested_amount'] = 0;
            }
            // trace([$totalValue, $buyTotal, $sellTotal]);
            if ($sellTotal > 0) {
                $portfolio['profit_loss'] = round($totalValue, 2);
                if ($sellTotal > $buyTotal) {
                    $portfolio['profit_loss'] = abs($portfolio['profit_loss']);
                } else {
                    $portfolio['profit_loss'] = round($totalValue - $buyTotal, 2);
                }
                if ($portfolio['invested_amount'] === 0) {
                    $portfolio['total_value'] = round($sellTotal, 2);

                    $portfolio['profit_loss'] = round($sellTotal - $buyTotal, 2);
                } else {
                    $portfolio['total_value'] = round($totalValue, 2);
                }
            } else {
                $portfolio['profit_loss'] = round($totalValue - $portfolio['invested_amount'], 2);

                $portfolio['total_value'] = round($buyTotal + $portfolio['profit_loss'], 2);
            }
            // trace([$totalValue, $portfolio['profit_loss'], $portfolio['total_value'], $buyTotal, $sellTotal, $portfolio['invested_amount']]);
            if (count($xirrDatesArr) > 0) {
                if ($portfolio['invested_amount'] !== 0) {
                    array_push($xirrDatesArr, $this->today);
                    if ($sellTotal > 0) {
                        array_push($xirrAmountsArr, $totalValue - $sellTotal);
                    } else {
                        array_push($xirrAmountsArr, $portfolio['total_value'] - $portfolio['invested_amount']);
                    }
                }

                $xirrDatesArr = array_reverse($xirrDatesArr, true);
                $xirrAmountsArr = array_reverse($xirrAmountsArr, true);
                // trace([$xirrDatesArr, $xirrAmountsArr]);
                $portfolio['xirr'] =
                    round(
                        (float) \PhpOffice\PhpSpreadsheet\Calculation\Financial\CashFlow\Variable\NonPeriodic::rate(
                            array_values($xirrAmountsArr),
                            array_values($xirrDatesArr)
                        ) * 100, 2
                    );
                // trace([$xirrAmountsArr, $xirrDatesArr, $portfolio['xirr']]);
            } else {
                $portfolio['xirr'] = 0;
            }

            // $portfolio['total_value'] = round($portfolio['total_value'] + $sellTotal, 2);

            if (isset($data['timelinemode']) && $data['timelinemode'] == true) {
                $portfolio['timeline'] = $portfolioTimeline;
                $portfolio['recalculate_timeline'] = false;
            }

            $portfolioPackage->update($portfolio);
        }

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

    protected function calculateTransactionUnitsAndValues(&$transaction, $update = false, $timeline = false, $sellTransactionData = null)
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

                $transaction['returns'] = $this->calculateTransactionReturns($scheme, $transaction, $update, $timeline, $sellTransactionData);

                //We calculate the total number of units for latest_value
                if ($transaction['type'] === 'buy') {
                    $totalUnits = round($transaction['units_bought'] - $transaction['units_sold'], 3);
                } else {
                    $totalUnits = $transaction['units_sold'];
                }

                $latestNav = $this->helper->last($scheme['navs']['navs']);

                $transaction['latest_value_date'] = $latestNav['date'];
                $transaction['latest_value'] = round($latestNav['nav'] * $totalUnits, 2);


                return $transaction;
            }
        }

        return false;
    }

    public function calculateTransactionReturns($scheme, $transaction, $update = false, $timeline = false, $sellTransactionData = null)
    {
        if ($transaction['status'] === 'close' && isset($transaction['returns']) && count($transaction['returns']) > 0) {
            return $transaction['returns'];
        }

        if (!isset($transaction['returns']) || $update) {
            $transaction['returns'] = [];
        }

        $units = round($transaction['units_bought'] - $transaction['units_sold'], 3);

        if ($units < 0) {
            $units = 0;
        }

        $navs = $scheme['navs']['navs'];

        $navsKeys = array_keys($navs);

        $transactionDateKey = array_search($transaction['date'], $navsKeys);

        $navs = array_slice($navs, $transactionDateKey);
        $navsToProcess = [];

        if ($timeline) {
            $navsToProcess = $navs;
        } else {
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
                $diff = $return - $transaction['amount'];
                $transaction['returns'][$nav['date']]['diff'] = round($diff, 2);
                $yearsDiff = floor((\Carbon\Carbon::parse($transaction['date']))->diffInYears(\Carbon\Carbon::parse($nav['date'])));
                if ($yearsDiff == 0) {
                    $yearsDiff = 1;
                }

                $cagr = ($nav['nav'] * $units) / $transaction['amount'];
                $transaction['returns'][$nav['date']]['cagr'] = round((pow(($cagr), (1 / $yearsDiff)) - 1) * 100, 2);
            } else {
                $transaction['returns'][$nav['date']]['units'] = $transaction['units_sold'];
                $transaction['returns'][$nav['date']]['total_return'] = round((float) $transaction['amount'], 2);
                $return = $transaction['returns'][$nav['date']]['calculated_return'] = round((float) $transaction['amount'], 2);
                $transaction['returns'][$nav['date']]['diff'] = 0;
                $transaction['returns'][$nav['date']]['cagr'] = 0;
            }
        }

        $transaction['returns'] = msort($transaction['returns'], 'date');

        return $transaction['returns'];
    }
}