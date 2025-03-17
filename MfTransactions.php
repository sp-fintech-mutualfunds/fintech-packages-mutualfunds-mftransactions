<?php

namespace Apps\Fintech\Packages\Mf\Transactions;

use Apps\Fintech\Packages\Accounts\Balances\AccountsBalances;
use Apps\Fintech\Packages\Mf\Portfolios\MfPortfolios;
use Apps\Fintech\Packages\Mf\Portfolios\Model\AppsFintechMfPortfolios;
use Apps\Fintech\Packages\Mf\Schemes\MfSchemes;
use Apps\Fintech\Packages\Mf\Transactions\Financial;
use Apps\Fintech\Packages\Mf\Transactions\Model\AppsFintechMfTransactions;
use System\Base\BasePackage;

class MfTransactions extends BasePackage
{
    protected $modelToUse = AppsFintechMfTransactions::class;

    protected $packageName = 'mftransactions';

    public $mftransactions;

    public $financialClass;

    public function init()
    {
        $this->financialClass = new Financial;

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

            $canSellTransactions = [];

            $portfolioPackage = new MfPortfolios;

            $portfolio = $portfolioPackage->getPortfolioById((int) $data['portfolio_id']);

            if ($portfolio && $portfolio['transactions'] && count($portfolio['transactions']) > 0) {
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
                        round($canSellTransactions[$transaction['amfi_code']]['available_units'] * $canSellTransactions[$transaction['amfi_code']]['latest_value'], 2);
                }

                if (isset($canSellTransactions[$data['amfi_code']])) {
                    if (isset($data['amount'])) {
                        if ((float) $data['amount'] > $canSellTransactions[$data['amfi_code']]['available_amount']) {
                            $this->addResponse('Amount exceeds from available amount', 1);

                            return false;
                        }
                        //Convert from $data['amount'] to $data['units']
                        $data['units'] = round($data['amount'] / $scheme['navs']['navs'][$data['date']]['nav'], 3);
                    } else if (isset($data['units'])) {
                        if ((float) $data['units'] > $canSellTransactions[$data['amfi_code']]['available_units']) {
                            $this->addResponse('Units exceeds from available units', 1);

                            return false;
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

                                if (isset($data['sell_all']) && $data['sell_all'] == true) {
                                    $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                    $buyTransactions[$transaction['id']]['units'] = round($availableUnits, 3);
                                    $buyTransactions[$transaction['id']]['value'] = round($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);

                                    $transaction['units_sold'] = $transaction['units_bought'];

                                    $transaction['status'] = 'close';
                                } else {
                                    if ($availableUnits < $unitsToProcess) {
                                        $transaction['units_sold'] = $transaction['units_bought'];

                                        $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                        $buyTransactions[$transaction['id']]['units'] = round($availableUnits, 3);
                                        $buyTransactions[$transaction['id']]['value'] = round($availableUnits * $transaction['returns'][$data['date']]['nav'], 2);

                                        $transaction['status'] = 'close';
                                    } else if ($availableUnits > $unitsToProcess) {
                                        $transaction['units_sold'] = round($unitsToProcess, 3);

                                        $buyTransactions[$transaction['id']]['date'] = $transaction['date'];
                                        $buyTransactions[$transaction['id']]['units'] = round($unitsToProcess, 3);
                                        $buyTransactions[$transaction['id']]['value'] = round($unitsToProcess * $transaction['returns'][$data['date']]['nav'], 2);
                                    }

                                    $unitsToProcess = $unitsToProcess - $availableUnits;
                                }

                                $this->update($transaction);
                            }
                        }

                        $data['buy_transactions'] = $buyTransactions;

                        if ($this->update($data)) {
                            $this->recalculatePortfolioTransactions($data);

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
        $mfTransactions = $this->getById($data['id']);

        if ($mfTransactions) {
            $mfTransactions['date'] = $data['date'];
            $mfTransactions['amount'] = $data['amount'];
            $mfTransactions['details'] = $data['details'];

            if ($this->update($mfTransactions)) {
                $this->addResponse('Updated transaction');

                return;
            }
        }

        $this->addResponse('Error updating transaction.', 1);
    }

    public function removeMfTransaction($data)
    {
        $mfTransactions = $this->getById($data['id']);

        if ($mfTransactions) {
            if ($mfTransactions['type'] === 'buy') {
                if ($mfTransactions['status'] !== 'open') {
                    $this->addResponse('Transaction is being used by other transactions. Cannot remove', 1);

                    return false;
                }

                if ($this->remove($mfTransactions['id'])) {
                    $this->recalculatePortfolioTransactions(['portfolio_id' => $mfTransactions['portfolio_id']]);

                    $this->addResponse('Transaction removed');

                    return true;
                }
            } else if ($mfTransactions['type'] === 'sell') {
                //
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
        $xirrArr = [];

        //Re arrange with ID as Key.
        $transactions = [];
        if ($transactionsArr && count($transactionsArr) > 0) {
            foreach ($transactionsArr as $transaction) {
                $transactions[$transaction['id']] = $transaction;
            }
        }

        $soldUnits = [];
        if ($transactions && count($transactions) > 0) {
            foreach ($transactions as $transactionId => $transaction) {
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
                        $this->calculateTransactionUnitsAndValues($transaction);

                        // $transaction['returns'] = $this->calculateTransactionReturns($scheme, $transaction);
                        $yearsDiff = (\Carbon\Carbon::parse($transaction['date']))->diffInYears(\Carbon\Carbon::parse($transaction['latest_value_date']));
                        $transaction['cagr'] = number_format((pow(($transaction['latest_value'] / $transaction['amount']), (1 / $yearsDiff)) - 1) * 100, 2, '.', '');
                        $transaction['diff'] = number_format($transaction['latest_value'] - $transaction['amount'], 2, '.', '');
                        $this->update($transaction);

                        $totalValue = $totalValue + $transaction['latest_value'];
                        if (isset($xirrArr[$this->helper->first($transaction['returns'])['timestamp']])) {
                            $xirrArr[$this->helper->first($transaction['returns'])['timestamp']] +=
                                round(-$this->helper->first($transaction['returns'])['return']);
                        } else {
                            $xirrArr[$this->helper->first($transaction['returns'])['timestamp']] = round(-$this->helper->first($transaction['returns'])['return']);
                        }
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

        $investedAmountTotal = $buyTotal - $sellTotal;
        if ($investedAmountTotal < 0) {
            $investedAmountTotal = 0;
        }

        $portfolioModel = new AppsFintechMfPortfolios;

        if ($this->config->databasetype === 'db') {
            $portfolio = $portfolioModel::findFirst(['id = ' . (int) $data['portfolio_id']]);
        } else {
            $portfoliosStore = $this->ff->store($portfolioModel->getSource());

            $portfolio = $portfoliosStore->findOneBy(['id', '=', (int) $data['portfolio_id']]);
        }

        if ($portfolio) {
            $portfolio['invested_amount'] = $investedAmountTotal;
            $portfolio['total_value'] = $totalValue;

            if (count($xirrArr) > 0) {
                $xirrArr = array_reverse($xirrArr, true);
                $xirrArr[(\Carbon\Carbon::now())->timestamp] = round($portfolio['total_value']);

                $portfolio['xirr'] = number_format($this->financialClass->XIRR(array_values($xirrArr), array_keys($xirrArr)) * 100, 2, '.', '');
            }

            if ($this->config->databasetype === 'db') {
                $portfolioModel->assign($portfolio);

                $portfolioModel->update();
            } else {
                $portfoliosStore->update($portfolio);
            }
        }

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
                                                                    ->formatCurrency($totalValue, 'en_IN')
                                                                ),
                                'xirr' => $portfolio['xirr']
                            ]
        );

        return [
            'invested_amount' => $portfolio['invested_amount'],
            'total_value' => $totalValue,
            'xir' => $portfolio['xirr']
        ];
    }

    protected function calculateTransactionUnitsAndValues(&$transaction)
    {
        $schemesPackage = $this->usepackage(MfSchemes::class);

        $scheme = $schemesPackage->getSchemeById((int) $transaction['scheme_id']);

        if ($scheme) {
            $transaction['amfi_code'] = $scheme['amfi_code'];

            if ($scheme['navs'] && isset($scheme['navs']['navs'][$transaction['date']])) {
                $units = $transaction['amount'] / $scheme['navs']['navs'][$transaction['date']]['nav'];
                $transaction['units_bought'] = (float) number_format(floor($units * pow(10, 3)) / pow(10, 3), 3, '.', '');
                if (!isset($transaction['units_sold'])) {
                    $transaction['units_sold'] = 0;
                }

                $latestNav = $this->helper->last($scheme['navs']['navs']);

                $transaction['latest_value_date'] = $latestNav['date'];
                $transaction['latest_value'] = round($latestNav['nav'] * $transaction['units_bought'], 2);

                $transaction['returns'] = $this->calculateTransactionReturns($scheme, $transaction);

                return $transaction;
            }
        }

        return false;
    }

    public function calculateTransactionReturns($scheme, $transaction)
    {
        if (!isset($transaction['returns'])) {
            $transaction['returns'] = [];
        }

        $units = $transaction['units_bought'] - $transaction['units_sold'];

        $navs = $scheme['navs']['navs'];

        $navsKeys = array_keys($navs);

        $transactionDateKey = array_search($transaction['date'], $navsKeys);

        $navs = array_slice($navs, $transactionDateKey);

        foreach ($navs as $nav) {
            if (!isset($transaction['returns'][$nav['date']])) {
                $transaction['returns'][$nav['date']] = [];
                $transaction['returns'][$nav['date']]['date'] = $nav['date'];
                $transaction['returns'][$nav['date']]['timestamp'] = $nav['timestamp'];
                $transaction['returns'][$nav['date']]['nav'] = $nav['nav'];
                $transaction['returns'][$nav['date']]['return'] = round($nav['nav'] * $units, 2);
            }
        }

        return $transaction['returns'];
    }
}