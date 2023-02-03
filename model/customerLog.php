<?php

class AvardaCustomerLog extends ObjectModel
{
    /**
     * @var int
     */
    public $id_customer = null;

    /**
     * @var string JSON
     */
    public $data = null;

    /**
     * @var string
     */
    public $date_add = null;

    /**
     * @var array
     */
    public static $definition = array(
        'table' => 'avarda_customer_log',
        'primary' => 'id_avarda_customer_log',
        'fields' => array(
            'id_customer' => array('type' => self::TYPE_INT, 'required' => true),
            'data' => array('type' => self::TYPE_STRING, 'required' => true),
            'date_add' => array('type' => self::TYPE_DATE, 'required' => true),
        ),
    );

    /**
     * @param Customer $customer
     * @param array $additionalData
     *
     * @return bool
     */
    public static function addLog(Customer $customer, array $additionalData = array())
    {
        $log = new AvardaCustomerLog();
        $log->id_customer = $customer->id;
        $log->date_add = date('Y-m-d H:i:s');

        $log->data = @json_encode(
            array_merge(
                array(
                    'customer' => array(
                        'id' => $customer->id,
                        'firstname' => $customer->firstname,
                        'lastname' => $customer->lastname,
                        'email' => $customer->email,
                    )
                ),
                $additionalData
            )
        );

        return $log->save();
    }

    /**
     * @param int $customerId
     * @param string $dateFrom
     * @param string $dateTo
     *
     * @return array
     */
    public static function getLogs($customerId = 0, $dateFrom = '', $dateTo = '')
    {
        $query = (new DbQuery())
            ->select('*')
            ->from('avarda_customer_log', 'acl')
            ->orderBy('acl.id_avarda_customer_log ASC');

        if ($customerId) {
            $query->where('acl.id_customer = ' . (int)$customerId);
        }

        if ($dateFrom) {
            $query->where('al.date_add >= \'' . $dateFrom . '\'');
        }

        if ($dateTo) {
            $query->where('al.date_add <= \'' . $dateTo . '\'');
        }

        if (is_array($result = Db::getInstance()->executeS($query->build()))) {
            return $result;
        }

        return array();
    }
}
