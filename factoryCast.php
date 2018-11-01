<?php

/**
 * FactoryCast get data From Schneider PLC over SOAP/XML
 *
 * PHP version 7
 *
 * Example code :
 * $PLC = new factorycast('10.0.10.210');
 * var_dump($PLC->browse()); // return a array of all variable in the factorycast module namespace
 * var_dump($PLC->read()); // return a array of all variable value in the factorycast module namespace
 *
 * @category Schneider_Plc_Communication
 * @package  Factorycast
 * @author   Yann Challet <Yann@cymdev.com>
 * @license  GPL V3
 * @link     https://github.com/CymDeveloppement/phpSchneiderFactoryCast
 */

/**
 * FactoryCast class
 *
 * @category Schneider_Plc_Communication
 * @package  Factorycast
 * @author   Yann Challet <Yann@cymdev.com>
 * @license  GPL V3
 * @link     https://github.com/CymDeveloppement/phpSchneiderFactoryCast
 */

class Factorycast
{
    private $_plcUrl;
    private $_soapClientExtendedSymbolicXmlDa;
    private $_NbOfSymbol = 300; //Max 300 for browse
    private $_maxReadVar = 250; //Max 250 for Read
    private $_lastRequestTime = 0;
    private $_totalRequestTime = 0;
    private $_allReadVar = array();
    private $_allWriteVar = array();
    private $_allErrorVar = array();
    private $_lastErrorMessage;
    private $_lastRequestState;
    private $_allRequestState = true;

    /**
     * Factorycast object constructor
     *
     * @param string $plcIp PLC IP Adress
     *
     * @return void
     */
    public function __construct($plcIp)
    {
        $this->_plcUrl = 'http://'.$plcIp.'/ws/ExtendedSymbolicXmlDa?wsdl=soap12';
        $execTime = microtime(true);

        $this->_soapClientExtendedSymbolicXmlDa = new SoapClient(
            $this->_plcUrl,
            array('soap_version' => SOAP_1_2)
        );
        //
        //var_dump($this->_soapClientExtendedSymbolicXmlDa->__getTypes());
        $this->_saveRequestTime($execTime);
    }

    /**
     * Get the last SOAP request Time
     *
     * @return int time (millisecond)
     */
    public function getLastRequestTime()
    {
        return intval($this->_lastRequestTime*1000);
    }

    /**
     * Get total SOAP request Time
     *
     * @return int time (millisecond)
     */
    public function getTotalRequestTime()
    {
        return intval($this->_totalRequestTime*1000);
    }

    public function clearTotalRequestTime()
    {
        $this->_totalRequestTime = 0;
    }

    /**
     * Save request time
     *
     * @param float $startRequestTime microtime value before start SOAP request
     *
     * @return void
     */
    private function _saveRequestTime($startRequestTime)
    {
        $lastRequestTime = (microtime(true) - $startRequestTime);
        $this->_lastRequestTime = $lastRequestTime;
        $this->_totalRequestTime += $lastRequestTime;
    }

    /**
     * Trace variable error
     *
     * @param string $message SOAP error message
     *
     * @return void
     */
    private function _errorVar($message)
    {
        if (strpos($message, 'Application error:The symbol \'') !== false
            && strpos($message, 'is not present in the namespace') !== false
        ) {
            $Var = explode('\'', $message);
            $this->_allErrorVar[$Var[1]] = 'Not exist in the namespace';
        }
    }

    /**
     * Return last SOAP Error
     *
     * @return string Last Error text
     */
    public function getLastError()
    {
        return $this->_lastErrorMessage;
    }

    /**
     * Return a array of all var with error
     *
     * @return array
     */
    public function getAllErrorVar()
    {
        return $this->_allErrorVar;
    }

    /**
     * Return true if all request id ok or false
     *
     * @return bool
     */
    public function getAllRequestState()
    {
        return $this->_allRequestState;
    }

    /**
     * List accessible variable in the factory cast namespace
     *
     * @param int $nbOfVar number of variable want to retrieve
     *
     * @return array var list
     */
    public function browse($nbOfVar = 0)
    {
        $data = array();
        $start = 0;
        do {
            $response = $this->_browseSoap($start, $this->_NbOfSymbol);
            if (!is_null($response)) {
                $response = $response->BrowseResult->Description;
                $data = array_merge($data, $response);
                $start += $this->_NbOfSymbol;
            } else {
                break;
            }
        } while (count($response) == $this->_NbOfSymbol
                && (($nbOfVar > 0 && count($data) < $nbOfVar) || $nbOfVar == 0));
        return $data;
    }
    
