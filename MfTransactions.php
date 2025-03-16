<?php

namespace Apps\Fintech\Packages\Mf\Transactions;

use Apps\Fintech\Packages\Accounts\Balances\AccountsBalances;
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
            //
        }

        $this->addResponse('Cannot obtain user equity information. Contact developer!', 1);

        return false;
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
            if ($mfTransactions['type'] === 'debit' || $mfTransactions['type'] === 'credit') {
                $usersBalancePackage = $this->usePackage(AccountsBalances::class);

                $userBalance = $usersBalancePackage->getById($mfTransactions['tx_id']);

                if ($userBalance) {
                    if ($this->remove($mfTransactions['id'])) {
                        $usersBalancePackage->remove($userBalance['id']);
                    }
                }

                $amounts = $this->recalculatePortfolioTransactions(['portfolio_id' => $mfTransactions['portfolio_id']]);

                $this->addResponse('Cannot obtain user equity information. Contact developer!', 1);

                return false;
            }
        }

        $this->addResponse('Error', 1);
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

        $transactions = $this->getByParams($conditions);

        $schemesPackage = $this->usepackage(MfSchemes::class);

        $buyTotal = 0;
        $sellTotal = 0;
        $totalValue = 0;
        $xirrArr = [];

        if ($transactions && count($transactions) > 0) {
            foreach ($transactions as $transaction) {
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
                        $scheme = $schemesPackage->getSchemeById((int) $scheme[0]['id']);

                        $transaction['returns'] = $this->calculateTransactionReturns($scheme, $transaction);
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
                }
            }
        }

        $investedAmountTotal = $buyTotal - $sellTotal;

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

    protected function calculateTransactionUnitsAndValues(&$data)
    {
        $schemesPackage = $this->usepackage(MfSchemes::class);

        $scheme = $schemesPackage->getSchemeById((int) $data['scheme_id']);

        if ($scheme) {
            $data['amfi_code'] = $scheme['amfi_code'];

            if ($scheme['navs'] && isset($scheme['navs']['navs'][$data['date']])) {
                $units = $data['amount'] / $scheme['navs']['navs'][$data['date']]['nav'];

                $latestNav = $this->helper->last($scheme['navs']['navs']);

                $data['latest_value_date'] = $latestNav['date'];
                $data['latest_value'] = round($latestNav['nav'] * $units, 2);

                $data['units_bought'] = (float) number_format(floor($units * pow(10, 3)) / pow(10, 3), 3, '.', '');
                $data['units_sold'] = 0;
                $data['returns'] = $this->calculateTransactionReturns($scheme, $data);

                return true;
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
                $transaction['returns'][$nav['date']]['return'] = round($nav['nav'] * $units, 2);
            }
        }

        return $transaction['returns'];
    }
}