<?php
/**
 *
 * This file is part of Open Library System.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * Open Library System is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Open Library System is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Open Library System.  If not, see <http://www.gnu.org/licenses/>.
*/


/**
 * 
 *
 */

require_once('OLS_class_lib/webServiceServer_class.php');


class openPriceCheck extends webServiceServer {

  protected $curl;

 /** \brief priceCheck - 
  *
  * Request:
  *   - CustomerIDType and CustomerID
  *   - one or more: MatIDType and MatID
  *
  * Response:
  */
  public function priceCheck($param) {
define('xDEBUG', FALSE);
    $ret->priceCheckResponse->_namespace = 'http://oss.dbc.dk/ns/openpricecheck';
    $pcr = &$ret->priceCheckResponse->_value;
    if (!$this->aaa->has_right('openpricecheck', 500))
      $pcr->error->_value = 'authentication_error';
    else {
      $this->curl = new curl();
      if (is_array($param->supplier))
        foreach ($param->supplier as $supl)
          $supplier[strtolower($supl->_value)] = $supl->_value;
      elseif ($param->supplier->_value)
        $supplier[strtolower($param->supplier->_value)] = $param->supplier->_value;
      if (!$timeout = (int) $param->timeout->_value)
        if (!$timeout = (int) $this->config->get_value('timeout', 'setup'))
          $timeout = 10;
      if ($supplier_count = (int) $param->supplierCount->_value)
        $this->curl->set_wait_for_connections($supplier_count);
      $pq->PriceQuery = $param->PriceQuery;
      $hosts = $this->config->get_value('supplier', 'setup');
      $http_proxy = $this->config->get_value('http_proxy', 'setup');
      $con = array();
      foreach ($hosts as $host_id => $host) {
        if (empty($supplier) || $supplier[strtolower($host_id)]) {
          if ($host['timeout'])
            $this->curl->set_timeout($host['timeout'], count($con));
          else
            $this->curl->set_timeout($timeout, count($con));
          if ($host['http_proxy'])
            $this->curl->set_proxy($host['http_proxy'], count($con));
          elseif ($http_proxy)
            $this->curl->set_proxy($http_proxy, count($con));
          if ($host['soapAction'])
            $this->curl->set_soap_action($host['soapAction'], count($con));
          if ($host['namespace']) 
            $req = $this->objconvert->obj2soap($this->objconvert->set_obj_namespace($pq, $host['namespace']));
          else
            $req = $this->objconvert->obj2soap($pq);
          $this->curl->set_post_xml($req, count($con));
          $this->curl->set_url($host['url'], count($con));
          $con[] = $host_id;
        }
      }
      if ($con) {
        $this->watch->start('curl');
        $curl_result = $this->curl->get();
        $this->watch->stop('curl');
        $curl_err = $this->curl->get_status();
        if (count($con) == 1) {
          $curl_result = array($curl_result);
          $curl_err = array($curl_err);
        }
        if (xDEBUG) var_dump($curl_result);
        if (xDEBUG) var_dump($curl_err);

        foreach ($curl_result as $res_idx => $result) {
          $obj = $this->xmlconvert->soap2obj($result);
          if ($obj->Envelope) $obj = &$obj->Envelope->_value->Body->_value;
          if (empty($result)) {
            verbose::log(FATAL, 'PriceCheck:: http error for ' . $con[$res_idx] . 
                                ' error: "' . $curl_err[$res_idx]['error'] .
                                '" errno: ' . $curl_err[$res_idx]['errno'] .
                                ' returning httpcode: ' . $curl_err[$res_idx]['http_code']);
            $pqr->error->_value = 'no answer from supplier';
            $pqr->responseTime->_value = strval($curl_err[$res_idx]['total_time']);
            $pqr->supplier->_value = $con[$res_idx];
          } elseif ($fv = $obj->Fault->_value) {
            verbose::log(FATAL, 'PriceCheck:: Soap error for ' . $con[$res_idx] . 
                                ' returning faultcode: "' . $fv->faultcode->_value . 
                                '" faultstring: "' . $fv->faultstring->_value . 
                                '" detail: "' . $fv->detail->_value . '"');
            $pqr->error->_value = 'no answer from supplier';
            $pqr->responseTime->_value = strval($curl_err[$res_idx]['total_time']);
            $pqr->supplier->_value = $con[$res_idx];
          } else {
            if ($this->validate['pricequery'] && empty($hosts[$con[$res_idx]]['skip_validate'])) {
              $this->watch->start('validate');
              $xml = $this->objconvert->obj2xmlNS($obj);
              if (@ !$this->validate_xml($xml, $this->validate['pricequery'])) {
                verbose::log(FATAL, 'PriceCheck:: Validate error for ' . $con[$res_idx] . ' record: ' . $xml);
                $pqr->error->_value = 'item not found';
              }
              $this->watch->stop('validate');
            }
            if (empty($pqr->error)) {
              $pqr->PriceQueryResponse = $this->objconvert->set_obj_namespace($obj->PriceQueryResponse, $this->xmlns['pric']);
              //$pqr->PriceQueryResponse = &$obj->PriceQueryResponse;;
            }
            $pqr->responseTime->_value = strval($curl_err[$res_idx]['total_time']);
            $pqr->supplier->_value = $con[$res_idx];
          }
          if ($pqr->error) 
            $pqr->error->_namespace = 'http://oss.dbc.dk/ns/openpricecheck';
          $pqr->responseTime->_namespace = 'http://oss.dbc.dk/ns/openpricecheck';
          $pqr->supplier->_namespace = 'http://oss.dbc.dk/ns/openpricecheck';
          $pcr->price[]->_value = $pqr;
          $pcr->price[count($pcr->price) - 1]->_namespace = 'http://oss.dbc.dk/ns/openpricecheck';
          unset($pqr);
        }
      } elseif (empty($supplier)) {
        $pcr->error->_value = 'no request or supplier recognized';
      }
      if ($supplier)
        foreach ($supplier as $supp) 
          if (empty($hosts[strtolower($supp)])) {
            $pqr->error->_value = 'unknown supplier';
            $pqr->supplier->_value = $supp;
            $pqr->error->_namespace = 
            $pqr->supplier->_namespace = 'http://oss.dbc.dk/ns/openpricecheck';
            $pcr->price[]->_value = $pqr;
            $pcr->price[count($pcr->price) - 1]->_namespace = 'http://oss.dbc.dk/ns/openpricecheck';
            unset($pqr);
          }
    }
    if ($pcr->error) 
      $pcr->error->_namespace = 'http://oss.dbc.dk/ns/openpricecheck';
if (xDEBUG) echo '<hr />';
if (xDEBUG) var_dump($ret);
if (xDEBUG) echo '<hr />';
    if (xDEBUG) { var_dump($param); var_dump($pcr); die('test'); }
    return $ret;
  }



 /** \brief getSuppliers - 
  *
  * Request: None
  *
  * Response: One or more suplliers
  */
  public function getSuppliers($param) {
define('xDEBUG', FALSE);
    $ret->getSuppliersResponse->_namespace = 'http://oss.dbc.dk/ns/openpricecheck';
    $gsr = &$ret->getSuppliersResponse->_value;
    if (!$this->aaa->has_right('openpricecheck', 500))
      $pcr->error->_value = 'authentication_error';
    else {
      $hosts = $this->config->get_value('supplier', 'setup');
      foreach ($hosts as $host_id => $host) {
        $suppl->_namespace = 'http://oss.dbc.dk/ns/openpricecheck';
        $suppl->_value = $host_id;
        $gsr->supplier[] = $suppl;
        unset($suppl);
      }
    }
    if (xDEBUG) { var_dump($param); var_dump($pcr); die('test'); }
    return $ret;
  }



 /** \brief constructor
  *
  */
  public function __construct(){
    webServiceServer::__construct('openpricecheck.ini');
  }

}
/*
 * MAIN
 */

$ws=new openPriceCheck();
$ws->handle_request();

?>

