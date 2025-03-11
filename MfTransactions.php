<?php

namespace Apps\Fintech\Packages\Mf\Transactions;

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
        //
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
}