<?php
/*
    org.project60.banking extension for CiviCRM

    This program is free software: you can redistribute it and/or modify
    it under the terms of the GNU Affero General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU Affero General Public License for more details.

    You should have received a copy of the GNU Affero General Public License
    along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 *
 * @package org.project60.banking
 * @copyright GNU Affero General Public License
 * $Id$
 *
 */
abstract class CRM_Banking_PluginModel_Importer extends CRM_Banking_PluginModel_IOPlugin {

  // these are the fields valid for a BTX record.
  protected $_primary_btx_fields = array( 'version', 'debug', 'amount', 'bank_reference', 'value_date', 'booking_date', 'currency', 'type_id', 'status_id', 'data_raw', 'data_parsed', 'ba_id', 'party_ba_id', 'tx_batch_id', 'sequence' );

  // these fields will be used to determine, if this is a duplicate record... the primary keys if you want
  protected $_compare_btx_fields = array( 'bank_reference'=>TRUE, 'amount'=>TRUE, 'value_date'=>TRUE, 'booking_date'=>TRUE, 'currency'=>TRUE, 'version'=>3 );
  
  // if this is set, all checkAndStoreBTX() methods will be added to it
  protected $_current_transaction_batch = NULL;
  protected $_current_transaction_batch_attributes = array();

  
  // ------------------------------------------------------
  // Functions to be provided by the plugin implementations
  // ------------------------------------------------------
  /** 
   * Report if the plugin is capable of importing files
   * 
   * @return bool
   */
  static function does_import_files() {
    return false;
  }

  /** 
   * Report if the plugin is capable of importing streams, i.e. data from a non-file source, e.g. the web
   * 
   * @return bool
   */
  static function does_import_stream() {
    return false;
  }

  /** 
   * Test if the given file can be imported
   * 
   * @var 
   * @return TODO: data format? 
   */
  abstract function probe_file( $file_path, $params );

  /** 
   * Import the given file
   * 
   * @return TODO: data format? 
   */
  abstract function import_file( $file_path, $params );

  /** 
   * Test if the configured source is available and ready
   * 
   * @var 
   * @return TODO: data format?
   */
  abstract function probe_stream( $params );

  /** 
   * Import from the configured source
   * 
   * @return TODO: data format?
   */
  abstract function import_stream( $params );


  // ------------------------------------------------------
  //            utility functions
  // ------------------------------------------------------

  /**
   * This will create a new transaction batch, that all bankt transcations created 
   * with checkAndStoreBTX will be attached to. The transaction gets written when calling
   * the corresponding closeTransactionBatch counterpart.
   *
   * You can also re-use and extend a given btx batch by providing a batch ID
   */
  function openTransactionBatch($batch_id=0) {
    if ($this->_current_transaction_batch==NULL) {
      $this->_current_transaction_batch = new CRM_Banking_BAO_BankTransactionBatch();
      $this->_current_transaction_batch_attributes = array();

      if ($batch_id) {
        // load an existing batch
        $this->_current_transaction_batch->get('id', $batch_id);
        $this->_current_transaction_batch_attributes['isnew'] = FALSE;
        $this->_current_transaction_batch_attributes['sum'] = ($this->_current_transaction_batch->ending_balance - $this->_current_transaction_batch->starting_balance);
      } else {
        // TODO: \/ why are the defaults not generated by CRM_Banking_BAO_BankTransactionBatch::add() ???
        $this->_current_transaction_batch->issue_date = date('YmdHis');
        $this->_current_transaction_batch->reference = '';
        $this->_current_transaction_batch->sequence = 0;
        $this->_current_transaction_batch->tx_count = 0;
        //       /\ 

        $this->_current_transaction_batch->save();
        $this->_current_transaction_batch_attributes['isnew'] = TRUE;
        $this->_current_transaction_batch_attributes['sum'] = 0;
      }
    } else {
      $this->reportProgress($progress, 
                  ts("Internal error: trying to open BTX batch before closing an old one."), 
                  CRM_Banking_PluginModel_Base::REPORT_LEVEL_ERROR);
    }
  }

  /**
   * This will return the current BTX batch as a BAO to the client for modification.
   * Please DON'T SAVE THE OBJECT. Saving should take place when calling the 
   * closeTransactionBatch() method.
   */
  function getCurrentTransactionBatch($store=TRUE) {
    return $this->_current_transaction_batch;
  }

