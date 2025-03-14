<?php

namespace Apps\Fintech\Packages\Mf\Transactions;

use Apps\Fintech\Packages\Accounts\Balances\AccountsBalances;
use Apps\Fintech\Packages\Mf\Portfolios\Model\AppsFintechMfPortfolios;
use Apps\Fintech\Packages\Mf\Transactions\Model\AppsFintechMfTransactions;
use System\Base\BasePackage;

class MfTransactions extends BasePackage
{
    protected $modelToUse = AppsFintechMfTransactions::class;

    protected $packageName = 'mftransactions';

    public $mftransactions;

    public function getMfTransactionById($id)
    {
        $mftransactions = $this->getById($id);

        if ($mftransactions) {
            //
            $this->addResponse('Success');

            return;
        }

        $this->addResponse('Error', 1);
    }

    public function addMfTransaction($data)
    {
        $data['account_id'] = $this->access->auth->account()['id'];

        if ($data['type'] === 'debit_fund' || $data['type'] === 'credit_fund') {
            $UsersBalancePackage = $this->usePackage(AccountsBalances::class);

            if ($data['type'] === 'debit_fund') {
                $userEquity = $UsersBalancePackage->getUserEquity($data);

                if ($userEquity !== false) {
                    if ((float) $data['amount'] <= $userEquity) {
                        if ($this->add($data)) {
                            $data['type'] = 'credit';

                            $data['details'] = 'Added via Portfolio ID:' . $data['portfolio_id'] . '. Transaction ID: ' . $this->packagesData->last['id'] . '.';

                            $UsersBalancePackage->addAccountsBalances($data);

                            $this->recalculatePortfolioTransactions($data);

                            $this->addResponse('Ok', 0);

                            return true;
                        }
                        $this->addResponse('Error adding information, contact developer', 1);

                        return false;
                    }

                    $this->addResponse('User does not have enough equity. Current balance is : ' . $userEquity, 1);

                    return false;
                }
            } else if ($data['type'] === 'credit_fund') {
                $amounts = $this->recalculatePortfolioTransactions($data);

                if ((float) $data['amount'] <= $amounts['equity_balance']) {
                    if ($this->add($data)) {
                        $data['type'] = 'debit';

                        $data['details'] = 'Added via Portfolio ID:' . $data['portfolio_id'] . '. Transaction ID: ' . $this->packagesData->last['id'] . '.';

                        $UsersBalancePackage->addAccountsBalances($data);

                        $this->recalculatePortfolioTransactions($data);

                        $this->addResponse('Ok', 0);

                        return true;
                    }

                    $this->addResponse('Error adding information, contact developer', 1);

                    return false;
                }

                $this->addResponse('Portfolio does not have enough equity. Current balance is : ' . $amounts['equity_balance'], 1);

                return false;

            }

            $this->addResponse('Cannot obtain user equity information. Contact developer!', 1);

            return false;
        }
    }

    public function updateMfTransaction($data)
    {
        $mftransactions = $this->getById($id);

        if ($mftransactions) {
            //
            $this->addResponse('Success');

            return;
        }

        $this->addResponse('Error', 1);
    }

    public function removeMfTransaction($data)
    {
        $mftransactions = $this->getById($id);

        if ($mftransactions) {
            //
            $this->addResponse('Success');

            return;
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

        $debitTotal = 0;
        $creditTotal = 0;
        $buyTotal = 0;
        $sellTotal = 0;

        if ($transactions && count($transactions) > 0) {
            foreach ($transactions as $transaction) {
                if ($transaction['type'] === 'debit_fund') {
                    $debitTotal = $debitTotal + $transaction['amount'];
                } else if ($transaction['type'] === 'credit_fund') {
                    $creditTotal = $creditTotal + $transaction['amount'];
                } else if ($transaction['type'] === 'buy') {
                    $buyTotal = $buyTotal + $transaction['amount'];
                } else if ($transaction['type'] === 'sell') {
                    $sellTotal = $sellTotal + $transaction['amount'];
                }
            }
        }

        $creditTotal = $creditTotal + $buyTotal;
        $debitTotal = $debitTotal + $sellTotal;
        $equityTotal = $debitTotal - $creditTotal;
        $investedAmountTotal = $buyTotal - $sellTotal;
        // $profitLossTotal = $sellTotal - $buyTotal; This needs to be calculated correctly
        // $totalValue = This needs to be calculated correctly

        $portfolioModel = new AppsFintechMfPortfolios;

        if ($this->config->databasetype === 'db') {
            $portfolio = $portfolioModel::findFirst(['id = ' . (int) $data['portfolio_id']]);
        } else {
            $portfoliosStore = $this->ff->store($portfolioModel->getSource());

            $portfolio = $portfoliosStore->findOneBy(['id', '=', (int) $data['portfolio_id']]);
        }

        if ($portfolio) {
            $portfolio['equity_balance'] = $equityTotal;
            $portfolio['invested_amount'] = $investedAmountTotal;
            // $portfolio['total_value'] = ;
            // $portfolio['profit_loss'] = ;

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
                                'equity_balance' => str_replace('EN_ ',
                                                                '',
                                                                (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                                    ->formatCurrency($portfolio['equity_balance'], 'en_IN')
                                                                ),
                                'invested_amount' => str_replace('EN_ ',
                                                                '',
                                                                (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                                                    ->formatCurrency($portfolio['invested_amount'], 'en_IN')
                                                                ),
                                // 'total_value' => str_replace('EN_ ',
                                //                                 '',
                                //                                 (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                //                                     ->formatCurrency($portfolio['total_value'], 'en_IN')
                                //                                 ),
                                // 'profit_loss' => str_replace('EN_ ',
                                //                                 '',
                                //                                 (new \NumberFormatter('en_IN', \NumberFormatter::CURRENCY))
                                //                                     ->formatCurrency($portfolio['profit_loss'], 'en_IN')
                                //                                 )
                            ]
        );

        return [
            'equity_balance' => $portfolio['equity_balance'],
            'invested_amount' => $portfolio['invested_amount'],
            'total_value' => $portfolio['total_value'],
            'profit_loss' => $portfolio['profit_loss'],
        ];
    }
}