    /**
     * SOAP request used by factorycast::browse function
     *
     * @param int $start offset start for list variable
     * @param int $nb    number of variable (maximum 250, factorycast limitation)
     *
     * @return array var list
     */
    private function _browseSoap($start, $nb)
    {
        $execTime = microtime(true);
        $result = null;
        try {
            $result = $this->_soapClientExtendedSymbolicXmlDa->Browse(
                array('FirstIndex' => $start,
                'NbOfSymbol' => $nb)
            );
        } catch (Exception $error) {
            $this->_lastErrorMessage = $error->getMessage().
                                        ' Ligne :'.
                                        $error->getLine();

            $this->_lastRequestState = false;
            $this->_allRequestState = false;
        }
        $this->_saveRequestTime($execTime);
        return $result;
    }

    /**
     * Read variable value in the factory cast namespace and store in a object array factorycast::_allReadVar
     *
     * @param array $list array of variable name ['var1', 'var2',...] if $list is empty the function retrieve all variable
     *
     * @return array variable array
     */
    public function read($list = array())
    {
        if (count($list) == 0) {
            $allVariable = $this->browse();
            if (count($allVariable) == 0) {
                return array();
            }
            foreach ($allVariable as $key => $var) {
                $list[] = $var->Name;
            }
        }
        $data = array();
        $start = 0;

        do {
            $slicedArray = array_slice($list, $start, $this->_maxReadVar);
            if (count($slicedArray) > 0) {
                $result = $this->_readSoap($slicedArray);
                if (!is_null($result)) {
                    if (count($list)>1) {
                        $data = array_merge($data, $result->ReadResult->Item);
                    } else {
                        $data = $result->ReadResult->Item;
                    }
                } else {
                    break;
                }
            }
            $start += $this->_maxReadVar;
        } while (count($slicedArray) > 0 || is_null($result));
        
        
        if (!is_null($result) && $this->_lastRequestState) {
            if (count($list) > 1) {
                foreach ($data as $key => $item) {
                    $this->_allReadVar[$item->Name] = array(
                                        'Value' => $item->Value,
                                        'VariableType' => $item->VariableType
                    );
                }
            } else {
                $this->_allReadVar[$result->ReadResult->Item->Name] = array(
                    'Value' => $result->ReadResult->Item->Value,
                    'VariableType' => $result->ReadResult->Item->VariableType
                );
            }
        }
        return $this->_allReadVar;
    }

    /**
     * SOAP request used by factorycast::read function
     *
     * @param array $list array of variable name ['var1', 'var2',...]
     *
     * @return array
     */
    private function _readSoap($list)
    {
        $execTime = microtime(true);
        $result = null;
        $this->_lastRequestState = true;
        try {
            $result = $this->_soapClientExtendedSymbolicXmlDa->Read(
                array('VariableList' => $list)
            );
        } catch (Exception $error) {
            $this->_lastErrorMessage = $error->getMessage().
                                        ' Ligne :'.
                                        $error->getLine();
            $this->_errorVar($this->_lastErrorMessage);
            $this->_lastRequestState = false;
            $this->_allRequestState = false;
        }
        $this->_saveRequestTime($execTime);
        return $result;
    }

    /**
     * Description
     *
     * @param string $a Foo
     *
     * @return int $b Bar
     */
    private function _makeSoapVarValue($value, $type)
    {
        $XSDTYPE = array(
                    'BOOL'  => array('boolean', XSD_BOOLEAN),
                    'EBOOL' => array('unsignedByte', XSD_UNSIGNEDBYTE),
                    'INT'   => array('short', XSD_SHORT),
                    'DINT'   => array('short', XSD_INT),
                    'UINT'   => array('unsignedInt', XSD_UNSIGNEDINT),
                    'UDINT'   => array('unsignedInt', XSD_UNSIGNEDINT),
                    'TIME'   => array('unsignedInt', XSD_UNSIGNEDINT),
                    'DATE'   => array('dateTime', XSD_DATETIME),
                    'TOD'   => array('dateTime', XSD_DATETIME),
                    'DT'   => array('dateTime', XSD_DATETIME),
                    'REAL'  => array('float', XSD_FLOAT),
                    'BYTE'  => array('unsignedByte', XSD_UNSIGNEDBYTE),
                    'WORD'  => array('unsignedShort', XSD_UNSIGNEDSHORT),
                    'DWORD'  => array('unsignedInt', XSD_UNSIGNEDINT),
                    'STRING'  => array('string', XSD_STRING),
                    'STRING[n]'  => array('string', XSD_STRING)
                );

        return new SoapVar($value, $XSDTYPE[$type][1], $XSDTYPE[$type][0], XSD_NAMESPACE);
    }

    /**
     * Write all modified value in factory cast variable
     *
     * @return void
     */
    public function write()
    {
        if (count($this->_allWriteVar) > 0) {
            $result = $this->_writeSoap();
        }
    }