  /**
   * This will close a previously opened transaction batch, see openTransactionBatch
   *
   * If you pass $store=FALSE as a parameter, the currently open batch will be dismissed
   */
  function closeTransactionBatch($store=TRUE) {
    if ($this->_current_transaction_batch!=NULL) {
      if ($store) {

        // check if the sums are correct:
        if ($this->_current_transaction_batch->ending_balance) {
          $sum_in_bao = $this->_current_transaction_batch->ending_balance - $this->_current_transaction_batch->starting_balance;
          $deviation = $sum_in_bao - $this->_current_transaction_batch_attributes['sum'];
          $correct_value = $this->_current_transaction_batch->starting_balance + $this->_current_transaction_batch_attributes['sum'];
          if (abs($deviation) > 0.005) {
            // there is a (too big) deviation!
            if ($this->_current_transaction_batch->ending_balance) { // only log if it was set
              $this->reportProgress($progress, 
                    sprintf(ts("Adjusted ending balance from %s to %s!"), $this->_current_transaction_batch->ending_balance, $correct_value),
                    CRM_Banking_PluginModel_Base::REPORT_LEVEL_WARN);
            }
            $this->_current_transaction_batch->ending_balance = $correct_value;
          }
        } else if ($this->_current_transaction_batch->starting_balance!=NULL) {
          // set the calculated ending balance only if the was a starting balance set
          $this->_current_transaction_batch->ending_balance = $this->_current_transaction_batch->starting_balance + $this->_current_transaction_batch_attributes['sum'];
        }

        // set the dates
        if (!$this->_current_transaction_batch->starting_date && isset($this->_current_transaction_batch_attributes['starting_date']))
          $this->_current_transaction_batch->starting_date = $this->_current_transaction_batch_attributes['starting_date'];
        if (!$this->_current_transaction_batch->ending_date && isset($this->_current_transaction_batch_attributes['ending_date']))
          $this->_current_transaction_batch->ending_date = $this->_current_transaction_batch_attributes['ending_date'];

        // set the bank reference
        if (!$this->_current_transaction_batch->reference && isset($this->_current_transaction_batch_attributes['references']))
          $this->_current_transaction_batch->reference = md5($this->_current_transaction_batch_attributes['references']);

        $this->_current_transaction_batch->save();

      } else if ($this->_current_transaction_batch_attributes['isnew']) {
        // since the batch object had to be created in order to get the ID, we would have to
        //  delete it here, if the user didn't want to keep it.
        $this->_current_transaction_batch->delete();
      }
      $this->_current_transaction_batch = NULL;

    } else {
      $this->reportProgress($progress, 
                  ts("Internal error: trying to close a nonexisting BTX batch."), 
                  CRM_Banking_PluginModel_Base::REPORT_LEVEL_ERROR);
    }
  }

  /** 
   * Will update the transaction information, which is collected for validation
   */
  function _updateTransactionBatchInfo($btx) {
    if ($this->_current_transaction_batch) {
      // update simple counters
      $this->_current_transaction_batch->tx_count += 1;
      $attribs = &$this->_current_transaction_batch_attributes;
      $attribs['sum'] += $btx['amount'];

      // keep track of dates
      if (!isset($attribs['starting_date'])) {
        error_log($btx['booking_date']);
        $attribs['starting_date'] = $btx['booking_date'];
      } else if (strtotime($attribs['ending_date']) > strtotime($btx['booking_date'])) {
        // the new transaction is before the current starting date:
        $attribs['starting_date'] = $btx['booking_date'];
      }
      if (!isset($attribs['ending_date'])) {
        $attribs['ending_date'] = $btx['booking_date'];
      } else if (strtotime($attribs['ending_date']) < strtotime($btx['booking_date'])) {
        // the new transaction is fater the current ending date:
        $attribs['ending_date'] = $btx['booking_date'];
      }

      // update bank reference list
      if (!isset($attribs['references'])) {
        $attribs['references'] = $btx['bank_reference'];
      } else {
        $attribs['references'] = $attribs['references'].$btx['bank_reference'];
      }

      // test currency
      if ($this->_current_transaction_batch->currency && isset($btx['currency'])) {
        if ($this->_current_transaction_batch->currency != $btx['currency']) {
          $this->reportProgress(CRM_Banking_PluginModel_Base::REPORT_PROGRESS_NONE, 
              ts("WARNING: multiple currency batches not fully supported")); 
        }
      } else {
        $this->_current_transaction_batch->currency = $btx['currency'];        
      }
    }
  }


  /**
   * This method will take an array with all the attributes for a bank transaction object,
   * check whether this object already exists, and create a new data entry if not.
   * In case the object exists, the existing entry is returned.
   * If the client wants to merge the data, this has to be done by the client.
   *
   * @return TRUE, if successful, FALSE if not, or a duplicate existing BTX as property array
   */
  function checkAndStoreBTX($btx, $progress, $params=array()) {
    // first, test for duplicates:
    $duplicate_test = array_intersect_key($btx, $this->_compare_btx_fields);
    $result = civicrm_api('BankingTransaction', 'get', $duplicate_test);
    if (isset($result['is_error']) && $result['is_error']) {
      $this->reportProgress($progress, 
                            ts("Failed to query BTX."), 
                            CRM_Banking_PluginModel_Base::REPORT_LEVEL_ERROR);
      return FALSE;
    }

    if ($result['count']>0) {
      // there might be another BTX...check the accounts
      $duplicates = $result['values'];
      $this->reportProgress($progress, 
                        ts("Duplicate BTX entry detected. Not imported!"), 
                        CRM_Banking_PluginModel_Base::REPORT_LEVEL_WARN);
      return reset($duplicates); // RETURN FIRST ENTRY
    }


    // now store...


    // check for dry run
    if (isset($params['dry_run']) && $params['dry_run']=="on") {
      // DRY RUN ENABLED
      $this->reportProgress($progress, 
                            sprintf(ts("DRY RUN: Did not create bank transaction '%d' (%s %s on %s)"), $result['id'], number_format((float)$btx['amount'], 2), $btx['currency'], $btx['booking_date']));
      return TRUE;
  
    } else {
      // attach to the transaction batch, if there is an open one
      if ($this->_current_transaction_batch) {
        $btx['tx_batch_id'] = $this->_current_transaction_batch->id;
      }

      $result = civicrm_api('BankingTransaction', 'create', $btx);
      if ($result['is_error']) {
        $this->reportProgress($progress, 
                              sprintf(ts("Error while storing BTX: %s") ,implode("<br>", $result)),
                              CRM_Banking_PluginModel_Base::REPORT_LEVEL_ERROR);
        return FALSE;
      } else {
        $this->reportProgress($progress, 
                              sprintf(ts("Created bank transaction '%d' (%s %s on %s)"), $result['id'], number_format((float)$btx['amount'], 2), $btx['currency'], $btx['booking_date']));

        $this->_updateTransactionBatchInfo($btx);
        return TRUE;
      }
    }
  }


  /**
   * class constructor
   */ function __construct($config_name) {
    parent::__construct($config_name);

  }
}

