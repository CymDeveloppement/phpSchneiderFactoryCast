<?php

/**
 * FactoryCast get data From Schneider PLC over SOAP/XML 
 *
 * PHP version 7
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
    function __construct($plcIp)
    {
        $this->_plcUrl = 'http://'.$plcIp.'/ws/ExtendedSymbolicXmlDa?wsdl';
        $execTime = microtime(true);
        $this->_soapClientExtendedSymbolicXmlDa = new SoapClient($this->_plcUrl);
        //var_dump($this->_soapClientExtendedSymbolicXmlDa->__getTypes());
        $this->saveRequestTime($execTime);
    }

    /**
     * Get the last SOAP request Time
     *  
     * @return int (millisecond)
     */
    public function getLastRequestTime()
    {
        return intval($this->_lastRequestTime*1000);
    }

    public function getTotalRequestTime()
    {
        return intval($this->_totalRequestTime*1000);
    }


    public function saveRequestTime($startRequestTime)
    {
        $lastRequestTime = (microtime(true) - $startRequestTime);
        $this->_lastRequestTime = $lastRequestTime;
        $this->_totalRequestTime += $lastRequestTime;
    }

    public function errorVar($message)
    {
        if (strpos($message, 'Application error:The symbol \'') !== false 
            && strpos($message, 'is not present in the namespace') !== false
        ) {
            $Var = explode('\'', $message);
            $this->_allErrorVar[$Var[1]] = 'Not exist in the namespace';
        }
    }

    public function getLastError()
    {
        return $this->_lastErrorMessage;
    }

    public function getAllErrorVar()
    {
        return $this->_allErrorVar;
    }

    public function getAllRequestState()
    {
        return $this->_allRequestState;
    }

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
    
    private function _browseSoap($start, $nb)
    {
        $execTime = microtime(true);
        $result = null;
        try{
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
        $this->saveRequestTime($execTime);
        return $result;
    }

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

    private function _readSoap($list)
    {
        $execTime = microtime(true);
        $result = null;
        $this->_lastRequestState = true;
        try{
            $result = $this->_soapClientExtendedSymbolicXmlDa->Read(
                array('VariableList' => $list)
            );
        } catch (Exception $error) {
            $this->_lastErrorMessage = $error->getMessage().
                                        ' Ligne :'.
                                        $error->getLine();
            $this->errorVar($this->_lastErrorMessage);
            $this->_lastRequestState = false;
            $this->_allRequestState = false;
        }
        $this->saveRequestTime($execTime);
        return $result;
    }

    public function write()
    {
        if (count($this->_allWriteVar) > 0) {
            $result = $this->_writeSoap();
            var_dump($this->_allWriteVar);
        }
    }

    private function _writeSoap()
    {
        $execTime = microtime(true);
        $result = null;
        $this->_lastRequestState = true;
        $Item = array();
        foreach ($this->_allWriteVar as $key => $value) {
            //$Item['Item'][] = (object) array('Name' => $key, 'VariableType' => $value['VariableType'], 'Value' => '13');
            $Item['Item']['Name'] = $key;
            $Item['Item']['VariableType'] = $value['VariableType'];
            $Item['Item']['Value'] = 13;
        }

        try{
            var_dump($Item);
            $result = $this->_soapClientExtendedSymbolicXmlDa->Write(
                array('ItemList' => $Item)
            );
        } catch (Exception $error) {
            echo $this->_soapClientExtendedSymbolicXmlDa->__getLastRequest();
            //var_dump($error);
            $this->_lastErrorMessage = $error->getMessage().
                                        ' Ligne :'.
                                        $error->getLine();
            $this->errorVar($this->_lastErrorMessage);
            $this->_lastRequestState = false;
            $this->_allRequestState = false;
        }
        $this->saveRequestTime($execTime);
        return $result;
    }

    public function get($varName)
    {
        return $this->_allReadVar[$varName]['Value'] ?? 'Variable Not Exist';
    }

    public function set($varName, $value, $variableType = null)
    {
        if (is_null($variableType) && !isset($this->_allReadVar[$varName])) {
            $this->read(array($varName));
            echo 'Lecture Variable';
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

    static public function getPLcInfo($plcIp)
    {
        $plcUrl = 'http://'.$plcIp.'/ws/Umas?wsdl';
        $plc = new SoapClient($plcUrl);
        $plcInfo = $plc->GetPlcInfo()->GetPlcInfoResult;
        return $plcInfo;
    }

    static public function getPLcStatus($plcIp)
    {
        $plcUrl = 'http://'.$plcIp.'/ws/Umas?wsdl';
        $plc = new SoapClient($plcUrl);
        $plcStatus = $plc->GetPlcStatus()->GetPlcStatusResult;
        return $plcStatus;
    }

    static public function getAppliInfo($plcIp)
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


}