<?php

class AvardaLog extends ObjectModel
{
    /**
     * @var string
     */
    public $name = null;

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
        'table' => 'avarda_log',
        'primary' => 'id_avarda_log',
        'fields' => array(
            'name' => array('type' => self::TYPE_STRING, 'size' => 255, 'required' => true),
            'data' => array('type' => self::TYPE_STRING, 'required' => true),
            'date_add' => array('type' => self::TYPE_DATE, 'required' => true),
        ),
    );

    /**
     * @var array
     */
    protected static $staticData = array();

    /**
     * @param string $name
     */
    public static function createLog($name)
    {
        if (!isset(self::$staticData[$name = (string)$name])) {
            self::$staticData[$name] = array();
        }
    }

    /**
     * @param string $name
     * @param array $data
     */
    public static function addDataToLog($name, array $data)
    {
        if (isset(self::$staticData[$name = (string)$name])) {
            self::$staticData[$name] = array_merge(
                self::$staticData[$name],
                $data
            );
        }
    }

    /**
     * @param string $name
     *
     * @return array
     */
    public static function getDataFromLog($name)
    {
        if (!isset(self::$staticData[$name = (string)$name])) {
            return array();
        }

        return self::$staticData[$name];
    }

    /**
     * @param string $name
     * @param array $data
     *
     * @return bool
     */
    public static function addLog($name, array $data)
    {
        if (!$data) {
            return false;
        }

        return (new AvardaLog())
            ->setName($name)
            ->setData($data)
            ->setDateAddToCurrent()
            ->save();
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    public static function saveLog($name)
    {
        if (!isset(self::$staticData[$name = (string)$name])) {
            return false;
        }

        return self::addLog($name, self::$staticData[$name]);
    }

    /**
     * @param string $name
     */
    public static function clearLog($name)
    {
        if (isset(self::$staticData[$name = (string)$name])) {
            unset(self::$staticData[$name]);
        }
    }

    /**
     * @param int $minId
     * @param int $maxId
     * @param string $dateFrom
     * @param string $dateTo
     *
     * @return array
     */
    public static function getLogs($minId = 0, $maxId = 0, $dateFrom = '', $dateTo = '')
    {
        $query = (new DbQuery())
            ->select('*')
            ->from('avarda_log', 'al')
            ->orderBy('al.id_avarda_log ASC');

        if ($minId) {
            $query->where('al.id_avarda_log >= ' . (int)$minId);
        }

        if ($maxId) {
            $query->where('al.id_avarda_log <= ' . (int)$maxId);
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

    /**
     * @param string $name
     *
     * @return $this
     */
    public function setName($name)
    {
        $this->name = (string)$name;

        return $this;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param array $data
     *
     * @return $this
     */
    public function setData(array $data)
    {
        $this->data = json_encode($data);

        return $this;
    }

    /**
     * @return array
     */
    public function getData()
    {
        if (is_array($data = @json_decode($this->data, true))) {
            return $data;
        }

        return array();
    }

    /**
     * @return $this
     */
    public function setDateAddToCurrent()
    {
        $this->date_add = date('Y-m-d H:i:s');

        return $this;
    }

    /**
     * @return string
     */
    public function getDateAdd()
    {
        return $this->date_add;
    }
}
