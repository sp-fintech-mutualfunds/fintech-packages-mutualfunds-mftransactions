<?php

namespace Apps\Fintech\Packages\Mf\Transactions\Model;

use System\Base\BaseModel;

class AppsFintechMfTransactions extends BaseModel
{
    public $id;

    public $account_id;

    public $user_id;

    public $portfolio_id;

    public $amfi_code;

    public $date;

    public $amount;

    public $type;//Purchase/Sale

    public $reference;

    public $tx_units;

    public $tx_id;
    //Open/Close - Example: If we purchase 10 units (tx_id: 1) we mark that status of the transaction open.
    //When we sell lets say 5 units, the units will be checked against all open tx_ids and using FIFO, we should close the tx_ids.
    public $tx_status;
}