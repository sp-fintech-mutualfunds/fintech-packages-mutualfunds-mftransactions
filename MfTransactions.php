<?php

namespace Apps\Fintech\Packages\Mf\Transactions;

use Apps\Fintech\Packages\Accounts\Transactions\Model\AppsFintechMfTransactions;
use System\Base\BasePackage;

class MfTransactions extends BasePackage
{
    protected $modelToUse = AppsFintechMfTransactions::class;

    protected $packageName = 'mftransactions';

    public $mftransactions;

    public function getAccountsBalancesById($id)
    {
        $mftransactions = $this->getById($id);

        if ($mftransactions) {
            //
            $this->addResponse('Success');

            return;
        }

        $this->addResponse('Error', 1);
    }

    public function addAccountsBalances($data)
    {
        //
    }

    public function updateAccountsBalances($data)
    {
        $mftransactions = $this->getById($id);

        if ($mftransactions) {
            //
            $this->addResponse('Success');

            return;
        }

        $this->addResponse('Error', 1);
    }

    public function removeAccountsBalances($data)
    {
        $mftransactions = $this->getById($id);

        if ($mftransactions) {
            //
            $this->addResponse('Success');

            return;
        }

        $this->addResponse('Error', 1);
    }
}