    /**
     * SOAP request used by factorycast::write function
     *
     * @return array
     */
    private function _writeSoap()
    {
        $execTime = microtime(true);
        $result = null;
        $this->_lastRequestState = true;
        $Item = array();
        foreach ($this->_allWriteVar as $key => $value) {
            $Item[] = array('Name' => $key, 'VariableType' => $value['VariableType'], 'Value' => $this->_makeSoapVarValue($value['Value'], $value['VariableType']));
        }

        try {
            var_dump($Item);
            $result = $this->_soapClientExtendedSymbolicXmlDa->Write(
                array('ItemList' => $Item)
            );
        } catch (Exception $error) {
            echo $this->_soapClientExtendedSymbolicXmlDa->__getLastRequest();
            $this->_lastErrorMessage = $error->getMessage().
                                        ' Ligne :'.
                                        $error->getLine();
            $this->_errorVar($this->_lastErrorMessage);
            $this->_lastRequestState = false;
            $this->_allRequestState = false;
        }
        $this->_saveRequestTime($execTime);
        return $result;
    }

    /**
     * Get variable value previously read with factorycast::read()
     *
     * @param string $varName Variable name
     *
     * @return mixed Variable value
     */
    public function get($varName)
    {
        return $this->_allReadVar[$varName]['Value'] ?? 'Variable Not Exist';
    }

    /**
     * Set variable value before write in factory cast module
     *
     * @param string $varName      Variable name to set
     * @param mixed  $value        New variable value
     * @param string $variableType Variable Type (if not set the function read variable type from factorycast module)
     *
     * @return void
     */
    public function set($varName, $value, $variableType = null)
    {
        if (is_null($variableType) && !isset($this->_allReadVar[$varName])) {
            $this->read(array($varName));
            if (isset($this->_allReadVar[$varName])) {
                $variableType = $this->_allReadVar[$varName]['VariableType'];
            } else {
                return false;
            }
        } elseif (is_null($variableType) && isset($this->_allReadVar[$varName])) {
            $variableType = $this->_allReadVar[$varName]['VariableType'];
        }

        $this->_allWriteVar[$varName] = array(
                                            'Value' => $value,
                                            'VariableType' => $variableType
                                        );
    }

    /**
     * Get plc Infos
     *
     * @param string $plcIp Factory cast ip adress
     *
     * @return array plc infos array
     */
    public static function getPLcInfo($plcIp)
    {
        $plcUrl = 'http://'.$plcIp.'/ws/Umas?wsdl';
        $plc = new SoapClient($plcUrl);
        $plcInfo = $plc->GetPlcInfo()->GetPlcInfoResult;
        return $plcInfo;
    }

    public static function getPLcStatus($plcIp)
    {
        $plcUrl = 'http://'.$plcIp.'/ws/Umas?wsdl';
        $plc = new SoapClient($plcUrl);
        $plcStatus = $plc->GetPlcStatus()->GetPlcStatusResult;
        return $plcStatus;
    }

    public static function getAppliInfo($plcIp)
    {
        $plcUrl = 'http://'.$plcIp.'/ws/Umas?wsdl';
        $plc = new SoapClient($plcUrl);
        $appliInfos = array();

        $appliInfos['SOAP'] = $plc->GetAppliInfo()->GetAppliInfoResult;

        $appliInfos['Name'] = $appliInfos['SOAP']->NameAppli;

        $appliInfos['CreateDate'] = $appliInfos['SOAP']->CreateDate->ucDay.'/'.
        $appliInfos['SOAP']->CreateDate->ucMonth.'/'.
        $appliInfos['SOAP']->CreateDate->usYear.' '.
        $appliInfos['SOAP']->CreateDate->ucHour.':'.
        $appliInfos['SOAP']->CreateDate->ucMin.':'.
        $appliInfos['SOAP']->CreateDate->ucSec;
                                    
        $appliInfos['ModificationDate'] = $appliInfos['SOAP']->ModifDate->ucDay.'/'.
        $appliInfos['SOAP']->ModifDate->ucMonth.'/'.
        $appliInfos['SOAP']->ModifDate->usYear.' '.
        $appliInfos['SOAP']->ModifDate->ucHour.':'.
        $appliInfos['SOAP']->ModifDate->ucMin.':'.
        $appliInfos['SOAP']->ModifDate->ucSec;
        
        return $appliInfos;
    }

    public static function readEthernetIP($plcIp)
    {
        $plcUrl = 'http://'.$plcIp.'/ws/Eth?wsdl';
        $plc = new SoapClient($plcUrl);
        $ethernetInfos = $plc->ReadEthernetIP();
    }

    public static function readEthernetStatistics($plcIp)
    {
        $plcUrl = 'http://'.$plcIp.'/ws/Eth?wsdl';
        $plc = new SoapClient($plcUrl);
        $ethernetStatistics = $plc->ReadEthernetStatistics()->ReadEthernetStatisticsResult;
        return $ethernetStatistics;
    }

    public static function getRack($plcIp)
    {
        $plcUrl = 'http://'.$plcIp.'/ws/Umas?wsdl';
        $param = array('Bus' => 0, 'Drop' => 0, 'Rack' => 0, 'Slot' => 0, 'SubSlot' => 0);
        $plc = new SoapClient($plcUrl);
        var_dump($plc->ReadModule($param)->ReadModuleResult);
    }
}
